<?php

declare(strict_types=1);

namespace Wezurlop\Support;

/**
 * Numer telefonu pracownika: **jedna reguła dla serwera i jedno zdanie odmowy**
 * (zlecenie Z69, 21.10.2026 — „pracownik ustawia numer sam na Moim koncie").
 *
 * ⚠ **Reguła jest wąska świadomie: dziewięć cyfr krajowych, bez zera, bez `+48`.** Pole na
 * ekranie ma nieruchomy prefiks `+48`, więc numer międzynarodowy nie ma jak przez nie przejść —
 * a gdyby reguła przyjmowała „cokolwiek z cyframi", to pierwszy numer wpisany jako `0048…`
 * albo `+48 600…` trafiłby do bazy w trzecim różnym kształcie i nie dałoby się ich zestawić.
 * Jeśli kiedyś numer ma być zagraniczny, trzeba zmienić pole, regułę i format wyświetlania
 * naraz — nie samą regułę.
 *
 * ⚠ **Walidacja po stronie serwera jest TĄ walidacją** (CLAUDE.md §6): to, co robi JavaScript
 * w `modules/konto.js`, jest wyłącznie wygodą pisania (formatowanie 3-3-3 i podpowiedź).
 * Żądanie da się wysłać bez przeglądarki, więc kształt rozstrzyga ta klasa.
 */
final class Telefon
{
    /** Odmowa — jedna treść, bo jedna reguła. */
    public const ODMOWA = 'Numer to dziewięć cyfr, bez zera na początku i bez +48.';

    /** Ile cyfr ma numer krajowy. */
    public const CYFR = 9;

    /**
     * Same cyfry z tego, co przyszło — zero zgadywania, zero obcinania do limitu.
     *
     * ⚠ Czyścimy spacje, myślniki i nawiasy, bo tak numer wygląda na papierze i tak go ludzie
     * przepisują; `+48` i wiodące `0` **też** znikają, ale dopiero gdy stoją na początku —
     * inaczej „600 048 100" straciłoby środek.
     */
    public static function cyfry(string $wejscie): string
    {
        $czyste = preg_replace('/[\s\-()]+/u', '', $wejscie) ?? '';
        $czyste = preg_replace('/^(\+?48|0048)/', '', $czyste) ?? '';
        $czyste = preg_replace('/^0+/', '', $czyste) ?? '';

        return preg_replace('/\D+/', '', $czyste) ?? '';
    }

    /** Czy to poprawny numer krajowy — dokładnie dziewięć cyfr, pierwsza nie jest zerem. */
    public static function poprawny(string $wejscie): bool
    {
        $cyfry = self::cyfry($wejscie);

        return strlen($cyfry) === self::CYFR && $cyfry[0] !== '0';
    }

    /**
     * Postać do ZAPISU: `+48600100200`, bez spacji. Jedna postać w bazie znaczy, że numer da się
     * porównać i wyszukać; formatowanie jest sprawą wyświetlania, nie przechowywania.
     */
    public static function doZapisu(string $wejscie): ?string
    {
        return self::poprawny($wejscie) ? '+48' . self::cyfry($wejscie) : null;
    }

    /**
     * Postać do WYŚWIETLENIA: `+48 600 100 200`.
     *
     * ⚠ Numer o innej długości (zastany, wpisany kiedyś przez administratora w dowolnym
     * kształcie) oddajemy **bez zmian**. Formatowanie na siłę zrobiłoby z numeru zagranicznego
     * napis, który wygląda na polski — a to gorsze niż brzydki numer.
     */
    public static function doWyswietlenia(?string $zapisany): ?string
    {
        if ($zapisany === null || trim($zapisany) === '') {
            return null;
        }
        $cyfry = self::cyfry($zapisany);
        if (strlen($cyfry) !== self::CYFR) {
            return trim($zapisany);
        }

        return '+48 ' . substr($cyfry, 0, 3) . ' ' . substr($cyfry, 3, 3) . ' ' . substr($cyfry, 6, 3);
    }
}
