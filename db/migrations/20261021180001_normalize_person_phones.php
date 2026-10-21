<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Wezurlop\Support\Telefon;

/**
 * **Ujednolicenie zapisu numerów w `person.phone`** (zlecenie Z74, decyzja Krzysztofa
 * z 21.10.2026 po Z73).
 *
 * ⚠ Powód jest praktyczny, nie porządkowy: do Z73 panel osoby zapisywał numer w dowolnym
 * kształcie, a „Moje konto" w jednym (`+48XXXXXXXXX`). W jednej kolumnie stały więc dwa formaty,
 * czyli **numeru nie dało się porównać ani wyszukać** — a dopasowanie osoby po numerze polega
 * właśnie na wyszukaniu. Od Z73 wszystkie trzy ekrany zapisują jedną postać; ta migracja
 * domyka dane, które powstały wcześniej.
 *
 * ⚠ **Reguła pochodzi z `Support\Telefon`, nie z kopii w SQL** (wymóg zlecenia). Kopia
 * w `UPDATE … REPLACE(…)` byłaby drugą definicją poprawnego numeru i rozjechałaby się z pierwszą
 * przy pierwszej zmianie reguły — a wtedy migracja przepisywałaby dane inaczej, niż aplikacja
 * je przyjmuje. Autoloader Composera jest w migracjach dostępny (korzysta z niego sam
 * `config/phinx.php`).
 *
 * ⚠ **Przepisujemy WYŁĄCZNIE numery, które po oczyszczeniu są poprawnym numerem polskim.**
 * Numer wewnętrzny („22 123 45 67 w. 12") albo zagraniczny zostaje nietknięty i trafia do
 * liczby pominiętych. Migracja nie zgaduje, co ktoś miał na myśli — zmiana danych osobowych
 * na podstawie domysłu jest gorsza niż niejednolity zapis.
 *
 * ⚠ **`down()` NIE przywraca starego kształtu i to jest decyzja, nie przeoczenie.** Dwie drogi
 * były możliwe i wybrałem drugą:
 *   1. zapisać stare wartości w tabeli pomocniczej — wtedy w bazie powstaje **druga kopia
 *      numerów telefonów**, czyli danych osobowych (CLAUDE.md §14), której nikt nigdy nie
 *      wyczyści, bo tabela po migracji nie ma właściciela;
 *   2. nie zapisywać nic i uzasadnić, czego cofnięcie nie odtworzy — bo **nie ginie żadna
 *      informacja o człowieku**: zmiana dotyczy wyłącznie separatorów i prefiksu, a same cyfry
 *      zostają te same (`+48` + dziewięć cyfr). Cofnięcie nie odtworzy stylu zapisu („600 100
 *      200" kontra „600-100-200"), bo styl nie jest daną o osobie.
 * Dlatego `down()` jest świadomie puste, a nie „pozornie bezpieczne" z tabelą kopii.
 *
 * ⚠ **Powtarzalna**: numer już w docelowej postaci nie wchodzi do zmian
 * (`Telefon::doPrzepisania()` odkłada go do `bez_zmian`), więc drugi przebieg rusza zero wierszy.
 *
 * ⚠ **Ani jeden numer nie trafia na wyjście migracji, do logu ani do dziennika** — wszędzie
 * idą tylko liczby wierszy (CLAUDE.md §14: telefon to dana osobowa).
 */
final class NormalizePersonPhones extends AbstractMigration
{
    public function up(): void
    {
        $pdo = $this->getAdapter()->getConnection();
        $numery = [];
        foreach ($pdo->query("SELECT id, phone FROM person WHERE phone IS NOT NULL AND phone <> ''") as $r) {
            $numery[(int) $r['id']] = (string) $r['phone'];
        }
        $plan = Telefon::doPrzepisania($numery);

        $zapis = $pdo->prepare('UPDATE person SET phone = :numer WHERE id = :id');
        foreach ($plan['zmiany'] as $id => $numer) {
            $zapis->execute(['numer' => $numer, 'id' => $id]);
        }
        $zmienionych = count($plan['zmiany']);

        // ⚠ Jeden wpis w dzienniku, systemowy: bez konta sprawcy (`account_id` puste,
        // `actor_label` nazywa narzędzie) i **z samą liczbą**. Wpis powstaje także przy zerze —
        // „migracja przebiegła i nie miała czego zmieniać" to też odpowiedź, a brak wpisu
        // wygląda jak brak przebiegu.
        $wpis = $pdo->prepare(
            "INSERT INTO event_log (occurred_at, account_id, company_id, actor_label, event_code,
                                    subject_type, subject_id, ip_hash, detail)
             VALUES (UTC_TIMESTAMP(), NULL, NULL, 'migracja', 'person.phones_normalized',
                     NULL, NULL, NULL, :detail)"
        );
        $wpis->execute(['detail' => json_encode([
            'zmienionych' => $zmienionych,
            'pominietych' => count($plan['pominiete']),
            'bez_zmian' => count($plan['bez_zmian']),
        ], JSON_THROW_ON_ERROR)]);

        // Same liczby — nazwisk ani numerów tu nie ma i nie będzie.
        $this->output->writeln(sprintf(
            '  numery telefonów: %d przepisanych, %d pominiętych (nienormalizowalne), %d już poprawnych',
            $zmienionych,
            count($plan['pominiete']),
            count($plan['bez_zmian']),
        ));
    }

    public function down(): void
    {
        // ⚠ Świadomie puste — uzasadnienie w docbloku klasy: cofnięcie nie odtworzy STYLU zapisu,
        // ale żadna informacja o człowieku nie ginie (cyfry zostają te same), a tabela z kopią
        // numerów byłaby drugim miejscem przechowującym dane osobowe bez właściciela.
        $this->output->writeln('  nic do cofnięcia: migracja zmieniała zapis numeru, nie jego treść');
    }
}
