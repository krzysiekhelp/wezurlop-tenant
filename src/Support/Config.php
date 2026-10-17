<?php

declare(strict_types=1);

namespace Wezurlop\Support;

use InvalidArgumentException;

/**
 * Konfiguracja aplikacji — niezmienna, budowana raz na żądanie z config/config.php.
 */
final class Config
{
    /**
     * @param array{host: string, port?: int, name: string, user: string, password: string, ssl?: string, ssl_ca?: string} $db
     * @param array{user?: string, password?: string} $dbMig
     * @param array{server_profile?: string, server_software?: string, self_addr?: ?string, cron_minutes?: int, queue_on_request?: bool} $deploy
     * @param list<string> $trustedProxies
     * @param array{idle_timeout: int, absolute_timeout: int, gc_maxlifetime: int, partial_timeout: int} $session
     */
    public function __construct(
        public readonly string $appEnv,
        public readonly string $appUrl,
        public readonly string $basePath,
        public readonly string $frontController,
        public readonly string $assetVersion,
        public readonly string $storagePath,
        public readonly string $varPath,
        public readonly string $templatesPath,
        public readonly string $assetsPath,
        public readonly array $db,
        public readonly int $hstsMaxAge,
        public readonly bool $hstsIncludeSubdomains,
        public readonly bool $noindex,
        public readonly bool $adminTwoFactorRequired,
        public readonly array $trustedProxies,
        public readonly array $session,
        #[\SensitiveParameter] private readonly string $appKey,
        public readonly array $license = [],
        public readonly bool $dbSingleAccount = false,
        public readonly array $dbMig = [],
        public readonly array $deploy = [],
        public readonly array $calendar = [],
    ) {
        if (!in_array($db['ssl'] ?? 'auto', ['auto', 'required', 'disabled'], true)) {
            throw new InvalidArgumentException('DB_SSL musi być auto, required albo disabled');
        }
        if ($appUrl !== '' && preg_match('#^https?://[^/\s]+$#i', $appUrl) !== 1) {
            throw new InvalidArgumentException('APP_URL musi mieć postać http(s)://host, bez ścieżki');
        }
        if (!self::isValidBasePath($basePath)) {
            throw new InvalidArgumentException('APP_BASE_PATH musi mieć postać /katalog, bez końcowego ukośnika');
        }
        if (!self::isValidFrontController($frontController)) {
            throw new InvalidArgumentException('APP_FRONT_CONTROLLER musi być pusty albo mieć postać /index.php');
        }
        if (strlen($this->appKeyBytes()) !== 32) {
            throw new InvalidArgumentException('APP_KEY musi mieć postać base64:<32 bajty>');
        }
        // ⚠ Konfiguracji poczty tu NIE MA i to jest decyzja, nie przeoczenie (etap 7.2):
        // ustawienia SMTP siedzą w bazie (`Service\Mail\MailSettings`), a `MAIL_*` z `.env`
        // przestało być czytane. Powód praktyczny: przy sprawdzaniu w konstruktorze konfiguracji
        // brak `MAIL_HOST` wywracał START APLIKACJI — czyli niedokończona konfiguracja poczty
        // dawała biały ekran na każdym adresie, zamiast jednej niedziałającej funkcji.
        if ($hstsMaxAge < 0) {
            throw new InvalidArgumentException('HSTS_MAX_AGE nie może być ujemne');
        }
    }

    /** @param array<string, mixed> $values wynik config/config.php */
    public static function fromArray(array $values): self
    {
        return new self(
            (string) $values['app_env'],
            (string) $values['app_url'],
            (string) $values['base_path'],
            (string) $values['front_controller'],
            (string) $values['asset_version'],
            (string) $values['storage_path'],
            (string) $values['var_path'],
            (string) $values['templates_path'],
            (string) $values['assets_path'],
            $values['db'],
            (int) $values['hsts_max_age'],
            (bool) $values['hsts_include_subdomains'],
            (bool) ($values['noindex'] ?? true),
            (bool) ($values['admin_2fa_required'] ?? true),
            $values['trusted_proxies'],
            $values['session'],
            (string) $values['app_key'],
            $values['license'] ?? [],
            (bool) ($values['db_single_account'] ?? false),
            $values['db_mig'] ?? [],
            $values['deploy'] ?? [],
            $values['calendar'] ?? [],
        );
    }

