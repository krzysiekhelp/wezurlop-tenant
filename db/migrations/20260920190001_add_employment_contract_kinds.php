<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Rodzaje umów przy zatrudnieniu: `contract_employment` (o pracę), `contract_mandate` (zlecenie),
 * `contract_work` (o dzieło). R2 przysyła je w obiekcie `umowy` zbioru `pracownik` i przyjmowaliśmy
 * je od początku — bez zapisu.
 *
 * ⚠ To NIE jest jeden rodzaj do wyboru: osoba może mieć równolegle kilka, więc trzy kolumny bool,
 * a nie jedna kolumna tekstowa „rodzaj".
 * ⚠ `NOT NULL DEFAULT 0` zamiast dopuszczenia `NULL` (CLAUDE.md §4): brak danych ma znaczyć
 * „nie zaznaczono", a nie trzeci stan, którego nie umiemy pokazać.
 *
 * Kolumny przy zatrudnieniu, mimo że dane przychodzą w kartotece pracownika: flagi dotyczą
 * konkretnej firmy, a jedna osoba bywa zatrudniona w kilku. Wypełnia je ta sama propagacja
 * kartoteka → zatrudnienia, którą jedzie `dataZwolnienia`.
 *
 * Jedna zmiana = jedno wywołanie (CLAUDE.md §4).
 */
final class AddEmploymentContractKinds extends AbstractMigration
{
    public function up(): void
    {
        $this->table('employment')
            ->addColumn('contract_employment', 'boolean', ['null' => false, 'default' => 0, 'after' => 'fte'])
            ->update();

        $this->table('employment')
            ->addColumn('contract_mandate', 'boolean', ['null' => false, 'default' => 0, 'after' => 'contract_employment'])
            ->update();

        $this->table('employment')
            ->addColumn('contract_work', 'boolean', ['null' => false, 'default' => 0, 'after' => 'contract_mandate'])
            ->update();
    }

    public function down(): void
    {
        $this->table('employment')->removeColumn('contract_work')->update();
        $this->table('employment')->removeColumn('contract_mandate')->update();
        $this->table('employment')->removeColumn('contract_employment')->update();
    }
}
