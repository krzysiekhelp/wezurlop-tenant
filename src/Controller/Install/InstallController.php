<?php

declare(strict_types=1);

namespace Wezurlop\Controller\Install;

use PDOException;
use RuntimeException;
use Throwable;
use Twig\Environment;
use Wezurlop\Diagnostics\CheckResult;
use Wezurlop\Diagnostics\EnvironmentCheck;
use Wezurlop\Diagnostics\HttpProbeTarget;
use Wezurlop\Http\Request;
use Wezurlop\Http\Response;
use Wezurlop\Repository\DiagnosticsRepository;
use Wezurlop\Service\Install\DeployFiles;
use Wezurlop\Service\Install\EnvWriter;
use Wezurlop\Service\Install\InstallStateStore;
use Wezurlop\Service\Install\MigrationRunner;
use Wezurlop\Service\Install\PrefixDetector;
use Wezurlop\Service\Install\SchemaProbeRepository;
use Wezurlop\Service\ServerProfile;
use Wezurlop\Service\JobRunner;
use Wezurlop\Service\Mail\MailMessage;
use Wezurlop\Service\Mail\FileTransport;
use Wezurlop\Service\Mail\SmtpTransport;
use Wezurlop\Support\Config;
use Wezurlop\Db\Connection;
use Wezurlop\Support\EnvFile;
use Wezurlop\Support\Logger;
use Wezurlop\Support\Services;

/**
 * Instalator tenanta (CLAUDE.md §15, docs/szkielet-tenanta.md rozdz. 12). POZA strefą kodowaną.
 *
 * - działa tylko bez var/install.lock (sprawdzane w pierwszej linii),
 * - stan w pliku (InstallStateStore), własny CSRF związany z tym plikiem, bez sesji PHP,
 * - od kroku 4 wymaga tokenu instalacyjnego (INSTALL_TOKEN w przygotowanym .env — zaślepka panelu),
 * - nie wyświetla wpisanych poświadczeń ani klucza API, bez phpinfo(),
 * - pierwszy administrator dostaje link ustawiający hasło — instalator hasła nie ustawia.
 */
final class InstallController
{
    public const PATH = '/instalacja';

    private const STEPS = [
        1  => 'Diagnostyka środowiska',
        2  => 'Adresy aplikacji',
        3  => 'Token instalacyjny',
        4  => 'Baza danych',
        5  => 'Adres i ścieżki',
        6  => 'Poczta',
        7  => 'Weryfikacja HTTP',
        8  => 'Migracje',
        9  => 'Administrator',
        10 => 'Zapis konfiguracji',
        11 => 'Zakończenie',
    ];

    /** Klucze wstępnego .env przenoszone do pliku końcowego (INSTALL_TOKEN celowo nie). */
    private const PRESERVED_ENV = [
        'STORAGE_PATH', 'VAR_PATH', 'LICENSE_PUBLIC_KEY_PATH', 'LICENSE_FILE', 'PANEL_URL', 'PANEL_INSTALLATION_KEY',
    ];

    private const MIGRATION_BUDGET = 20.0;

    /** Potwierdzona częstotliwość crona → minuty (0 = nieznana). */
    private const CRON_MODES = ['crontab5' => 5, 'http5' => 5, 'every15' => 15, 'hourly' => 60, 'none' => 0];

    private const CRON_INSTRUCTIONS = [
        'crontab5' => 'Wpis crontab: */5 * * * * php <katalog aplikacji>/bin/run-jobs.php',
        'http5'    => 'Hosting uruchamia plik cron-05min.php z katalogu domeny co 5 minut.',
        'every15'  => 'Cron co 15 minut: bin/run-jobs.php. Pilne wiadomości wysyłane są także po zwykłych żądaniach.',
        'hourly'   => 'Cron co godzinę: bin/run-jobs.php. Pilne wiadomości wysyłane są także po zwykłych żądaniach.',
        'none'     => 'Bez crona kolejka działa tylko po zwykłych żądaniach (pilne wiadomości). Skonfiguruj cron jak najszybciej.',
    ];

    public function __construct(
        private readonly string $appRoot,
        private readonly string $envFile,
        private readonly string $varPath,
        private readonly Environment $twig,
        private readonly Logger $logger,
        private readonly bool $https,
    ) {
    }

