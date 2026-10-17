<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Próg ciszy klucza instalacji — po ilu godzinach bez ani jednego żądania connectora uznajemy,
 * że integracja stoi (zlecenie 21.09.2026 §2.2).
 *
 * ⚠ Klucz nie musi wygasnąć, żeby synchronizacja umarła: wystarczy wyłączona maszyna z connectorem,
 * zmienione hasło do programu kadrowego albo zablokowany port. Bez progu ekran pokazuje
 * „ostatnie użycie 3 dni temu” i nie mówi, że to awaria.
 *
 * Domyślnie 24 godziny. Wartość 0 wyłącza ostrzeżenie.
 *
 * Jedna zmiana = jedno wywołanie (CLAUDE.md §4).
 */
final class AddKeyIdleAlertHours extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(
            "INSERT INTO tenant_setting (`key`, value, updated_at) VALUES ('key_idle_alert_hours', '24', UTC_TIMESTAMP())"
        );
    }

    public function down(): void
    {
        $this->execute("DELETE FROM tenant_setting WHERE `key` = 'key_idle_alert_hours'");
    }
}
