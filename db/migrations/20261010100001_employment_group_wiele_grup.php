<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Pracownik może należeć do **wielu grup** (decyzja Krzysztofa 10.10.2026).
 *
 * ⚠ **To zmiana rozstrzygnięcia**, nie poprawka: zlecenie `2026-10-08-grupy-dzialy.md` §1
 * mówiło `UNIQUE (employment_id)` — jedna grupa na zatrudnienie — i na tym stało §2b
 * (krok potwierdzenia przeniesienia). Oba przestają obowiązywać: przypisanie do grupy
 * **nic nie odbiera**, więc nie ma z czego przenosić i nie ma czego potwierdzać.
 *
 * ⚠ **Klucz główny na PARZE, nie zdjęty.** Samo usunięcie klucza pozwoliłoby wstawić ten sam
 * wiersz dwa razy — a wtedy licznik „osób w grupie" liczy jedną osobę dwa razy i nikt nie wie,
 * dlaczego grupa ma 4 osoby przy 3 nazwiskach. `PRIMARY KEY (employment_id, group_id)` daje
 * dokładnie tę regułę, której potrzebujemy: **jedna przynależność na parę**, dowolnie wiele par.
 *
 * ⚠ **`down()` nie usuwa danych i ma prawo paść.** Powrót do jednej grupy na zatrudnienie jest
 * możliwy tylko wtedy, gdy nikt nie jest w dwóch — a skoro to zmiana modelu, a nie literówki,
 * rollback po wprowadzeniu drugiej przynależności musi **odmówić z błędu klucza**, nie wybrać
 * za człowieka, którą przynależność skasować. Na czystej bazie (`migrate → rollback → migrate`,
 * warunek odbioru z `CLAUDE.md` §4) nie ma wierszy, więc przechodzi.
 */
final class EmploymentGroupWieleGrup extends AbstractMigration
{
    public function up(): void
    {
        // Jedna zmiana = jedno wywołanie (CLAUDE.md §4).
        $this->table('employment_group')
            ->changePrimaryKey(['employment_id', 'group_id'])
            ->update();
    }

    public function down(): void
    {
        $this->table('employment_group')
            ->changePrimaryKey(['employment_id'])
            ->update();
    }
}