    public function handle(Request $request): Response
    {
        if (is_file($this->varPath . '/install.lock')) {
            // Pierwsza linia: po instalacji instalator nie odpowiada.
            return Response::text("Nie znaleziono\n", 404);
        }

        $store = new InstallStateStore($this->varPath);
        // Po tokenie: stan w pliku (klucz w ciasteczku). Przed tokenem: podpisane ciasteczko, bez blokady.
        $state = $store->loadAuthorized($request->cookie(InstallStateStore::COOKIE))
            ?? $store->loadPre($request->cookie(InstallStateStore::COOKIE_PRE));
        if ($state === null) {
            $state = $store->newPre();
            $this->logger->info('Instalator: nowy przebieg');
        }
        $cookie = null;
        $status = 200;

        $errors = [];
        $notice = null;
        $finished = null;
        if ($request->method() === 'POST') {
            if (!hash_equals((string) $state['csrf'], $request->postString('_install_csrf', 64))) {
                return $this->withCookie($this->render(['expired' => true, 'step' => $state['step']], 419), $this->stateCookie($store, $state));
            }
            $step = (int) $state['step'];
            if ((int) $request->postString('step', 3) !== $step) {
                $errors[] = 'Ten krok został już wykonany albo formularz jest nieaktualny.';
            } elseif ($step >= 4 && ($state['authorized'] ?? false) !== true) {
                $errors[] = 'Najpierw podaj token instalacyjny.';
                $state['step'] = 3;
            } elseif ($request->postString('back', 1) === '1' && $step >= 5 && $step <= 7) {
                $state['step'] = $step - 1;
            } else {
                try {
                    $result = $this->submit($step, $request, $state, $store);
                    $errors = $result['errors'];
                    $notice = $result['notice'] ?? null;
                    $finished = $result['finished'] ?? null;
                    $state = $result['state'];
                    $cookie = $result['cookie'] ?? null;
                    $status = $result['status'] ?? 200;
                } catch (Throwable $e) {
                    $eventId = bin2hex(random_bytes(8));
                    $this->logger->error('Instalator: błąd kroku', [
                        'event_id' => $eventId, 'step' => $step, 'exception' => $e::class, 'error' => $e->getMessage(),
                    ]);
                    $errors[] = 'Nie udało się wykonać kroku. Identyfikator zdarzenia: ' . $eventId . '.';
                }
            }
            if ($finished !== null) {
                $store->delete();

                return $this->render(['finished' => $finished, 'step' => 11]);
            }
            if (($state['authorized'] ?? false) === true && $cookie === null) {
                $store->save($state);
            }
        }

        return $this->withCookie(
            $this->render($this->view($request, $state, $errors, $notice), $status),
            $cookie ?? $this->stateCookie($store, $state),
        );
    }

