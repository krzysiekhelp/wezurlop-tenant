<?php

declare(strict_types=1);

namespace Wezurlop\Diagnostics;

use ErrorException;
use PDOException;
use Wezurlop\Db\Connection;
use Wezurlop\Repository\DiagnosticsRepository;
use Wezurlop\Service\Install\EnvWriter;
use Wezurlop\Support\ErrorGuard;

/**
 * Diagnostyka środowiska tenanta (CLAUDE.md §15, docs/szkielet-tenanta.md rozdz. 12–13).
 *
 * Jeden zestaw sprawdzeń dla instalatora, bin/diagnose.php i strony /diagnostyka.
 * Klasa niczego nie wypisuje — zwraca listę wyników; prezentacja należy do wywołującego.
 */
final class EnvironmentCheck
{
    private const MIN_PHP = '8.3.0';
    /** Pierwsza wersja poza zakresem z composer.json (>=8.3 <8.6). */
    private const ABOVE_RANGE = '8.6.0';

    /** Brak = aplikacja się nie zainstaluje (CLAUDE.md §0). */
    private const REQUIRED_EXTENSIONS = [
        'pdo_mysql' => '',
        'mbstring'  => '',
        'curl'      => '',
        'fileinfo'  => '',
        'openssl'   => '',
        'iconv'     => 'wymagane przez generator kodu QR (2FA)',
        'xmlwriter' => 'wymagane przez generator kodu QR w SVG (2FA)',
    ];

    /** Brak = ostrzeżenie; aplikacja działa. */
    private const RECOMMENDED_EXTENSIONS = [
        'intl'         => 'ograniczone formatowanie dat i sortowanie według locale',
        'zip'          => 'paczki aktualizacji rozpakowywane przez PharData (wolniej)',
        'gd'           => 'brak przetwarzania obrazów',
        'Zend OPcache' => 'wolniejsze wykonywanie PHP',
        'sodium'       => 'paragonie/sodium_compat liczy w czystym PHP (to samo API, wolniej)',
    ];

    private const EXPECTED_COLLATION = 'utf8mb4_unicode_ci';

    /** Prawa, których konto aplikacyjne mieć nie może (CLAUDE.md §4). */
    private const SCHEMA_PRIVILEGES = '/^(ALL|CREATE|DROP|ALTER|INDEX|REFERENCES|TRIGGER|GRANT|FILE|SUPER)\b/';

    /** Pliki spoza public/, które nie mogą być osiągalne przez HTTP (ścieżki względem korzenia aplikacji). */
    private const PROTECTED_FILES = [
        '.env'                           => 'hasła do bazy',
        'composer.json'                  => 'konfiguracja projektu',
        'vendor/composer/installed.json' => 'vendor/ — znany wektor RCE',
        'vendor/autoload.php'            => 'vendor/ — wykonywalny kod PHP',
    ];

    /** @var list<CheckResult> */
    private array $results = [];

    /**
     * @param array<string, string> $env konfiguracja z .env (DB_*, APP_ENV)
     */
    public function __construct(
        private readonly string $appRoot,
        private readonly string $storagePath,
        private readonly string $varPath,
        private readonly array $env,
        private readonly bool $cli,
        private readonly ?HttpProbeTarget $http = null,
        private readonly ?string $appPathSetting = null,
        private readonly ?string $installLockPath = null,
        private readonly ?string $envFile = null,
    ) {
    }

    public const SECTION_PLATFORM = 'platform';
    public const SECTION_DATABASE = 'database';
    public const SECTION_FILES = 'files';
    public const SECTION_EXPOSURE = 'exposure';

    /** @return list<CheckResult> */
    public function run(): array
    {
        return $this->runSections([self::SECTION_PLATFORM, self::SECTION_DATABASE, self::SECTION_FILES, self::SECTION_EXPOSURE]);
    }

