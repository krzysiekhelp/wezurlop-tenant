<?php

declare(strict_types=1);

namespace Wezurlop\Support;

use Wezurlop\Service\ZakresWidocznosci;

/**
 * **Wpis dziennika o regule widoczności kalendarza, powiedziany po polsku** (zlecenie Z57 U2).
 *
 * ⚠ **Powód istnienia tej klasy.** W dzienniku stoi `account:4 → persons · ze_szczegolami`,
 * bo treść wpisu to **identyfikatory** — i tak ma zostać (zlecenie Z53 §5, CLAUDE.md §18):
 * para „kto widzi czyje nieobecności" opisuje dostęp do danych o zdrowiu, więc nazwiska nie
 * wchodzą do tabeli, z której się ich potem nie kasuje. Nazwy **składa ekran przy odczycie**,
 * z bazy — czyli tutaj.
 *
 * ⚠ **Mianownik i znak „›", nie „widzi".** Zdanie „X widzi Jan Testowy" wymagałoby odmiany
 * nazwiska przez przypadki, czego nie da się zrobić poprawnie dla obcych nazwisk; a zdanie
 * „X widzi 2 osoby" mówiłoby mniej niż nazwiska, które ekran i tak pokazuje na
 * `/admin/widocznosc`. Dlatego podmiot i cel stoją w mianowniku, rozdzielone „›" — tym samym
 * znakiem, co podpis reguły na ekranie widoczności.
 * ⚠ Znak „→" (strzałka) **nie istnieje w używanym kroju pisma** i rysuje się jako pusty
 * prostokąt — zmierzone 20.10.2026 na zrzucie, nie na tekście z DOM-u.
 *
 * ⚠ **Czego w dzienniku nie ma, tego nie zmyślamy.** Wpisy sprzed Z57 nie niosą numerów
 * wskazanych zatrudnień (sam napis `persons`), więc cel takiego wpisu brzmi „wskazane osoby",
 * a nie „2 osoby" wyliczone z dzisiejszego stanu reguły. Stan dzisiejszy nie jest stanem
 * z chwili zapisu — a dziennik opisuje tę chwilę.
 *
 * Czysty helper, bez stanu i bez bazy (CLAUDE.md §1): nazwy dostaje gotową mapą.
 */
final class ZdanieDostepu
{
    /** Rodzina kodów, której dotyczy ta klasa. */
    public const PREFIKS = 'calendar_access.';

    /** Wariant zdania dla reguł stałych firmy (`#stala` w katalogu `ZdanieZdarzenia`). */
    public const WARIANT_STALA = 'stala';

    /** Zakres wglądu słowami — jedno miejsce na te trzy napisy. */
    private const ZAKRESY = [
        ZakresWidocznosci::ZE_SZCZEGOLAMI => 'ze szczegółami',
        ZakresWidocznosci::BEZ_SZCZEGOLOW => 'bez szczegółów',
        ZakresWidocznosci::BRAK           => 'brak dostępu',
    ];

    /** Ile nazwisk wyliczamy, zanim przejdziemy na „i N innych". */
    private const NAZWISK_W_WYLICZENIU = 2;

    public static function dotyczy(string $kod): bool
    {
        return str_starts_with($kod, self::PREFIKS);
    }