    /**
     * Ile osób kontrolka kalendarza pokazuje bez zawężania (domyślnie 100).
     *
     * ⚠ To **limit wyświetlania**, nie filtr — i ta różnica jest całą treścią tej wartości.
     * Limitu nie wolno zapisać jako wyłączeń: filtr stałby się migawką i pracownik przyjęty
     * jutro nie pojawiłby się nikomu na kalendarzu (`docs/etap3-logika-urlopowa.md` §8b).
     *
     * ⚠ Domyślna wartość podniesiona z 30 na 100 — powód w `config/config.php`: przy trzydziestu
     * próg obcinał każdą spółkę w danych, więc „Zaznacz wszystkich (36)" kończyło się trzydziestką.
     */
    public function calendarPickerLimit(): int
    {
        $limit = $this->calendar['picker_limit'] ?? 100;

        return is_int($limit) && $limit > 0 ? $limit : 100;
    }

    /** Surowy klucz aplikacji (32 bajty). Nie trafia do logów ani szablonów. */
    public function appKeyBytes(): string
    {
        if (!str_starts_with($this->appKey, 'base64:')) {
            return '';
        }
        $decoded = base64_decode(substr($this->appKey, 7), true);

        return is_string($decoded) ? $decoded : '';
    }

    /**
     * Dane bazy w postaci oczekiwanej przez diagnostykę (klucze jak w .env).
     *
     * @return array<string, string>
     */
    public function dbEnv(): array
    {
        return [
            'APP_ENV' => $this->appEnv,
            'DB_HOST' => $this->db['host'],
            'DB_NAME' => $this->db['name'],
            'DB_USER' => $this->db['user'],
            'DB_PASS' => $this->db['password'],
            'DB_PORT' => (string) ($this->db['port'] ?? 3306),
            'DB_SSL'  => (string) ($this->db['ssl'] ?? 'auto'),
            'DB_SSL_CA' => (string) ($this->db['ssl_ca'] ?? ''),
            'DB_SINGLE_ACCOUNT' => $this->dbSingleAccount ? '1' : '0',
        ];
    }

    /** Host instalacji z APP_URL — do powiązania licencji z instalacją. */
    public function installationHost(): string
    {
        $host = parse_url($this->appUrl, PHP_URL_HOST);

        return is_string($host) ? strtolower($host) : '';
    }

    public function licenseClockPath(): string
    {
        return $this->varPath . '/license-clock';
    }

    public function mailDumpPath(): string
    {
        return $this->varPath . '/mail';
    }

    public function isDev(): bool
    {
        return $this->appEnv === 'dev';
    }

    public function logPath(): string
    {
        return $this->storagePath . '/logs';
    }

    public function installLockPath(): string
    {
        return $this->varPath . '/install.lock';
    }

    public static function isValidBasePath(string $basePath): bool
    {
        return $basePath === '' || preg_match('#^(/[A-Za-z0-9._~-]+)+$#', $basePath) === 1;
    }

    public static function isValidFrontController(string $frontController): bool
    {
        return $frontController === '' || preg_match('#^/[A-Za-z0-9_-]+\.php$#', $frontController) === 1;
    }

    /** Prefiks adresów tras: APP_BASE_PATH + APP_FRONT_CONTROLLER (rozdz. 3 — dwa prefiksy). */
    public function routePrefix(): string
    {
        return $this->basePath . $this->frontController;
    }

    /** Adres trasy — jedyne źródło adresów aplikacji w szablonach i przekierowaniach. */
    public function url(string $path): string
    {
        return $this->routePrefix() . '/' . ltrim($path, '/');
    }

    /** Pełny adres do wysłania poza przeglądarkę (e-mail) — wyłącznie z APP_URL, nigdy z nagłówka Host. */
    public function absoluteUrl(string $path): string
    {
        return $this->appUrl . $this->url($path);
    }
}