    /**
     * @param array<string, mixed> $state
     * @return array{state: array<string, mixed>, errors: list<string>, notice?: ?string, finished?: array<string, string>, cookie?: array{0: string, 1: string}, status?: int}
     */
    private function submit(int $step, Request $request, array $state, InstallStateStore $store): array
    {
        $errors = [];
        switch ($step) {
            case 1:
                if ($this->platformBlocking($request) !== []) {
                    $errors[] = 'Środowisko ma błędy blokujące — popraw je i sprawdź ponownie.';
                    break;
                }
                $state['step'] = 2;
                break;

            case 2:
                $detected = (new PrefixDetector())->detect($request->serverParams());
                if ($detected === null || ($detected['failed'] ?? false)) {
                    $errors[] = 'Nie udało się ustalić, pod jakim adresem odpowiada aplikacja. Sprawdź katalog domeny i obsługę .htaccess.';
                    $state['prefix_tried'] = $detected['tried'] ?? [];
                    break;
                }
                // Profil serwera: nagłówek wybiera kandydata, rozstrzyga sonda (docs/profile-hostingu.md).
                // Bez działającego przepisywania (potrzebny /index.php) profil to „minimal”.
                $software = mb_substr((string) ($request->serverValue('SERVER_SOFTWARE') ?? ''), 0, 120);
                $profile = ServerProfile::decide(ServerProfile::candidate($software), $detected['front'] === '');
                if (!ServerProfile::allowsLayout($profile, $this->layout($request) === 'public')) {
                    $errors[] = 'Serwer nie przepisuje adresów, a katalog domeny nie wskazuje na public/. Bez przepisywania '
                        . 'nie da się zablokować katalogów aplikacji — ustaw katalog domeny na public/ albo włącz mod_rewrite.';
                    $state['prefix_tried'] = $detected['tried'];
                    break;
                }
                $state['prefix'] = ['base' => $detected['base'], 'front' => $detected['front']];
                $state['authorization_header'] = $detected['authorization'] ?? false;
                $state['prefix_tried'] = $detected['tried'];
                $state['server'] = [
                    'profile'   => $profile,
                    'software'  => $software,
                    'self_addr' => filter_var($request->serverValue('SERVER_ADDR'), FILTER_VALIDATE_IP) !== false
                        ? (string) $request->serverValue('SERVER_ADDR') : '',
                ];
                $this->logger->info('Instalator: profil serwera', ['profile' => $profile, 'server_software' => $software]);
                $state['step'] = 3;
                break;

            case 3:
                $ip = (string) ($request->serverValue('REMOTE_ADDR') ?? '');
                if ($store->tokenAttemptsExceeded($ip)) {
                    $errors[] = 'Zbyt wiele błędnych prób tokenu z tego adresu. Spróbuj ponownie za 15 minut.';
                    $this->logger->warning('Instalator: przekroczony limit prób tokenu');
                    break;
                }
                $expected = $this->installToken();
                $given = trim($request->postString('token', 200));
                if ($expected === null || $given === '' || !hash_equals($expected, $given)) {
                    $store->recordTokenFailure($ip);
                    $errors[] = $expected === null
                        ? 'Brak tokenu instalacyjnego w pliku .env (INSTALL_TOKEN). Wygeneruj go w panelu wezurlop.'
                        : 'Token jest nieprawidłowy.';
                    $this->logger->warning('Instalator: błędny token');
                    break;
                }
                // Dopiero poprawny token zajmuje instalator (jeden przebieg naraz) — rozdz. 12, krok 3.
                if ($store->busyForOthers()) {
                    $this->logger->warning('Instalator: poprawny token, ale trwa inny przebieg');

                    return ['state' => $state, 'status' => 409, 'errors' => [
                        'Instalację prowadzi już inna osoba z ważnym tokenem. Spróbuj ponownie później.',
                    ]];
                }
                $state['app_key'] = 'base64:' . base64_encode(random_bytes(32));
                $state['step'] = 4;
                [$key, $state] = $store->promote($state);
                $this->logger->info('Instalator: token przyjęty');

                return ['state' => $state, 'errors' => [], 'cookie' => [InstallStateStore::COOKIE, $key]];

            case 4:
                $db = [
                    'host'     => trim($request->postString('db_host', 255)) ?: 'localhost',
                    'port'     => (int) $request->postString('db_port', 5) ?: 3306,
                    'name'     => trim($request->postString('db_name', 64)),
                    'mig_user' => trim($request->postString('db_mig_user', 64)),
                    'mig_pass' => $request->postString('db_mig_pass', 255),
                    'user'     => trim($request->postString('db_user', 64)),
                    'pass'     => $request->postString('db_pass', 255),
                    'ssl_ca'   => trim($request->postString('db_ssl_ca', 255)),
                    'ssl'      => $request->postString('db_allow_plain', 1) === '1' ? Connection::SSL_DISABLED : Connection::SSL_AUTO,
                ];
                $check = $this->checkDatabase($db);
                $errors = $check['errors'];
                if ($errors === []) {
                    $state['db'] = $check['db'];
                    $state['db_report'] = $check['report'];
                    $state['step'] = 5;

                    return ['state' => $state, 'errors' => [], 'notice' => implode(' ', $check['report'])];
                }
                break;

            case 5:
                $url = rtrim(trim($request->postString('app_url', 255)), '/');
                $env = $request->postString('app_env', 4) === 'dev' ? 'dev' : 'prod';
                if (preg_match('#^https?://[a-z0-9.-]+(:\d+)?$#i', $url) !== 1) {
                    $errors[] = 'Adres aplikacji musi mieć postać https://host (bez ścieżki).';
                    break;
                }
                if ($env === 'prod' && !str_starts_with(strtolower($url), 'https://')) {
                    $errors[] = 'Instalacja produkcyjna wymaga adresu https://.';
                    break;
                }
                $state['app'] = ['url' => strtolower($url), 'env' => $env];
                // Pliki wdrożenia profilu (.htaccess, app-path.php) — dopiero po tokenie, przed weryfikacją HTTP.
                $profile = (string) ($state['server']['profile'] ?? ServerProfile::APACHE);
                $state['deploy_files'] = (new DeployFiles($this->appRoot))->apply(
                    $profile,
                    $this->documentRoot($request),
                    $this->layout($request) === 'above',
                    false,
                );
                $state['step'] = 6;
                break;

            case 6:
                $mail = [
                    'transport'  => $request->postString('mail_transport', 5) === 'file' ? 'file' : 'smtp',
                    'host'       => trim($request->postString('mail_host', 255)),
                    'port'       => (string) ((int) $request->postString('mail_port', 5) ?: 587),
                    'username'   => trim($request->postString('mail_username', 255)),
                    'password'   => $request->postString('mail_password', 255),
                    'encryption' => in_array($request->postString('mail_encryption', 4), ['tls', 'ssl', 'none'], true)
                        ? $request->postString('mail_encryption', 4) : 'tls',
                    'from'       => trim($request->postString('mail_from', 254)),
                    'from_name'  => trim($request->postString('mail_from_name', 100)) ?: 'wezurlop',
                ];
                $alert = trim($request->postString('alert_email', 254));
                $testTo = trim($request->postString('test_to', 254));
                if ($mail['transport'] === 'file' && ($state['app']['env'] ?? 'prod') !== 'dev') {
                    $errors[] = 'Zapis wiadomości do plików jest dostępny wyłącznie w instalacji deweloperskiej.';
                }
                foreach (['from' => $mail['from'], 'alert' => $alert, 'test' => $testTo] as $label => $address) {
                    if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
                        $errors[] = ['from' => 'Adres nadawcy', 'alert' => 'Skrzynka na alerty', 'test' => 'Adres do wiadomości testowej'][$label]
                            . ' jest nieprawidłowy.';
                    }
                }
                if ($mail['transport'] === 'smtp' && $mail['host'] === '') {
                    $errors[] = 'Podaj serwer SMTP.';
                }
                if ($errors !== []) {
                    break;
                }
                // Wysyłka testowa w żądaniu to świadomy wyjątek: bez działającej poczty administrator
                // nigdy nie dostanie linku (krok 9). Limit czasu SMTP: 10 s.
                try {
                    $transport = $mail['transport'] === 'file'
                        ? new FileTransport($this->varPath . '/mail')
                        : new SmtpTransport(['port' => (int) $mail['port']] + $mail);
                    $transport->send(new MailMessage(
                        $testTo,
                        'wezurlop: wiadomość testowa instalatora',
                        "Konfiguracja poczty w instalatorze wezurlop działa.\n",
                    ));
                } catch (Throwable $e) {
                    $this->logger->warning('Instalator: wysyłka testowa nieudana', ['error' => $e->getMessage()]);
                    $errors[] = 'Wysyłka testowa nie powiodła się. Sprawdź serwer, port, szyfrowanie i dane logowania.';
                    break;
                }
                $state['mail'] = $mail;
                $state['alert_email'] = strtolower($alert);
                $state['step'] = 7;

                return ['state' => $state, 'errors' => [], 'notice' => 'Wiadomość testowa została wysłana.'];

            case 7:
                $results = $this->exposureResults($request, $state);
                $blocking = array_filter($results, static fn (CheckResult $r): bool => $r->status === CheckResult::FAIL);
                $unresolved = array_filter($results, static fn (CheckResult $r): bool => $r->status === CheckResult::WARN);
                if ($blocking !== []) {
                    $errors[] = 'Pliki spoza public/ są dostępne przez HTTP. Ustaw katalog domeny na public/ — instalacja zablokowana.';
                    $this->logger->error('Instalator: odsłonięte pliki', ['items' => array_values(array_map(static fn (CheckResult $r): string => $r->name, $blocking))]);
                    break;
                }
                if ($unresolved !== [] && $request->postString('confirm_unresolved', 1) !== '1') {
                    $errors[] = 'Części sprawdzeń nie udało się rozstrzygnąć. Sprawdź ręcznie, że pliki nie są dostępne, i potwierdź.';
                    break;
                }
                if ($unresolved !== []) {
                    $this->logger->warning('Instalator: wynik nierozstrzygnięty potwierdzony przez instalującego', [
                        'items' => array_values(array_map(static fn (CheckResult $r): string => $r->name . ': ' . $r->value, $unresolved)),
                    ]);
                }
                $state['http_verified'] = $unresolved === [] ? 'protected' : 'confirmed';
                $state['step'] = 8;
                break;

            case 8:
                $run = (new MigrationRunner($this->appRoot, $state['db']))->run(self::MIGRATION_BUDGET);
                $state['migrations'] = $run;
                if ($run['done']) {
                    $state['step'] = 9;
                    $this->logger->info('Instalator: migracje wykonane');

                    return ['state' => $state, 'errors' => [], 'notice' => 'Migracje zakończone.'];
                }

                return ['state' => $state, 'errors' => [], 'notice' => 'Wykonano część migracji (' . $run['applied'] . '). Kontynuuj.'];

            case 9:
                $email = strtolower(trim($request->postString('admin_email', 254)));
                $first = trim($request->postString('admin_first', 100));
                $last = trim($request->postString('admin_last', 100));
                if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || $first === '' || $last === '') {
                    $errors[] = 'Podaj imię, nazwisko i adres e-mail administratora.';
                    break;
                }
                $services = new Services($this->configFrom($state));
                $services->settings->set('alert_email', (string) $state['alert_email'], null);
                // ⚠ Konfiguracja poczty do BAZY, nie do `.env` — i przed pierwszą wysyłką,
                // bo to z niej korzysta kolejka przy linku dla administratora.
                $mail = $state['mail'];
                $services->mailSettings()->zapisz(
                    (string) $mail['host'],
                    (int) $mail['port'],
                    (string) $mail['encryption'],
                    (string) $mail['username'],
                    (string) $mail['password'],
                    (string) $mail['from'],
                    (string) $mail['from_name'],
                    false,
                    null,
                );
                // Plik-kanarek w storage/ — dobowe sprawdzenie, czy katalog nie stał się dostępny po HTTP.
                $services->canary()->ensure();
                $services->invitations->invite($first, $last, $email, null);
                // Link wychodzi od razu — cron może jeszcze nie działać.
                // Tylko zadania pilne — sondy i sprzątanie zrobi cron, nie żądanie instalatora.
                $stats = $services->jobRunner($this->logger)->runUrgent(10.0, 5);
                if ($stats['done'] < 1) {
                    $errors[] = 'Konto administratora utworzone, ale wiadomości nie udało się wysłać — zostanie ponowiona przez cron.';
                }
                $state['admin_email'] = $email;
                $state['step'] = 10;
                break;

