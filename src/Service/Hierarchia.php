<?php

declare(strict_types=1);

namespace Wezurlop\Service;

use Wezurlop\Db\Connection;
use Wezurlop\Repository\EventLogRepository;
use Wezurlop\Repository\HierarchyRepository;

/**
 * Hierarchia przełożonych — struktura do pokazania i zapis partii zmian
 * (zlecenie `hierarchia` §4–§8, makieta `docs/makieta/hierarchia`).
 *
 * ⚠ **Wygaszona karta w przeglądarce jest wygodą; zabezpieczeniem jest ten serwis.** Wszystkie
 * reguły z §6 rozstrzygają się przy zapisie, w transakcji, na stanie z bazy — nie na tym,
 * co przyszło w żądaniu (`CLAUDE.md` §10). Interfejs, który tylko „nie pozwala kliknąć",
 * jest wart dokładnie tyle co ukryty przycisk.
 *
 * ⚠ **Jedna ścieżka zapisu dla przeciągania i dla menu `⋯`** (§8): obie drogi wołają
 * `zapisz()` z tym samym ładunkiem. Dwa zapisy to dwa zestawy reguł, z których drugi
 * zawsze zostaje w tyle.
 */
final class Hierarchia
{
    /**
     * Uprawnienie, po którym poznajemy „rolę przełożonego".
     *
     * ⚠ Uprawnień urlopowych jeszcze nie ma — przychodzą z Etapem 3 (§9 zlecenia świadomie
     * ich nie dotyczy). Do tego czasu odchylenie „ma podwładnych, nie ma roli przełożonego"
     * jest **wyłączone**, a nie zapalone dla wszystkich (patrz `HierarchyRepository`).
     */
    public const UPRAWNIENIE_ZATWIERDZANIA = 'leave.approve';

    /**
     * Twardy limit głębokości przy wchodzeniu w górę łańcucha.
     *
     * ⚠ Nie jest ograniczeniem organizacyjnym, tylko **bezpiecznikiem pętli**: gdyby w bazie
     * znalazł się cykl (ręczna poprawka, import, błąd przyszłej migracji), wchodzenie w górę
     * kręciłoby się bez końca i zjadło limit 30 s zamiast zgłosić błąd. Głębokość 64 szczebli
     * to więcej, niż ma jakakolwiek firma, i mniej, niż potrzeba, żeby cokolwiek zawiesić.
     */
    public const GLEBOKOSC_MAX = 64;

    public const CEL_PRZELOZONY = 'przelozony';
    public const CEL_SZCZYT     = 'szczyt';
    public const CEL_ZDEJMIJ    = 'zdejmij';

    public const POWOD_BRAK_OSOBY   = 'to zatrudnienie nie istnieje albo nie jest czynne';
    public const POWOD_INNA_SPOLKA  = 'przełożony musi być z tej samej firmy';
    public const POWOD_SAM_SIEBIE   = 'nikt nie może być swoim przełożonym';
    public const POWOD_CYKL         = 'to ułożyłoby pętlę w hierarchii — wskazana osoba jest podwładnym tej osoby';
    public const POWOD_ZA_GLEBOKO   = 'łańcuch przełożonych jest nienaturalnie długi — przerwane bezpiecznikiem';
    public const POWOD_BRAK_CELU    = 'nie wskazano przełożonego';

    public function __construct(
        private readonly Connection $db,
        private readonly HierarchyRepository $hierarchia,
        private readonly EventLogRepository $events,
        // ⚠ Zapis hierarchii przelicza reguły widoczności (Z80 B3, droga 1): krawędź przesądza
        // o tym, kto ma podwładnych, a reguła „przełożony X › jego podwładni" bez nich nie daje nic.
        private readonly WidocznoscService $widocznosc,
    ) {
    }

