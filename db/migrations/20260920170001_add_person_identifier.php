<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * `person.r2_identifier` — numer kadrowy pracownika z programu kadrowego (pole `identyfikator`
 * w zbiorze `pracownik`). Przyjmowaliśmy je i walidowali, ale nigdzie nie zapisywali: do łączenia
 * osób służy `osobaId` (skrót z PESEL-u), a ten numer jest tym, czym ludzie posługują się w kadrach
 * (u klienta odniesienia: Krzysztof Siwiec → 397).
 *
 * ⚠ To dana **opisowa**: niczego po niej nie wyszukujemy i niczego nie dopasowujemy — dopasowanie
 * osób zostaje na `osobaId`, bo numer kadrowy bywa nadawany per firma i potrafi się powtórzyć.
 *
 * Jedna zmiana = jedno wywołanie (CLAUDE.md §4).
 */
final class AddPersonIdentifier extends AbstractMigration
{
    public function up(): void
    {
        $this->table('person')
            ->addColumn('r2_identifier', 'string', ['null' => true, 'limit' => 32, 'after' => 'external_person_id'])
            ->update();
    }

    public function down(): void
    {
        $this->table('person')->removeColumn('r2_identifier')->update();
    }
}
