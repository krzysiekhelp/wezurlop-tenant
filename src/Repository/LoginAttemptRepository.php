<?php

declare(strict_types=1);

namespace Wezurlop\Repository;

use Wezurlop\Db\Connection;

/**
 * Próby logowania (i pokrewne: kod 2FA, kod zapasowy, reset). Liczniki w bazie, nie w sesji —
 * atakujący sesji nie ma (CLAUDE.md §9).
 */
final class LoginAttemptRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function record(?int $accountId, string $ipHash, string $kind, bool $succeeded): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO login_attempt (account_id, ip_hash, kind, succeeded, created_at)
             VALUES (:account, :ip, :kind, :ok, UTC_TIMESTAMP())'
        );
        $stmt->execute(['account' => $accountId, 'ip' => $ipHash, 'kind' => $kind, 'ok' => $succeeded ? 1 : 0]);
    }

    /**
     * Nieudane próby konta w oknie, liczone od ostatniej udanej próby tego rodzaju i od ostatniej
     * zmiany hasła (po resecie konto działa od razu).
     *
     * @return array{count: int, last_seconds_ago: ?int}
     */
    public function accountFailures(int $accountId, string $kind, int $minutes): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) AS n, TIMESTAMPDIFF(SECOND, MAX(f.created_at), UTC_TIMESTAMP()) AS ago
             FROM login_attempt f
             WHERE f.account_id = :account AND f.kind = :kind AND f.succeeded = 0
               AND f.created_at > UTC_TIMESTAMP() - INTERVAL :minutes MINUTE
               AND f.created_at >= COALESCE((
                   SELECT MAX(s.created_at) FROM login_attempt s
                   WHERE s.account_id = :account2 AND s.kind = :kind2 AND s.succeeded = 1
               ), '1970-01-01')
               AND f.created_at >= COALESCE((
                   SELECT DATE_FORMAT(a.password_changed_at, '%Y-%m-%d %H:%i:%s') FROM account a WHERE a.id = :account3
               ), '1970-01-01')"
        );
        $stmt->execute([
            'account'  => $accountId,
            'kind'     => $kind,
            'minutes'  => $minutes,
            'account2' => $accountId,
            'kind2'    => $kind,
            'account3' => $accountId,
        ]);

        return $this->counter($stmt->fetch());
    }

    /**
     * Nieudane próby z adresu. Udana próba licznika nie zeruje (inaczej jedno poprawne konto
     * zerowałoby limit adresu).
     *
     * @return array{count: int, last_seconds_ago: ?int}
     */
    public function ipFailures(string $ipHash, string $kind, int $minutes): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) AS n, TIMESTAMPDIFF(SECOND, MAX(created_at), UTC_TIMESTAMP()) AS ago
             FROM login_attempt
             WHERE ip_hash = :ip AND kind = :kind AND succeeded = 0
               AND created_at > UTC_TIMESTAMP() - INTERVAL :minutes MINUTE'
        );
        $stmt->execute(['ip' => $ipHash, 'kind' => $kind, 'minutes' => $minutes]);

        return $this->counter($stmt->fetch());
    }

    /** Nieudane próby w całej instalacji — trzeci licznik (password spraying). */
    public function tenantFailures(string $kind, int $minutes): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM login_attempt
             WHERE kind = :kind AND succeeded = 0 AND created_at > UTC_TIMESTAMP() - INTERVAL :minutes MINUTE'
        );
        $stmt->execute(['kind' => $kind, 'minutes' => $minutes]);

        return (int) $stmt->fetchColumn();
    }

    /** @return array{count: int, last_seconds_ago: ?int} */
    private function counter(mixed $row): array
    {
        return [
            'count'            => is_array($row) ? (int) $row['n'] : 0,
            'last_seconds_ago' => is_array($row) && $row['ago'] !== null ? (int) $row['ago'] : null,
        ];
    }

    /** Wszystkie próby danego rodzaju (także udane) — dla limitu żądań resetu hasła. */
    public function accountRequests(int $accountId, string $kind, int $minutes): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM login_attempt
             WHERE account_id = :account AND kind = :kind AND created_at > UTC_TIMESTAMP() - INTERVAL :minutes MINUTE'
        );
        $stmt->execute(['account' => $accountId, 'kind' => $kind, 'minutes' => $minutes]);

        return (int) $stmt->fetchColumn();
    }

    public function ipRequests(string $ipHash, string $kind, int $minutes): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM login_attempt
             WHERE ip_hash = :ip AND kind = :kind AND created_at > UTC_TIMESTAMP() - INTERVAL :minutes MINUTE'
        );
        $stmt->execute(['ip' => $ipHash, 'kind' => $kind, 'minutes' => $minutes]);

        return (int) $stmt->fetchColumn();
    }
}
