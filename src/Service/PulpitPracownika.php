<?php

declare(strict_types=1);

namespace Wezurlop\Service;

use Wezurlop\Repository\PracownikRepository;
use Wezurlop\Support\DataDnia;
use Wezurlop\Support\Odmiana;
use Wezurlop\Support\WorkTime;

/**
 * Pulpit pracownika: pięć liczb, tabela „Moje nieobecności”, karta limitu.
 * Strefa jawna (CLAUDE.md §15) — prezentacja danych z kadr, zero reguł biznesowych.
 *
 * ⚠ **Nie liczymy nic sami.** Dni i minuty przychodzą z programu kadrowego; tutaj dzieje się
 * wyłącznie przeliczenie minut na dni i godziny do czytania (`WorkTime::parts`) oraz sklejenie
 * ciągu oznaczonego polem `kontynuacja`. Własne liczenie równoległe dałoby dwie prawdy i żadnego
 * sposobu, żeby rozstrzygnąć, która obowiązuje (zlecenie §2).
 *
 * ⚠ Dzielnik to **zawsze 480**, także przy niepełnym etacie — tak liczy program kadrowy
 * (`docs/ksztalt-danych-r2.md`, rekord `limit`). Dlatego `WorkTime::parts()` woła się tu bez normy
 * kalendarza; karta kadrowej w `/dane` dzieli przez `normaUrlopowaMin` zatrudnienia i przy dniówce
 * innej niż 8 h obie liczby by się rozjechały. Zgłoszone w raporcie jako otwarta rozbieżność.
 */
final class PulpitPracownika
{
    /** Ile pozycji pokazuje tabela na pulpicie — pełna lista jest w kalendarzu. */
    public const NIEOBECNOSCI_NA_PULPICIE = 5;

    /** Rodzaj limitu, który odpowiada na pytanie „ile mi zostało urlopu”. */
    public const LIMIT_URLOPOWY = 'wypoczynkowy';

    /** ⚠ Znak minus (U+2212), nie łącznik: „-2 dni” w tej samej linii co cyfry wygląda jak literówka. */
    private const MINUS = "\u{2212}";

    public function __construct(private readonly PracownikRepository $dane)
    {
    }

    /**
     * Wszystko, co pulpit ma wyświetlić dla jednego zatrudnienia i jednego roku.
     *
     * @param array<string, mixed> $zatrudnienie wiersz z `PracownikRepository::mojeZatrudnienie()`
     * @return array<string, mixed>
     */
    public function pulpit(array $zatrudnienie, int $rok, string $dzis): array
    {
        $id = (int) $zatrudnienie['id'];
        $saldo = $this->dane->saldo($id, $rok);
        $urlopowy = self::rodzaj($saldo, self::LIMIT_URLOPOWY);
        $podsumowanie = $this->dane->podsumowanie($id, $rok);
        $najblizsza = $this->dane->najblizsza($id, $dzis);
        // ⚠ Daty formatuje JEDEN helper (`DataDnia`), a nie szablon: inaczej tabela na pulpicie
        // i podpowiedź w kalendarzu zapisywałyby ten sam zakres dwoma sposobami.
        $wiersze = array_map(
            static fn (array $w): array => $w + ['kiedy' => DataDnia::zakres($w['od'], $w['do'])],
            self::sklej($this->dane->nieobecnosci($id, $rok, self::NIEOBECNOSCI_NA_PULPICIE + 5)),
        );

        return [
            'rok' => $rok,
            'lata' => $this->dane->lata($id),
            'kafelki' => self::kafelki($rok, $urlopowy, $podsumowanie, $najblizsza),
            'limit' => self::karta($urlopowy),
            'limity_inne' => array_values(array_filter(
                array_map(self::karta(...), $saldo),
                static fn (?array $k): bool => $k !== null && $k['kind'] !== self::LIMIT_URLOPOWY,
            )),
            'nieobecnosci' => array_slice($wiersze, 0, self::NIEOBECNOSCI_NA_PULPICIE),
            'nieobecnosci_wiecej' => max(0, $podsumowanie['liczba'] - self::NIEOBECNOSCI_NA_PULPICIE),
            'podsumowanie' => $podsumowanie,
        ];
    }

