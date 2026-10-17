<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Zapamiętany filtr kalendarza wisi na parze **(konto, SPÓŁKA)**, a nie (konto, zatrudnienie).
 *
 * ⚠ **Powód wyszedł z nowej funkcji, nie z gustu.** Od 23.09.2026 kalendarz spółki oglądają także
 * konta **bez zatrudnienia** (administrator z nadanym poziomem widoczności — `etap3` §8b). Takie
 * konto nie ma `employment_id`, więc przy dotychczasowym kluczu jego zawężenie albo nie dałoby się
 * zapisać (klucz obcy do `employment`), albo trzeba by wpisać sztuczne `0` i **rozbroić ten klucz
 * obcy dla wszystkich** — czyli zapłacić gwarancją spójności za jedno pole.
 *
 * ⚠ Nowy klucz jest też **bliższy temu, czym ten filtr jest**: „co ten człowiek chce widzieć,
 * patrząc na tę spółkę". Osoba zatrudniona w dwóch spółkach nadal ma dwa niezależne filtry,
 * bo to dwie spółki — a nie dlatego, że ma dwa zatrudnienia.
 *
 * ⚠ **Wiersze czyszczone, nie przeliczane** — ta sama decyzja co przy zamianie włączeń
 * na wyłączenia (`20260922180001`) i z tego samego powodu: przepisanie `employment_id` na
 * `company_id` wymagałoby zgadnięcia, którą spółkę ten człowiek wtedy oglądał. Każde zgadnięcie
 * tutaj to ktoś ukryty na cudzym kalendarzu bez jego wiedzy. Filtr wraca do stanu domyślnego,
 * czyli „widać wszystkich" — bezpiecznego. Tabela powstała 22.09.2026 i **nie wyszła do żadnej
 * instalacji**.
 *
 * ⚠ **Jedna zmiana = jedno wywołanie** (`CLAUDE.md` §4): usunięcie klucza obcego, zmiana nazwy
 * kolumny i nowy klucz obcy to trzy osobne `update()`. Phinx potrafi przestawić operacje zebrane
 * razem, a wtedy klucz obcy trafiłby na kolumnę o starej nazwie i migracja przerwałaby się
 * w połowie.
 */
final class CalendarFilterPerCompany extends AbstractMigration
{
    public function up(): void
    {
        $this->execute('DELETE FROM calendar_filter');
        $this->table('calendar_filter')->dropForeignKey('employment_id')->update();
        $this->table('calendar_filter')->renameColumn('employment_id', 'company_id')->update();
        $this->table('calendar_filter')
            ->addForeignKey('company_id', 'company', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE'])
            ->update();
    }

    public function down(): void
    {
        $this->execute('DELETE FROM calendar_filter');
        $this->table('calendar_filter')->dropForeignKey('company_id')->update();
        $this->table('calendar_filter')->renameColumn('company_id', 'employment_id')->update();
        $this->table('calendar_filter')
            ->addForeignKey('employment_id', 'employment', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE'])
            ->update();
    }
}
