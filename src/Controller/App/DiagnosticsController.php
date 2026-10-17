<?php

declare(strict_types=1);

namespace Wezurlop\Controller\App;

use DateTimeImmutable;
use DateTimeZone;
use Wezurlop\Diagnostics\CheckResult;
use Wezurlop\Diagnostics\CronHttpProbe;
use Wezurlop\Diagnostics\EnvironmentCheck;
use Wezurlop\Diagnostics\ExposureProbe;
use Wezurlop\Diagnostics\HttpProbeTarget;
use Wezurlop\Entity\CurrentUser;
use Wezurlop\Http\ApiKernel;
use Wezurlop\Http\Middleware\Authenticate;
use Wezurlop\Http\Request;
use Wezurlop\Http\Response;
use Wezurlop\Repository\CompanyCalendarRepository;
use Wezurlop\Repository\ApiKeyRepository;
use Wezurlop\Repository\HierarchyRepository;
use Wezurlop\Repository\PermissionRepository;
use Wezurlop\Repository\EventLogRepository;
use Wezurlop\Repository\SyncDomainRepository;
use Wezurlop\Repository\TenantSettingRepository;
use Wezurlop\Service\Canary;
use Wezurlop\Service\CronHeartbeat;
use Wezurlop\Service\Leave\LeaveRequestService;
use Wezurlop\Http\Session;
use Wezurlop\Service\Maintenance\TenantReset;
use Wezurlop\Service\ServerProfile;
use Wezurlop\Support\Config;
use Wezurlop\Support\EnvFile;
use Wezurlop\Support\ErrorHandler;
use Wezurlop\Service\AccessService;
use Wezurlop\Service\CronStan;
use Wezurlop\Service\Mail\MailSettings;
use Wezurlop\Service\Mail\TransportFactory;
use Wezurlop\Support\UrlGenerator;
use Wezurlop\Service\Sync\SyncService;
use Wezurlop\Support\Cisza;
use Wezurlop\Support\Odmiana;
use Wezurlop\Support\Strefa;
use Wezurlop\Support\View;

/**
 * Strona /diagnostyka (tylko web, admin.settings) — ten sam zestaw sprawdzeń co krok 1 instalatora
 * i bin/diagnose.php (docs/szkielet-tenanta.md rozdz. 13).
 */
final class DiagnosticsController
{
    /** Więcej 503 na dobę niż tyle to już seria, nie pojedynczy przypadek. */
    private const TRANSIENT_WARN_PER_DAY = 5;

    public function __construct(
        private readonly View $view,
        private readonly Config $config,
        private readonly string $appRoot,
        private readonly Canary $canary,
        private readonly EventLogRepository $events,
        private readonly CompanyCalendarRepository $calendars,
        private readonly TenantReset $reset,
        private readonly TenantSettingRepository $settings,
        private readonly ApiKeyRepository $apiKeys,
        private readonly SyncDomainRepository $domain,
        private readonly Session $session,
        private readonly AccessService $access,
        private readonly HierarchyRepository $hierarchia,
        private readonly PermissionRepository $permissions,
        private readonly MailSettings $poczta,
        private readonly CronStan $cron,
        private readonly TransportFactory $transporty,
        private readonly ErrorHandler $errors,
        private readonly UrlGenerator $urls,
    ) {
    }