    /**
     * Identyfikatory do rozwiązania na nazwy — **ze wszystkich wpisów strony naraz**, żeby
     * ekran zrobił trzy zapytania, a nie trzy na wiersz.
     *
     * @param list<array<string, mixed>> $wpisy wiersze z `EventLogRepository::page()`
     * @return array{konta: list<int>, etaty: list<int>, grupy: list<int>}
     */
    public static function identyfikatory(array $wpisy): array
    {
        $konta = $etaty = $grupy = [];
        foreach ($wpisy as $wpis) {
            if (!self::dotyczy((string) ($wpis['kod'] ?? ''))) {
                continue;
            }
            $detale = is_array($wpis['detale'] ?? null) ? $wpis['detale'] : [];
            $czesci = [(string) ($detale['kto'] ?? ''), (string) ($detale['czyje'] ?? '')];
            // ⚠ „było" przy zwykłej regule to PODPIS, czyli druga porcja identyfikatorów:
            // bez nich wpis o zmianie pokazywałby nazwy tylko dla stanu po zmianie.
            foreach (explode('|', (string) ($detale['bylo'] ?? '')) as $kawalek) {
                $czesci[] = $kawalek;
            }
            foreach ($czesci as $kawalek) {
                [$rodzaj, $ids] = self::rozbij($kawalek);
                match ($rodzaj) {
                    ZakresWidocznosci::KTO_KONTO => $konta = array_merge($konta, $ids),
                    ZakresWidocznosci::KTO_PRZELOZONY, ZakresWidocznosci::CZYJE_OSOBY => $etaty = array_merge($etaty, $ids),
                    ZakresWidocznosci::KTO_GRUPA, ZakresWidocznosci::CZYJE_GRUPY => $grupy = array_merge($grupy, $ids),
                    default => null,
                };
            }
        }

        return [
            'konta' => array_values(array_unique($konta)),
            'etaty' => array_values(array_unique($etaty)),
            'grupy' => array_values(array_unique($grupy)),
        ];
    }

    /**
     * Wartości do wstawienia w zdanie — nazwy zamiast identyfikatorów.
     *
     * ⚠ Zwracamy **tylko to, co umiemy nazwać**. Klucz z pustą wartością sprawia, że część
     * opcjonalna zdania znika (`ZdanieZdarzenia::zloz`), a nie że na ekran trafia „:" bez treści.
     *
     * @param array<string, mixed> $detale treść wpisu (identyfikatory)
     * @param array{konto?: array<int, string>, etat?: array<int, string>, grupa?: array<int, string>} $nazwy
     * @return array<string, string>
     */
    public static function wartosci(array $detale, array $nazwy): array
    {
        $kto = (string) ($detale['kto'] ?? '');
        $zakres = (string) ($detale['zakres'] ?? '');
        $bylo = (string) ($detale['bylo'] ?? '');
        $stala = self::stala($kto);

        $out = [
            'kto'     => self::podmiot($kto, $nazwy),
            'czyje'   => self::cel((string) ($detale['czyje'] ?? ''), $nazwy),
            'zakres'  => self::ZAKRESY[$zakres] ?? '',
            'wariant' => $stala ? self::WARIANT_STALA : '',
        ];
        // ⚠ „było" ma DWA kształty, bo tak je zapisuje `WidocznoscService`: przy regule stałej
        // sam zakres (podmiotu i celu nie da się w niej zmienić), przy zwykłej — cały podpis.
        // Rozstrzyga zawartość, nie wariant: wpis z 19.10 nie wie o tym podziale.
        $out['bylo'] = self::ZAKRESY[$bylo] ?? ($bylo === '' ? '' : self::podpis($bylo, $nazwy));

        return $out;
    }

    /** Czy podmiotem jest reguła stała firmy (przełożeni albo administratorzy). */
    private static function stala(string $kto): bool
    {
        [$rodzaj] = self::rozbij($kto);

        return $rodzaj === ZakresWidocznosci::KTO_PRZELOZENI || $rodzaj === ZakresWidocznosci::KTO_ADMINI;
    }