    /**
     * Czy ustawienie `$supervisorId` przełożonym `$employmentId` ułożyłoby pętlę.
     *
     * ⚠ Funkcja **czysta** — bez bazy, więc da się ją zamknąć testem (`tests/hierarchia.php`).
     * Znalezisko **L2** z audytu: `A → B → A` zawiesiłoby eskalację w nieskończonej pętli,
     * więc to jest błąd walidacji, nie ostrzeżenie w interfejsie.
     *
     * @param array<int, int> $krawedzie `id zatrudnienia => id przełożonego` (stan spółki)
     * @return string|null powód odmowy albo `null`, gdy droga jest wolna
     */
    public static function powodCyklu(array $krawedzie, int $employmentId, int $supervisorId): ?string
    {
        if ($employmentId === $supervisorId) {
            return self::POWOD_SAM_SIEBIE;
        }
        // ⚠ Idziemy w GÓRĘ od nowego przełożonego: jeśli po drodze natrafimy na osobę, której
        // właśnie zmieniamy przełożonego, to znaczy, że jest ona jego (pra)przełożonym —
        // czyli powstałby cykl.
        $biezacy = $supervisorId;
        for ($krok = 0; $krok < self::GLEBOKOSC_MAX; $krok++) {
            if ($biezacy === $employmentId) {
                return self::POWOD_CYKL;
            }
            if (!isset($krawedzie[$biezacy])) {
                return null;
            }
            $biezacy = $krawedzie[$biezacy];
        }

        return self::POWOD_ZA_GLEBOKO;
    }

    /**
     * Najgłębszy poziom struktury — do paska liczb.
     *
     * ⚠ Ten sam bezpiecznik głębokości: gdyby w danych siedział cykl, liczenie poziomów
     * byłoby drugim miejscem, które zawiesza żądanie. Funkcja czysta, więc testowalna.
     *
     * @param array<int, int> $krawedzie `id => id przełożonego`
     * @param list<int> $wszystkie identyfikatory zatrudnień spółki
     */
    public static function najglebszyPoziom(array $krawedzie, array $wszystkie): int
    {
        $max = 0;
        foreach ($wszystkie as $id) {
            $poziom = 1;
            $biezacy = $id;
            while (isset($krawedzie[$biezacy]) && $poziom < self::GLEBOKOSC_MAX) {
                $biezacy = $krawedzie[$biezacy];
                $poziom++;
            }
            $max = max($max, $poziom);
        }

        return $max;
    }

    /**
     * Struktura spółki: drzewo, nieustawieni, liczby i odchylenia.
     *
     * @return array{
     *     wezly: list<array<string, mixed>>, nieustawieni: list<array<string, mixed>>,
     *     liczby: array{zatrudnionych: int, nieustawionych: int, szczytow: int,
     *         najglebszy: int, uwagi: int},
     *     rola_znana: bool
     * }
     */
    public function struktura(int $companyId): array
    {
        $zatrudnienia = $this->hierarchia->zatrudnienia($companyId);
        $krawedzie = [];
        foreach ($zatrudnienia as $z) {
            if ($z['supervisor_id'] !== null) {
                $krawedzie[$z['id']] = $z['supervisor_id'];
            }
        }
        // Liczba podwładnych bezpośrednich i całego zespołu (z podzespołami).
        $bezposredni = [];
        foreach ($krawedzie as $id => $szef) {
            $bezposredni[$szef] = ($bezposredni[$szef] ?? 0) + 1;
        }
        $zespol = [];
        foreach ($zatrudnienia as $z) {
            $biezacy = $z['id'];
            $krok = 0;
            while (isset($krawedzie[$biezacy]) && $krok < self::GLEBOKOSC_MAX) {
                $biezacy = $krawedzie[$biezacy];
                $zespol[$biezacy] = ($zespol[$biezacy] ?? 0) + 1;
                $krok++;
            }
        }

        // ⚠ Odchylenie „ma podwładnych, nie ma roli przełożonego" liczymy TYLKO wtedy, gdy
        // uprawnienie zatwierdzania w ogóle istnieje w katalogu — inaczej zapaliłoby się
        // przy każdym przełożonym i lista odchyleń byłaby w całości szumem (§7).
        $rolaZnana = $this->hierarchia->uprawnienieIstnieje(self::UPRAWNIENIE_ZATWIERDZANIA);
        $zPrawem = $rolaZnana
            ? array_fill_keys($this->hierarchia->zPrawemZatwierdzania($companyId, self::UPRAWNIENIE_ZATWIERDZANIA), true)
            : [];

        $wezly = [];
        $nieustawieni = [];
        $szczytow = 0;
        $uwagi = 0;
        foreach ($zatrudnienia as $z) {
            $ma = $bezposredni[$z['id']] ?? 0;
            $brakRoli = $rolaZnana && $ma > 0 && !isset($zPrawem[$z['id']]);
            $zablokowane = $ma > 0 && $z['konto_status'] === 'blocked';
            $wezel = $z + [
                'podwladnych'  => $ma,
                'zespol'       => $zespol[$z['id']] ?? 0,
                'brak_roli'    => $brakRoli,
                'zablokowane'  => $zablokowane,
                // „Nieustawiony" to brak przełożonego BEZ jawnej decyzji o szczycie.
                'nieustawiony' => $z['supervisor_id'] === null && !$z['szczyt'],
            ];
            if ($brakRoli || $zablokowane) {
                $uwagi++;
            }
            if ($z['szczyt']) {
                $szczytow++;
            }
            if ($wezel['nieustawiony']) {
                $nieustawieni[] = $wezel;
            }
            $wezly[] = $wezel;
        }

        return [
            'wezly'        => $wezly,
            'nieustawieni' => $nieustawieni,
            'liczby'       => [
                'zatrudnionych'  => count($zatrudnienia),
                'nieustawionych' => count($nieustawieni),
                'szczytow'       => $szczytow,
                'najglebszy'     => self::najglebszyPoziom($krawedzie, array_column($zatrudnienia, 'id')),
                'uwagi'          => $uwagi,
            ],
            'rola_znana'   => $rolaZnana,
        ];
    }

