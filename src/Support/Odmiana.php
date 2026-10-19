<?php

declare(strict_types=1);

namespace Wezurlop\Support;

/**
 * Odmiana rzeczownika przez liczbę — czysty, bezstanowy helper (CLAUDE.md §1), strefa jawna.
 *
 * ⚠ Powód nie jest estetyczny. „1 dni roboczych” i „4 dni roboczych” w tabeli z danymi kadrowymi
 * czyta się jak literówkę w danych, a nie w zdaniu — a ekran, który wygląda na niedokończony,
 * podważa liczby, które pokazuje. Polski ma trzy formy i wszystkie trzy występują w tych widokach.
 *
 * ⚠ Jedno miejsce na tę regułę: pulpit, kalendarz i podpowiedzi odmieniają to samo słowo,
 * a trzy kopie warunku rozjechałyby się przy pierwszej zmianie brzmienia.
 */
final class Odmiana
{
    /** „1 dzień”, „2 dni”, „5 dni” — sama forma słowa, bez liczby. */
    public static function dni(int $ile): string
    {
        return abs($ile) === 1 ? 'dzień' : 'dni';
    }

    /** „1 dzień roboczy”, „3 dni robocze”, „5 dni roboczych” — sama forma słowa, bez liczby. */
    public static function dniRobocze(int $ile): string
    {
        return abs($ile) === 1 ? 'dzień roboczy' : (self::mnoga($ile) ? 'dni robocze' : 'dni roboczych');
    }

    /** „1 osoba”, „3 osoby”, „5 osób” — kalendarz zespołu liczy ludzi w trzech miejscach. */
    public static function osoby(int $ile): string
    {
        return abs($ile) === 1 ? 'osoba' : (self::mnoga($ile) ? 'osoby' : 'osób');
    }

    /**
     * „z 1 osoby”, „z 3 osób”, „z 53 osób” — forma po **przyimku**, nie po liczebniku.
     *
     * ⚠ To osobna funkcja, a nie błąd w `osoby()`. Polski przyimek `z` (i `u`) rządzi
     * dopełniaczem **niezależnie od liczby**, więc o formie decyduje POZYCJA W ZDANIU,
     * a nie liczebnik: „widać 30 z 36 osób” i „widać 30 z 53 osób”, ale „zasięg: 53 osoby”.
     * Zmierzone 06.10.2026: `osoby()` dawało „widać 30 z 53 osoby”, a przy 36 wyszło dobrze
     * **przypadkiem** — bo dopełniacz i mianownik mnogi zbiegają się na „5 i więcej”.
     * To znowu reguła dobra w jednym kontekście i zła w drugim, więc konteksty mają
     * dwie nazwy, a nie jeden warunek z parametrem.
     */
    public static function osobyDopelniacz(int $ile): string
    {
        return abs($ile) === 1 ? 'osoby' : 'osób';
    }

    /** „1 konto”, „3 konta”, „5 kont” — pozycja diagnostyki liczy konta. */
    public static function kont(int $ile): string
    {
        return abs($ile) === 1 ? 'konto' : (self::mnoga($ile) ? 'konta' : 'kont');
    }

    /**
     * „1 wpis”, „3 wpisy”, „5 wpisów” — wpisy kadrowe sklejone w jeden ciąg.
     *
     * ⚠ Dochodzi 17.10.2026, bo dwa ekrany pisały „5 wpisy w kadrach”: wykaz w Miesiącu
     * „Tylko ja” i tabela nieobecności na pulpicie. Liczba brała się z danych kadrowych,
     * więc błędna forma czytała się jak usterka w danych, a nie w zdaniu.
     */
    public static function wpisy(int $ile): string
    {
        return abs($ile) === 1 ? 'wpis' : (self::mnoga($ile) ? 'wpisy' : 'wpisów');
    }

    /** „1 nieobecność”, „2 nieobecności”, „5 nieobecności” — obie formy mnogie są takie same. */
    public static function nieobecnosci(int $ile): string
    {
        return abs($ile) === 1 ? 'nieobecność' : 'nieobecności';
    }

    /**
     * „1 zadanie", „3 zadania", „5 zadań" — kafel kolejki na `/ustawienia/poczta` (Z43).
     *
     * ⚠ Prototyp miał w kaflu „N zadania", czyli formę poprawną wyłącznie dla 2–4. Kafel pokazuje
     * liczbę z bazy, więc zła forma czyta się jak usterka w danych, a nie w zdaniu — ta sama
     * zasada, co przy dniach i wpisach kadrowych.
     */
    public static function zadania(int $ile): string
    {
        return abs($ile) === 1 ? 'zadanie' : (self::mnoga($ile) ? 'zadania' : 'zadań');
    }

    /**
     * Czy liczba bierze formę mnogą „lekką” (2, 3, 4), a nie dopełniacz (5 i więcej).
     *
     * ⚠ Wyjątek nastu (12, 13, 14) jest konieczny: bez niego wychodzi „13 dni robocze”
     * i „13 osoby”.
     */
    private static function mnoga(int $ile): bool
    {
        $n = abs($ile);
        $ostatnia = $n % 10;
        $dwie = $n % 100;

        return $ostatnia >= 2 && $ostatnia <= 4 && ($dwie < 12 || $dwie > 14);
    }
}
