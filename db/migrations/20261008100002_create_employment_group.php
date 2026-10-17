<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Przynależność zatrudnienia do grupy (zlecenie `2026-10-08-grupy-dzialy.md` §1).
 *
 * ⚠ **Osobna tabela, nie kolumna `group_id` w `employment`** — tak ustalił §8a. Zdjęcie
 * `UNIQUE (employment_id)` poszerza model na wiele grup **bez migracji danych**; kolumna
 * wymagałaby przepisania wszystkiego.
 *
 * ⚠ **`ON DELETE RESTRICT` na `group_id`.** Sprawdzenie „grupę da się usunąć tylko pustą" jest
 * w aplikacji — ale aplikację da się ominąć bezpośrednim SQL-em, a klucza nie. Dwie warstwy,
 * nie jedna; raport pokazuje obie (usterka wstrzyknięta w aplikacji → odmowa z klucza).
 *
 * ⚠ **`ON DELETE CASCADE` na `employment_id`**: zatrudnienie usunięte z bazy nie ma po sobie
 * zostawiać przynależności-widma. To inny przypadek niż **wygaszenie** zatrudnienia
 * (`is_active = 0`), które przynależności **nie czyści** (§8a) — i właśnie dlatego licznik
 * na ekranie musi mówić „12 osób (2 nieaktywne)", a nie „12 osób".
 *
 * ⚠ **Czego ten klucz NIE wyraża: zgodności spółki.** Nic tu nie broni przypisać zatrudnienia
 * ze spółki A do grupy spółki B — to musi być warunek w zapytaniu (`e.company_id = g.company_id`),
 * tak jak granica spółki w hierarchii i w kalendarzu.
 */
final class CreateEmploymentGroup extends AbstractMigration
{
    public function up(): void
    {
        $this->table('employment_group', ['id' => false, 'primary_key' => ['employment_id']])
            ->addColumn('employment_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('group_id', 'integer', ['signed' => false])
            ->addColumn('assigned_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('assigned_by', 'integer', ['signed' => false, 'null' => true])
            ->create();

        $this->table('employment_group')
            ->addIndex(['group_id'], ['name' => 'idx_employment_group_group'])
            ->update();

        $this->table('employment_group')
            ->addForeignKey('employment_id', 'employment', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->update();

        $this->table('employment_group')
            ->addForeignKey('group_id', 'company_group', 'id', ['delete' => 'RESTRICT', 'update' => 'NO_ACTION'])
            ->update();
    }

    public function down(): void
    {
        $this->table('employment_group')->drop()->save();
    }
}