    /**
     * Kandydaci na przełożonego dla jednego zatrudnienia — **z powodem przy każdym odrzuconym**
     * (zlecenie §8).
     *
     * ⚠ To jest ścieżka bez myszki i ona **nie jest dodatkiem**: przeciąganie nie działa
     * z klawiatury, źle działa palcem i nie działa wcale przy czytniku ekranu. Dlatego lista
     * niesie te same blokady co plansza — i **mówi dlaczego**, zamiast tylko wygaszać pozycję.
     * Wygaszona pozycja bez powodu jest tym samym co przycisk, który nic nie robi.
     *
     * ⚠ **Wyszukiwanie idzie do zapytania**, nie do przeglądarki: lista rośnie z firmą,
     * a przy 36 osobach okno ważyło już 44 KB (zmierzone). Do tego twardy limit
     * `KANDYDATOW_NA_RAZ` i uczciwe „pokazano N z M" — lista ucięta w ciszy jest gorsza
     * od krótkiej listy, bo nie wiadomo, że kogoś w niej nie ma.
     *
     * @return array{osoby: list<array{id: int, osoba: string, podwladnych: int, powod: ?string}>,
     *     razem: int, szukaj: string}
     */
    /** Ilu kandydatów pokazujemy najwyżej — dalej zawęża się wyszukiwaniem, nie przewijaniem. */
    public const KANDYDATOW_NA_RAZ = 50;

    public function kandydaci(int $companyId, int $employmentId, string $szukaj = ''): array
    {
        $lekkie = $this->hierarchia->kandydaci($companyId, $szukaj, self::KANDYDATOW_NA_RAZ + 1);
        $krawedzie = $this->hierarchia->krawedzie($companyId);
        $bezposredni = [];
        foreach ($krawedzie as $id => $szef) {
            $bezposredni[$szef] = ($bezposredni[$szef] ?? 0) + 1;
        }
        $lista = [];
        foreach ($lekkie['osoby'] as $z) {
            if ($z['id'] === $employmentId) {
                continue;
            }
            if (count($lista) >= self::KANDYDATOW_NA_RAZ) {
                break;
            }
            $lista[] = [
                'id'          => $z['id'],
                'osoba'       => $z['osoba'],
                'podwladnych' => $bezposredni[$z['id']] ?? 0,
                'powod'       => self::powodCyklu($krawedzie, $employmentId, $z['id']),
            ];
        }

        // ⚠ „Razem" liczy TE SAME warunki co lista, minus sama osoba — żeby zdanie „pokazano
        // N z M" nie kłamało o jeden przy każdym wyszukiwaniu.
        $razem = max(0, $lekkie['razem'] - ($szukaj === '' ? 1 : 0));

        return ['osoby' => $lista, 'razem' => $razem, 'szukaj' => $szukaj];
    }