    /** @param array<string, string> $params */
    public function index(Request $request, array $params): Response
    {
        $serverAddr = $request->serverValue('SERVER_ADDR');
        $target = new HttpProbeTarget(
            $this->config->appUrl,
            $this->config->basePath,
            $serverAddr !== null && filter_var($serverAddr, FILTER_VALIDATE_IP) !== false ? $serverAddr : null,
            $request->serverValue('DOCUMENT_ROOT'),
        );
        $check = new EnvironmentCheck(
            $this->appRoot,
            $this->config->storagePath,
            $this->config->varPath,
            $this->config->dbEnv(),
            false,
            $target,
            $request->serverValue('WEZURLOP_APP_PATH') ?? $request->serverValue('REDIRECT_WEZURLOP_APP_PATH'),
            $this->config->installLockPath(),
            EnvFile::path($this->appRoot, $request->serverParams()),
        );

        $groups = [];
        $counts = [CheckResult::OK => 0, CheckResult::WARN => 0, CheckResult::FAIL => 0];
        foreach ([...$check->run(), ...$this->deployment($target), ...$this->security(), ...$this->poczta(), ...$this->sync(), ...$this->czas(), ...$this->hierarchia(), ...$this->rozdzialRol()] as $result) {
            $groups[$result->group][] = [
                'name'   => $result->name,
                'status' => $result->status,
                'value'  => $result->value,
                'note'   => $result->note,
            ];
            $counts[$result->status]++;
        }

        return $this->view->render('page/web/diagnostics.twig', [
            'groups' => $groups,
            'counts' => $counts,
            // Sekcja deweloperska istnieje tylko na dev — u klienta nie ma jej ani w szablonie, ani w routingu.
            'dev' => $this->config->isDev(),
            'reset_tabele' => $this->config->isDev() ? count(TenantReset::tables()) : 0,
            'reset_ok' => $request->query('reset') !== null,
        ])->withHeader('Cache-Control', 'no-store');
    }

    /**
     * Reset instalacji do stanu „jak po instalacji” — drugie wejście do tego samego serwisu co
     * `dev/reset-tenanta.php`. ⚠ Warunek `APP_ENV=dev` sprawdzany TU jeszcze raz, mimo że trasa
     * poza dev w ogóle nie powstaje: dwie niezależne przeszkody zamiast jednej (CLAUDE.md §10).
     *
     * @param array<string, string> $params
     */
    public function reset(Request $request, array $params): Response
    {
        if (!$this->config->isDev()) {
            return $this->errors->errorPage(404, null, $request->isHtmx());
        }
        $user = $request->attribute(Authenticate::USER);
        $account = $user instanceof CurrentUser ? $user->accountId() : null;
        $label = $user instanceof CurrentUser ? $user->displayName : 'admin';
        // ⚠ Sesja wykonującego przeżywa reset — inaczej po skasowaniu danych przeglądarka wylądowałaby
        // na logowaniu zamiast na kreatorze podłączenia, czyli tam, gdzie ma się zacząć kolejne przejście.
        $this->reset->run($account, $label, $this->session->id());

        return Response::redirectFor($request, $this->urls->to('/admin/integracja') . '?reset=1');
    }

