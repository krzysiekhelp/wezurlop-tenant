<?php

declare(strict_types=1);

namespace Wezurlop\Support;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Znacznik czasu z bazy (UTC) na czas w strefie tenanta (docs/system-wizualny.md §10).
 *
 * Strefa jawna, funkcja czysta — bez bazy i bez plików. Proces PHP pracuje w UTC
 * (`date_default_timezone_set('UTC')` w bootstrapie), więc wszystko, co przychodzi z bazy,
 * jest w UTC i dopiero tutaj zmienia strefę.
 *
 * ⚠ **Data kalendarzowa to nie znacznik czasu** (§10 punkt 4). Początek i koniec nieobecności,
 * data zatrudnienia i zwolnienia to DATY — przeliczenie ich przez strefę przesunęłoby urlop
 * o jeden dzień. Dlatego ta klasa przyjmuje wyłącznie znaczniki `Y-m-d H:i:s`, a wartość bez
 * części godzinowej oddaje NIETKNIĘTĄ. Rozstrzyga typ danych, nie wygląd wartości.
 */
final class Strefa
{
    public const DOMYSLNA = 'Europe/Warsaw';

    /** Znacznik z bazy: „2026-09-20 17:27:58”. Data kalendarzowa nie ma części godzinowej. */
    private const ZNACZNIK = '/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}/';

    /**
     * Data i godzina w strefie tenanta: „20.09.2026, 19:27”.
     *
     * ⚠ Wartość BEZ części godzinowej wraca nietknięta — to data kalendarzowa, a nie moment
     * (§10 punkt 4). Dzięki temu filtr użyty przez pomyłkę na dacie urlopu niczego nie przesunie.
     */
    public static function czas(?string $utc, string $strefa, string $format = 'd.m.Y, H:i'): string
    {
        if ($utc === null || trim($utc) === '') {
            return '—';
        }
        $moment = self::moment($utc, $strefa);

        return $moment === null ? $utc : $moment->format($format);
    }

    /**
     * Nagłówek dnia na liście zdarzeń: „Dziś, 18.10.2026", „Wczoraj, 17.10.2026" albo sama data.
     *
     * ⚠ „Dziś" liczy się W STREFIE TENANTA, nie w strefie serwera (zlecenie Z36 §4). O 23:30
     * w Warszawie serwer w UTC ma jeszcze poprzedni dzień — nagłówek „Wczoraj" nad wpisami
     * sprzed pięciu minut byłby gorszy niż brak nagłówka, bo wyglądałby na pewny.
     * ⚠ Słowo stoi PRZED datą, a nie zamiast niej: data zostaje, żeby dało się zestawić
     * wpis z innym dokumentem bez liczenia, kiedy „dziś" było.
     */
    public static function naglowekDnia(?string $utc, string $strefa): string
    {
        $moment = $utc === null || trim($utc) === '' ? null : self::moment($utc, $strefa);
        if ($moment === null) {
            return self::czas($utc, $strefa, 'd.m.Y');
        }
        $dzien = $moment->format('Y-m-d');
        $dzis = new \DateTimeImmutable('now', new \DateTimeZone($strefa));
        $data = $moment->format('d.m.Y');
        if ($dzien === $dzis->format('Y-m-d')) {
            return 'Dziś, ' . $data;
        }

        return $dzien === $dzis->modify('-1 day')->format('Y-m-d') ? 'Wczoraj, ' . $data : $data;
    }

    /** Sama godzina — do tabel, gdzie data stoi obok. */
    public static function godzina(?string $utc, string $strefa): string
    {
        return self::czas($utc, $strefa, 'H:i:s');
    }

    /**
     * Godzina ZDARZENIA bez sekund — „12:45”.
     *
     * ⚠ Osobna nazwa, a nie `godzina()` obcinana w szablonie (zlecenie Z35, 18.10.2026).
     * Reguła z 22.09.2026 („prognoza bez sekund, pomiar z sekundami”) mówi o tym, żeby nie
     * UDAWAĆ pomiaru tam, gdzie go nie ma — i dalej obowiązuje. Tutaj jest odwrotnie: sekunda
     * jest zmierzona, ale w tabeli dwudziestu przebiegów nie rozróżnia niczego, bo przebiegi
     * idą co kilka minut, a rozdziela je godzina. Krótsza postać mówi MNIEJ, niż wiemy,
     * i to jest bezpieczny kierunek; pełny czas z sekundami zostaje w `czas()` pod ręką.
     */
    public static function godzinaKrotka(?string $utc, string $strefa): string
    {
        return self::czas($utc, $strefa, 'H:i');
    }

