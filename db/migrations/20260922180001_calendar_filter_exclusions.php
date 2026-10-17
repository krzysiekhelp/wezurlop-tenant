<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Zapamiętany filtr kalendarza zespołu przechowuje **WYŁĄCZENIA**, nie włączenia
 * (zlecenie `kalendarz-zespolu-domyslny-wybor` §1.3).
 *
 * ⚠ **Powód jest taki, że lista włączeń starzeje się po cichu.** Domyślnie widać wszystkich
 * w zasięgu, a kontrolka służy do zawężania — więc zapamiętana lista musi znaczyć „te osoby
 * odznaczyłem”, a nie „te wybrałem”. Przy liście włączeń pracownik przyjęty jutro **nie pojawi
 * się nikomu** na kalendarzu: jego identyfikatora nie ma w żadnym zapisanym wyborze, więc
 * przełożony nie zobaczy jego urlopu i nie ma czego zauważyć. To ta sama różnica co między
 * listą dozwolonych a listą zakazanych — i tu potrzebna jest ta druga, bo zbiór rośnie sam.
 *
 * ⚠ **Wiersze są czyszczone, nie przeliczane.** Zamiana „włączone” na „wyłączone” wymagałaby
 * odjęcia zapisanej listy od zasięgu **z chwili zapisu**, a tego zasięgu już nie znamy — hierarchia
 * mogła się w międzyczasie zmienić. Przeliczenie dałoby więc wyłączenia wzięte z powietrza,
 * a każde zgadnięcie tutaj oznacza kogoś ukrytego na cudzym kalendarzu bez jego wiedzy.
 * Czyszczenie resetuje filtr do stanu „widać wszystkich”, czyli do stanu bezpiecznego
 * i domyślnego. Tabela powstała 22.09.2026 tego samego dnia i **nie wyszła do żadnej instalacji**,
 * więc jedyne wiersze do skasowania to wiersze z pomiarów na maszynie deweloperskiej.
 *
 * ⚠ **Jedna zmiana = jedno wywołanie** (`CLAUDE.md` §4): najpierw czyszczenie treści, potem
 * zmiana nazwy kolumny. Phinx potrafi przestawić operacje zebrane w jednym `update()`,
 * a wtedy migracja przerywa się w połowie.
 */
final class CalendarFilterExclusions extends AbstractMigration
{
    public function up(): void
    {
        $this->execute('DELETE FROM calendar_filter');
        $this->table('calendar_filter')
            ->renameColumn('employment_ids', 'excluded_ids')
            ->update();
        $this->table('calendar_filter')
            ->changeColumn('excluded_ids', 'json', [
                'null' => false,
                'comment' => 'Zatrudnienia ODZNACZONE przez użytkownika — reszta zasięgu jest widoczna',
            ])
            ->update();
    }

    public function down(): void
    {
        $this->execute('DELETE FROM calendar_filter');
        $this->table('calendar_filter')
            ->renameColumn('excluded_ids', 'employment_ids')
            ->update();
    }
}
