<?php

declare(strict_types=1);

namespace Wezurlop\Service\Calendar;

use DateTimeImmutable;

/**
 * Święta państwowe w Polsce (dni ustawowo wolne od pracy). STREFA JAWNA.
 *
 * ⚠ Święta ruchome liczone ALGORYTMICZNIE (Wielkanoc i zależne od niej) — tablica wpisanych dat
 * zestarzałaby się po cichu (docs/etap3-logika-urlopowa.md rozdz. 5). Bez rozszerzenia `calendar`
 * (easter_date() nie jest dostępne na każdym hostingu) — algorytm Meeusa/Jonesa/Butchera.
 */
final class PolishHolidays
{
    /** @return array<string, string> data Y-m-d => nazwa */
    public static function forYear(int $year): array
    {
        $easter = self::easterSunday($year);
        $days = [
            sprintf('%04d-01-01', $year) => 'Nowy Rok',
            sprintf('%04d-05-01', $year) => 'Święto Pracy',
            sprintf('%04d-05-03', $year) => 'Święto Konstytucji 3 Maja',
            sprintf('%04d-08-15', $year) => 'Wniebowzięcie Najświętszej Maryi Panny',
            sprintf('%04d-11-01', $year) => 'Wszystkich Świętych',
            sprintf('%04d-11-11', $year) => 'Narodowe Święto Niepodległości',
            sprintf('%04d-12-25', $year) => 'Boże Narodzenie (pierwszy dzień)',
            sprintf('%04d-12-26', $year) => 'Boże Narodzenie (drugi dzień)',
            $easter->format('Y-m-d')                    => 'Wielkanoc',
            $easter->modify('+1 day')->format('Y-m-d')  => 'Poniedziałek Wielkanocny',
            $easter->modify('+49 days')->format('Y-m-d') => 'Zesłanie Ducha Świętego',
            $easter->modify('+60 days')->format('Y-m-d') => 'Boże Ciało',
        ];
        if ($year >= 2011) {
            $days[sprintf('%04d-01-06', $year)] = 'Święto Trzech Króli';
        }
        if ($year >= 2025) {
            // Wigilia dniem wolnym od pracy od 2025 r. (nowelizacja ustawy o dniach wolnych od pracy).
            $days[sprintf('%04d-12-24', $year)] = 'Wigilia Bożego Narodzenia';
        }
        ksort($days);

        return $days;
    }

    /** Wielkanoc w kalendarzu gregoriańskim (algorytm anonimowy Meeusa/Jonesa/Butchera). */
    public static function easterSunday(int $year): DateTimeImmutable
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
    }
}
