<?php

declare(strict_types=1);

namespace Wezurlop\Support;

/**
 * Data KALENDARZOWA z kadr (`RRRR-MM-DD`) na zapis do czytania. Czysty, bezstanowy helper
 * (CLAUDE.md §1), strefa jawna.
 *
 * ⚠ To **nie jest** to samo co `Strefa` i dlatego jest osobno. `Strefa` przelicza znacznik czasu
 * UTC na czas użytkownika — data nieobecności nie ma strefy ani godziny. Przepuszczenie jej
 * przez przeliczanie stref dałoby „przesunięcie o jeden dzień” przy każdej zmianie czasu,
 * czyli urlop rozpoczynający się dzień wcześniej, niż zapisały kadry.
 *
 * ⚠ Wartości nierozpoznanej **nie udajemy** — wraca taka, jaka przyszła. Data z R2 w nieznanym
 * formacie ma być widoczna jako dziwna, a nie zamieniona na dzisiejszą.
 */
final class DataDnia
{
    /** „25.08.2026” */
    public static function pl(string $iso): string
    {
        $c = self::czesci($iso);

        return $c === null ? $iso : $c[2] . '.' . $c[1] . '.' . $c[0];
    }

    /** „29.09” — dzień i miesiąc, gdy rok wynika z kontekstu (kafelek najbliższej nieobecności). */
    public static function dzienMiesiac(string $iso): string
    {
        $c = self::czesci($iso);

        return $c === null ? $iso : $c[2] . '.' . $c[1];
    }

    /**
     * Zakres dat: „25.08 – 28.08.2026”, a przy przełomie roku „28.12.2025 – 03.01.2026”.
     * Jeden dzień to jedna data, nie „25.08 – 25.08”.
     */
    public static function zakres(string $od, string $do): string
    {
        if ($od === $do) {
            return self::pl($od);
        }
        $a = self::czesci($od);
        $b = self::czesci($do);
        if ($a === null || $b === null) {
            return self::pl($od) . ' – ' . self::pl($do);
        }

        // Rok podany raz, gdy jest ten sam — powtórzony przy każdej dacie zabiera uwagę
        // dniom, a to one są tu informacją.
        return $a[0] === $b[0]
            ? self::dzienMiesiac($od) . ' – ' . self::pl($do)
            : self::pl($od) . ' – ' . self::pl($do);
    }

    /**
     * Zakres **zwięzły, bez roku**, na pasek w osi czasu: „10–18.08”, „28.07–3.08”, „7.08”.
     *
     * ⚠ Osobna funkcja, a nie parametr w `zakres()`: tam rok jest treścią (podpis wpisu
     * w siatce miesiąca może wyjść poza rok), a tutaj przeszkodą. Pasek ma kilkadziesiąt
     * pikseli szerokości, a rok wynika z nagłówka karty — więc wpisany na pasku zabiera
     * miejsce dniom, czyli jedynej rzeczy, która się tu zmienia.
     *
     * ⚠ Dzień **bez zera wiodącego**, miesiąc z zerem: „7.08” czyta się jak data, a „07.08”
     * jak numer katalogowy. Pełny zakres z rokiem zostaje w `title` i w `aria-label`.
     */
    public static function zakresKrotki(string $od, string $do): string
    {
        $a = self::czesci($od);
        $b = self::czesci($do);
        if ($a === null || $b === null) {
            // Nierozpoznanej daty nie udajemy (jak w całej tej klasie) — wraca pełny zapis.
            return self::zakres($od, $do);
        }
        $dzien = static fn (array $c): string => (string) (int) $c[2];
        if ($od === $do) {
            return $dzien($a) . '.' . $a[1];
        }

        // Miesiąc podany raz, gdy jest ten sam — „10–18.08” zamiast „10.08–18.08”.
        return $a[0] === $b[0] && $a[1] === $b[1]
            ? $dzien($a) . '–' . $dzien($b) . '.' . $b[1]
            : $dzien($a) . '.' . $a[1] . '–' . $dzien($b) . '.' . $b[1];
    }

    /** @return list<string>|null trzy części daty albo `null`, gdy to nie jest `RRRR-MM-DD` */
    private static function czesci(string $iso): ?array
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $iso, $m) !== 1) {
            return null;
        }

        return [$m[1], $m[2], $m[3]];
    }
}
