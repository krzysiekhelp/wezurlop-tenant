<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Reguły dodatkowego wglądu w kalendarz — **KTO WIDZI › CZYJE NIEOBECNOŚCI › W JAKIM ZAKRESIE**
 * (zlecenie Z53, 19.10.2026; decyzje Krzysztofa z odpowiedzi na pytania 1–4).
 *
 * ⚠ **Cztery rodzaje podmiotu, trzy rodzaje celu** (dopisek Krzysztofa z 19.10.2026, po
 * zaakceptowaniu prototypu okna):
 * ```
 * kto widzi: supervisor_all | supervisor | account | group
 * czyje:     subordinates   | company    | groups
 * ```
 * ⚠ **`supervisor_all` › `subordinates` to REGUŁA DOMYŚLNA FIRMY** — ta, która do 19.10 miała
 * być osobną tabelą ustawień. Wybrałem regułę, a nie ustawienie, i to nie jest kwestia gustu:
 * administrator ogląda ją w tym samym wierszu listy, zmienia tym samym oknem trzykrokowym
 * i podlega tej samej zasadzie „wygrywa wyższy zakres". Osobne ustawienie znaczyłoby drugi
 * zapis, drugi ekran i drugą gałąź w funkcji liczącej zakres — czyli dokładnie to, czego §8b
 * zabrania od pierwszego dnia. Jedyne, co trzeba dołożyć, to zakaz usunięcia tej jednej reguły
 * i jej domyślny zakres, gdy wiersza jeszcze nie ma.
 *
 * ⚠ **Reguła należy do FIRMY.** Dostęp nadany w ALFA nie daje niczego w BECIE — tak samo jak
 * hierarchia i grupy są osobne dla każdej firmy. Granicę pilnuje `company_id` **w zapytaniu**,
 * a nie warunek po pobraniu (CLAUDE.md §10); kolumna jest tu po to, żeby było co w tym warunku
 * porównać.
 *
 * ⚠ **Obie strony reguły liczą się W CHWILI ODCZYTU, nie w chwili zapisu.** Grupa po stronie
 * „kto widzi" i po stronie „czyje", a także „cała firma", są **odniesieniami**, nie migawkami
 * list osób. Pracownik przyjęty jutro i osoba dopisana jutro do grupy są objęte regułą bez
 * żadnego dotykania reguły. Migawka byłaby dokładnie tą usterką, przez którą filtr kalendarza
 * trzyma **wyłączenia**, a nie włączenia (`docs/etap3-logika-urlopowa.md` §8b).
 *
 * ⚠ **`NOT NULL DEFAULT 0` zamiast `NULL` w kolumnach podmiotu** — CLAUDE.md §4: `UNIQUE`
 * nie pilnuje wierszy z `NULL`, a klucz unikalny jest tu jedyną obroną przed dwiema identycznymi
 * regułami wstawionymi w tej samej chwili z dwóch kart przeglądarki. Ceną jest brak klucza obcego
 * na `subject_account_id` i `subject_group_id`, i to jest **świadome**: zlecenie wymaga przy
 * usunięciu grupy nie kaskady, tylko **zapisu w dzienniku zdarzeń** (reguła znika po stronie
 * „kto", wypada z listy po stronie „czyje", pusta lista kasuje regułę). Kaskada bazy zrobiłaby
 * to po cichu i dziennik skłamałby przez przemilczenie.
 *
 * ⚠ **`signature` to nie ozdoba ani cache.** „Identyczna reguła jest odrzucana" musi być
 * pilnowane przez BAZĘ, bo sprawdzenie w kodzie („najpierw policz, potem wstaw") przepuszcza
 * dwa równoległe żądania. Klucz unikalny nie obejmie listy grup z tabeli obok, więc całą trójkę
 * — kto, czyje (z posortowanymi identyfikatorami grup) i zakres — zapisujemy jako jeden napis
 * liczony w jednym miejscu (`ZakresWidocznosci::podpis()`).
 *
 * ⚠ **Zakres jest napisem ze słownika aplikacji, nie liczbą.** Dawne poziomy były liczbami
 * (0/1/2) i to się nie sprawdziło przy zmianie znaczenia: „rodzaj ogólny" znika w Z53, więc
 * liczba 1 miałaby od dziś inny sens niż w zeszłym tygodniu. Napis czyta się w dzienniku
 * zdarzeń bez tłumacza i nie przesuwa się przy następnej zmianie.
 *
 * Jedna zmiana = jedno wywołanie (CLAUDE.md §4).
 */
final class CreateCalendarAccessRule extends AbstractMigration
{
    public function up(): void
    {
        $this->table('calendar_access_rule', ['id' => true, 'signed' => false])
            ->addColumn('company_id', 'integer', ['signed' => false])
            // 'account' albo 'group' — kto dostaje wgląd.
            ->addColumn('subject_kind', 'string', ['limit' => 16])
            ->addColumn('subject_account_id', 'integer', ['signed' => false, 'default' => 0])
            // ⚠ Przełożony jest ZATRUDNIENIEM, nie kontem: hierarchia łączy zatrudnienia,
            // a jedna osoba potrafi mieć etat w dwóch firmach i podwładnych tylko w jednej.
            ->addColumn('subject_employment_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('subject_group_id', 'integer', ['signed' => false, 'default' => 0])
            // 'subordinates' (gałąź pod widzem), 'company' (cała firma) albo 'groups'.
            ->addColumn('target_kind', 'string', ['limit' => 16])
            // 'bez_szczegolow' albo 'ze_szczegolami'.
            ->addColumn('scope', 'string', ['limit' => 20])
            ->addColumn('signature', 'string', ['limit' => 190])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('created_by', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->create();

        $this->table('calendar_access_rule')
            ->addIndex(['company_id', 'signature'], ['unique' => true, 'name' => 'uq_car_signature'])
            ->update();

        // Odczyt przy KAŻDYM żądaniu kalendarza idzie po firmie — bez tego indeksu każdy
        // wyświetlony miesiąc skanowałby całą tabelę.
        $this->table('calendar_access_rule')
            ->addIndex(['company_id', 'subject_kind'], ['name' => 'idx_car_company_subject'])
            ->update();

        $this->table('calendar_access_rule')
            ->addForeignKey('company_id', 'company', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->update();
    }

    public function down(): void
    {
        $this->table('calendar_access_rule')->drop()->save();
    }
}