            case 10:
                $cron = $request->postString('cron', 8);
                if (!isset(self::CRON_MODES[$cron])) {
                    $errors[] = 'Wskaż, jak na tym hostingu uruchamiane jest zadanie cykliczne.';
                    break;
                }
                $state['cron'] = $cron;
                $httpCron = $cron === 'http5';
                if ($httpCron) {
                    $state['deploy_files'] = array_merge($state['deploy_files'] ?? [], array_values(array_filter(
                        (new DeployFiles($this->appRoot))->apply(
                            (string) ($state['server']['profile'] ?? ServerProfile::APACHE),
                            $this->documentRoot($request),
                            $this->layout($request) === 'above',
                            true,
                        ),
                        static fn (array $f): bool => str_ends_with($f['file'], '/cron-05min.php'),
                    )));
                }
                $values = $this->envValues($state) + $this->preservedEnv();
                EnvWriter::write($this->envFile, $values);
                // Zapis wymagał 0660; zaciśnięcie robi instalator, nie przypomnienie na ekranie (rozdz. 12, krok 11).
                // Nieudany chmod to błąd — bez blokady instalacja się nie kończy, a krok można powtórzyć.
                EnvWriter::restrict($this->envFile);
                $lock = $this->varPath . '/install.lock';
                if (file_put_contents($lock, gmdate('c') . "\n", LOCK_EX) === false) {
                    throw new RuntimeException('Nie można zapisać znacznika instalacji');
                }
                $this->logger->info('Instalator: instalacja zakończona', ['app_url' => $state['app']['url']]);
                $this->recordInstallEvents($state);
                // ⚠ Sonda crona: zadanie, które ma podjąć PRAWDZIWY przebieg crona. Instalator
                // tylko je stawia i **nie czeka** — cron bywa ustawiany w panelu hostingu dopiero
                // po instalacji, a instalacja, której nie da się dokończyć, jest gorszym problemem
                // niż brak sondy (zlecenie `link-i-cron-przyjete` §2.2). Wynik widać na ekranie
                // „Ustawienia → Cron", tą samą sondą i tym samym przyciskiem co później.
                $uslugi = new Services($this->configFrom($state));
                $uslugi->cronStan()->wyslijSonde();

