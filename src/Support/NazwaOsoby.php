<?php

declare(strict_types=1);

namespace Wezurlop\Support;

/**
 * Imię i nazwisko do wyświetlenia (docs/system-wizualny.md §9).
 *
 * R2 przysyła `imie` i `nazwisko` rozdzielone, ale zwykle wersalikami (`TESTOWSKI`, `JAN`).
 * Ta klasa naprawia wyłącznie WYGLĄD — strefa jawna (CLAUDE.md §15), funkcja czysta, bez bazy.
 *
 * ⚠ Wynik idzie tylko na ekran i do poczty. Do bazy nigdy: wartość z R2 zostaje nietknięta,
 * a sortowanie i wyszukiwanie pracują na wartości źródłowej — inaczej kolejność w tabeli
 * zależałaby od warstwy prezentacji.
 * ⚠ Wyłącznie funkcje `mb_*`: `strtolower` i `ucfirst` psują `Ł`, `Ó`, `Ą`.
 */
final class NazwaOsoby
{
    /** Przedrostki nazwisk — małą literą, gdy człon nie jest jedynym w wartości. */
    private const PRZEDROSTKI = [
        'de', 'del', 'della', 'di', 'du', 'da', 'das', 'dos',
        'van', 'von', 'der', 'den', 'ten', 'ter', 'la', 'le', 'af', 'av',
    ];

    /** Separatory członów: spacja, myślnik i oba apostrofy. */
    private const SEPARATORY = "/([ \-'’])/u";

    public static function doWyswietlenia(string $wartosc): string
    {
        $wartosc = trim($wartosc);
        if ($wartosc === '') {
            return '';
        }

        // ⚠ Reguła 1: choć jedna mała litera = ktoś zapisał to świadomie. Nie ruszamy.
        // Klient, który wpisał „von Neumann” poprawnie, nie może tego stracić.
        if (preg_match('/\p{Ll}/u', $wartosc) === 1) {
            return $wartosc;
        }

        $czesci = preg_split(self::SEPARATORY, mb_strtolower($wartosc, 'UTF-8'), -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($czesci === false) {
            return $wartosc;
        }

        $czlony = 0;
        foreach ($czesci as $czesc) {
            if ($czesc !== '' && preg_match(self::SEPARATORY, $czesc) !== 1) {
                $czlony++;
            }
        }

        $wynik = '';
        $poprzedniSeparator = null;
        foreach ($czesci as $czesc) {
            if ($czesc === '') {
                continue;
            }
            if (preg_match(self::SEPARATORY, $czesc) === 1) {
                $wynik .= $czesc;
                $poprzedniSeparator = $czesc;
                continue;
            }
            $wynik .= self::czlon($czesc, $czlony > 1 && ($poprzedniSeparator === null || $poprzedniSeparator === ' '));
        }

        return $wynik;
    }

    /** Do tabel i list: „Kowalski Jan”. */
    public static function nazwiskoImie(string $nazwisko, string $imie): string
    {
        return trim(self::doWyswietlenia($nazwisko) . ' ' . self::doWyswietlenia($imie));
    }

    /** Do nagłówka karty, powitania i poczty: „Jan Kowalski”. */
    public static function imieNazwisko(string $imie, string $nazwisko): string
    {
        return trim(self::doWyswietlenia($imie) . ' ' . self::doWyswietlenia($nazwisko));
    }

    private static function czlon(string $czlon, bool $przedrostkiWolno): string
    {
        if ($przedrostkiWolno && in_array($czlon, self::PRZEDROSTKI, true)) {
            return $czlon;
        }

        // ⚠ `Mc` tak, `Mac` NIE: reguła dla „mac” zamieniłaby MACIEJ na MacIej.
        if (mb_strlen($czlon, 'UTF-8') > 2 && mb_substr($czlon, 0, 2, 'UTF-8') === 'mc') {
            return 'Mc' . self::wielkaPierwsza(mb_substr($czlon, 2, null, 'UTF-8'));
        }

        return self::wielkaPierwsza($czlon);
    }

    private static function wielkaPierwsza(string $czlon): string
    {
        return mb_strtoupper(mb_substr($czlon, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($czlon, 1, null, 'UTF-8');
    }
}
