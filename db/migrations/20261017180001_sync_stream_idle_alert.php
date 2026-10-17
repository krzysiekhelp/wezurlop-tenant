<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Znacznik ostatniego alertu o ciszy JEDNEJ FIRMY (zlecenie Z18, 17.10.2026).
 *
 * ⚠ Dlaczego kolumna w `sync_stream`, a nie wiersz w `tenant_setting` jak przy ciszy klucza:
 * znaczników per firma nie da się założyć migracją, bo firmy powstają po instalacji. Zakładanie
 * ich w locie znaczyłoby wiersze ustawień tworzone przez zadanie cron, z nazwami zawierającymi
 * identyfikator — a `sync_stream` ma już wiersz na firmę i trzyma dokładnie ten rodzaj stanu
 * (kursor, przegląd, ostatni stan connectora). Zajęcie slotu robi ten sam warunkowy UPDATE,
 * co `TenantSettingRepository::claimAlertSlot()`.
 *
 * Wartość `1970-01-01 00:00:00` znaczy „alertu jeszcze nie było" i jest tą samą stałą, której
 * używa znacznik ciszy klucza — porównanie `value < teraz - próg` działa wtedy bez wyjątków.
 *
 * Jedna zmiana = jedno wywołanie (CLAUDE.md §4).
 */
final class SyncStreamIdleAlert extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(
            "ALTER TABLE sync_stream ADD COLUMN idle_alert_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00'
             COMMENT 'ostatni alert o ciszy tej firmy; 1970-01-01 = nie było' AFTER last_review_at"
        );
    }

    public function down(): void
    {
        $this->execute('ALTER TABLE sync_stream DROP COLUMN idle_alert_at');
    }
}
