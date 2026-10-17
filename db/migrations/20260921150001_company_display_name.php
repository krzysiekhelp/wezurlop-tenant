<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Nazwa wyświetlana spółki (zlecenie `2026-09-21-konta-lista-dostep-i-nazwy.md` §8).
 *
 * Powód: `BETA SPÓŁKA Z OGRANICZONĄ ODPOWIEDZIALNOŚCIĄ` nie mieści się w żadnej kolumnie ani
 * w kontrolce filtra, a skracać w locie nie ma czego — skrót musi wybrać człowiek. Nazwa z R2
 * (`company.name`) zostaje nietknięta i tylko do odczytu; ta kolumna jest **nasza**.
 *
 * ⚠ **`NULL`, nie `NOT NULL DEFAULT ''`** — odstępstwo od litery §8 zlecenia, zmierzone przed
 * zbudowaniem. Zlecenie chce jednocześnie trzech rzeczy: (1) nazwa unikalna w tenancie,
 * (2) pusta wartość znaczy „pokazuj nazwę z R2", (3) nowa spółka z R2 pojawia się z pustą nazwą
 * i **niczego nie blokuje**. Przy `''` i `UNIQUE` te trzy rzeczy są sprzeczne — zmierzone
 * na tabeli tymczasowej:
 *
 *     NOT NULL DEFAULT '' + UNIQUE: druga spółka z pustą nazwą → 1062 Duplicate entry ''
 *     NULL + UNIQUE:                dowolnie wiele pustych, a „Beta" drugi raz → odbity
 *
 * Czyli przy `''` druga spółka przyjęta z R2 wywróciłaby synchronizację. `NULL` znaczy tu
 * **brak wartości** (nie wartość specjalną), a `UNIQUE` pilnuje dokładnie tego, co ma pilnować:
 * nazw prawdziwych. To ta sama sytuacja co `account.email` w migracji `20260921140001`
 * i ten sam wyjątek od CLAUDE.md §4, z tego samego powodu.
 *
 * ⚠ Synchronizacja tej kolumny **nie dotyka** i nic z niej nie wysyła do R2 — pilnuje tego
 * `RecordApplier`, który wymienia kolumny spółki jawnie.
 */
final class CompanyDisplayName extends AbstractMigration
{
    public function up(): void
    {
        // Jedna zmiana = jedno wywołanie (CLAUDE.md §4).
        $this->table('company')
            ->addColumn('display_name', 'string', [
                'limit'   => 200,
                'null'    => true,
                'after'   => 'name',
                'comment' => 'Nasza krótka nazwa; NULL = pokazuj nazwę z R2',
            ])
            ->update();

        $this->table('company')
            ->addIndex(['display_name'], ['unique' => true, 'name' => 'uq_company_display_name'])
            ->update();
    }

    public function down(): void
    {
        $this->table('company')->removeIndexByName('uq_company_display_name')->update();
        $this->table('company')->removeColumn('display_name')->update();
    }
}
