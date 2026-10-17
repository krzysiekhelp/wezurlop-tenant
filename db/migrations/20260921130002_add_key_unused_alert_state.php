<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Znacznik ostatniego alertu o kluczu wydanym i nieużytym — wiadomość ma pójść RAZ na epizod,
 * tym samym mechanizmem co alert o ciszy connectora (`key_idle_alert_last_at`).
 *
 * Jedna zmiana = jedno wywołanie (CLAUDE.md §4).
 */
final class AddKeyUnusedAlertState extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(
            "INSERT INTO tenant_setting (`key`, value, updated_at) VALUES ('key_unused_alert_last_at', '1970-01-01 00:00:00', UTC_TIMESTAMP())"
        );
    }

    public function down(): void
    {
        $this->execute("DELETE FROM tenant_setting WHERE `key` = 'key_unused_alert_last_at'");
    }
}