    /**
     * Wdrożenie (docs/profile-hostingu.md): profil serwera, cron i ostatni wynik kanarka storage/.
     * Profil to dana ze środowiska — do diagnozy, nie do decyzji (CLAUDE.md §11).
     *
     * @return list<CheckResult>
     */
    private function deployment(HttpProbeTarget $target): array
    {
        $group = 'Wdrożenie';
        $deploy = $this->config->deploy;
        $profile = (string) ($deploy['server_profile'] ?? '');
        $minutes = (int) ($deploy['cron_minutes'] ?? 0);
        $results = [
            new CheckResult($group, 'Profil serwera', $profile !== '' ? CheckResult::OK : CheckResult::WARN,
                $profile !== '' ? $profile : 'nieustalony',
                (string) ($deploy['server_software'] ?? '') !== '' ? 'SERVER_SOFTWARE: ' . $deploy['server_software'] : 'brak zapisu instalatora'),
            new CheckResult($group, 'Cron', $minutes > 0 && $minutes <= 15 ? CheckResult::OK : CheckResult::WARN,
                $minutes > 0 ? 'co ' . $minutes . ' min' : 'nieznany',
                ($deploy['queue_on_request'] ?? false) ? 'pilne wiadomości wysyłane także po zwykłych żądaniach (QUEUE_ON_REQUEST)' : ''),
        ];
        // ⚠ Deklaracja z instalatora nie jest dowodem — potwierdza ją dopiero przebieg crona.
        // ⚠ Te same dane czyta „Ustawienia → Cron": JEDNA klasa, dwa ekrany (`Service\CronStan`).
        $cron = $this->cron->stan();
        $results[] = new CheckResult($group, 'Ostatni przebieg crona',
            $cron['awaria'] ? CheckResult::FAIL : ($cron['potwierdzony'] ? CheckResult::OK : CheckResult::WARN),
            $cron['nigdy'] ? 'nigdy' : $cron['wiek'] . ' min temu',
            $cron['awaria']
                ? 'CRON NIE PRZEBIEGŁ — poczta nie wychodzi, eskalacje stoją; szczegóły i polecenie do wklejenia: Ustawienia → Cron'
                : ($cron['potwierdzony'] ? '' : 'cron niepotwierdzony — pilne wiadomości wysyłane po zwykłych żądaniach, dopóki nie ruszy'));
        if (ServerProfile::usesHttpCron($profile)) {
            $probe = (new CronHttpProbe($target))->check();
            $results[] = new CheckResult($group, 'Cron przez HTTP (cron-05min.php)', $probe['status'], $probe['value'], $probe['note']);
        }
        // ⚠ Nagłówek HTML mierzony POBRANIEM PO HTTP, nie przez `ini_get`: wartość w `php.ini`
        // mówi, co ma się stać, a nagłówek odpowiedzi — co się stało (rozstrzygnięcie 22.09.2026,
        // ta sama zasada co przy `.htaccess`). Sprawdzamy `no-store`, bo to on trzyma ekrany
        // z danymi kadrowymi poza cache'em dysku po wylogowaniu.
        $naglowek = (new ExposureProbe($target))->cacheControlHtml();
        $maNoStore = $naglowek['naglowek'] !== null && str_contains(strtolower($naglowek['naglowek']), 'no-store');
        $results[] = new CheckResult(
            $group,
            'Cache-Control odpowiedzi HTML',
            match (true) {
                $naglowek['naglowek'] === null => CheckResult::WARN,
                $maNoStore                     => CheckResult::OK,
                default                        => CheckResult::FAIL,
            },
            $naglowek['naglowek'] ?? 'serwer nie odpowiedział',
            match (true) {
                $naglowek['naglowek'] === null =>
                    'nie udało się pobrać strony pod APP_URL — brak odpowiedzi to nie „w porządku"',
                $maNoStore =>
                    'ekran z danymi kadrowymi nie zostaje w cache\'u po wylogowaniu; cena: wyłączony bfcache („wstecz" odpytuje serwer)',
                default =>
                    'BRAK `no-store` — ekrany z danymi kadrowymi mogą zostać w cache\'u przeglądarki i pośredników',
            },
        );
        $canary = $this->canary->lastResult();
        // Czas sprawdzenia w strefie tenanta — diagnostyka wolno pokazywać UTC, ale nie jako jedyną
        // formę (docs §10 punkt 5). Blok „Czas” niżej podaje oba zegary obok siebie.
        $sprawdzono = 'sprawdzono ' . Strefa::czasZeStrefa($canary['at'], $this->settings->get('timezone', Strefa::DOMYSLNA));
        $results[] = match ($canary['result']) {
            ExposureProbe::PROTECTED  => new CheckResult($group, 'Kanarek storage/', CheckResult::OK, 'niedostępny po HTTP', $sprawdzono),
            ExposureProbe::EXPOSED    => new CheckResult($group, 'Kanarek storage/', CheckResult::FAIL, 'DOSTĘPNY PO HTTP',
                $sprawdzono . ' — katalog storage/ jest widoczny z internetu'),
            ExposureProbe::UNRESOLVED => new CheckResult($group, 'Kanarek storage/', CheckResult::WARN, 'nierozstrzygnięte',
                $sprawdzono . ' — serwer nie pobrał kanarka pod APP_URL (brak odpowiedzi to nie „chronione”)'),
            default                   => new CheckResult($group, 'Kanarek storage/', CheckResult::WARN, 'jeszcze nie sprawdzano', 'zadanie dobowe w kolejce'),
        };

        return $results;
    }

