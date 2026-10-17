<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Stan konta **sprzed blokady** (zlecenie `konta-budowa-2` etap 5).
 *
 * ⚠ Bez tej kolumny odblokowanie musiałoby zgadywać, do czego wrócić — a „wróć do `active`"
 * zamieniłoby konto `niezaproszone` (bez hasła i bez zaproszenia) w konto **logowalne**.
 * Zgadywanie ze stanu hasła też nie wystarcza: `invited` i `niezaproszone` mają jednakowo
 * `password_hash IS NULL`.
 *
 * ⚠ Dlaczego OSOBNA migracja, a nie dopisek do `20260921170001`: tamta była już **zastosowana**,
 * a migracji zastosowanej nie wolno zmieniać. Sprawdzone boleśnie na maszynie deweloperskiej —
 * po dopisaniu kolumny do tamtego pliku jej `down()` próbował usunąć kolumnę, której sam nie
 * dodał, i `rollback` przerwał się na `1091 Can't DROP 'status_before_block'`. U klienta,
 * gdzie ta sama migracja jest już w `phinxlog`, skutek byłby identyczny.
 *
 * `NULL` znaczy „konto nie jest zablokowane, nie ma do czego wracać" — brak wartości,
 * nie wartość specjalna.
 */
final class AccountStatusBeforeBlock extends AbstractMigration
{
    public function up(): void
    {
        $this->table('account')
            ->addColumn('status_before_block', 'string', [
                'limit'   => 16,
                'null'    => true,
                'after'   => 'blocked_by_account_id',
                'comment' => 'Stan, do którego wraca konto po odblokowaniu',
            ])
            ->update();
    }

    public function down(): void
    {
        $this->table('account')->removeColumn('status_before_block')->update();
    }
}
