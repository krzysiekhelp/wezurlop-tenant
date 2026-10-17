<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Ustawienie okna wymiany klucza znika razem z samym oknem (20260921120001).
 * Zostają dwie operacje: wydanie nowego klucza (bez terminu) i unieważnienie.
 *
 * Jedna zmiana = jedno wywołanie (CLAUDE.md §4).
 */
final class DropKeyOverlapSetting extends AbstractMigration
{
    public function up(): void
    {
        $this->execute("DELETE FROM tenant_setting WHERE `key` = 'key_overlap_hours'");
    }

    public function down(): void
    {
        $this->execute(
            "INSERT INTO tenant_setting (`key`, value, updated_at) VALUES ('key_overlap_hours', '24', UTC_TIMESTAMP())"
        );
    }
}
