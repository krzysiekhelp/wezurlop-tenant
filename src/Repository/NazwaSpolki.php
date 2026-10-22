<?php

declare(strict_types=1);

namespace Wezurlop\Repository;

/**
 * Nazwa spółki **do pokazania człowiekowi** — jedno wyrażenie SQL dla całej aplikacji
 * (zlecenie `konta-lista-dostep` §8).
 *
 * ⚠ To nie jest klasa z logiką, tylko jedno miejsce na jeden fragment SQL. Powód: nazwa
 * wyświetlana wchodzi do kilku zapytań w różnych repozytoriach, a wpisana z pamięci w każdym
 * z nich rozjechałaby się przy pierwszej zmianie reguły — dokładnie tak, jak rozjechały się
 * dwa liczniki tej samej rzeczy w innych miejscach tego projektu.
 *
 * ⚠ `NULLIF(..., '')` jest tu świadomie, mimo że pusta nazwa jest w bazie `NULL`: gdyby
 * kiedykolwiek wpadł pusty łańcuch (import, ręczna poprawka w bazie), wyrażenie nadal pokaże
 * nazwę z R2, a nie puste miejsce w kolumnie.
 *
 * ⚠ Gdzie NIE używamy: `/admin/integracja` i przebiegi synchronizacji pokazują dane **tak, jak
 * przyszły z R2** — tam nazwa z R2 jest treścią, nie etykietą, i podmiana na naszą krótką nazwę
 * utrudniłaby porównanie z programem kadrowym.
 *
 * ⚠ **`/dane` od Z76 (22.10.2026) pokazuje nazwę WYŚWIETLANĄ, a prawną trzyma w podpowiedzi**
 * (decyzja Krzysztofa). Wcześniej stało tu, że ten ekran jej nie używa — i było to uzasadnione
 * tym samym porównaniem z kadrami. Zmiana nie unieważnia tego powodu, tylko rozdziela role:
 * w kolumnie na jedną jednostkę siatki 46-znakowa nazwa prawna wersalikami była nieczytelna,
 * a **kluczem porównania z programem kadrowym jest NIP**, który stoi w wierszu jawnie. Nazwa
 * z R2 nie zniknęła: niesie ją atrybut `title` nazwy firmy, więc dosłowne brzmienie dalej jest
 * na tym ekranie dostępne.
 */
final class NazwaSpolki
{
    /** Wymaga aliasu `c` na tabeli `company` w zapytaniu. */
    public const SQL = "COALESCE(NULLIF(c.display_name, ''), c.name)";

    /**
     * To samo wyrażenie dla **innego aliasu** tabeli `company`.
     *
     * ⚠ Istnieje, bo podzapytanie ma własny alias (`c2`) i stała `SQL` tam nie wchodzi — a raz już
     * skończyło się to wpisaniem wyrażenia z pamięci w `HierarchyRepository`. Jedno miejsce na
     * regułę znaczy też: żadnej jej kopii w innym aliasie.
     *
     * ⚠ Alias przychodzi **wyłącznie z kodu**, nigdy z żądania — ale i tak go zawężamy.
     * To fragment SQL-a, a identyfikatora nie da się sparametryzować (CLAUDE.md §4), więc
     * jedyną dopuszczalną drogą jest biała lista. Błąd aliasu ma wybuchnąć tu, nie w bazie.
     */
    public static function sqlDla(string $alias): string
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,15}$/', $alias) !== 1) {
            throw new \InvalidArgumentException('Niedozwolony alias tabeli company: ' . $alias);
        }

        return "COALESCE(NULLIF({$alias}.display_name, ''), {$alias}.name)";
    }

    /** Ta sama reguła w PHP — dla danych już pobranych z bazy. */
    public static function wyswietlana(?string $displayName, string $nazwaR2): string
    {
        $krotka = trim((string) $displayName);

        return $krotka === '' ? $nazwaR2 : $krotka;
    }
}