    /**
     * Synchronizacja: 503 (baza zajęta) — pojedyncze są normalne, seria znaczy, że coś trzyma blokady
     * dłużej, niż powinno.
     *
     * @return list<CheckResult>
     */
    /** Stan obniżony ma być widoczny, nie przemilczany (CLAUDE.md §4 i §9). */
    private function security(): array
    {
        $required = $this->config->adminTwoFactorRequired;

        return [
            new CheckResult('Bezpieczeństwo', 'Obowiązkowe 2FA administratora', $required ? CheckResult::OK : CheckResult::WARN,
                $required ? 'wymagane' : 'WYŁĄCZONE (ADMIN_2FA_REQUIRED=0)',
                $required ? '' : 'administrator tenanta wchodzi bez drugiego składnika — odstępstwo dopuszczalne tylko na środowisku deweloperskim'),
            $this->licencja(),
        ];
    }

    /**
     * Poczta wychodząca — **trwała pozycja w diagnostyce** (etap 7.2).
     *
     * ⚠ Dwie różne rzeczy w dwóch wierszach, bo to dwa różne pytania:
     *  1. **czy jest skonfigurowana** — czy w ogóle mamy gdzie wysyłać,
     *  2. **kiedy ostatnio coś wyszło** — bo ustawienia bywają poprawne, a skrzynka i tak
     *     odrzuca (limit dostawcy, zmienione hasło, blokada na wychodzące).
     *
     * ⚠ Brak konfiguracji poza `APP_ENV=dev` to **`FAIL`, nie ostrzeżenie**: zaproszenia
     * i linki do ustawienia hasła nie docierają do nikogo, więc nowy pracownik nie wejdzie
     * do aplikacji, a osoba, która zapomniała hasła, nie odzyska dostępu.
     *
     * @return list<CheckResult>
     */
    private function poczta(): array
    {
        $rodzaj = $this->transporty->rodzaj();
        $ostatnia = $this->poczta->ostatniaWysylka();

        $stan = match ($rodzaj) {
            'smtp'  => new CheckResult('Poczta', 'Wysyłka', CheckResult::OK,
                'SMTP ' . $this->poczta->host() . ':' . $this->poczta->port()
                . ' (' . $this->poczta->szyfrowanie() . ', ' . ($this->poczta->maHaslo() ? 'z hasłem' : 'bez hasła') . ')', ''),
            'plik'  => new CheckResult('Poczta', 'Wysyłka', CheckResult::WARN,
                'zrzut do plików var/mail',
                'poczta nieskonfigurowana; wiadomości NIE WYCHODZĄ — dopuszczalne wyłącznie przy APP_ENV=dev'),
            default => new CheckResult('Poczta', 'Wysyłka', CheckResult::FAIL,
                'nieskonfigurowana',
                'zaproszenia i linki do ustawienia hasła nie docierają do nikogo; ustaw serwer SMTP '
                . 'na ekranie Ustawienia → Poczta'),
        };

        return [
            $stan,
            new CheckResult('Poczta', 'Ostatnia udana wysyłka',
                $ostatnia === null ? CheckResult::WARN : CheckResult::OK,
                $ostatnia === null ? 'jeszcze nic nie wyszło' : $ostatnia . ' UTC',
                $ostatnia === null ? 'znacznik stawia kolejka po udanej wysyłce — jeśli poczta jest skonfigurowana, wyślij wiadomość próbną' : ''),
            $this->skrzynkaAlertowa(),
        ];
    }

