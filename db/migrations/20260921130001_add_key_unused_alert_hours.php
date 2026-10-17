<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Osobny próg dla klucza WYDANEGO I NIEUŻYTEGO (rozstrzygnięcie 20.09.2026).
 *
 * ⚠ To nie to samo co cisza connectora: świeżo wydany klucz bywa wklejany dopiero następnego dnia
 * roboczego, a cisza DZIAŁAJĄCEGO connectora po dobie to już awaria. Jeden próg dla obu zjawisk
 * albo alarmowałby za wcześnie, albo milczał za długo.
 *
 * Domyślnie 72 godziny (cisza zostaje na 24). Wartość 0 wyłącza.
 *
 * Jedna zmiana = jedno wywołanie (CLAUDE.md §4).
 */
final class AddKeyUnusedAlertHours extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(
            "INSERT INTO tenant_setting (`key`, value, updated_at) VALUES ('key_unused_alert_hours', '72', UTC_TIMESTAMP())"
        );
    }

    public function down(): void
    {
        $this->execute("DELETE FROM tenant_setting WHERE `key` = 'key_unused_alert_hours'");
    }
}
