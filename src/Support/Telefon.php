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
     * **Jedna decyzja dla wszystkich trzech ekranów: zapisać, usunąć czy odmówić** (Z73).
     *
     * ⚠ Powstała, bo ta sama para linijek („jeśli niepoprawny — odmów; zapisz znormalizowany")
     * stała w trzech miejscach: w panelu osoby, w formularzu nowego konta administracyjnego
     * i na „Moim koncie". Trzy kopie reguły to trzy okazje, żeby jedna z nich została przy
     * starej — i dokładnie to się stało między Z69 a Z73: panel miał własną, luźniejszą białą
     * listę znaków i zapisywał numery w dowolnym kształcie.
     *
     * ⚠ **Pusty numer to USUNIĘCIE, nie błąd** — i to jest rozstrzygnięcie, nie wygoda:
     * pole bez wartości znaczy „nie mam numeru", a odmowa zapisu zmuszałaby do wpisania
     * czegokolwiek, żeby wyjść z formularza.
     *
     * @return array{0: ?string, 1: ?string} [numer do zapisu albo null, komunikat odmowy albo null]
     */
    public static function dlaZapisu(string $wejscie): array
    {
        $wejscie = trim($wejscie);
        if ($wejscie === '') {
            return [null, null];
        }

        return self::poprawny($wejscie) ? [self::doZapisu($wejscie), null] : [null, self::ODMOWA];
    }

    /**
     * Wartość do POLA FORMULARZA — bez prefiksu `+48`, bo prefiks jest nieruchomym napisem
     * w kontrolce (zlecenie Z73, 21.10.2026: to samo pole w panelu osoby i na „Moim koncie").
     *
     * ⚠ Numer o innej długości oddajemy **bez zmian i bez obcinania**: w bazie stoją numery
     * zapisane przed tą regułą (zmierzone 21.10.2026: dwa wiersze), a pole ma pokazać to, co
     * jest, a nie udawać, że tego nie ma. Poprawki wymaga dopiero ZAPIS — wtedy serwer odmawia
     * i mówi, jaki kształt przyjmuje. Formatowanie na siłę zrobiłoby z cudzego numeru napis
     * wyglądający na polski, a obcięcie zmieniłoby daną bez wiedzy człowieka.
     */
    public static function doPola(?string $zapisany): string
    {
        if ($zapisany === null || trim($zapisany) === '') {
            return '';
        }
        $cyfry = self::cyfry($zapisany);
        if (strlen($cyfry) !== self::CYFR) {
            return trim($zapisany);
        }

        return substr($cyfry, 0, 3) . ' ' . substr($cyfry, 3, 3) . ' ' . substr($cyfry, 6, 3);
    }

    /**
     * **Co zrobić z zastanymi numerami** — czysta funkcja dla migracji ujednolicającej
     * `person.phone` (zlecenie Z74, decyzja Krzysztofa po Z73).
     *
     * ⚠ Osobno od `dlaZapisu()`, bo pytanie jest inne: tam „co zapisać z tego, co wpisał
     * człowiek", tutaj „które z już zapisanych wierszy da się przepisać, a które zostawić".
     * Numer, który po oczyszczeniu NIE jest poprawnym numerem polskim, zostaje nietknięty —
     * migracja nie ma prawa zgadywać, co ktoś miał na myśli, wpisując numer wewnętrzny albo
     * zagraniczny.
     *
     * ⚠ Czysta i bez bazy, żeby dała się sprawdzić testem na danych fikcyjnych we wszystkich
     * trzech przypadkach (do przepisania / już poprawny / nienormalizowalny) — migracja robi
     * potem tylko `UPDATE` tym, co ta funkcja wskaże.
     *
     * @param array<int, ?string> $numery identyfikator wiersza → numer z bazy
     * @return array{zmiany: array<int, string>, pominiete: list<int>, bez_zmian: list<int>}
     */
    public static function doPrzepisania(array $numery): array
    {
        $zmiany = [];
        $pominiete = [];
        $bezZmian = [];
        foreach ($numery as $id => $numer) {
            if ($numer === null || trim($numer) === '') {
                continue;
            }
            $docelowy = self::doZapisu($numer);
            if ($docelowy === null) {
                $pominiete[] = $id;
            } elseif ($docelowy === $numer) {
                // ⚠ Ten przypadek jest powodem, dla którego migracja jest POWTARZALNA: numer
                // już w docelowej postaci nie trafia do zmian, więc drugi przebieg rusza zero
                // wierszy (warunek odbioru z Z74).
                $bezZmian[] = $id;
            } else {
                $zmiany[$id] = $docelowy;
            }
        }

        return ['zmiany' => $zmiany, 'pominiete' => $pominiete, 'bez_zmian' => $bezZmian];
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
