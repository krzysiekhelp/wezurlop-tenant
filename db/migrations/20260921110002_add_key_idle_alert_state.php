<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Znacznik ostatniego alertu o ciszy klucza — alert ma pójść RAZ, nie co przebieg crona
 * (zlecenie 21.09.2026 §2.2). Ten sam wzorzec co `login_alert_last_at`: wiersz ustawienia
 * zajmowany warunkowym UPDATE-em, więc dwa równoległe przebiegi nie wyślą dwóch maili.
 *
 * Jedna zmiana = jedno wywołanie (CLAUDE.md §4).
 */
final class AddKeyIdleAlertState extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(
            "INSERT INTO tenant_setting (`key`, value, updated_at) VALUES ('key_idle_alert_last_at', '1970-01-01 00:00:00', UTC_TIMESTAMP())"
        );
    }

    public function down(): void
    {
        $this->execute("DELETE FROM tenant_setting WHERE `key` = 'key_idle_alert_last_at'");
    }
}
