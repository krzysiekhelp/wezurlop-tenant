<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Osoby po stronie **CZYJE NIEOBECNOŚCI** — wskazani pracownicy, a nie cała firma i nie grupy
 * (zlecenie Z53, dopisek Krzysztofa z 19.10.2026 po prototypie).
 *
 * ⚠ **Wskazujemy ZATRUDNIENIE, nie osobę.** Hierarchia, grupy i nieobecności wiszą na
 * zatrudnieniu, a jedna osoba miewa etat w dwóch firmach — reguła należy do firmy, więc musi
 * wskazywać ten etat, który w tej firmie istnieje. To ta sama decyzja, co przy przełożonym.
 *
 * ⚠ **Klucz obcy JEST na `rule_id` i nie ma go na `employment_id`** — tak samo jak przy grupach
 * celu, ale z odwrotnym powodem. Przy grupach brak klucza był po to, żeby usunięcie grupy
 * przeszło przez kod i trafiło do dziennika. Tutaj **nie sprzątamy nic** (decyzja Krzysztofa
 * z 19.10.2026, zmiana wcześniejszego polecenia): zwolnienie przychodzi z synchronizacji i jest
 * już zdarzeniem w dzienniku, a drugi wpis o skutku ubocznym niczego nie dodaje. Dostęp znika
 * sam, bo **każde zapytanie o zatrudnienia bierze wyłącznie czynne** — wiersz w tej tabeli
 * zostaje i służy już tylko do tego, żeby ekran mógł pokazać nazwisko przekreślone.
 * ⚠ Kaskada z `employment` byłaby więc tu szkodliwa: skasowałaby ślad po kimś, kogo
 * administrator ma zobaczyć na liście reguły, zanim zdecyduje, co z nią zrobić.
 *
 * ⚠ `UNIQUE (rule_id, employment_id)` przez klucz główny — ta sama osoba nie wejdzie do reguły
 * dwa razy, inaczej stopka „wybrano N osób" liczyłaby ją podwójnie.
 *
 * Jedna zmiana = jedno wywołanie (CLAUDE.md §4).
 */
final class CreateCalendarAccessRulePerson extends AbstractMigration
{
    public function up(): void
    {
        $this->table('calendar_access_rule_person', ['id' => false, 'primary_key' => ['rule_id', 'employment_id']])
            ->addColumn('rule_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('employment_id', 'integer', ['signed' => false, 'null' => false])
            ->create();

        $this->table('calendar_access_rule_person')
            ->addIndex(['employment_id'], ['name' => 'idx_carp_employment'])
            ->update();

        $this->table('calendar_access_rule_person')
            ->addForeignKey('rule_id', 'calendar_access_rule', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->update();
    }

    public function down(): void
    {
        $this->table('calendar_access_rule_person')->drop()->save();
    }
}
