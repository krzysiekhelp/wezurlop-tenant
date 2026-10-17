<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Szczyt hierarchii jako **jawna decyzja człowieka** (zlecenie `hierarchia` §5).
 *
 * ⚠ **„Brak przełożonego" to dwa różne stany i muszą być rozróżnialne.** Prezes nie podlega
 * nikomu — to ustalenie. Magazynier przyjęty wczoraj też nie podlega nikomu — to robota
 * do zrobienia. Bez tej kolumny lista „czyich wniosków nie ma kto zatwierdzić" byłaby
 * w połowie szumem i przestałaby być czytana, co jest gorsze, niż gdyby jej nie było.
 *
 * Stany czyta się tak:
 *
 * ```
 * nie ustawiono :  supervisor_employment_id IS NULL     AND is_hierarchy_root = 0
 * szczyt        :  supervisor_employment_id IS NULL     AND is_hierarchy_root = 1
 * podlega       :  supervisor_employment_id IS NOT NULL
 * ```
 *
 * ⚠ `supervisor_employment_id` **zostaje `NULL`-owalne i nietknięte**, razem z kluczem obcym
 * `employment_ibfk_3 → employment.id ON DELETE SET NULL`. Pierwsza wersja zlecenia kazała
 * zmienić je na `NOT NULL DEFAULT 0`; pomiar schematu pokazał, że wartość `0` nie przejdzie
 * przez ten klucz (nie ma wiersza o `id = 0`), a `SET NULL` nie może stać na kolumnie
 * `NOT NULL` — czyli tamten wariant wymagał usunięcia działającego klucza obcego.
 * Rozstrzygające było jednak coś innego: **ta flaga jest potrzebna tak czy tak**, bo „szczyt"
 * i „nie ustawiono" mają tę samą wartość w kolumnie przełożonego w obu wariantach. Gdy flaga
 * istnieje, kolumna przełożonego ma jedno znaczenie i nie ma czego rozdzielać.
 *
 * ⚠ **Jedna zmiana = jedno wywołanie `update()`** (`CLAUDE.md` §4): Phinx potrafi przestawić
 * kolejność operacji zebranych w jednym wywołaniu, a wtedy migracja przerywa się w połowie.
 *
 * ⚠ **`CHECK` w bazie jest NIEMOŻLIWY na tej kolumnie — zmierzone, nie wyczytane.** Zlecenie
 * §5.1 kazało dołożyć `CHECK (is_hierarchy_root = 0 OR supervisor_employment_id IS NULL)`
 * jako drugą warstwę. MySQL 8.4.11 odrzuca to wprost:
 *
 * ```
 * 3823 Column 'supervisor_employment_id' cannot be used in a check constraint
 *      'chk_employment_hierarchy_root': needed in a foreign key constraint
 *      'employment_ibfk_3' referential action.
 * ```
 *
 * Czyli **ograniczenie `CHECK` i klucz obcy z akcją (`ON DELETE SET NULL`) wykluczają się
 * na tej samej kolumnie** — ta sama kolizja co przy `NOT NULL DEFAULT 0`, tylko z drugiej
 * strony. Klucz obcy zostaje, bo gwarancja „przełożony istnieje" jest warta więcej niż
 * druga warstwa dla warunku, który i tak sprawdzamy przy zapisie. Zamiast `CHECK`:
 * walidacja w serwisie (obowiązkowa tak czy tak, `CLAUDE.md` §10) **plus wykrywacz
 * w `/diagnostyka`**, który liczy wiersze łamiące ten warunek — bo warunek niepilnowany
 * przez bazę musi mieć coś, co go mierzy, a nie tylko kod, który obiecuje go nie łamać.
 */
final class EmploymentHierarchyRoot extends AbstractMigration
{
    public function up(): void
    {
        $this->table('employment')
            ->addColumn('is_hierarchy_root', 'boolean', [
                'null'    => false,
                'default' => false,
                'after'   => 'supervisor_employment_id',
                'comment' => 'Szczyt hierarchii — jawna decyzja, nie brak ustawienia',
            ])
            ->update();
    }

    public function down(): void
    {
        $this->table('employment')->removeColumn('is_hierarchy_root')->update();
    }
}
