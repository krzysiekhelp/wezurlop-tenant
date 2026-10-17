<?php

declare(strict_types=1);

// Konfiguracja aplikacji: mapowanie wartości z .env na ustawienia.
// Nie zawiera sekretów ani wartości zależnych od wdrożenia — te są wyłącznie w .env.
// Jedyny wyjątek od „tylko .env”: prefiksy adresów (APP_BASE_PATH, APP_FRONT_CONTROLLER)
// czytane najpierw ze zmiennych serwera (SetEnv w vhoście), bo dwa vhosty mogą dzielić jedno
// repozytorium i jeden .env (docs/szkielet-tenanta.md rozdz. 3).

return static function (array $env, array $server, string $appRoot): array {
    $value = static fn (string $key, string $default = ''): string =>
        isset($env[$key]) && is_string($env[$key]) && $env[$key] !== '' ? $env[$key] : $default;

    // Prefiksy adresów (rozdz. 3): zmienna serwera (SetEnv) -> .env -> pusty.
    $prefix = static function (string $key) use ($server, $value): string {
        $fromServer = $server[$key] ?? $server['REDIRECT_' . $key] ?? null;

        return is_string($fromServer) ? $fromServer : $value($key);
    };

    return [
        'app_env'          => strtolower($value('APP_ENV', 'prod')),
        'app_url'          => rtrim($value('APP_URL'), '/'),
        'base_path'        => $prefix('APP_BASE_PATH'),
        'front_controller' => $prefix('APP_FRONT_CONTROLLER'),
        'asset_version'    => $value('ASSET_VERSION', '1'),
        'storage_path'     => rtrim($value('STORAGE_PATH', $appRoot . '/storage'), '/'),
        'var_path'         => rtrim($value('VAR_PATH', $appRoot . '/var'), '/'),
        'templates_path'   => $appRoot . '/templates',
        // ⚠ Katalog zasobów w SYSTEMIE PLIKÓW — potrzebny, żeby wersję `?v=` liczyć z pliku,
        // a nie z ręcznie wpisanej daty (Support/AssetVersions). Konfigurowalny, bo wariant 2
        // wdrożenia trzyma zawartość `public/` w katalogu domeny, poza katalogiem aplikacji.
        'assets_path'      => rtrim($value('ASSETS_PATH', $appRoot . '/public/assets'), '/'),
        'db' => [
            'host'     => $value('DB_HOST', 'localhost'),
            'port'     => (int) $value('DB_PORT', '3306'),
            'name'     => $value('DB_NAME'),
            'user'     => $value('DB_USER'),
            'password' => $value('DB_PASS'),
            // TLS (CLAUDE.md §4): auto = szyfrowanie, gdy host nie jest lokalny; DB_SSL_CA włącza weryfikację.
            'ssl'      => strtolower($value('DB_SSL', 'auto')),
            'ssl_ca'   => $value('DB_SSL_CA'),
        ],
        // Migracje (konto z DDL). W trybie jednego konta te same wartości co DB_* i DB_SINGLE_ACCOUNT=1.
        'db_mig' => [
            'user'     => $value('DB_MIG_USER'),
            'password' => $value('DB_MIG_PASS'),
        ],
        'db_single_account' => filter_var($value('DB_SINGLE_ACCOUNT', '0'), FILTER_VALIDATE_BOOLEAN),
        // Wdrożenie (docs/profile-hostingu.md): wykryty profil serwera i surowe SERVER_SOFTWARE — tylko do
        // .htaccess, crona i diagnostyki, nigdy do decyzji o uprawnieniach. SELF_ADDR: adres, pod którym
        // serwer łączy się sam ze sobą przy sondach HTTP (kanarek), zapisany przez instalator.
        'deploy' => [
            'server_profile'   => $value('SERVER_PROFILE'),
            'server_software'  => $value('SERVER_SOFTWARE'),
            'self_addr'        => filter_var($value('SELF_ADDR'), FILTER_VALIDATE_IP) !== false ? $value('SELF_ADDR') : null,
            'cron_minutes'     => (int) $value('CRON_INTERVAL_MINUTES', '0'),
            'queue_on_request' => filter_var($value('QUEUE_ON_REQUEST', '0'), FILTER_VALIDATE_BOOLEAN),
        ],
        // APP_KEY: 32 bajty, zapis „base64:...”. Szyfrowanie sekretów TOTP i HMAC adresów IP.
        'app_key' => $value('APP_KEY'),
        // ⚠ `MAIL_*` NIE JEST tu czytane (etap 7.2): konfiguracja poczty mieszka w bazie,
        // bo serwer SMTP klienta zmienia się po instalacji, a `.env` pisze instalator raz.
        // Katalog zrzutu wiadomości na dev zostaje — patrz Config::mailDumpPath().
        // Licencja: klucz publiczny panelu (plik, nie kod) i podpisana odpowiedź panelu.
        'license' => [
            'public_key_file' => $value('LICENSE_PUBLIC_KEY_PATH', $appRoot . '/config/license-public.key'),
            'file'            => $value('LICENSE_FILE', rtrim($value('VAR_PATH', $appRoot . '/var'), '/') . '/license.json'),
            'panel_url'       => $value('PANEL_URL'),
            'installation_key'=> $value('PANEL_INSTALLATION_KEY'),
        ],
        'hsts_max_age'            => (int) $value('HSTS_MAX_AGE', '31536000'),
        'hsts_include_subdomains' => filter_var($value('HSTS_INCLUDE_SUBDOMAINS', 'true'), FILTER_VALIDATE_BOOLEAN),
        // Aplikacja kadrowa za logowaniem nie ma czego indeksować; NOINDEX=0 wyłącza nagłówek świadomie.
        'noindex'                 => filter_var($value('NOINDEX', 'true'), FILTER_VALIDATE_BOOLEAN),
        // ⚠ Obowiązkowe 2FA administratora tenanta (CLAUDE.md §9). Wyłączenie to stan obniżony — widoczny
        // na /diagnostyka, nigdy przemilczany; na środowisku deweloperskim wyłączane świadomie.
        'admin_2fa_required'      => filter_var($value('ADMIN_2FA_REQUIRED', 'true'), FILTER_VALIDATE_BOOLEAN),
        'trusted_proxies'         => array_values(array_filter(array_map('trim', explode(',', $value('TRUSTED_PROXIES'))))),
        // ⚠ Ile osób kontrolka kalendarza pokazuje bez zawężania. Próg jest KONFIGUROWALNY,
        // bo zależy od wielkości firmy, a nie od naszego upodobania: reguła „domyślnie zaznaczeni
        // wszyscy" powstała dla zespołu sześcioosobowego i przy 53 osobach jest błędem
        // (`docs/etap3-logika-urlopowa.md` §8b). Różnicujemy po LICZBIE OSÓB W ZASIĘGU, nie po roli
        // patrzącego — przełożony z sześćdziesięcioma podwładnymi trafia na tę samą ścianę.
        // ⚠ **Domyślne 30 podniesione do 100 (06.10.2026, zgłoszenie Krzysztofa.)** Przy trzydziestu
        // próg obcinał KAŻDĄ spółkę w danych — także trzydziestosześcioosobową — więc przycisk
        // „Zaznacz wszystkich (36)" dawał w wyniku 30 wierszy i 30 pozycji w kontrolce. Etykieta
        // przycisku przeczyła jego skutkowi, a to gorsze niż brak liczby.
        // ⚠ Próg ZOSTAJE, bo nadal jest potrzebny: ma zatrzymać siatkę przy spółce liczonej
        // w setkach. Zmienia się tylko miejsce, w którym zaczyna działać — ponad rozmiarem
        // firmy, a nie poniżej. Zawężanie listy zostaje w rękach człowieka (szukajka i wybór),
        // nie w progu.
        'calendar' => [
            'picker_limit' => max(1, (int) $value('CALENDAR_PICKER_LIMIT', '100')),
        ],
        'session' => [
            'idle_timeout'     => 1800,
            'absolute_timeout' => 43200,
            'gc_maxlifetime'   => 3600,
            'partial_timeout'  => 300,
        ],
    ];
};