    /**
     * Skrzynka alertowa — **trwała pozycja**, bo od niej zależy, czy KTÓRYKOLWIEK alert dojdzie.
     *
     * ⚠ Sprawdzamy nie tylko pustkę, ale i **poprawność adresu** (zlecenie Z18). Każda metoda
     * `AccountMailService::deliver*Alert()` odrzuca adres niepoprawny tym samym `filter_var()`
     * i kończy się cicho — `kadry@firma` bez domeny wyglądało na ekranie jak adres ustawiony,
     * a nie wyszłaby pod niego ani jedna wiadomość. Stan obniżony ma być widoczny, nie przemilczany.
     *
     * ⚠ Lista alertów w podpowiedzi jest konkretna, bo z tej jednej skrzynki idzie kilka różnych
     * spraw: cisza connectora, cisza pojedynczej firmy, klucz wydany i nieużyty, rozjazd tożsamości
     * i podejrzane logowania. „Alerty bezpieczeństwa” mówiło o jednej z nich.
     * ⚠ Odnośnik jest SŁOWNY („Ustawienia, sekcja Poczta”): podpowiedź jedzie do szablonu jako
     * tekst i Twig ją escapuje, więc znacznik `<a>` z PHP-a byłby złamaniem §5 i §6 CLAUDE.md,
     * a nie ułatwieniem.
     * ⚠ **Bez strzałki „→”**, której używają sąsiednie podpowiedzi: krojem hostujemy u siebie
     * podzbiór IBM Plex Sans, a jego `unicode-range` obejmuje U+2191 i U+2193, ale **nie U+2192**.
     * Zmierzone na zrzucie z `/diagnostyka`: w miejscu strzałki wychodzi pusty prostokąt. Pozostałe
     * napisy ze strzałką są starsze od tej pozycji i zgłoszone osobno — tu po prostu jej nie dokładam.
     */
    private function skrzynkaAlertowa(): CheckResult
    {
        $adres = $this->settings->get('alert_email');
        $poprawny = $adres !== '' && filter_var($adres, FILTER_VALIDATE_EMAIL) !== false;
        $alerty = 'cisza connectora, cisza pojedynczej firmy, klucz nieużyty, rozjazd tożsamości, podejrzane logowania';

        return new CheckResult('Poczta', 'Skrzynka na alerty',
            $poprawny ? CheckResult::OK : CheckResult::WARN,
            $adres === '' ? 'brak' : $adres,
            match (true) {
                $poprawny => '',
                $adres === '' => 'skrzynka alertowa nie jest ustawiona — alerty (' . $alerty
                    . ') trafią TYLKO do dziennika zdarzeń; ustaw adres na ekranie Ustawienia, sekcja Poczta',
                default => 'adres jest niepoprawny, więc wysyłka jest pomijana — alerty (' . $alerty
                    . ') trafią TYLKO do dziennika zdarzeń; popraw adres na ekranie Ustawienia, sekcja Poczta',
            });
    }

    /**
     * Zajęte dostępy wobec limitu licencji — **trwała pozycja w diagnostyce**, nie komunikat
     * na jednym ekranie.
     *
     * ⚠ Powód jest zmierzony: blokada konta **zwalnia** dostępy, a powrót pracownika
     * (odblokowanie z synchronizacji) **zajmuje je z powrotem** — i limit może zostać
     * przekroczony bez żadnej decyzji człowieka. Nie odmawiamy wtedy powrotu, bo to oprogramowanie
     * decydowałoby, że pracownik nie może pracować; ale stan obniżony ma być **widoczny**,
     * nie przemilczany — tak samo jak tryb jednego konta bazy (CLAUDE.md §4).
     */
    private function licencja(): CheckResult
    {
        $uzycie = $this->access->licenceUsage();
        $limit = $uzycie['limit'];
        if ($limit === null) {
            return new CheckResult('Bezpieczeństwo', 'Dostępy wobec licencji', CheckResult::OK,
                $uzycie['uzyte'] . ' zajętych (licencja bez limitu)', '');
        }
        $ponad = $uzycie['uzyte'] > $limit;

        return new CheckResult('Bezpieczeństwo', 'Dostępy wobec licencji',
            $ponad ? CheckResult::WARN : CheckResult::OK,
            $uzycie['uzyte'] . ' z ' . $limit,
            $ponad
                ? 'limit przekroczony — najczęstsza przyczyna: pracownik wrócił po zwolnieniu '
                    . 'i jego dostępy wróciły razem z nim. Odbierz zbędne dostępy albo podnieś licencję.'
                : '');
    }