    /**
     * Zapis partii zmian — **jedno żądanie, jedna transakcja** (§6.4).
     *
     * ⚠ Odmowa dotyczy **całej partii**: pół zapisanej struktury jest gorsze od niezapisanej,
     * bo administrator zobaczy, że „coś się stało", i nie będzie wiedział co. Dlatego walidacja
     * przechodzi wszystkie zmiany przed pierwszym zapisem, a stan hierarchii w pamięci jest
     * aktualizowany po każdej — inaczej dwie zmiany w jednej partii mogłyby razem ułożyć cykl,
     * którego żadna z nich osobno nie układa.
     *
     * @param list<array{employment: int, cel: string, supervisor?: int|null}> $zmiany
     * @return array{powod: ?string, zapisane: int, zdania: list<string>, wylaczonych_regul: int}
     */
    public function zapisz(int $companyId, array $zmiany, ?int $accountId, ?string $actorLabel): array
    {
        if ($zmiany === []) {
            return ['powod' => null, 'zapisane' => 0, 'zdania' => [], 'wylaczonych_regul' => 0];
        }

        return $this->db->transaction(function () use ($companyId, $zmiany, $accountId, $actorLabel): array {
            $nazwy = [];
            foreach ($this->hierarchia->zatrudnienia($companyId) as $z) {
                $nazwy[$z['id']] = $z['osoba'];
            }
            $krawedzie = $this->hierarchia->krawedzie($companyId);
            $doZapisu = [];
            foreach ($zmiany as $zmiana) {
                $wynik = $this->sprawdz($companyId, $zmiana, $krawedzie);
                if ($wynik['powod'] !== null) {
                    return ['powod' => $wynik['powod'], 'zapisane' => 0, 'zdania' => [],
                        'wylaczonych_regul' => 0];
                }
                // Stan w pamięci idzie za zmianami — patrz uwaga w opisie metody.
                if ($wynik['supervisor'] === null) {
                    unset($krawedzie[$wynik['employment']]);
                } else {
                    $krawedzie[$wynik['employment']] = $wynik['supervisor'];
                }
                $doZapisu[] = $wynik;
            }

            $zdania = [];
            foreach ($doZapisu as $w) {
                $this->hierarchia->ustaw($w['employment'], $w['supervisor'], $w['szczyt']);
                $zdanie = self::zdanie(
                    $nazwy[$w['employment']] ?? ('zatrudnienie #' . $w['employment']),
                    $w['supervisor'] === null ? null : ($nazwy[$w['supervisor']] ?? ('zatrudnienie #' . $w['supervisor'])),
                    $w['szczyt'],
                    $w['poprzedni'] === null ? null : ($nazwy[$w['poprzedni']] ?? ('zatrudnienie #' . $w['poprzedni'])),
                    $w['poprzedni_szczyt'],
                );
                $zdania[] = $zdanie;
                // ⚠ Wpis w dzienniku na KAŻDĄ zmianę, a jego treść to dokładnie to zdanie,
                // które administrator widział na pasku zmian (§6.5). Zmiana hierarchii jest
                // zmianą tego, kto oglądać będzie czyje dane kadrowe.
                $this->events->record(
                    'hierarchy.supervisor_changed',
                    $accountId,
                    null,
                    $actorLabel,
                    'employment',
                    $w['employment'],
                    $companyId,
                    [
                        'zdanie'   => $zdanie,
                        'z'        => $w['poprzedni'],
                        'na'       => $w['supervisor'],
                        'szczyt'   => $w['szczyt'],
                    ],
                );
            }

            // ⚠ **Przeliczenie reguł widoczności W TEJ SAMEJ transakcji** (Z80 B3). Zmiana krawędzi
            // odbiera komuś podwładnych, a reguła, która ich dotyczyła, przestaje cokolwiek dawać —
            // i ma to po sobie zapisać, zanim ktokolwiek zdąży odczytać dostęp. Poza transakcją
            // istniałaby chwila, w której hierarchia jest nowa, a reguły jeszcze stare.
            // ⚠ Sprawcą wpisu o wyłączeniu jest SYSTEM (`calendar_access.auto_disabled`), nawet
            // jeśli krawędź zmienił człowiek: reguły nie wyłączył świadomie, tylko w skutku.
            $wylaczone = $this->widocznosc->przelicz($companyId, true, $accountId, $actorLabel);

            return ['powod' => null, 'zapisane' => count($doZapisu), 'zdania' => $zdania,
                'wylaczonych_regul' => count($wylaczone)];
        });
    }

