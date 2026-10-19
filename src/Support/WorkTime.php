<?php

declare(strict_types=1);

namespace Wezurlop\Support;

/**
 * Minuty z kadr na „dni i godziny” — czysty, bezstanowy helper (CLAUDE.md §1: funkcje tylko dla
 * takich rzeczy, bez dostępu do bazy).
 *
 * ⚠ Wartością wiążącą są **minuty** — tak liczy program kadrowy i tak trzymamy je u siebie.
 * Ten zapis służy wyłącznie do czytania na ekranie, dlatego wszędzie obok idzie oryginał w `title`.
 * ⚠ **Dzielnik to 480 i obowiązuje na WSZYSTKICH ekranach** (rozstrzygnięcie z 22.09.2026, zlecenie `kalendarz-zespolu-domyslny-wybor` §3.1).
 * Wcześniej karta kadrowej dzieliła przez `normaUrlopowaMin` kalendarza, a pulpit pracownika
 * przez 480 — czyli z tych samych minut wychodziły **dwie różne liczby dni na dwóch naszych
 * ekranach**, bez sposobu rozstrzygnięcia, która jest ważna. `ksztalt-danych-r2.md` (rekord
 * `limit`) mówi wprost „zawsze 480, także przy niepełnym etacie”, a cała ta warstwa istnieje,
 * żeby pracownik widział tę samą liczbę co w programie kadrowym.
 * ⚠ Parametr `$dayMinutes` **został usunięty**, a nie zostawiony „na wszelki wypadek” (CLAUDE.md §1:
 * bez interfejsów na zapas). Parametr, którego nikt nie podaje, to obietnica w komentarzu zamiast
 * jednej formuły w kodzie — a gdyby odpowiedź autora connectora na pytanie z `docs/audyt.md`
 * (co R2 pokazuje przy `normaUrlopowaMin = 720`) kazała wrócić do normy kalendarza, dodanie go
 * z powrotem jest zmianą w TYM jednym pliku i w wołających. „Zawsze 480” w dokumencie dotyczy
 * niepełnego etatu, a dniówka 12 h to inny przypadek — do czasu odpowiedzi nie zgadujemy.
 */
final class WorkTime
{
    /**
     * Dzielnik minut na dni — **jedyny w prezentacji**, taki sam dla pracownika i dla kadrowej.
     *
     * ⚠ Nazwa mówi „norma dnia”, a nie „wartość zapasowa”, bo to już nie jest zapas na wypadek
     * braku danych: to obowiązująca reguła prezentacji. `LeaveRequestService` ma własną stałą
     * o tej samej wartości i to jest w porządku — tam liczy się minuty Z DNI (w drugą stronę,
     * z uwzględnieniem etatu), czyli inna operacja i inna decyzja.
     */
    public const MINUT_W_DNIU = 480;

    /**
     * Rozbicie minut na dni, godziny i minuty — **jedno miejsce, w którym ta arytmetyka istnieje**.
     *
     * ⚠ Pulpit pracownika pokazuje dni i godziny w dwóch osobnych elementach (liczba dni dużą
     * cyfrą, reszta w `<small>`), więc potrzebuje składników, a nie gotowego zdania. Gdyby wyliczał
     * je sam, w repozytorium byłyby dwie formuły przeliczania minut i pierwsza rozbieżność
     * wyszłaby dopiero u klienta. `format()` poniżej składa napis **z tego samego wyniku**.
     *
     * @return array{ujemne: bool, dni: int, godziny: int, minuty: int}
     */
    public static function parts(?int $minutes): array
    {
        if ($minutes === null) {
            return ['ujemne' => false, 'dni' => 0, 'godziny' => 0, 'minuty' => 0];
        }
        $left = abs($minutes);
        $days = intdiv($left, self::MINUT_W_DNIU);
        $left -= $days * self::MINUT_W_DNIU;
        $hours = intdiv($left, 60);

        return ['ujemne' => $minutes < 0, 'dni' => $days, 'godziny' => $hours, 'minuty' => $left - $hours * 60];
    }