    /**
     * Czas i strefa (docs/system-wizualny.md §10 punkt 8) — cztery wartości jednym blokiem.
     * ⚠ Rozbieżność zegara serwera ma być widoczna TUTAJ, a nie odkrywana przy reklamacji
     * („synchronizacja stoi od trzech godzin”, a stała czterdzieści osiem minut).
     *
     * @return list<CheckResult>
     */
    /**
     * Hierarchia: wykrywacz niespójności, **bo ograniczenia `CHECK` założyć się nie da**.
     *
     * ⚠ Zlecenie §5.1 kazało wymusić w bazie warunek „szczyt hierarchii nie ma przełożonego".
     * MySQL 8.4.11 odmawia wprost (zmierzone, nie wyczytane):
     *
     * ```
     * 3823 Column 'supervisor_employment_id' cannot be used in a check constraint
     *      'chk_employment_hierarchy_root': needed in a foreign key constraint
     *      'employment_ibfk_3' referential action.
     * ```
     *
     * Czyli `CHECK` i klucz obcy z akcją (`ON DELETE SET NULL`) wykluczają się na tej samej
     * kolumnie. Klucz obcy zostaje — gwarancja „przełożony istnieje" jest warta więcej. Ale
     * warunek, którego baza nie pilnuje, musi mieć coś, co go **mierzy**: inaczej zostaje
     * wyłącznie kod, który obiecuje go nie łamać, i nikt nie dowie się o poprawce zrobionej
     * ręcznie w phpMyAdmin albo przez przyszły import.
     *
     * @return list<CheckResult>
     */
    private function hierarchia(): array
    {
        $niespojne = $this->hierarchia->niespojnosciSzczytu();

        return [new CheckResult(
            'Dane kadrowe',
            'Spójność hierarchii (szczyt bez przełożonego)',
            $niespojne === 0 ? CheckResult::OK : CheckResult::FAIL,
            $niespojne === 0 ? 'zgodna' : $niespojne . ' niespójnych zatrudnień',
            $niespojne === 0
                ? 'warunku nie pilnuje baza (CHECK niemożliwy przy kluczu obcym) — pilnuje zapis i ta pozycja'
                : 'zatrudnienia oznaczone jako szczyt mają jednocześnie przełożonego; popraw na ekranie Hierarchia',
        )];
    }

    /**
     * Rozdział „administrator tenanta ≠ pracownik” (`docs/etap3-logika-urlopowa.md` §8c).
     *
     * ⚠ **Pozycja BLOKUJĄCA, nie ostrzeżenie** — i to jest cała treść reguły 2. Dwie drogi
     * nadania roli odrzuca `PermissionRepository::assignRole()`, ale trzeciej nie da się odrzucić:
     * zatrudnienie administratora przychodzi z kadr i wchodzi zawsze. Wtedy jedyną obroną jest
     * **widoczność** — stan obniżony ma być widoczny, nie przemilczany (ta sama zasada co
     * `DB_SINGLE_ACCOUNT`).
     *
     * ⚠ **Automatu, który sam by to „naprawił”, tu nie ma i nie będzie** (reguła 3). Zablokowanie
     * konta administratora w dniu, w którym właściciel przechodzi na etat, zamyka klientowi dostęp
     * do własnego systemu — to ta sama pułapka co przy ostatnim administratorze. Rozwiązanie
     * należy do człowieka: osobne konto pracownicze albo przeniesienie administracji.
     *
     * ⚠ Pozycja czyta STAN, nie zapamiętany znacznik, więc **znika sama**, gdy sprawa zostanie
     * rozwiązana — i pojawia się także wtedy, gdy ktoś wpisał wiersz ręcznie w phpMyAdmin.
     *
     * @return list<CheckResult>
     */
    private function rozdzialRol(): array
    {
        $naruszenia = $this->permissions->administratorzyZEtatem();

        return [new CheckResult(
            'Bezpieczeństwo',
            'Rozdział ról (administrator ≠ pracownik)',
            $naruszenia === [] ? CheckResult::OK : CheckResult::FAIL,
            $naruszenia === []
                ? 'zachowany'
                : count($naruszenia) . ' ' . Odmiana::kont(count($naruszenia)) . ' z zatrudnieniem',
            $naruszenia === []
                ? 'nadania łamiące rozdział odrzuca zapis; tę pozycję zapala zatrudnienie przysłane z kadr'
                : 'z kadr przyszło zatrudnienie dla konta administracyjnego ('
                    . implode('; ', array_map(
                        static fn (array $n): string => $n['osoba'] . ' — ' . $n['spolka'],
                        array_slice($naruszenia, 0, 5),
                    ))
                    . '). Załóż tej osobie osobne konto pracownicze albo przenieś administrację '
                    . 'na kogoś innego — synchronizacji nie blokujemy i danych z kadr nie poprawiamy.',
        )];
    }

