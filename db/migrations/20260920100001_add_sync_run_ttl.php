<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Czas życia przebiegu synchronizacji (docs/api-connector.md 3.1): przebieg bez żadnego żądania wygasa.
 * Wartość w tenant_setting, nie w kodzie — u klienta z rzadkim cronem albo wolnym programem kadrowym
 * może wymagać innej niż domyślne 30 minut.
 *
 * Jedna zmiana = jedno wywołanie (CLAUDE.md §4).
 */
final class AddSyncRunTtl extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(
            "INSERT INTO tenant_setting (`key`, value, updated_at) VALUES ('sync_run_ttl_minutes', '30', UTC_TIMESTAMP())"
        );
    }

    public function down(): void
    {
        $this->execute("DELETE FROM tenant_setting WHERE `key` = 'sync_run_ttl_minutes'");
    }
}