    /**
     * Przykłady: 1920 → „4 dni”, 1200 → „2 dni 4 godz.”, 90 → „1 godz. 30 min”,
     * -480 → „-1 dzień”, 0 → „0 dni”.
     */
    public static function format(?int $minutes): string
    {
        if ($minutes === null) {
            return '—';
        }
        if ($minutes === 0) {
            // W tabeli obok „13 dni” czytelniejsze jest „0 dni” niż „0 min”.
            return '0 dni';
        }
        $skladniki = self::parts($minutes);
        $sign = $skladniki['ujemne'] ? '-' : '';
        $days = $skladniki['dni'];
        $hours = $skladniki['godziny'];
        $rest = $skladniki['minuty'];

        $parts = [];
        if ($days > 0) {
            $parts[] = $days . ' ' . ($days === 1 ? 'dzień' : 'dni');
        }
        if ($hours > 0) {
            $parts[] = $hours . ' godz.';
        }
        if ($rest > 0 || $parts === []) {
            $parts[] = $rest . ' min';
        }

        return $sign . implode(' ', $parts);
    }

    /** Norma dnia i inne wartości krótsze niż doba: „8 godz.”, „7 godz. 35 min”. */
    public static function hours(?int $minutes): string
    {
        if ($minutes === null) {
            return '—';
        }
        $sign = $minutes < 0 ? '-' : '';
        $left = abs($minutes);
        $hours = intdiv($left, 60);
        $rest = $left - $hours * 60;
        $parts = [];
        if ($hours > 0) {
            $parts[] = $hours . ' godz.';
        }
        if ($rest > 0 || $parts === []) {
            $parts[] = $rest . ' min';
        }

        return $sign . implode(' ', $parts);
    }

    /** Norma dobowa w zapisie z makiety: `8 h 00 min` (docs/system-wizualny.md §5.2). */
    public static function dayNorm(?int $minutes): string
    {
        if ($minutes === null) {
            return '—';
        }

        return sprintf('%d h %02d min', intdiv($minutes, 60), $minutes % 60);
    }

    /**
     * Etat do czytania: `1,00`, a przy typowych ułamkach z dopiskiem `0,75 (3/4 etatu)`.
     * ⚠ `1,0000` z bazy to zapis techniczny — cztery miejsca po przecinku nie niosą tu informacji (§3.4).
     */
    public static function fte(?float $fte): string
    {
        if ($fte === null) {
            return '—';
        }
        $zapis = number_format($fte, 2, ',', ' ');
        $ulamki = ['0,50' => '1/2 etatu', '0,75' => '3/4 etatu', '0,25' => '1/4 etatu', '0,33' => '1/3 etatu'];

        return isset($ulamki[$zapis]) ? $zapis . ' (' . $ulamki[$zapis] . ')' : $zapis;
    }

    /**
     * Etat w kolumnie liczbowej: **sama liczba** („0,50"), bez dopisku (zlecenie Z45 §4).
     *
     * ⚠ Dwie formy tej samej danej, bo decyduje POZYCJA — ta sama zasada, co w `Odmiana::osoby()`
     * i `osobyDopelniacz()`. W kolumnie liczb „0,50 (1/2 etatu)" rozpychało kolumnę i czytało się
     * jak dwie dane obok siebie; opis przeniósł się do `title`, czyli tam, gdzie się go szuka.
     */
    public static function fteKrotki(?float $fte): string
    {
        return $fte === null ? '—' : number_format($fte, 2, ',', ' ');
    }

    /** Pełny opis etatu do atrybutu `title`: „1/2 etatu", a przy nietypowym ułamku „0,80 etatu". */
    public static function fteOpis(?float $fte): string
    {
        if ($fte === null) {
            return 'brak wartości';
        }
        $zapis = self::fteKrotki($fte);
        $ulamki = ['0,50' => '1/2 etatu', '0,75' => '3/4 etatu', '0,25' => '1/4 etatu', '0,33' => '1/3 etatu'];

        return $ulamki[$zapis] ?? $zapis . ' etatu';
    }

    /** Sam zapis w minutach do atrybutu `title` — żeby oryginalna wartość z kadr była pod ręką. */
    public static function minutes(?int $minutes): string
    {
        return $minutes === null ? 'brak wartości' : $minutes . ' min';
    }
}
