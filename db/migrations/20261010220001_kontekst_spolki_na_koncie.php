<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * **Zapamiętana spółka kontekstu na koncie** (zlecenie `2026-10-10-globalny-wybor-spolki.md` §1:
 * „kontekst ma przeżyć przejście między ekranami **i ponowne zalogowanie**").
 *
 * ⚠ Dotąd kontekst żył **wyłącznie w sesji**, więc po wylogowaniu przepadał: konto z dwoma
 * zatrudnieniami po każdym logowaniu trafiało na ekran wyboru firmy, a konto z rolą tenantową
 * (zero zatrudnień) wracało do pierwszej spółki z listy. Trwałość wymaga miejsca poza sesją
 * i to jest jedyny powód tej kolumny.
 *
 * ⚠ **Jedna kolumna, nie dwie.** Zapisujemy **spółkę**, nie zatrudnienie, bo to spółka jest
 * tym, czym człowiek przełącza (znacznik w belce), a zatrudnienie wyprowadzamy z niej tak samo,
 * jak robi to kontrolka od 07.10: pierwsze czynne zatrudnienie konta w tej spółce. Koszt jest
 * jawny i zapisany w raporcie: konto z **dwoma zatrudnieniami w jednej spółce** nie odtworzy
 * po zalogowaniu, które z nich wybrało — w trakcie sesji nadal odtwarza, bo tam kontekstem
 * jest identyfikator zatrudnienia.
 *
 * ⚠ `NULL` znaczy „nic nie zapamiętano" i jest stanem normalnym (nowe konto). Nie `0`:
 * `UNIQUE`/klucz obcy na zerze nie ma sensu, a `NULL` tu niczego nie pilnuje (§4 reguł dotyczy
 * kolumn, na których stoi indeks unikalny).
 *
 * ⚠ `ON DELETE SET NULL`: usunięcie spółki nie ma prawa zablokować usunięcia ani zostawić
 * konta ze wskazaniem na nieistniejący wiersz. Kontekst to podpowiedź, nie dana kadrowa.
 */
final class KontekstSpolkiNaKoncie extends AbstractMigration
{
    public function up(): void
    {
        $this->table('account')
            ->addColumn('context_company_id', 'integer', [
                'signed' => false,
                'null' => true,
                'after' => 'last_login_at',
                'comment' => 'Zapamiętana spółka kontekstu — podpowiedź, walidowana przy każdym żądaniu',
            ])
            ->addForeignKey('context_company_id', 'company', 'id', ['delete' => 'SET_NULL', 'update' => 'NO_ACTION'])
            ->update();
    }

    public function down(): void
    {
        $this->table('account')->dropForeignKey('context_company_id')->update();
        $this->table('account')->removeColumn('context_company_id')->update();
    }
}