    /**
     * Godzina PROGNOZY — „12:45”, bez sekund.
     *
     * ⚠ Osobna funkcja, nie `godzina()` z obciętym końcem: reguła przyjęta 22.09.2026 mówi
     * **prognoza bez sekund, pomiar z sekundami** („liczba ma nieść tyle pewności, ile jej
     * naprawdę za nią stoi”). „Około 12:45:02” udawałoby pomiar tam, gdzie jest wyliczenie
     * z zadeklarowanej częstotliwości crona. Jedna nazwa dla reguły jest tańsza niż sztuczka
     * `czas()|split(', ')|last` powtarzana w każdym szablonie.
     */
    public static function prognoza(?string $utc, string $strefa): string
    {
        return self::czas($utc, $strefa, 'H:i');
    }

    /** Pełny znacznik do miejsc technicznych: „20.09.2026, 19:27:58”. */
    /**
     * **Ostatnie logowanie w kolumnie listy** (zlecenie Z59 §3): „dziś, 09:41" dla dzisiejszego
     * dnia, sama data dla wcześniejszego, „—" gdy nigdy.
     *
     * ⚠ Dzisiejsze logowanie ma GODZINĘ, a wcześniejsze nie — i to nie jest niekonsekwencja.
     * Pytanie o konto administracyjne brzmi „czy ktoś go dziś używał", więc przy dzisiejszym
     * dniu rozróżnia godzina, a przy starszym sama data: „14.09.2026, 11:02" w kolumnie
     * szerokiej na 12% daje szum, a nie informację.
     *
     * ⚠ Słowo małą literą, bo stoi w komórce tabeli, a nie w nagłówku dnia (`naglowekDnia()`
     * pisze „Dziś" wielką, bo tam jest początkiem zdania).
     */
    public static function logowanie(?string $utc, string $strefa): string
    {
        $moment = $utc === null || trim($utc) === '' ? null : self::moment($utc, $strefa);
        if ($moment === null) {
            return '—';
        }
        $dzis = new \DateTimeImmutable('now', new \DateTimeZone($strefa));

        return $moment->format('Y-m-d') === $dzis->format('Y-m-d')
            ? 'dziś, ' . $moment->format('H:i')
            : $moment->format('d.m.Y');
    }

    public static function czasZSekundami(?string $utc, string $strefa): string
    {
        return self::czas($utc, $strefa, 'd.m.Y, H:i:s');
    }

    /** Data i godzina z nazwą strefy — do poczty, eksportów i diagnostyki (§10 punkt 7). */
    public static function czasZeStrefa(?string $utc, string $strefa): string
    {
        if ($utc === null || trim($utc) === '') {
            return '—';
        }
        $moment = self::moment($utc, $strefa);

        return $moment === null ? $utc : $moment->format('d.m.Y, H:i') . ' (' . $strefa . ')';
    }

    /**
     * Data, godzina **z sekundami** i nazwa strefy — forma do zestawiania z logiem i z dziennikiem.
     *
     * ⚠ Sekundy nie są ozdobą: dzisiejsze „wysłałem, nie przyszło" rozstrzygnęło porównanie
     * trzech znaczników co do sekundy (kliknięcie 09:41:47, przebieg crona 09:45, wysyłka
     * 09:45:02). Bez sekund takiego zestawienia nie da się zrobić.
     *
     * ⚠ Nazwa strefy **zawsze**: tenant ma własną strefę w ustawieniach, a baza trzyma UTC —
     * godzina bez strefy to drugi sposób na rozjechanie się pomiarów.
     */
    public static function czasSekundyZeStrefa(?string $utc, string $strefa): string
    {
        if ($utc === null || trim($utc) === '') {
            return '—';
        }
        $moment = self::moment($utc, $strefa);

        return $moment === null ? $utc : $moment->format('d.m.Y, H:i:s') . ' (' . $strefa . ')';
    }

    /** Nazwa strefy jest prawdziwa? Wartość spoza listy PHP nigdy nie trafia do formatowania. */
    public static function istnieje(string $strefa): bool
    {
        return in_array($strefa, DateTimeZone::listIdentifiers(), true);
    }

    /**
     * Strefa do zapisania przy instalacji: podpowiedź z PHP, gdy to prawdziwa nazwa strefy,
     * a nie „UTC” z pustego pola `date.timezone` (§10 punkt 2).
     */
    public static function podpowiedz(string $zPhp): string
    {
        return $zPhp !== '' && $zPhp !== 'UTC' && self::istnieje($zPhp) ? $zPhp : self::DOMYSLNA;
    }

    /** Znacznik z bazy (UTC) przeliczony na strefę tenanta — jedyne takie miejsce w projekcie. */
    private static function moment(?string $utc, string $strefa): ?DateTimeImmutable
    {
        // ⚠ Wartość bez godziny to data kalendarzowa — oddajemy ją bez dotykania strefy.
        if ($utc === null || $utc === '' || preg_match(self::ZNACZNIK, $utc) !== 1) {
            return null;
        }
        try {
            return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone(self::istnieje($strefa) ? $strefa : self::DOMYSLNA));
        } catch (Throwable) {
            return null;
        }
    }
}