    /**
     * Pięć kafelków paska. Piąty **nie jest liczbą** i to jest sedno (zlecenie §1):
     * „Wnioski oczekujące: 0” znaczyłoby „nic nie czeka”, a prawda brzmi „tego jeszcze nie ma”.
     *
     * @param array<string, mixed>|null $urlopowy
     * @param array{liczba: int, dni: int} $podsumowanie
     * @param array<string, mixed>|null $najblizsza
     * @return list<array<string, mixed>>
     */
    private static function kafelki(int $rok, ?array $urlopowy, array $podsumowanie, ?array $najblizsza): array
    {
        return [
            self::kafelekPozostalo($rok, $urlopowy),
            self::kafelekWykorzystane($urlopowy),
            self::kafelekNajblizsza($najblizsza),
            [
                'klucz' => 'Nieobecności w ' . $rok,
                'wartosc' => (string) $podsumowanie['liczba'],
                'reszta' => null,
                'podpis' => $podsumowanie['liczba'] === 0
                    ? 'brak wpisów z kadr'
                    : $podsumowanie['dni'] . ' ' . self::dniRoboczych($podsumowanie['dni']),
                'stan' => $podsumowanie['liczba'] === 0 ? 'brak' : 'liczba',
            ],
            [
                // ⚠ Podpis jest jednowierszowy świadomie: wysokość paska bierze się z najwyższego
                // kafelka, a makieta ma 122 px. Dłuższe zdanie rozpycha pasek na wszystkich ekranach.
                'klucz' => 'Wnioski oczekujące',
                'wartosc' => '—',
                'reszta' => null,
                'podpis' => 'wnioski dojdą w kolejnym etapie',
                'stan' => 'brak',
            ],
        ];
    }

    /**
     * ⚠ Trzy różne stany, trzy różne komunikaty — i ani jeden z nich nie brzmi „0 dni”:
     * brak wiersza (kadry nie przysłały limitu w ogóle), `naliczony: false` (wiersz jest, limitu
     * jeszcze nie założono) i wartość ujemna (niezgodność, która powstała **poza nami**).
     *
     * @param array<string, mixed>|null $urlopowy
     * @return array<string, mixed>
     */
    private static function kafelekPozostalo(int $rok, ?array $urlopowy): array
    {
        $klucz = 'Pozostało w ' . $rok;
        if ($urlopowy === null) {
            return ['klucz' => $klucz, 'wartosc' => 'brak danych', 'reszta' => null,
                'podpis' => 'kadry nie przysłały limitu', 'stan' => 'brak'];
        }
        if (!$urlopowy['naliczony']) {
            // Zero znaczyłoby „wykorzystałeś wszystko” i w styczniu dałoby telefon do kadr
            // od całej firmy naraz (zlecenie §3.1).
            // ⚠ Zdanie krótkie z POMIARU, nie z upodobania: pełne „limit nie jest jeszcze
            // założony / kadry zakładają go zwykle w drugiej połowie stycznia" dało pasek
            // 137,7 px przy makiecie 122 px. Skracamy zdanie, nie rozpychamy paska (§1).
            // Całe wyjaśnienie stoi na karcie limitu, gdzie jest miejsce na dwa zdania.
            return ['klucz' => $klucz, 'wartosc' => 'nie jest założony', 'reszta' => null,
                'podpis' => 'kadry zakładają limity w styczniu', 'stan' => 'brak'];
        }
        $zapis = self::zapis($urlopowy['pozostalo_min']);
        if (($urlopowy['pozostalo_min'] ?? 0) < 0) {
            // ⚠ Minusa nie kasujemy i nie zaokrąglamy. Komunikat NIE brzmi „przekroczyłeś limit”:
            // system pilnuje limitu, więc pracownik nie mógł tego zrobić (zlecenie §3.2).
            return ['klucz' => $klucz, 'wartosc' => $zapis['glowna'], 'reszta' => $zapis['reszta'],
                'podpis' => 'stan do sprawdzenia w kadrach', 'stan' => 'odchylenie'];
        }

        return ['klucz' => $klucz, 'wartosc' => $zapis['glowna'], 'reszta' => $zapis['reszta'],
            'podpis' => null, 'stan' => 'liczba'];
    }

