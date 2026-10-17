<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Konto bez adresu e-mail — stan `niezaproszone`
 * (zlecenie `2026-09-21-konta-lista-dostep-i-nazwy.md` §4, zgoda w dopisku §2 punkt 6.1).
 *
 * Powód: uprawnienie wisi na `role_assignment.account_id`, więc nadanie dostępu osobie bez konta
 * wymaga wiersza konta. Takie konto powstaje **przed** zaproszeniem, a administrator może jeszcze
 * nie znać adresu — więc `account.email` musi dopuszczać brak wartości.
 *
 * ⚠ `NULL`, a nie `''` — i to jest **wyjątek od CLAUDE.md §4**, świadomy i wąski. Tamta reguła
 * zabrania `NULL` tam, gdzie miałby ZNACZYĆ wartość („wszystkie firmy") i gdzie `UNIQUE` ma
 * pilnować jednoznaczności. Tutaj jest odwrotnie:
 *   - `NULL` znaczy **brak wartości**, nie wartość specjalną,
 *   - `UNIQUE` na `email` ma dalej pilnować adresów **prawdziwych**, a kont bez adresu może być
 *     wiele. `''` byłoby wartością, więc UNIQUE dopuściłby dokładnie JEDNO konto bez adresu —
 *     drugie odbiłoby się o duplikat. MySQL i MariaDB pomijają `NULL` w kluczu unikalnym,
 *     co jest tu dokładnie pożądanym zachowaniem.
 *
 * ⚠ Indeks `email` (UNIQUE) **zostaje nietknięty** — to on chroni logowanie przed dwoma kontami
 * na jeden adres. Zmienia się wyłącznie dopuszczalność braku wartości.
 *
 * Rollback: wymaga, żeby nie było kont bez adresu — inaczej `NOT NULL` nie przejdzie.
 * To świadome: cofnięcie tej migracji na bazie z kontami `niezaproszone` musi się zatrzymać,
 * a nie wpisać im wymyślony adres.
 */
final class AccountEmailNullable extends AbstractMigration
{
    public function up(): void
    {
        // Jedna zmiana = jedno wywołanie (CLAUDE.md §4: phinx potrafi przestawić kolejność
        // operacji zebranych w jednym `update()`).
        $this->table('account')
            ->changeColumn('email', 'string', ['limit' => 254, 'null' => true])
            ->update();
    }

    public function down(): void
    {
        $bezAdresu = (int) $this->fetchRow('SELECT COUNT(*) AS ile FROM account WHERE email IS NULL')['ile'];
        if ($bezAdresu > 0) {
            throw new RuntimeException(
                'Nie mogę przywrócić NOT NULL na account.email: ' . $bezAdresu . ' kont nie ma adresu '
                . '(stan `niezaproszone`). Najpierw je zaproś albo usuń — migracja nie wymyśli adresu.'
            );
        }
        $this->table('account')
            ->changeColumn('email', 'string', ['limit' => 254, 'null' => false])
            ->update();
    }
}
