<?php

declare(strict_types=1);

namespace Wezurlop\Repository;

use Wezurlop\Db\Connection;

final class BackupCodeRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** @param list<string> $hashes */
    public function replaceAll(int $accountId, array $hashes): void
    {
        $this->deleteAll($accountId);
        $stmt = $this->db->pdo()->prepare('INSERT INTO backup_code (account_id, code_hash) VALUES (:account, :hash)');
        foreach ($hashes as $hash) {
            $stmt->execute(['account' => $accountId, 'hash' => $hash]);
        }
    }

    public function deleteAll(int $accountId): void
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM backup_code WHERE account_id = :account');
        $stmt->execute(['account' => $accountId]);
    }

    /** @return list<array{id: int, code_hash: string}> */
    public function unused(int $accountId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, code_hash FROM backup_code WHERE account_id = :account AND used_at IS NULL ORDER BY id'
        );
        $stmt->execute(['account' => $accountId]);

        return array_map(
            static fn (array $row): array => ['id' => (int) $row['id'], 'code_hash' => (string) $row['code_hash']],
            $stmt->fetchAll(),
        );
    }

    /** Zużycie kodu; warunek w UPDATE rozstrzyga wyścig dwóch równoległych żądań. */
    public function markUsed(int $id, int $accountId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE backup_code SET used_at = UTC_TIMESTAMP() WHERE id = :id AND account_id = :account AND used_at IS NULL'
        );
        $stmt->execute(['id' => $id, 'account' => $accountId]);

        return $stmt->rowCount() === 1;
    }

    public function countUnused(int $accountId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM backup_code WHERE account_id = :account AND used_at IS NULL'
        );
        $stmt->execute(['account' => $accountId]);

        return (int) $stmt->fetchColumn();
    }
}
