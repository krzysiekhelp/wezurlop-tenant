<?php

declare(strict_types=1);

namespace Wezurlop\Repository;

use Wezurlop\Db\Connection;

/**
 * Ustawienia tenanta (klucz → wartość). Kolumna `key` to słowo zastrzeżone — zawsze w `` ` ``.
 */
final class TenantSettingRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function get(string $key, string $default = ''): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT value FROM tenant_setting WHERE `key` = :key');
        $stmt->execute(['key' => $key]);
        $value = $stmt->fetchColumn();

        return is_string($value) && $value !== '' ? $value : $default;
    }

    public function set(string $key, string $value, ?int $updatedBy): void
    {
        $update = $this->db->pdo()->prepare(
            'UPDATE tenant_setting SET value = :value, updated_at = UTC_TIMESTAMP(), updated_by = :by WHERE `key` = :key'
        );
        $update->execute(['value' => $value, 'by' => $updatedBy, 'key' => $key]);
        if ($update->rowCount() === 0 && $this->get($key, "\0") === "\0") {
            $insert = $this->db->pdo()->prepare(
                'INSERT INTO tenant_setting (`key`, value, updated_at, updated_by) VALUES (:key, :value, UTC_TIMESTAMP(), :by)'
            );
            $insert->execute(['key' => $key, 'value' => $value, 'by' => $updatedBy]);
        }
    }

    /**
     * Zajęcie „slotu” alertu: najwyżej jeden na podany okres. Warunek w UPDATE rozstrzyga wyścig
     * równoległych żądań — alert wyśle tylko jedno z nich.
     */
    public function claimAlertSlot(string $key, int $periodSeconds): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "UPDATE tenant_setting SET value = DATE_FORMAT(UTC_TIMESTAMP(), '%Y-%m-%d %H:%i:%s'), updated_at = UTC_TIMESTAMP()
             WHERE `key` = :key
               AND value < DATE_FORMAT(UTC_TIMESTAMP() - INTERVAL :period SECOND, '%Y-%m-%d %H:%i:%s')"
        );
        $stmt->execute(['key' => $key, 'period' => $periodSeconds]);

        return $stmt->rowCount() === 1;
    }
}
