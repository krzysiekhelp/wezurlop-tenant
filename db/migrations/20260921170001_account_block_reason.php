<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Powód blokady konta w kolumnie (zlecenie `konta-budowa-2` §4 punkt 2, etap 5).
 *
 * ⚠ **Dziennik mówi, co się stało; stan konta musi mówić, co jest teraz.** Dotąd jedynym śladem
 * po blokadzie był wpis `account.blocked_by_sync` w dzienniku zdarzeń — czyli żeby odpowiedzieć
 * na pytanie „dlaczego to konto jest dziś zablokowane", trzeba było przeszukać historię
 * i zakładać, że nic się po drodze nie zmieniło.
 *
 * ⚠ Bez tej kolumny nie da się też spełnić reguły „**synchronizacja zdejmuje wyłącznie blokadę,
 * którą sama założyła**": nie wiedząc, kto zablokował, automat zdjąłby także blokadę nałożoną
 * ręcznie przez administratora — czyli cofnąłby decyzję człowieka.
 *
 * Wartości `blocked_reason`: `sync` (wygasły wszystkie zatrudnienia) albo `manual` (decyzja
 * administratora). ⚠ `NULL` znaczy **brak blokady** — czyli brak wartości, a nie wartość
 * specjalną, więc reguła z CLAUDE.md §4 nie każe tu wartości domyślnej; nie ma też na tej
 * kolumnie klucza unikalnego, który musiałby cokolwiek pilnować.
 */
final class AccountBlockReason extends AbstractMigration
{
    public function up(): void
    {
        // Jedna zmiana = jedno wywołanie (CLAUDE.md §4).
        $this->table('account')
            ->addColumn('blocked_reason', 'string', [
                'limit'   => 16,
                'null'    => true,
                'after'   => 'status',
                'comment' => 'sync | manual; NULL = konto nie jest zablokowane',
            ])
            ->update();

        $this->table('account')
            ->addColumn('blocked_at', 'datetime', ['null' => true, 'after' => 'blocked_reason'])
            ->update();

        // Kto zablokował — przy blokadzie z synchronizacji zostaje `NULL`, bo nie zrobił tego człowiek.
        $this->table('account')
            ->addColumn('blocked_by_account_id', 'integer', [
                'null' => true, 'signed' => false, 'after' => 'blocked_at',
            ])
            ->update();
    }

    public function down(): void
    {
        $this->table('account')->removeColumn('blocked_by_account_id')->update();
        $this->table('account')->removeColumn('blocked_at')->update();
        $this->table('account')->removeColumn('blocked_reason')->update();
    }
}