    private function czas(): array
    {
        $strefaPhp = date_default_timezone_get();
        $strefa = $this->settings->get('timezone', Strefa::DOMYSLNA);
        $utc = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        return [
            // Proces MA pracować w UTC — inaczej znaczniki w bazie przestają być UTC (§10 punkt 1).
            new CheckResult('Czas', 'Strefa procesu PHP', $strefaPhp === 'UTC' ? CheckResult::OK : CheckResult::FAIL,
                $strefaPhp,
                $strefaPhp === 'UTC'
                    ? 'ustawiana jawnie w bootstrapie, niezależnie od php.ini hostingu'
                    : 'aplikacja zapisuje czas jako UTC — przy innej strefie procesu znaczniki w bazie będą przesunięte'),
            new CheckResult('Czas', 'Strefa prezentacji tenanta', Strefa::istnieje($strefa) ? CheckResult::OK : CheckResult::WARN,
                $strefa,
                Strefa::istnieje($strefa)
                    ? 'w niej pokazujemy godziny na ekranach, w poczcie i w eksportach (Ustawienia)'
                    : 'nieznana nazwa strefy — pokazujemy czas w ' . Strefa::DOMYSLNA),
            new CheckResult('Czas', 'Czas serwera (UTC)', CheckResult::OK, $utc . ' UTC',
                'to jest czas zapisywany w bazie; porównaj go z zegarem, na który patrzysz'),
            new CheckResult('Czas', 'Ten sam moment u użytkownika', CheckResult::OK,
                Strefa::czasZeStrefa($utc, $strefa),
                'tę godzinę widzi kadrowa na ekranie'),
        ];
    }