    /**
     * @param array<string, mixed>|null $urlopowy
     * @return array<string, mixed>
     */
    private static function kafelekWykorzystane(?array $urlopowy): array
    {
        if ($urlopowy === null || !$urlopowy['naliczony']) {
            return ['klucz' => 'Wykorzystane', 'wartosc' => '—', 'reszta' => null,
                'podpis' => 'bez limitu nie ma z czego liczyć', 'stan' => 'brak'];
        }
        $zapis = self::zapis($urlopowy['wykorzystane_min']);
        $start = self::zapis($urlopowy['na_start_min']);

        return [
            'klucz' => 'Wykorzystane',
            'wartosc' => $zapis['glowna'],
            'reszta' => $zapis['reszta'],
            'podpis' => 'z ' . trim($start['glowna'] . ' ' . ($start['reszta'] ?? '')),
            'stan' => 'liczba',
        ];
    }

    /**
     * @param array<string, mixed>|null $najblizsza
     * @return array<string, mixed>
     */
    private static function kafelekNajblizsza(?array $najblizsza): array
    {
        if ($najblizsza === null) {
            return ['klucz' => 'Najbliższa nieobecność', 'wartosc' => '—', 'reszta' => null,
                'podpis' => 'nic nie jest zaplanowane', 'stan' => 'brak'];
        }
        $dni = $najblizsza['dni'];

        return [
            'klucz' => 'Najbliższa nieobecność',
            'wartosc' => DataDnia::dzienMiesiac($najblizsza['od']),
            'reszta' => null,
            'podpis' => $najblizsza['rodzaj'] . ($dni === null ? '' : ', ' . $dni . ' ' . self::dniSlowo($dni)),
            'stan' => 'liczba',
        ];
    }

    /**
     * Karta limitu: wymiar podstawowy, zaległy, dostępne na start, wykorzystane, pozostało.
     *
     * @param array<string, mixed>|null $saldo
     * @return array<string, mixed>|null
     */
    private static function karta(?array $saldo): ?array
    {
        if ($saldo === null) {
            return null;
        }

        return [
            'kind' => $saldo['kind'],
            'naliczony' => $saldo['naliczony'],
            'poza_oknem' => $saldo['poza_oknem'],
            'wymiar' => self::zapis($saldo['wymiar_min']),
            'zalegly' => self::zapis($saldo['zalegly_min']),
            'na_start' => self::zapis($saldo['na_start_min']),
            'wykorzystane' => self::zapis($saldo['wykorzystane_min']),
            'korekty' => self::zapis($saldo['korekty_min']),
            'ma_korekty' => ($saldo['korekty_min'] ?? 0) !== 0,
            'pozostalo' => self::zapis($saldo['pozostalo_min']),
            'ujemne' => ($saldo['pozostalo_min'] ?? 0) < 0,
            // ⚠ Sam licznik, bez „z 4 dni”: liczby dni na żądanie R2 nie przysyła, a dopisanie
            // limitu z głowy byłoby polem bez źródła (zlecenie §0 i uwaga o „stanowisku”).
            'na_zadanie_dni' => $saldo['na_zadanie_dni'],
        ];
    }

