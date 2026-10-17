<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Grupy (działy) — tabela grup (zlecenie `2026-10-08-grupy-dzialy.md` §1,
 * `docs/etap3-logika-urlopowa.md` §8a).
 *
 * ⚠ **Grupa jest etykietą, nie warstwą zatwierdzania** (decyzja Krzysztofa 08.10.2026). Dlatego
 * nie ma tu kolumny „kierownik": §8a mówi, że kierownik działu **nie jest** automatycznie
 * przełożonym, a gdyby pole istniało, na ekranie stałyby obok siebie dwie osoby odpowiedzialne
 * za tych samych ludzi — i ktoś założyłby, że to kierownik zatwierdza urlopy. Dodanie pola
 * później jest tanie; usunięcie, gdy coś już na nim stoi — nie.
 *
 * ⚠ **`UNIQUE (company_id, name)` jest NIECZUŁY na wielkość liter**, bo kolacja bazy to
 * `utf8mb4_unicode_ci` — i to jest zamierzone: „Produkcja" i „PRODUKCJA" w jednej firmie to dwa
 * wiersze, które na liście wyglądają jak jeden. ⚠ Zmierzone, nie założone (raport §4.3): przy
 * nazwach spółek ten sam mechanizm łapał część kolizji **przez przypadek**, a wyglądał
 * na ochronę ogólną — więc tutaj sprawdziliśmy go wprost.
 *
 * ⚠ **Grupa należy do SPÓŁKI, nie do tenanta.** Dział „Produkcja" w ALFA i „Produkcja"
 * w BETA to dwie różne grupy — tak samo jak hierarchia jest osobna dla każdej spółki.
 */
final class CreateCompanyGroup extends AbstractMigration
{
    public function up(): void
    {
        // Jedna zmiana = jedno wywołanie (CLAUDE.md §4: Phinx potrafi przestawić kolejność
        // operacji zebranych w jednym `update()`).
        $this->table('company_group', ['id' => true, 'signed' => false])
            ->addColumn('company_id', 'integer', ['signed' => false])
            ->addColumn('name', 'string', ['limit' => 100])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('created_by', 'integer', ['signed' => false, 'null' => true])
            ->create();

        $this->table('company_group')
            ->addIndex(['company_id', 'name'], ['unique' => true, 'name' => 'uq_company_group_name'])
            ->update();

        $this->table('company_group')
            ->addForeignKey('company_id', 'company', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->update();
    }

    public function down(): void
    {
        $this->table('company_group')->drop()->save();
    }
}
