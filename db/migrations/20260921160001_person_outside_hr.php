<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * „Spoza kadr" jako własna kolumna (zlecenie `konta-budowa-2` §3, etap 4).
 *
 * ⚠ **Trwale, a nie przez brak wiersza w innej tabeli.** Dotąd jedyną przesłanką było to, że
 * osoba NIE MA wiązania w `person_external_ref` — czyli oświadczenie wyprowadzone z nieobecności
 * danych. To ta sama klasa błędu co powód blokady wyprowadzany z dziennika: brak wiersza znaczy
 * „nie wiem", a nie „ktoś tak postanowił".
 *
 * ⚠ `NOT NULL DEFAULT 0`, bez `NULL` — tu reguła z CLAUDE.md §4 obowiązuje **wprost**, nie jako
 * wyjątek: kolumna ma znaczyć „tak albo nie", a nie „brak wartości", i każdy istniejący wiersz
 * ma sensowną wartość domyślną (wszystkie dzisiejsze osoby przyszły z R2 albo z instalatora).
 *
 * Kto ją ustawia: wyłącznie ręczna ścieżka tworzenia konta administracyjnego
 * (`InvitationService::invite()`). Synchronizacja tej kolumny nie dotyka — osoby z R2 powstają
 * przez `SyncDomainRepository`, gdzie kolumny są wymienione jawnie.
 */
final class PersonOutsideHr extends AbstractMigration
{
    public function up(): void
    {
        $this->table('person')
            ->addColumn('outside_hr', 'boolean', [
                'null'    => false,
                'default' => false,
                'after'   => 'r2_identifier',
                'comment' => 'Osoba dodana ręcznie, nie z programu kadrowego',
            ])
            ->update();
    }

    public function down(): void
    {
        $this->table('person')->removeColumn('outside_hr')->update();
    }
}