    /**
     * @return list<CheckResult>
     */
    private function sync(): array
    {
        $day = $this->events->countRecent(ApiKernel::EVENT_TRANSIENT, 24);
        $week = $this->events->countRecent(ApiKernel::EVENT_TRANSIENT, 24 * 7);
        $results = [new CheckResult('Synchronizacja', 'Przerwy techniczne API (503)', $day > self::TRANSIENT_WARN_PER_DAY ? CheckResult::WARN : CheckResult::OK,
            $day . ' w 24 h, ' . $week . ' w 7 dni',
            $day > self::TRANSIENT_WARN_PER_DAY ? 'rosnąca seria — sprawdź długie transakcje i blokady w bazie' : 'baza zajęta przy paczce connectora; paczka powtarzana bez strat')];
        // ⚠ Cisza connectora: stan OBNIŻONY widoczny tutaj, tak jak tryb jednego konta bazy (§2.2 zlecenia).
        // Klucz aktywny i zero żądań to awaria, która bez tej pozycji wychodzi dopiero przy reklamacji.
        $prog = (int) $this->settings->get('key_idle_alert_hours', '24');
        $progNieuzytego = (int) $this->settings->get('key_unused_alert_hours', '72');
        $milczace = [];
        $czekajace = [];
        $bezFirm = [];
        $rozjazdy = [];
        foreach ($this->apiKeys->all() as $key) {
            if ($key['status'] !== 'active') {
                continue;
            }
            // ⚠ Klucz bez firm uwierzytelnia się poprawnie i nie dostaje ŻADNYCH danych.
            if ($key['nips'] === []) {
                $bezFirm[] = $key['name'];
            }
            // ⚠ Odciski firm jednego klucza muszą być zgodne — inaczej to dwie instalacje connectora.
            $rozjazd = SyncService::identitySplit($this->domain->companiesForKey($key['id']));
            if ($rozjazd !== null) {
                $rozjazdy[] = $key['name'] . ' — ' . (string) $rozjazd['firmy'];
            }
            // ⚠ Dwa różne stany i dwa różne komunikaty: klucz, który ZAMILKŁ (connector przestał
            // się odzywać), i klucz, którego connector NIGDY nie dostał (czeka na wklejenie).
            if ($key['last_used_at'] === null) {
                if (Cisza::przekroczona(null, (string) $key['created_at'], $progNieuzytego)) {
                    $czekajace[] = $key['name'] . ' — ' . Cisza::godziny((string) $key['created_at']) . ' h';
                }
            } elseif (Cisza::przekroczona($key['last_used_at'], (string) $key['created_at'], $prog)) {
                $milczace[] = $key['name'] . ' — ' . Cisza::godziny((string) $key['last_used_at']) . ' h';
            }
        }
        $results[] = new CheckResult('Synchronizacja', 'Odcisk tożsamości firm jednego klucza',
            $rozjazdy === [] ? CheckResult::OK : CheckResult::WARN,
            $rozjazdy === [] ? 'zgodny' : implode(' · ', $rozjazdy),
            $rozjazdy === []
                ? 'firmy jednego klucza liczą identyfikatory osób z tego samego sekretu; osób w więcej niż jednej firmie: '
                    . $this->domain->peopleInManyCompanies()
                : 'dwie instalacje connectora — ta sama osoba w dwóch takich firmach będzie widziana jako dwie; '
                    . 'osób w więcej niż jednej firmie: ' . $this->domain->peopleInManyCompanies());
        $results[] = new CheckResult('Synchronizacja', 'Klucz bez firm w zakresie',
            $bezFirm === [] ? CheckResult::OK : CheckResult::WARN,
            $bezFirm === [] ? 'brak' : implode(', ', $bezFirm),
            $bezFirm === []
                ? 'każdy aktywny klucz ma przypisaną firmę'
                : 'klucz uwierzytelnia się poprawnie i nie odbierze żadnych danych — przypisz firmę albo unieważnij');
        $results[] = new CheckResult('Synchronizacja', 'Klucz wydany i nieużyty', $czekajace === [] ? CheckResult::OK : CheckResult::WARN,
            $czekajace === [] ? 'brak' : implode(', ', $czekajace),
            $czekajace === []
                ? 'każdy wydany klucz trafił do connectora'
                : 'klucz czeka na wklejenie w konfiguracji connectora — dotychczasowy działa dalej, nic nie zostanie zabite');
        $results[] = new CheckResult('Synchronizacja', 'Cisza connectora', $milczace === [] ? CheckResult::OK : CheckResult::WARN,
            $milczace === []
                ? ($prog === 0 ? 'ostrzeżenie wyłączone (próg 0)' : 'connector odzywa się w progu ' . $prog . ' h')
                : implode(', ', $milczace),
            $milczace === []
                ? 'próg w Ustawieniach (key_idle_alert_hours)'
                : 'sprawdź, czy maszyna z connectorem działa, czy usługa wstała i czy nie zmienił się adres serwera kadrowego');

        // ⚠ Kalendarz bez `normaUrlopowaMin` znaczy, że minuty na dni dzielimy wartością zapasową 480.
        // U firmy z dniówką 12 h myliłby się KAŻDY wniosek, a bez tej pozycji nikt by się o tym nie dowiedział.
        $withoutNorm = $this->calendars->countWithoutNorm();
        $results[] = new CheckResult('Synchronizacja', 'Normy kalendarzy z kadr', $withoutNorm > 0 ? CheckResult::WARN : CheckResult::OK,
            $withoutNorm > 0 ? $withoutNorm . ' bez normy urlopowej' : 'wszystkie z normą',
            $withoutNorm > 0
                ? 'dla nich dzień liczymy wartością zapasową ' . LeaveRequestService::FALLBACK_DAY_MINUTES
                    . ' min — poproś o wersję connectora wysyłającą normaUrlopowaMin'
                : 'liczba dni wniosku liczona normą z kadr, nie stałą w kodzie');

        return $results;
    }
}