    /**
     * **Podgląd: co ten zapis zrobi z regułami widoczności** (zlecenie Z80 cz. C) — liczony
     * PRZED zapisem, po stronie serwera, bez zapisywania czegokolwiek.
     *
     * ⚠ **Ta sama walidacja i ta sama pętla, co w `zapisz()`** — stąd `sprawdz()` tutaj, a nie
     * „optymistyczne" nałożenie zmian. Partia, której zapis odrzuci, nie wyłączy ani jednej
     * reguły, więc podgląd takiej partii MUSI mówić „nic" zamiast liczby, której zapis nigdy
     * nie osiągnie. Inaczej pasek straszyłby ostrzeżeniem, a zapis kończyłby się odmową.
     *
     * ⚠ **Bez blokad wierszy** (`$blokuj = false`): pasek odpytuje serwer przy każdym
     * przeciągnięciu karty, a odczyt pokazujący ostrzeżenie nie ma prawa blokować wierszy
     * załogi. Jedyna różnica wobec zapisu jest w tym jednym argumencie i w tym, że nic nie
     * zapisujemy — reszta drogi jest wspólna.
     *
     * ⚠ Nazwiska bierzemy z TEJ SAMEJ mapy, co zdania paska (`zatrudnienia()`), a nie z listy
     * przełożonych: osoba, która po zmianie nie będzie miała podwładnych, na liście przełożonych
     * już się nie znajdzie — a to właśnie o niej jest całe ostrzeżenie (ta sama pułapka
     * co w wierszu reguły wyłączonej automatycznie, Z80 cz. B).
     *
     * @param list<array{employment: int, cel: string, supervisor?: int|null}> $zmiany
     * @return array{powod: ?string, reguly: list<array{powod: string, osoba: string}>}
     */
    public function podgladZmian(int $companyId, array $zmiany): array
    {
        if ($zmiany === []) {
            return ['powod' => null, 'reguly' => []];
        }
        $krawedzie = $this->hierarchia->krawedzie($companyId);
        foreach ($zmiany as $zmiana) {
            $wynik = $this->sprawdz($companyId, $zmiana, $krawedzie, false);
            if ($wynik['powod'] !== null) {
                return ['powod' => $wynik['powod'], 'reguly' => []];
            }
            // Stan w pamięci idzie za zmianami — tak samo jak przy zapisie, bo dwie zmiany
            // w jednej partii dopiero razem mogą zabrać komuś ostatniego podwładnego.
            if ($wynik['supervisor'] === null) {
                unset($krawedzie[$wynik['employment']]);
            } else {
                $krawedzie[$wynik['employment']] = $wynik['supervisor'];
            }
        }
        $nazwy = [];
        foreach ($this->hierarchia->zatrudnienia($companyId) as $z) {
            $nazwy[$z['id']] = $z['osoba'];
        }
        $reguly = [];
        foreach ($this->widocznosc->podgladPoZmianieHierarchii($companyId, $krawedzie) as $r) {
            $osoba = '';
            foreach ($r['etaty'] as $etat) {
                if (isset($nazwy[$etat])) {
                    $osoba = (string) $nazwy[$etat];
                    break;
                }
            }
            $reguly[] = ['powod' => $r['powod'], 'osoba' => $osoba];
        }

        return ['powod' => null, 'reguly' => $reguly];
    }

