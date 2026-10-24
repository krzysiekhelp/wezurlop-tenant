<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Znacznik ostatniego alertu „osoba wróciła do pracy, ale brak wolnych licencji" (zlecenie Z87).
 *
 * ⚠ Po co znacznik, skoro zlecenie mówi „jeden alert na przebieg synchronizacji": przebieg
 * zdarza się **co kilka minut**, a brak licencji nie znika sam. Bez odstępu ta sama wiadomość
 * szłaby na skrzynkę alertową kilkanaście razy na godzinę i nauczyłaby czytelnika ją kasować.
 * Mechanizm jest ten sam, co przy ciszy connectora (`key_idle_alert_last_at`) i przy alercie
 * logowań (`login_alert_last_at`) — `claimAlertSlot()` jest atomowym `UPDATE`, więc dwa
 * równoległe przebiegi nie wyślą dwóch listów.
 *
 * ⚠ Wartość `1970-01-01` znaczy „jeszcze nigdy", więc pierwszy przebieg z odmową wyśle alert
 * od razu. Wiersz MUSI istnieć, bo `claimAlertSlot()` to `UPDATE`, a nie `INSERT`.
 *
 * Jedna zmiana = jedno wywołanie (CLAUDE.md §4).
 */
final class AddAutoUnblockAlertState extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(
            "INSERT INTO tenant_setting (`key`, value, updated_at) VALUES ('auto_unblock_alert_last_at', '1970-01-01 00:00:00', UTC_TIMESTAMP())"
        );
    }

    public function down(): void
    {
        $this->execute("DELETE FROM tenant_setting WHERE `key` = 'auto_unblock_alert_last_at'");
    }
}