                return ['state' => $state, 'errors' => [], 'finished' => [
                    'admin_email' => (string) $state['admin_email'],
                    'login_url'   => $state['app']['url'] . $state['prefix']['base'] . $state['prefix']['front'] . '/logowanie',
                    'panel'       => 'Rejestracja w panelu wezurlop zostanie wykonana po uruchomieniu panelu (w tej wersji pominięta).',
                    'cron'        => self::CRON_INSTRUCTIONS[$cron],
                    // Jedno zdanie o sondzie — instalujący ma wiedzieć, gdzie sprawdzić WYNIK.
                    'cron_sonda'  => 'W kolejce czeka zadanie kontrolne. Jeśli cron jest ustawiony, '
                        . 'zniknie przy najbliższym przebiegu — stan i gotowe polecenie do wklejenia '
                        . 'znajdziesz w Ustawieniach, w sekcji „Cron".',
                    'manual'      => implode("\n", array_map(
                        static fn (array $f): string => $f['file'] . ' (z: ' . $f['source'] . ')',
                        array_values(array_filter($state['deploy_files'] ?? [], static fn (array $f): bool => $f['status'] === DeployFiles::MANUAL)),
                    )),
                ]];
        }

        return ['state' => $state, 'errors' => $errors];
    }

    /**
     * @param array<string, mixed> $state
     * @param list<string> $errors
     * @return array<string, mixed>
     */
    private function view(Request $request, array $state, array $errors, ?string $notice): array
    {
        $step = (int) $state['step'];
        $data = [
            'step'      => $step,
            'steps'     => self::STEPS,
            'csrf'      => $state['csrf'],
            'errors'    => $errors,
            'notice'    => $notice,
        ];

        if ($step === 1) {
            $data['checks'] = $this->group($this->platformResults($request));
            $data['blocking'] = $this->platformBlocking($request) !== [];
        } elseif ($step === 2 || $step === 3) {
            $data['prefix'] = $state['prefix'] ?? null;
            $data['authorization_header'] = $state['authorization_header'] ?? null;
            $data['tried'] = $state['prefix_tried'] ?? [];
            $data['variant'] = $this->variant($request);
            $data['server'] = $state['server'] ?? null;
        } elseif ($step === 5) {
            $target = PrefixDetector::target($request->serverParams());
            $data['default_url'] = $state['app']['url'] ?? ($target !== null ? $target['origin'] : '');
            $data['paths'] = [
                'Plik konfiguracji' => $this->envFile,
                'Katalog var/'      => $this->varPath,
                'Katalog storage/'  => $this->appRoot . '/storage',
            ];
            $data['variant'] = $this->variant($request);
        } elseif ($step === 7) {
            $results = $this->exposureResults($request, $state);
            $data['checks'] = $this->group($results);
            $data['blocking'] = array_filter($results, static fn (CheckResult $r): bool => $r->status === CheckResult::FAIL) !== [];
            $data['unresolved'] = array_filter($results, static fn (CheckResult $r): bool => $r->status === CheckResult::WARN) !== [];
            $data['deploy_files'] = $state['deploy_files'] ?? [];
        } elseif ($step === 8) {
            $data['migrations'] = $state['migrations'] ?? null;
        } elseif ($step === 10) {
            $data['cron_default'] = ServerProfile::usesHttpCron((string) ($state['server']['profile'] ?? '')) ? 'http5' : 'crontab5';
            $data['server'] = $state['server'] ?? null;
        }

        return $data;
    }

    /** @return list<CheckResult> */
    private function platformResults(Request $request): array
    {
        return (new EnvironmentCheck(
            $this->appRoot,
            $this->appRoot . '/storage',
            $this->varPath,
            [],
            false,
            null,
            $request->serverValue('WEZURLOP_APP_PATH') ?? $request->serverValue('REDIRECT_WEZURLOP_APP_PATH'),
        ))->runSections([EnvironmentCheck::SECTION_PLATFORM, EnvironmentCheck::SECTION_FILES]);
    }

    /** @return list<CheckResult> */
    private function platformBlocking(Request $request): array
    {
        return array_values(array_filter(
            $this->platformResults($request),
            static fn (CheckResult $r): bool => $r->status === CheckResult::FAIL,
        ));
    }

    /**
     * Krok 7: pobranie chronionych plików pod APP_URL (nie pod adresem z formularza), połączenie
     * na adres tego serwera.
     *
     * @param array<string, mixed> $state
     * @return list<CheckResult>
     */
    private function exposureResults(Request $request, array $state): array
    {
        $addr = $request->serverValue('SERVER_ADDR');
        $target = new HttpProbeTarget(
            (string) $state['app']['url'],
            (string) $state['prefix']['base'],
            $addr !== null && filter_var($addr, FILTER_VALIDATE_IP) !== false ? $addr : null,
            $request->serverValue('DOCUMENT_ROOT'),
        );
        $check = new EnvironmentCheck($this->appRoot, $this->appRoot . '/storage', $this->varPath, [], false, $target);

        return array_values(array_filter(
            $check->runSections([EnvironmentCheck::SECTION_EXPOSURE]),
            static fn (CheckResult $r): bool => $r->name !== 'Katalog domeny',
        ));
    }

    /**
     * Krok 4 (docs/szkielet-tenanta.md rozdz. 12): konto z DDL obowiązkowe (DB_MIG_*), konto aplikacyjne
     * zalecane (DB_*). Uprawnienia sprawdzane PRÓBĄ (CREATE TABLE), nie odczytem GRANT. Brak konta
     * aplikacyjnego = tryb jednego konta, zapisany jawnie. Host zdalny = połączenie szyfrowane.
     *
     * @param array{host: string, port: int, name: string, mig_user: string, mig_pass: string, user: string, pass: string, ssl_ca: string, ssl: string} $db
     * @return array{errors: list<string>, report: list<string>, db: array<string, mixed>}
     */
    private function checkDatabase(array $db): array
    {
        $fail = static fn (string $message): array => ['errors' => [$message], 'report' => [], 'db' => []];
        foreach (['name', 'mig_user', 'mig_pass'] as $field) {
            if ($db[$field] === '') {
                return $fail('Podaj nazwę bazy oraz konto z prawem zmiany schematu (migracje).');
            }
        }
        if ($db['ssl_ca'] !== '' && !is_readable($db['ssl_ca'])) {
            return $fail('Nie można odczytać pliku CA do weryfikacji certyfikatu bazy.');
        }
        $single = $db['user'] === '' || ($db['user'] === $db['mig_user'] && $db['pass'] === $db['mig_pass']);
        if ($db['user'] === '') {
            $db['user'] = $db['mig_user'];
            $db['pass'] = $db['mig_pass'];
        }
        $credentials = static fn (string $user, string $pass): array => [
            'host' => $db['host'], 'port' => $db['port'], 'name' => $db['name'],
            'user' => $user, 'password' => $pass, 'ssl' => $db['ssl'], 'ssl_ca' => $db['ssl_ca'],
        ];
        $tls = Connection::wantsTls($db['host'], $db['ssl']);
        $report = [];

        try {
            $migPdo = (new Connection($credentials($db['mig_user'], $db['mig_pass']), Connection::PROFILE_SCHEMA))->pdo();
        } catch (PDOException $e) {
            $this->logger->warning('Instalator: połączenie kontem migracyjnym nieudane', ['error' => $e->getMessage()]);

            return $fail($tls
                ? 'Nie udało się nawiązać szyfrowanego połączenia z bazą. Jeśli serwer bazy nie obsługuje TLS, zaznacz '
                    . '„połącz bez szyfrowania” — dane będą wtedy przesyłane jawnym tekstem.'
                : 'Nie udało się połączyć kontem migracyjnym.');
        }
        $mig = new DiagnosticsRepository($migPdo);
        if ($mig->schemaCollation() !== 'utf8mb4_unicode_ci') {
            return $fail('Baza musi mieć kolację utf8mb4_unicode_ci.');
        }
        if (!(new SchemaProbeRepository($migPdo))->canCreateTable()) {
            return $fail('Konto migracyjne musi mieć prawo zmiany schematu (CREATE, ALTER, DROP) — bez tego nie ma migracji.');
        }
        $cipher = $mig->sslCipher();
        $report[] = $cipher !== ''
            ? 'Połączenie z bazą szyfrowane (' . $cipher . ($db['ssl_ca'] === '' ? ', bez weryfikacji certyfikatu' : ', certyfikat zweryfikowany') . ').'
            : (Connection::isLocalHost($db['host']) ? 'Baza lokalna — połączenie bez TLS.' : 'UWAGA: połączenie z bazą NIESZYFROWANE.');

        $appHasDdl = false;
        if (!$single) {
            try {
                $appPdo = (new Connection($credentials($db['user'], $db['pass']), Connection::PROFILE_SCHEMA))->pdo();
            } catch (PDOException) {
                return $fail('Nie udało się połączyć kontem aplikacyjnym.');
            }
            // Weryfikacja podziału: konto aplikacyjne NIE powinno móc utworzyć tabeli.
            $appHasDdl = (new SchemaProbeRepository($appPdo))->canCreateTable();
            $report[] = $appHasDdl
                ? 'UWAGA: konto aplikacyjne ma prawo zmiany schematu — to wygląda na jedno konto wpisane dwa razy. '
                    . 'Aplikacja zadziała, ale podział kont niczego nie chroni.'
                : 'Podział kont potwierdzony: konto aplikacyjne nie może zmieniać schematu.';
        } else {
            $report[] = 'Tryb jednego konta (DB_SINGLE_ACCOUNT=1): aplikacja pracuje kontem z prawem zmiany schematu, '
                . 'z bezpiecznikiem DDL. Kopia zapasowa przed każdą aktualizacją jest obowiązkowa.';
        }

        return ['errors' => [], 'report' => $report, 'db' => $db + ['single' => $single, 'tls_cipher' => $cipher, 'app_has_ddl' => $appHasDdl]];
    }

    /** Zdarzenia instalacji w event_log — stan obniżony ma być widoczny, nie przemilczany (CLAUDE.md §4). */
    private function recordInstallEvents(array $state): void
    {
        $events = (new Services($this->configFrom($state)))->events;
        $detail = [
            'server_profile'   => $state['server']['profile'] ?? '',
            'server_software'  => $state['server']['software'] ?? '',
            'single_account'   => (bool) ($state['db']['single'] ?? false),
            'db_tls'           => (string) ($state['db']['tls_cipher'] ?? ''),
            'cron'             => (string) ($state['cron'] ?? ''),
            'http_verified'    => (string) ($state['http_verified'] ?? ''),
        ];
        $events->record('install.completed', null, null, 'installer', null, null, null, $detail);
        if (($state['db']['single'] ?? false) === true) {
            $events->record('security.db_single_account', null, null, 'installer');
        }
        if (($state['db']['app_has_ddl'] ?? false) === true) {
            $events->record('security.db_app_account_has_ddl', null, null, 'installer');
        }
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, string>
     */
    private function envValues(array $state): array
    {
        return [
            'APP_ENV'                 => (string) $state['app']['env'],
            'APP_URL'                 => (string) $state['app']['url'],
            'APP_BASE_PATH'           => (string) $state['prefix']['base'],
            'APP_FRONT_CONTROLLER'    => (string) $state['prefix']['front'],
            'APP_KEY'                 => (string) $state['app_key'],
            // Ścieżka zapasowa dla zasobu spoza manifestu; prawdziwa wersja idzie ze skrótu
            // treści (Support/AssetVersions). Znacznik instalacji wystarcza i nie wymaga
            // pamiętania o niczym przy aktualizacji.
            'ASSET_VERSION'           => gmdate('YmdHis'),
            'DB_HOST'                 => (string) $state['db']['host'],
            'DB_PORT'                 => (string) ($state['db']['port'] ?? 3306),
            'DB_NAME'                 => (string) $state['db']['name'],
            'DB_USER'                 => (string) $state['db']['user'],
            'DB_PASS'                 => (string) $state['db']['pass'],
            'DB_MIG_USER'             => (string) $state['db']['mig_user'],
            'DB_MIG_PASS'             => (string) $state['db']['mig_pass'],
            'DB_SINGLE_ACCOUNT'       => ($state['db']['single'] ?? false) ? '1' : '0',
            'DB_SSL'                  => (string) ($state['db']['ssl'] ?? Connection::SSL_AUTO),
            'DB_SSL_CA'               => (string) ($state['db']['ssl_ca'] ?? ''),
            'SERVER_PROFILE'          => (string) ($state['server']['profile'] ?? ServerProfile::APACHE),
            'SERVER_SOFTWARE'         => str_replace("'", '', (string) ($state['server']['software'] ?? '')),
            'SELF_ADDR'               => (string) ($state['server']['self_addr'] ?? ''),
            'CRON_INTERVAL_MINUTES'   => (string) (self::CRON_MODES[$state['cron'] ?? 'none'] ?? 0),
            // Kolejka domykana po odpowiedzi, gdy cron nie jest częstszy niż co 15 minut (albo nieznany).
            'QUEUE_ON_REQUEST'        => ((self::CRON_MODES[$state['cron'] ?? 'none'] ?? 0) === 0
                || (self::CRON_MODES[$state['cron'] ?? 'none'] ?? 0) >= 15) ? '1' : '0',
            // ⚠ `MAIL_*` NIE IDZIE do `.env` (etap 7.2): konfiguracja poczty ląduje w bazie
            // (`Service\Mail\MailSettings`) w kroku 9, razem ze skrzynką alertową. Hasło SMTP
            // jest tam zaszyfrowane kluczem z `.env`, a nie zapisane jawnie w pliku.
            // CLAUDE.md §13: max-age narastająco (30 dni na start), includeSubDomains włącza administrator świadomie.
            'HSTS_MAX_AGE'            => $state['app']['env'] === 'dev' ? '300' : '2592000',
            'HSTS_INCLUDE_SUBDOMAINS' => 'false',
            'TRUSTED_PROXIES'         => '',
        ];
    }

    /** @param array<string, mixed> $state */
    private function configFrom(array $state): Config
    {
        $factory = require $this->appRoot . '/config/config.php';

        return Config::fromArray($factory($this->envValues($state), [], $this->appRoot));
    }

    /** @return array<string, string> */
    private function preservedEnv(): array
    {
        $env = EnvFile::exists($this->envFile) ? EnvFile::load($this->envFile) : [];

        return array_filter(
            array_intersect_key($env, array_flip(self::PRESERVED_ENV)),
            static fn (mixed $value): bool => is_string($value) && $value !== '',
        );
    }

    private function installToken(): ?string
    {
        if (!EnvFile::exists($this->envFile)) {
            return null;
        }
        $token = EnvFile::load($this->envFile)['INSTALL_TOKEN'] ?? '';

        return strlen($token) >= 16 ? $token : null;
    }

    /** Katalog, w którym uruchomiono index.php — katalog domeny w wariancie 2. */
    private function documentRoot(Request $request): string
    {
        $script = (string) ($request->serverValue('SCRIPT_FILENAME') ?? '');

        return $script !== '' ? dirname($script) : $this->appRoot . '/public';
    }

    /** public = katalog domeny na public/, root = na korzeń aplikacji, above = aplikacja powyżej katalogu domeny. */
    private function layout(Request $request): string
    {
        $docRoot = realpath((string) $request->serverValue('DOCUMENT_ROOT'));
        $appRoot = realpath($this->appRoot);

        return match (true) {
            $docRoot !== false && $docRoot === $appRoot . '/public' => 'public',
            $docRoot !== false && $docRoot === $appRoot             => 'root',
            default                                                 => 'above',
        };
    }

    private function variant(Request $request): string
    {
        return match ($this->layout($request)) {
            'public' => 'Katalog domeny wskazuje na public/ (zalecany).',
            'root'   => 'Katalog domeny wskazuje na katalog aplikacji — ochronę zapewnia .htaccess (sprawdzenie w kroku 7).',
            default  => 'Aplikacja leży poza katalogiem domeny (wariant 2) — położenie przekazuje public/app-path.php.',
        };
    }

    /**
     * @param list<CheckResult> $results
     * @return array<string, list<array{name: string, status: string, value: string, note: string}>>
     */
    private function group(array $results): array
    {
        $groups = [];
        foreach ($results as $r) {
            $groups[$r->group][] = ['name' => $r->name, 'status' => $r->status, 'value' => $r->value, 'note' => $r->note];
        }

        return $groups;
    }

    /** @param array<string, mixed> $data */
    private function render(array $data, int $status = 200): Response
    {
        $data += ['steps' => self::STEPS, 'csrf' => '', 'errors' => [], 'notice' => null, 'expired' => false, 'finished' => null];

        return Response::html($this->twig->render('page/install/installer.twig', $data), $status)
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Robots-Tag', 'noindex');
    }

    /**
     * Ciasteczko stanu: przed tokenem podpisany stan, po tokenie nic (klucz ustawiany raz, w kroku 3).
     *
     * @param array<string, mixed> $state
     * @return array{0: string, 1: string}|null
     */
    private function stateCookie(InstallStateStore $store, array $state): ?array
    {
        return ($state['authorized'] ?? false) === true ? null : [InstallStateStore::COOKIE_PRE, $store->encodePre($state)];
    }

    /** @param array{0: string, 1: string}|null $cookie */
    private function withCookie(Response $response, ?array $cookie): Response
    {
        if ($cookie === null) {
            return $response;
        }

        return $response->withHeader('Set-Cookie', sprintf(
            '%s=%s; Path=/; HttpOnly; SameSite=Strict%s',
            $cookie[0],
            $cookie[1],
            $this->https ? '; Secure' : '',
        ));
    }
}
