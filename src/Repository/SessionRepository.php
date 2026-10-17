<?php

declare(strict_types=1);

namespace Wezurlop\Repository;

use Wezurlop\Db\Connection;

/**
 * Rejestr sesji (docs/szkielet-tenanta.md rozdz. 5). Wszystkie metody przyjmują HASH identyfikatora —
 * jawny identyfikator sesji nie trafia do bazy.
 *
 * @phpstan-type SessionRow array{id: string, account_id: ?int, payload: string, last_seen_at: string,
 *     expires_at: string, revoked_at: ?string, is_active: bool, seen_seconds_ago: int}
 */
final class SessionRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** @return SessionRow|null */
    public function find(string $idHash): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, account_id, payload, last_seen_at, expires_at, revoked_at,
                (revoked_at IS NULL AND expires_at > UTC_TIMESTAMP()) AS is_active,
                TIMESTAMPDIFF(SECOND, last_seen_at, UTC_TIMESTAMP()) AS seen_seconds_ago
             FROM session WHERE id = :id'
        );
        $stmt->execute(['id' => $idHash]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }

        return [
            'id'               => (string) $row['id'],
            'account_id'       => $row['account_id'] === null ? null : (int) $row['account_id'],
            'payload'          => (string) $row['payload'],
            'last_seen_at'     => (string) $row['last_seen_at'],
            'expires_at'       => (string) $row['expires_at'],
            'revoked_at'       => $row['revoked_at'] === null ? null : (string) $row['revoked_at'],
            'is_active'        => (bool) $row['is_active'],
            'seen_seconds_ago' => (int) $row['seen_seconds_ago'],
        ];
    }

    /**
     * Zapis danych sesji. Unieważniona sesja nie jest „wskrzeszana” zapisem.
     *
     * @param array{account_id: ?int, employment_id: ?int, client: string, app_version: ?string} $meta
     * @param array{ip_hash: ?string, ip_prefix: ?string, user_agent: ?string} $client
     */
    public function save(string $idHash, string $payload, array $meta, array $client, int $idleSeconds): void
    {
        // INSERT, a przy istniejącym wierszu UPDATE — bez ON DUPLICATE KEY UPDATE z VALUES()
        // (przestarzałe w MySQL 8, a składnia z aliasem nie działa w MariaDB).
        $params = [
            'account'    => $meta['account_id'],
            'employment' => $meta['employment_id'],
            'kind'       => $meta['client'] === 'app' ? 'app' : 'web',
            'app_ver'    => $meta['app_version'],
            'ip_hash'    => $client['ip_hash'],
            'ip_prefix'  => $client['ip_prefix'],
            'ua'         => $client['user_agent'],
            'idle'       => $idleSeconds,
            'id'         => $idHash,
        ];
        try {
            $insert = $this->db->pdo()->prepare(
                'INSERT INTO session (id, account_id, employment_id, client, app_version, payload, ip_hash, ip_prefix,
                    user_agent, created_at, last_seen_at, expires_at)
                 VALUES (:id, :account, :employment, :kind, :app_ver, :payload, :ip_hash, :ip_prefix, :ua,
                    UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP() + INTERVAL :idle SECOND)'
            );
            $this->bindAll($insert, $params, $payload);
            $insert->execute();

            return;
        } catch (\PDOException $e) {
            if ((string) $e->getCode() !== '23000') {
                throw $e;
            }
        }

        // Unieważniona sesja nie jest „wskrzeszana” zapisem.
        $update = $this->db->pdo()->prepare(
            'UPDATE session SET account_id = :account, employment_id = :employment, client = :kind,
                app_version = :app_ver, payload = :payload,
                ip_hash = :ip_hash, ip_prefix = :ip_prefix, user_agent = :ua,
                last_seen_at = UTC_TIMESTAMP(), expires_at = UTC_TIMESTAMP() + INTERVAL :idle SECOND
             WHERE id = :id AND revoked_at IS NULL'
        );
        $this->bindAll($update, $params, $payload);
        $update->execute();
    }

    /** @param array<string, string|int|null> $params */
    private function bindAll(\PDOStatement $stmt, array $params, string $payload): void
    {
        foreach ($params as $name => $value) {
            $stmt->bindValue($name, $value, match (true) {
                $value === null => \PDO::PARAM_NULL,
                is_int($value)  => \PDO::PARAM_INT,
                default         => \PDO::PARAM_STR,
            });
        }
        $stmt->bindValue('payload', $payload, \PDO::PARAM_LOB);
    }

    /** Odświeżenie aktywności bez zapisu danych (lazy_write) — najwyżej raz na minutę. */
    public function touch(string $idHash, int $idleSeconds): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE session SET last_seen_at = UTC_TIMESTAMP(), expires_at = UTC_TIMESTAMP() + INTERVAL :idle SECOND
             WHERE id = :id AND revoked_at IS NULL AND last_seen_at < UTC_TIMESTAMP() - INTERVAL 60 SECOND'
        );
        $stmt->execute(['idle' => $idleSeconds, 'id' => $idHash]);
    }

    public function delete(string $idHash): void
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM session WHERE id = :id');
        $stmt->execute(['id' => $idHash]);
    }

    /**
     * Aktywne sesje konta (lista urządzeń).
     *
     * @return list<array{id: string, created_at: string, last_seen_at: string, ip_prefix: ?string, user_agent: ?string, client: string, app_version: ?string}>
     */
    public function activeForAccount(int $accountId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, created_at, last_seen_at, ip_prefix, user_agent, client, app_version FROM session
             WHERE account_id = :account AND revoked_at IS NULL AND expires_at > UTC_TIMESTAMP()
             ORDER BY last_seen_at DESC'
        );
        $stmt->execute(['account' => $accountId]);

        return $stmt->fetchAll();
    }

    /** Zakończenie jednej sesji — właściciel sprawdzany w zapytaniu (IDOR). */
    public function revokeForAccount(string $idHash, int $accountId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE session SET revoked_at = UTC_TIMESTAMP()
             WHERE id = :id AND account_id = :account AND revoked_at IS NULL'
        );
        $stmt->execute(['id' => $idHash, 'account' => $accountId]);

        return $stmt->rowCount() === 1;
    }

    /** @return int liczba zakończonych sesji */
    public function revokeAllForAccount(int $accountId, ?string $exceptIdHash = null): int
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE session SET revoked_at = UTC_TIMESTAMP()
             WHERE account_id = :account AND revoked_at IS NULL AND id <> :except'
        );
        $stmt->execute(['account' => $accountId, 'except' => $exceptIdHash ?? '']);

        return $stmt->rowCount();
    }

    /** Sprzątanie porcjami: wygasłe i unieważnione starsze niż doba. */
    public function deleteStale(int $limit): int
    {
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM session
             WHERE expires_at < UTC_TIMESTAMP() - INTERVAL 1 DAY
                OR (revoked_at IS NOT NULL AND revoked_at < UTC_TIMESTAMP() - INTERVAL 1 DAY)
             LIMIT :limit'
        );
        $stmt->bindValue('limit', max(1, min($limit, 5000)), \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount();
    }
}
