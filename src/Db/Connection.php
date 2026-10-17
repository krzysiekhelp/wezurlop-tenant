<?php

declare(strict_types=1);

namespace Wezurlop\Db;

use PDO;

/**
 * Połączenie z bazą (CLAUDE.md §4), otwierane dopiero przy pierwszym użyciu.
 * Czas sesji bazy w UTC — wszystkie znaczniki czasu liczy baza (UTC_TIMESTAMP()).
 *
 * Dwa profile (docs/szkielet-tenanta.md rozdz. 12, krok 4):
 * - `data`   — praca aplikacji (DB_*). Zawsze z bezpiecznikiem DDL: polecenie zaczynające się od
 *              CREATE/ALTER/DROP/TRUNCATE/RENAME/GRANT/REVOKE jest odrzucane i zapisywane jako
 *              zdarzenie bezpieczeństwa. To ochrona przed NASZYM błędem, nie przed atakującym
 *              z poświadczeniami — realną obroną jest ATTR_EMULATE_PREPARES => false.
 * - `schema` — migracje (DB_MIG_*), wyłącznie instalator i aktualizator.
 *
 * TLS: gdy host bazy nie jest lokalny (DB_SSL=auto), połączenie jest szyfrowane. Z DB_SSL_CA —
 * z weryfikacją certyfikatu; bez CA — szyfrowanie bez weryfikacji: chroni przed podsłuchem,
 * nie przed aktywnym atakiem w sieci (CLAUDE.md §4).
 */
final class Connection
{
    public const PROFILE_DATA = 'data';
    public const PROFILE_SCHEMA = 'schema';

    public const SSL_AUTO = 'auto';
    public const SSL_REQUIRED = 'required';
    public const SSL_DISABLED = 'disabled';

    private ?PDO $pdo = null;

    /** @var (callable(string): void)|null */
    private $onDdlBlocked;

    /**
     * @param array{host: string, port?: int, name: string, user: string, password: string, ssl?: string, ssl_ca?: string} $credentials
     * @param (callable(string): void)|null $onDdlBlocked wywoływane przy zablokowanej próbie DDL (profil data)
     */
    public function __construct(
        #[\SensitiveParameter] private readonly array $credentials,
        private readonly string $profile = self::PROFILE_DATA,
        ?callable $onDdlBlocked = null,
    ) {
        $this->onDdlBlocked = $onDdlBlocked;
    }

    public function profile(): string
    {
        return $this->profile;
    }

    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
                PDO::ATTR_TIMEOUT            => 5,
                // Profil danych: blokada czeka najwyżej 10 s (domyślne 50 s przekracza budżet żądania ~30 s) —
                // zamiast przerwania przez limit czasu PHP jest wyjątek, który API zamienia na 503 z Retry-After.
                PDO::MYSQL_ATTR_INIT_COMMAND => $this->profile === self::PROFILE_DATA
                    ? "SET time_zone = '+00:00', innodb_lock_wait_timeout = 10"
                    : "SET time_zone = '+00:00'",
            ] + self::sslOptions(
                $this->credentials['host'],
                $this->credentials['ssl'] ?? self::SSL_AUTO,
                $this->credentials['ssl_ca'] ?? '',
            );
            $dsn = self::dsn($this->credentials);
            $this->pdo = $this->profile === self::PROFILE_DATA
                ? new GuardedPdo($dsn, $this->credentials['user'], $this->credentials['password'], $options, $this->onDdlBlocked)
                : new PDO($dsn, $this->credentials['user'], $this->credentials['password'], $options);
        }

        return $this->pdo;
    }

    /**
     * Operacja wielokrokowa w transakcji (CLAUDE.md §4).
     *
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    public function transaction(callable $operation): mixed
    {
        $pdo = $this->pdo();
        $pdo->beginTransaction();
        $committed = false;
        try {
            $result = $operation();
            $pdo->commit();
            $committed = true;

            return $result;
        } finally {
            if (!$committed && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }

    /** @param array{host: string, port?: int, name: string} $credentials */
    public static function dsn(array $credentials): string
    {
        $port = (int) ($credentials['port'] ?? 3306);

        return 'mysql:host=' . $credentials['host'] . ';port=' . ($port > 0 ? $port : 3306)
            . ';dbname=' . $credentials['name'] . ';charset=utf8mb4';
    }

    /** Host lokalny: bez TLS w trybie auto (ruch nie wychodzi z maszyny). */
    public static function isLocalHost(string $host): bool
    {
        $host = strtolower(trim($host, '[] '));

        return $host === '' || $host === 'localhost' || $host === '::1' || str_starts_with($host, '127.');
    }

    public static function wantsTls(string $host, string $sslMode): bool
    {
        return match ($sslMode) {
            self::SSL_REQUIRED => true,
            self::SSL_DISABLED => false,
            default            => !self::isLocalHost($host),
        };
    }

    /**
     * Te same opcje TLS w postaci konfiguracji phinx (klucze mysql_attr_*), dla migracji.
     *
     * @return array<string, mixed>
     */
    public static function phinxSslOptions(string $host, string $sslMode, string $caFile): array
    {
        $options = [];
        foreach (self::sslOptions($host, $sslMode, $caFile) as $attribute => $value) {
            $options[$attribute === PDO::MYSQL_ATTR_SSL_CA ? 'mysql_attr_ssl_ca' : 'mysql_attr_ssl_verify_server_cert'] = $value;
        }

        return $options;
    }

    /**
     * Opcje PDO włączające TLS. Sprawdzone na mysqlnd: samo VERIFY_SERVER_CERT nie włącza szyfrowania,
     * dopiero ustawienie SSL_CA (także pustego) — dlatego pusty CA oznacza „szyfruj bez weryfikacji”.
     *
     * @return array<int, mixed>
     */
    public static function sslOptions(string $host, string $sslMode, string $caFile): array
    {
        if (!self::wantsTls($host, $sslMode)) {
            return [];
        }
        if ($caFile !== '') {
            return [PDO::MYSQL_ATTR_SSL_CA => $caFile, PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => true];
        }

        return [PDO::MYSQL_ATTR_SSL_CA => '', PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false];
    }
}