    /**
     * Minuty na dni i godziny do wyświetlenia — **jedyne** miejsce, w którym ten zapis powstaje.
     *
     * Godziny wchodzą tylko wtedy, gdy reszta jest niezerowa: inaczej większość ludzi widziałaby
     * zbędne „0 h” (zlecenie §2). Gdy dni nie ma, a godziny są — godziny stają się liczbą główną,
     * bo „0 dni 4 godz.” czyta się gorzej niż „4 godz.”.
     *
     * @return array{glowna: string, reszta: ?string, minuty: ?int}
     */
    public static function zapis(?int $minuty): array
    {
        if ($minuty === null) {
            return ['glowna' => '—', 'reszta' => null, 'minuty' => null];
        }
        if ($minuty === 0) {
            return ['glowna' => '0 dni', 'reszta' => null, 'minuty' => 0];
        }
        $s = WorkTime::parts($minuty);
        $znak = $s['ujemne'] ? self::MINUS : '';
        $reszta = null;
        if ($s['godziny'] > 0) {
            $reszta = $s['godziny'] . ' godz.';
        } elseif ($s['minuty'] > 0) {
            $reszta = $s['minuty'] . ' min';
        }
        if ($s['dni'] === 0) {
            return ['glowna' => $znak . ($reszta ?? '0 dni'), 'reszta' => null, 'minuty' => $minuty];
        }

        return [
            'glowna' => $znak . $s['dni'] . ' ' . self::dniSlowo($s['dni']),
            'reszta' => $reszta,
            'minuty' => $minuty,
        ];
    }

    /**
     * Sklejenie ciągu oznaczonego polem `kontynuacja` w jedną pozycję z pełnym zakresem dat.
     *
     * ⚠ Sklejamy **wyłącznie** na podstawie pola z R2. Sąsiadujące wpisy bez tego pola zostają
     * osobno, choć w danych roboczych widać pary rozdzielone granicą miesiąca (np. 26–31.01
     * i 01–06.02): domyślanie się ciągu z samych dat byłoby liczeniem za kadry, a tego zlecenie
     * zabrania. ⚠ W danych roboczych `kontynuacja` jest wszędzie `NULL`, więc ta ścieżka jest
     * sprawdzona **testem** (`tests/pracownik.php`), nie pomiarem na ekranie — mówię to wprost.
     *
     * @param list<array<string, mixed>> $wiersze najnowsze pierwsze
     * @return list<array<string, mixed>> najnowsze pierwsze, z polem `wpisow`
     */
    public static function sklej(array $wiersze): array
    {
        $rosnaco = array_reverse($wiersze);
        $ciagi = [];
        foreach ($rosnaco as $wiersz) {
            $wiersz['wpisow'] = 1;
            $ostatni = $ciagi === [] ? null : count($ciagi) - 1;
            $poprzedni = $ostatni === null ? null : $ciagi[$ostatni];
            if (
                $wiersz['kontynuacja'] === true
                && $poprzedni !== null
                && $poprzedni['zrodlo'] === $wiersz['zrodlo']
                && $poprzedni['do'] <= $wiersz['od']
            ) {
                $ciagi[$ostatni]['do'] = max($poprzedni['do'], $wiersz['do']);
                $ciagi[$ostatni]['dni'] = self::suma($poprzedni['dni'], $wiersz['dni']);
                $ciagi[$ostatni]['minuty'] = self::suma($poprzedni['minuty'], $wiersz['minuty']);
                $ciagi[$ostatni]['wpisow'] = $poprzedni['wpisow'] + 1;
                continue;
            }
            $ciagi[] = $wiersz;
        }

        return array_reverse($ciagi);
    }

    private static function suma(?int $a, ?int $b): ?int
    {
        return $a === null && $b === null ? null : (int) $a + (int) $b;
    }

    /**
     * @param list<array<string, mixed>> $saldo
     * @return array<string, mixed>|null
     */
    private static function rodzaj(array $saldo, string $kind): ?array
    {
        foreach ($saldo as $wiersz) {
            if ($wiersz['kind'] === $kind) {
                return $wiersz;
            }
        }

        return null;
    }

    /** Odmiana mieszka w jednym miejscu (`Odmiana`) — tu tylko jej używamy. */
    private static function dniSlowo(int $dni): string
    {
        return Odmiana::dni($dni);
    }

    private static function dniRoboczych(int $dni): string
    {
        return Odmiana::dniRobocze($dni);
    }
}