    /**
     * `account:4` → `['account', [4]]`, `groups:1,2` → `['groups', [1, 2]]`, `company` → `['company', []]`.
     *
     * @return array{0: string, 1: list<int>}
     */
    private static function rozbij(string $wartosc): array
    {
        $kawalki = explode(':', trim($wartosc), 2);
        $ids = [];
        foreach (explode(',', $kawalki[1] ?? '') as $id) {
            $id = (int) trim($id);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return [$kawalki[0], $ids];
    }

    /**
     * @param array{konto?: array<int, string>, etat?: array<int, string>, grupa?: array<int, string>} $nazwy
     */
    private static function podmiot(string $wartosc, array $nazwy): string
    {
        [$rodzaj, $ids] = self::rozbij($wartosc);
        $id = $ids[0] ?? 0;

        return match ($rodzaj) {
            ZakresWidocznosci::KTO_PRZELOZENI => 'Każdy przełożony',
            ZakresWidocznosci::KTO_ADMINI     => 'Administratorzy',
            ZakresWidocznosci::KTO_KONTO      => $nazwy['konto'][$id] ?? self::usuniety('konto', $id),
            ZakresWidocznosci::KTO_PRZELOZONY => $nazwy['etat'][$id] ?? self::usuniety('etat', $id),
            ZakresWidocznosci::KTO_GRUPA      => isset($nazwy['grupa'][$id])
                ? 'grupa „' . $nazwy['grupa'][$id] . '”'
                : self::usuniety('grupa', $id),
            default => '',
        };
    }

    /**
     * Cel reguły w MIANOWNIKU — tak, jak stoi w podpisie reguły na ekranie widoczności.
     *
     * @param array{konto?: array<int, string>, etat?: array<int, string>, grupa?: array<int, string>} $nazwy
     */
    private static function cel(string $wartosc, array $nazwy): string
    {
        [$rodzaj, $ids] = self::rozbij($wartosc);

        return match ($rodzaj) {
            ZakresWidocznosci::CZYJE_PODWLADNI => 'swoi podwładni',
            ZakresWidocznosci::CZYJE_FIRMA     => 'cała firma',
            ZakresWidocznosci::CZYJE_GRUPY     => $ids === []
                ? 'wskazane grupy'
                : 'grupy: ' . self::wyliczenie($ids, $nazwy['grupa'] ?? [], 'grupa'),
            // ⚠ Wpis sprzed Z57 nie niesie numerów — i wtedy mówimy tyle, ile wiemy.
            ZakresWidocznosci::CZYJE_OSOBY     => $ids === []
                ? 'wskazane osoby'
                : self::wyliczenie($ids, $nazwy['etat'] ?? [], 'etat'),
            default => '',
        };
    }

    /**
     * Podpis stanu poprzedniego (`kto:id|cel[|zakres]`) tymi samymi słowami, co stan obecny.
     *
     * @param array{konto?: array<int, string>, etat?: array<int, string>, grupa?: array<int, string>} $nazwy
     */
    private static function podpis(string $wartosc, array $nazwy): string
    {
        $kawalki = explode('|', $wartosc);
        $czesci = array_filter([
            self::podmiot($kawalki[0] ?? '', $nazwy),
            self::cel($kawalki[1] ?? '', $nazwy),
        ], static fn (string $x): bool => $x !== '');
        if ($czesci === []) {
            return '';
        }
        $zakres = self::ZAKRESY[$kawalki[2] ?? ''] ?? '';

        return implode(' › ', $czesci) . ($zakres === '' ? '' : ' · ' . $zakres);
    }

    /**
     * Nazwiska albo nazwy grup: najwyżej dwie, potem „i N innych".
     *
     * @param list<int> $ids
     * @param array<int, string> $nazwy
     */
    private static function wyliczenie(array $ids, array $nazwy, string $rodzaj): string
    {
        $widoczne = [];
        foreach (array_slice($ids, 0, self::NAZWISK_W_WYLICZENIU) as $id) {
            $widoczne[] = $nazwy[$id] ?? self::usuniety($rodzaj, $id);
        }
        $reszta = count($ids) - count($widoczne);

        return implode(', ', $widoczne) . ($reszta > 0 ? ' i ' . $reszta . ' ' . Odmiana::inne($reszta) : '');
    }

    /**
     * ⚠ **Nigdy pusto.** Usunięte konto, zwolniony etat i skasowana grupa zostają w dzienniku
     * jako numer: pusta komórka w audycie wygląda jak brak zdarzenia, a nie jak brak nazwy
     * (ta sama zasada, co w `EventLogController::opis()`).
     */
    private static function usuniety(string $rodzaj, int $id): string
    {
        return match ($rodzaj) {
            'konto' => 'konto #' . $id . ' — usunięte',
            'grupa' => 'grupa #' . $id . ' — usunięta',
            default => 'etat #' . $id . ' — usunięty',
        };
    }
}