    /**
     * Wybrane grupy sprawdzeń — instalator uruchamia platformę przed podaniem danych bazy,
     * a ekspozycję plików dopiero po ustaleniu adresu aplikacji.
     *
     * @param list<string> $sections
     * @return list<CheckResult>
     */
    public function runSections(array $sections): array
    {
        $this->results = [];
        if (in_array(self::SECTION_PLATFORM, $sections, true)) {
            $this->checkPhp();
            $this->checkIonCube();
            $this->checkAppLocation();
            $this->checkInstallLock();
            $this->checkExtensions();
        }
        if (in_array(self::SECTION_DATABASE, $sections, true)) {
            $this->checkDatabase();
        }
        if (in_array(self::SECTION_FILES, $sections, true)) {
            $this->checkDirectories();
            $this->checkEnvPermissions();
            $this->checkPhpSettings();
        }
        if (in_array(self::SECTION_EXPOSURE, $sections, true)) {
            $this->checkDocumentRoot();
            $this->checkWebExposure();
        }

        return $this->results;
    }

    private function add(string $group, string $name, string $status, string $value, string $note = ''): void
    {
        $this->results[] = new CheckResult($group, $name, $status, $value, $note);
    }

    // --- PHP i ionCube ----------------------------------------------------------------------

    private function checkPhp(): void
    {
        // Zakres 8.3–8.5 (CLAUDE.md §0, composer.json): poniżej — twardy błąd; powyżej — poza sprawdzonym zakresem.
        $ok = version_compare(PHP_VERSION, self::MIN_PHP, '>=');
        $above = version_compare(PHP_VERSION, self::ABOVE_RANGE, '>=');
        $this->add('PHP', 'Wersja PHP', !$ok ? CheckResult::FAIL : ($above ? CheckResult::WARN : CheckResult::OK), PHP_VERSION,
            $above ? 'wersja wyższa niż sprawdzony zakres 8.3–8.5' : '8.3 minimum, zalecane najnowsze PHP, do którego hosting ma Loadera');
    }

    private function checkIonCube(): void
    {
        if (!extension_loaded('ionCube Loader')) {
            $this->add('PHP', 'ionCube Loader', CheckResult::FAIL, 'brak',
                'wymagany do uruchomienia zakodowanych modułów');
            return;
        }
        $version = function_exists('ioncube_loader_version') ? (string) ioncube_loader_version() : 'nieznana';
        $this->add('PHP', 'ionCube Loader', CheckResult::OK, $version);
    }

    /**
     * Skąd front controller wziął położenie aplikacji: WEZURLOP_APP_PATH (wariant „aplikacja powyżej
     * katalogu domeny”) albo katalog nad public/ (docs/szkielet-tenanta.md rozdz. 12).
     */
    private function checkAppLocation(): void
    {
        $source = match (true) {
            $this->cli                    => 'uruchomienie z CLI (katalog bin/..)',
            $this->appPathSetting !== null
                && $this->appPathSetting !== '' => 'WEZURLOP_APP_PATH',
            default                       => 'katalog nad public/',
        };
        $complete = is_file($this->appRoot . '/vendor/autoload.php') && is_file($this->appRoot . '/config/config.php');
        $this->add('PHP', 'Położenie aplikacji', $complete ? CheckResult::OK : CheckResult::FAIL,
            $this->appRoot, $complete ? 'źródło: ' . $source : 'brak vendor/ lub config/ — sprawdź WEZURLOP_APP_PATH');
    }

    /** Po instalacji znacznik blokady musi istnieć — inaczej instalator odpowiada (docs rozdz. 2, 12). */
    private function checkInstallLock(): void
    {
        if ($this->installLockPath === null) {
            return;
        }
        $locked = is_file($this->installLockPath);
        $this->add('PHP', 'Blokada instalatora', $locked ? CheckResult::OK : CheckResult::FAIL,
            $locked ? 'var/install.lock' : 'brak znacznika',
            $locked ? '' : 'instalator jest dostępny — ktoś może przejąć instalację');
    }

    private function checkExtensions(): void
    {
        foreach (self::REQUIRED_EXTENSIONS as $ext => $why) {
            $loaded = extension_loaded($ext);
            $this->add('Rozszerzenia wymagane', $ext, $loaded ? CheckResult::OK : CheckResult::FAIL,
                $loaded ? 'jest' : 'brak', $loaded ? '' : ($why !== '' ? $why : 'wymagane'));
        }
        foreach (self::RECOMMENDED_EXTENSIONS as $ext => $whenMissing) {
            $loaded = extension_loaded($ext);
            $this->add('Rozszerzenia zalecane', $ext, $loaded ? CheckResult::OK : CheckResult::WARN,
                $loaded ? 'jest' : 'brak', $loaded ? '' : $whenMissing);
        }
    }