    /**
     * Zdanie dla paska zmian i dla dziennika — **jedno brzmienie w obu miejscach**.
     *
     * Format z makiety: „Nowak Anna → przełożony: Kowalski Jan (było: nie ustawiono)".
     */
    public static function zdanie(
        string $osoba,
        ?string $przelozony,
        bool $szczyt,
        ?string $poprzedni,
        bool $poprzedniSzczyt,
    ): string {
        $co = match (true) {
            $przelozony !== null => 'przełożony: ' . $przelozony,
            $szczyt              => 'nie podlega nikomu (szczyt hierarchii)',
            default              => 'zdjęty ze struktury (nie ustawiono)',
        };
        $bylo = match (true) {
            $poprzedni !== null => $poprzedni,
            $poprzedniSzczyt    => 'szczyt hierarchii',
            default             => 'nie ustawiono',
        };

        return $osoba . ' — ' . $co . ' (było: ' . $bylo . ')';
    }

    /**
     * Sprawdzenie jednej zmiany na stanie z bazy.
     *
     * @param array{employment: int, cel: string, supervisor?: int|null} $zmiana
     * ⚠ `$blokuj = false` wyłącza blokadę wiersza i jest WYŁĄCZNIE dla podglądu
     * (`podgladZmian()`): sama walidacja jest identyczna, bo inaczej podgląd uznawałby za
     * dopuszczalną partię, którą zapis odrzuci.
     *
     * @param array<int, int> $krawedzie
     * @return array{powod: ?string, employment: int, supervisor: ?int, szczyt: bool,
     *     poprzedni: ?int, poprzedni_szczyt: bool}
     */
    private function sprawdz(int $companyId, array $zmiana, array $krawedzie, bool $blokuj = true): array
    {
        $puste = [
            'employment' => $zmiana['employment'] ?? 0, 'supervisor' => null, 'szczyt' => false,
            'poprzedni' => null, 'poprzedni_szczyt' => false,
        ];
        $wiersz = $this->hierarchia->doZapisu((int) ($zmiana['employment'] ?? 0), $blokuj);
        // ⚠ Zgodność spółki sprawdzana po stronie serwera, nie „bo ekran pokazuje jedną
        // spółkę": inaczej dane kadrowe jednej spółki wychodzą ścieżką akceptacji drugiej.
        if ($wiersz === null || !$wiersz['aktywne'] || $wiersz['company_id'] !== $companyId) {
            return ['powod' => self::POWOD_BRAK_OSOBY] + $puste;
        }
        $wspolne = [
            'employment'       => $wiersz['id'],
            'poprzedni'        => $wiersz['supervisor_id'],
            'poprzedni_szczyt' => $wiersz['szczyt'],
        ];

        if ($zmiana['cel'] === self::CEL_SZCZYT) {
            // Szczyt wyklucza przełożonego — ten warunek nie ma oparcia w bazie (`CHECK`
            // nie da się założyć, patrz migracja), więc to jest JEDYNE miejsce, w którym
            // się go pilnuje przy zapisie.
            return ['powod' => null, 'supervisor' => null, 'szczyt' => true] + $wspolne;
        }
        if ($zmiana['cel'] === self::CEL_ZDEJMIJ) {
            return ['powod' => null, 'supervisor' => null, 'szczyt' => false] + $wspolne;
        }
        if ($zmiana['cel'] !== self::CEL_PRZELOZONY) {
            return ['powod' => self::POWOD_BRAK_CELU] + $puste;
        }

        $supervisorId = (int) ($zmiana['supervisor'] ?? 0);
        if ($supervisorId <= 0) {
            return ['powod' => self::POWOD_BRAK_CELU] + $puste;
        }
        $szef = $this->hierarchia->doZapisu($supervisorId, $blokuj);
        if ($szef === null || !$szef['aktywne']) {
            return ['powod' => self::POWOD_BRAK_OSOBY] + $puste;
        }
        if ($szef['company_id'] !== $companyId) {
            return ['powod' => self::POWOD_INNA_SPOLKA] + $puste;
        }
        $powod = self::powodCyklu($krawedzie, $wiersz['id'], $supervisorId);
        if ($powod !== null) {
            return ['powod' => $powod] + $puste;
        }

        return ['powod' => null, 'supervisor' => $supervisorId, 'szczyt' => false] + $wspolne;
    }
}
