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
    /**
     * **Zajęcie okienka czasu dla czynności, która ma się nie powtarzać** — wiadomość próbna
     * (zlecenie Z57 U7). `true` = wolno działać, `false` = odstęp jeszcze nie minął.
     *
     * ⚠ **Jedno zapytanie, bez odczytu przed zapisem** — i to jest cały sens tej metody.
     * Zgłoszenie Krzysztofa brzmiało „przycisk zostaje czynny, więc da się kliknąć wiele razy":
     * dwa kliknięcia pod rząd to dwa żądania w tej samej sekundzie, a wzorzec „sprawdź, potem
     * zapisz" przepuściłby oba, bo oba przeczytałyby ten sam stan. Rozstrzyga baza, jednym
     * poleceniem.
     *
     * ⚠ W odróżnieniu od `claimAlertSlot()` wiersz nie musi wcześniej istnieć: `INSERT` z klauzulą
     * `ON DUPLICATE KEY` zakłada go przy pierwszym użyciu, więc nie potrzeba migracji z zasiewem.
     * Liczba wierszy zmienionych rozstrzyga: 1 (wstawiony) albo 2 (zaktualizowany) = zajęte,
     * 0 = odstęp nie minął i nic nie ruszyliśmy.
     *
     * ⚠ Ten sam `:okres` pod dwiema nazwami — PDO bez emulacji nie pozwala użyć jednego
     * placeholdera dwa razy (HY093).
     */
    public function claimSlot(string $key, int $periodSeconds): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "INSERT INTO tenant_setting (`key`, value, updated_at)
             VALUES (:key, DATE_FORMAT(UTC_TIMESTAMP(), '%Y-%m-%d %H:%i:%s'), UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
               updated_at = IF(value < DATE_FORMAT(UTC_TIMESTAMP() - INTERVAL :okres SECOND, '%Y-%m-%d %H:%i:%s'),
                               UTC_TIMESTAMP(), updated_at),
               value = IF(value < DATE_FORMAT(UTC_TIMESTAMP() - INTERVAL :okres2 SECOND, '%Y-%m-%d %H:%i:%s'),
                          DATE_FORMAT(UTC_TIMESTAMP(), '%Y-%m-%d %H:%i:%s'), value)"
        );
        $stmt->execute(['key' => $key, 'okres' => $periodSeconds, 'okres2' => $periodSeconds]);

        return $stmt->rowCount() > 0;
    }

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