    // --- Baza danych ------------------------------------------------------------------------

    private function checkDatabase(): void
    {
        $group = 'Baza danych';
        foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS'] as $key) {
            if (($this->env[$key] ?? '') === '') {
                $this->add($group, 'Konfiguracja', CheckResult::FAIL, 'brak ' . $key, 'uzupełnij .env');
                return;
            }
        }
        if (!extension_loaded('pdo_mysql')) {
            $this->add($group, 'Połączenie', CheckResult::FAIL, 'nie sprawdzono', 'brak pdo_mysql');
            return;
        }

        $host = $this->env['DB_HOST'];
        $sslMode = ($this->env['DB_SSL'] ?? '') !== '' ? strtolower($this->env['DB_SSL']) : Connection::SSL_AUTO;
        $caFile = $this->env['DB_SSL_CA'] ?? '';
        try {
            // Profil schema: diagnostyka niczego nie zmienia, a bezpiecznik DDL nie jest tu potrzebny.
            $pdo = (new Connection([
                'host' => $host, 'port' => (int) (($this->env['DB_PORT'] ?? '') !== '' ? $this->env['DB_PORT'] : 3306),
                'name' => $this->env['DB_NAME'], 'user' => $this->env['DB_USER'], 'password' => $this->env['DB_PASS'],
                'ssl' => $sslMode, 'ssl_ca' => $caFile,
            ], Connection::PROFILE_SCHEMA))->pdo();
            $repo = new DiagnosticsRepository($pdo);
            $version = $repo->serverVersion();
            $collation = $repo->schemaCollation();
            $grants = $repo->currentGrants();
            $cipher = $repo->sslCipher();
        } catch (PDOException $e) {
            // Komunikat PDO nie trafia do wyniku (CLAUDE.md §14) — tylko kod błędu.
            $this->add($group, 'Połączenie (konto aplikacyjne)', CheckResult::FAIL, 'błąd',
                'nie udało się połączyć (kod ' . (string) $e->getCode() . ')');
            return;
        }

        $this->add($group, 'Połączenie (konto aplikacyjne)', CheckResult::OK, $this->env['DB_NAME'],
            'serwer ' . $version);

        $collationOk = $collation === self::EXPECTED_COLLATION;
        $this->add($group, 'Kolacja bazy', $collationOk ? CheckResult::OK : CheckResult::FAIL, $collation,
            $collationOk ? '' : 'wymagana ' . self::EXPECTED_COLLATION);

        // Szyfrowanie połączenia (CLAUDE.md §4) — pozycja stała, zapisana także przy instalacji.
        if ($cipher !== '') {
            $this->add($group, 'Szyfrowanie połączenia z bazą', $caFile !== '' ? CheckResult::OK : CheckResult::WARN, $cipher,
                $caFile !== '' ? 'certyfikat zweryfikowany (DB_SSL_CA)'
                    : 'szyfrowane bez weryfikacji certyfikatu — chroni przed podsłuchem, nie przed aktywnym atakiem w sieci');
        } elseif (Connection::isLocalHost($host)) {
            $this->add($group, 'Szyfrowanie połączenia z bazą', CheckResult::OK, 'bez TLS', 'baza lokalna — ruch nie opuszcza serwera');
        } else {
            $this->add($group, 'Szyfrowanie połączenia z bazą', CheckResult::FAIL, 'NIESZYFROWANE',
                'baza na zdalnym hoście — hasła i dane kadrowe idą jawnym tekstem (DB_SSL=' . $sslMode . ')');
        }

        // Tryb jednego konta: stan obniżony, widoczny na stałe (CLAUDE.md §4).
        if (($this->env['DB_SINGLE_ACCOUNT'] ?? '0') === '1') {
            $this->add($group, 'Konta bazy', CheckResult::WARN, 'tryb jednego konta (DB_SINGLE_ACCOUNT=1)',
                'aplikacja pracuje kontem z prawem zmiany schematu; bezpiecznik DDL aktywny, kopia zapasowa '
                . 'przed każdą aktualizacją obowiązkowa — rozdziel konta, jeśli hosting na to pozwala');

            return;
        }
        $risky = $this->schemaPrivileges($grants);
        $this->add($group, 'Uprawnienia konta aplikacyjnego',
            $risky === [] ? CheckResult::OK : CheckResult::WARN,
            $risky === [] ? 'bez zmian schematu' : implode(', ', $risky),
            $risky === [] ? '' : 'zmiany schematu tylko kontem migracyjnym (CLAUDE.md §4)');
    }

    /**
     * @param list<string> $grants
     * @return list<string>
     */
    private function schemaPrivileges(array $grants): array
    {
        $risky = [];
        foreach ($grants as $grant) {
            if (preg_match('/^GRANT (.+?) ON /i', $grant, $m) !== 1) {
                continue;
            }
            foreach (array_map('trim', explode(',', strtoupper($m[1]))) as $privilege) {
                if (preg_match(self::SCHEMA_PRIVILEGES, $privilege) === 1) {
                    $risky[] = $privilege;
                }
            }
        }

        return array_values(array_unique($risky));
    }

    // --- Katalogi ---------------------------------------------------------------------------

    private function checkDirectories(): void
    {
        $dirs = [
            'storage/logs'    => $this->storagePath . '/logs',
            'storage/uploads' => $this->storagePath . '/uploads',
            'var/cache'       => $this->varPath . '/cache',
        ];
        foreach ($dirs as $label => $dir) {
            if (!is_dir($dir)) {
                $this->add('Katalogi', $label, CheckResult::FAIL, 'nie istnieje');
                continue;
            }
            $probe = $this->writeProbe($dir, 'diag-write-' . bin2hex(random_bytes(8)), '');
            if ($probe === null) {
                // CLI działa zwykle jako inny użytkownik niż serwer WWW — wynik niejednoznaczny.
                $this->add('Katalogi', $label, $this->cli ? CheckResult::WARN : CheckResult::FAIL,
                    'brak zapisu', $this->cli
                        ? 'sprawdzone jako użytkownik CLI; rozstrzyga wynik z przeglądarki'
                        : 'nadaj prawa zapisu użytkownikowi serwera WWW');
                continue;
            }
            $this->removeProbe($probe);
            $this->add('Katalogi', $label, CheckResult::OK, 'zapisywalny');
        }
    }

    /**
     * Prawa .env po instalacji: 0640 lub ciaśniej. Instalator zapisuje plik przy 0660 i sam je zaciska;
     * dopóki to się nie stanie, każde uruchomienie diagnostyki zgłasza błąd (rozdz. 12, krok 11).
     */
    private function checkEnvPermissions(): void
    {
        if ($this->envFile === null || !is_file($this->envFile)) {
            return;
        }
        $perms = fileperms($this->envFile);
        $mode = $perms === false ? 'nieznane' : sprintf('%04o', $perms & 0o7777);
        $ok = EnvWriter::isRestricted($this->envFile);
        $this->add('Katalogi', 'Prawa pliku .env', $ok ? CheckResult::OK : CheckResult::FAIL, $mode,
            $ok ? '' : 'plik zawiera hasła — ustaw 0640 (chmod 640 .env)');
    }

    /** @return string|null ścieżka utworzonego pliku albo null, gdy zapis się nie udał */
    private function writeProbe(string $dir, string $name, string $content): ?string
    {
        if (!is_writable($dir)) {
            return null;
        }
        $file = $dir . '/' . $name;
        try {
            ErrorGuard::run(static function () use ($file, $content): void {
                $handle = fopen($file, 'x');
                fwrite($handle, $content);
                fclose($handle);
            });
        } catch (ErrorException) {
            return null;
        }

        return $file;
    }

    private function removeProbe(string $file): void
    {
        try {
            ErrorGuard::run(static fn (): bool => unlink($file));
        } catch (ErrorException) {
            // plik-sonda ma losową nazwę i pustą lub losową treść — pozostawienie go nie jest groźne
        }
    }

    // --- Ustawienia PHP ---------------------------------------------------------------------

    private function checkPhpSettings(): void
    {
        $group = 'Ustawienia PHP';
        $raw = strtolower((string) ini_get('display_errors'));
        $displayErrors = in_array($raw, ['1', 'on', 'yes', 'true', 'stdout', 'stderr'], true);
        $appEnv = strtolower($this->env['APP_ENV'] ?? 'prod');
        if ($appEnv === 'dev') {
            $this->add($group, 'display_errors', $displayErrors ? CheckResult::OK : CheckResult::WARN,
                $displayErrors ? 'On' : 'Off', 'APP_ENV=dev: błędy mają być widoczne');
        } else {
            $this->add($group, 'display_errors', $displayErrors ? CheckResult::FAIL : CheckResult::OK,
                $displayErrors ? 'On' : 'Off', 'APP_ENV=' . $appEnv . ': błędy nie mogą być wyświetlane');
        }

        $upload = (string) ini_get('upload_max_filesize');
        $post = (string) ini_get('post_max_size');
        $postBytes = $this->iniBytes($post);
        $postOk = $postBytes === 0 || $postBytes >= $this->iniBytes($upload);
        $this->add($group, 'upload_max_filesize', CheckResult::OK, $upload);
        $this->add($group, 'post_max_size', $postOk ? CheckResult::OK : CheckResult::WARN, $post,
            $postOk ? '' : 'mniejsze niż upload_max_filesize — duże pliki nie dotrą');

        $maxExec = (int) ini_get('max_execution_time');
        if ($this->cli) {
            $this->add($group, 'max_execution_time', CheckResult::OK, (string) $maxExec,
                'CLI; limit serwera WWW widoczny w przeglądarce');
        } else {
            $tooHigh = $maxExec === 0 || $maxExec > 30;
            $this->add($group, 'max_execution_time', $tooHigh ? CheckResult::WARN : CheckResult::OK,
                $maxExec === 0 ? '0 (bez limitu)' : $maxExec . ' s',
                $tooHigh ? 'aplikacja zakłada limit 30 s — wyższy limit ukrywa błędy porcjowania' : '');
        }
    }

    private function iniBytes(string $value): int
    {
        $value = trim($value);
        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }

    // --- Katalog domeny i ekspozycja plików ---------------------------------------------------

    private function checkDocumentRoot(): void
    {
        if ($this->http === null || $this->http->documentRoot === null) {
            return;
        }
        $docRoot = realpath($this->http->documentRoot);
        $appRoot = realpath($this->appRoot);
        $name = 'Katalog domeny';
        if ($docRoot !== false && $docRoot === $appRoot . DIRECTORY_SEPARATOR . 'public') {
            $this->add('Bezpieczeństwo', $name, CheckResult::OK, 'public/');
        } elseif ($docRoot !== false && $docRoot === $appRoot) {
            $this->add('Bezpieczeństwo', $name, CheckResult::WARN, 'korzeń aplikacji',
                'układ awaryjny; o dopuszczeniu decyduje test pobrania plików poniżej');
        } else {
            $this->add('Bezpieczeństwo', $name, CheckResult::WARN, 'nietypowy układ',
                'oczekiwano katalogu public/ aplikacji');
        }
    }

    /**
     * Próba pobrania przez HTTP plików spoza public/. Liczy się brak treści, nie kod odpowiedzi
     * (docs/szkielet-tenanta.md rozdz. 14): treść = błąd blokujący, brak odpowiedzi = wynik
     * nierozstrzygnięty, wymagający potwierdzenia.
     */
    private function checkWebExposure(): void
    {
        $group = 'Bezpieczeństwo';
        if ($this->http === null) {
            $this->add($group, 'Pliki spoza public/ przez HTTP', CheckResult::WARN, 'nie sprawdzono',
                'podaj adres aplikacji, aby wykonać test');
            return;
        }
        if (!extension_loaded('curl')) {
            $this->add($group, 'Pliki spoza public/ przez HTTP', CheckResult::FAIL, 'nie sprawdzono',
                'brak rozszerzenia curl');
            return;
        }

        foreach (self::PROTECTED_FILES as $rel => $risk) {
            $file = $this->appRoot . '/' . $rel;
            if (!is_file($file) || !is_readable($file)) {
                $this->add($group, $rel, CheckResult::WARN, 'nie sprawdzono',
                    str_starts_with($rel, 'vendor/') ? 'brak pliku — uruchom composer install' : 'brak pliku');
                continue;
            }
            $this->probe($group, $rel, $rel, $risk, $this->leakNeedles($rel, $file), str_ends_with($rel, '.php'));
        }

        // Katalogi zapisywalne: plik-sonda o losowej nazwie i treści, usuwany po teście.
        // Nazwa bez kropki na początku, żeby blokada plików z kropką nie zamaskowała wyniku.
        $probeDirs = [
            'storage/uploads/' => [$this->storagePath . '/uploads', 'załączniki z pominięciem kontroli uprawnień'],
            'var/cache/'       => [$this->varPath . '/cache', 'pliki tymczasowe aplikacji'],
        ];
        foreach ($probeDirs as $label => [$dir, $risk]) {
            $rel = $this->relativeToAppRoot($dir);
            if ($rel === null) {
                $this->add($group, $label, CheckResult::OK, 'poza katalogiem aplikacji');
                continue;
            }
            $token = bin2hex(random_bytes(12));
            $name = 'diag-probe-' . $token . '.txt';
            $content = 'wezurlop-diag-probe-' . $token;
            $probe = $this->writeProbe($dir, $name, $content);
            if ($probe === null) {
                $this->add($group, $label, CheckResult::WARN, 'nie sprawdzono',
                    'nie można utworzyć pliku-sondy' . ($this->cli ? ' jako użytkownik CLI' : ''));
                continue;
            }
            try {
                $this->probe($group, $label, $rel . '/' . $name, $risk, [$content], false);
            } finally {
                $this->removeProbe($probe);
            }
        }
    }

    /** @param list<string> $needles fragmenty treści pliku; obecność w odpowiedzi = wyciek */
    private function probe(
        string $group,
        string $label,
        string $rel,
        string $risk,
        array $needles,
        bool $executable,
    ): void {
        $r = (new ExposureProbe($this->http))->check($rel, $needles, $executable);
        $value = $r['codes'] === [] ? 'brak odpowiedzi' : 'HTTP ' . implode(', ', $r['codes']);
        if ($r['result'] === ExposureProbe::EXPOSED) {
            $this->add($group, $label, CheckResult::FAIL, 'DOSTĘPNY: ' . implode('; ', $r['leaks']),
                $risk . ' — ustaw katalog domeny na public/');
        } elseif ($r['result'] === ExposureProbe::UNRESOLVED) {
            $this->add($group, $label, CheckResult::WARN, $value,
                'wynik nierozstrzygnięty — serwer nie odpowiedział sam sobie; wymaga potwierdzenia');
        } elseif ($r['errors'] !== []) {
            $this->add($group, $label, CheckResult::WARN, $value,
                'część adresów bez odpowiedzi: ' . implode('; ', $r['errors']));
        } else {
            $this->add($group, $label, CheckResult::OK, $value, 'brak treści pliku');
        }
    }

    /** @return list<string> */
    private function leakNeedles(string $rel, string $file): array
    {
        $needles = [];
        try {
            $content = trim((string) ErrorGuard::run(static fn (): string|false => file_get_contents($file)));
        } catch (ErrorException) {
            $content = '';
        }
        if (strlen($content) >= 16) {
            $needles[] = substr($content, 0, 64);
        }
        if ($rel === '.env') {
            array_push($needles, 'DB_PASS=', 'DB_USER=', 'DB_MIG_PASS=', 'APP_KEY=');
            foreach (['DB_PASS', 'DB_MIG_PASS', 'APP_KEY'] as $key) {
                if (strlen($this->env[$key] ?? '') >= 8) {
                    $needles[] = $this->env[$key];
                }
            }
        }
        if (str_ends_with($rel, '.php')) {
            $needles[] = '<?php';
        }

        return $needles;
    }

    private function relativeToAppRoot(string $dir): ?string
    {
        $root = realpath($this->appRoot);
        $real = realpath($dir);
        if ($root === false || $real === false || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return str_replace(DIRECTORY_SEPARATOR, '/', substr($real, strlen($root) + 1));
    }
}
