<?php

declare(strict_types=1);

namespace Wezurlop\Service;

use DateTimeImmutable;
use Wezurlop\Repository\PracownikRepository;
use Wezurlop\Support\DataDnia;
use Wezurlop\Support\Odmiana;

/**
 * „Mój kalendarz” — jeden ekran, dwa widoki: siatka miesiąca „Tylko ja” i siatka zespołu
 * (wiersze to osoby, kolumny to dni). Strefa jawna (CLAUDE.md §15).
 *
 * ⚠ **Rodzaj ogólny dla cudzych nieobecności i dla wszystkich w widoku „Zespół”.** Kod ze słownika
 * („chorobowe szpitalne”, „opieka nad dzieckiem”) to dane o zdrowiu — kolega ma widzieć **fakt**
 * nieobecności, nigdy jej przyczynę (`etap3` §8, zlecenie §5). W widoku zespołu rodzaj ogólny mają
 * także własne wpisy: inaczej w jednej siatce byłyby dwa różne paski, a różnica myliłaby zamiast
 * informować.
 *
 * ⚠ Zasięg podglądu liczy `ZasiegPodgladu` i przechodzi przez niego **każde** żądanie. Ta klasa
 * nigdy nie dostaje identyfikatorów wprost z żądania.
 */
final class KalendarzPracownika
{
    /** Widok „Tylko ja” — pełne nazwy rodzajów, bo to własne dane. */
    public const WIDOK_JA = 'ja';

    /** Widok „Zespół” — wiersze to osoby, rodzaj ogólny dla wszystkich. */
    public const WIDOK_ZESPOL = 'zespol';

    /**
     * Widok „Firma” — ta sama siatka co „Zespół”, szerszy zasięg.
     *
     * ⚠ **Trzeci zasięg, nie trzeci ekran** (zlecenie §3). Ta sama siatka, ta sama funkcja
     * budująca wiersze; różni się wyłącznie tym, kogo obejmuje `$dozwolone`. Drugi ekran
     * znaczyłby drugie miejsce, w którym trzeba pamiętać o rodzaju ogólnym.
     */
    public const WIDOK_FIRMA = 'firma';

    /** Nazwy ogólne po źródle rodzaju z R2 — **nie nasza klasyfikacja**, tylko trzy źródła słownika. */
    private const OGOLNE = [
        'urlop' => 'urlop',
        'zasilek' => 'zwolnienie',
        'kadrowe' => 'inna nieobecność',
    ];

    private const MIESIACE = [1 => 'styczeń', 'luty', 'marzec', 'kwiecień', 'maj', 'czerwiec',
        'lipiec', 'sierpień', 'wrzesień', 'październik', 'listopad', 'grudzień'];

    /** ⚠ **Skala czasu jest STANEM WIDOKU, nie nowym ekranem** (zlecenie 11.10.2026 §1):
     * jedzie w adresie tak samo jak `?widok=` i `?miesiac=`, więc wybór da się wkleić, zakładkować
     * i cofnąć przyciskiem przeglądarki. Dotyczy wyłącznie widoku „Tylko ja”. */
    /**
     * Ile wpisów pokazuje lista obok siatki Miesiąca — reszta jest na skali Lista.
     *
     * ⚠ Ta sama liczba i ta sama zasada, co w tabeli na pulpicie
     * (`PulpitPracownika::NIEOBECNOSCI_NA_PULPICIE`), ale **osobna stała, nie wspólna**:
     * tam limit wynika z wysokości karty na pulpicie, tutaj z wysokości siatki miesiąca
     * obok. Jedna stała na dwa powody rozjechałaby się przy pierwszej zmianie jednego z nich,
     * a zmiana „przy okazji" ruszyłaby drugi ekran (decyzja Krzysztofa po Z13: lista ma się
     * nie rozpychać ponad siatkę).
     */
    public const WPISOW_W_MIESIACU = 5;

    public const SKALA_MIESIAC = 'miesiac';
    public const SKALA_ROK = 'rok';
    public const SKALA_LISTA = 'lista';

    /** ⚠ Skala `kwartal` **została zniesiona 11.10.2026** (zlecenie `rok-jako-dwanascie-miesiecy`):
     * Rok pokazuje teraz dwanaście tych samych siatek miesięcznych, więc kwartał odpowiadał na to
     * samo pytanie trzema siatkami zamiast dwunastoma. Stary adres `?skala=kwartal` nie jest błędem
     * — wypada z białej listy i `skala()` zwraca dla niego miesiąc, czyli stan domyślny ekranu.
     * @var list<string> */
    public const SKALE = [self::SKALA_MIESIAC, self::SKALA_ROK, self::SKALA_LISTA];

    /**
     * Nagłówek siatki miesiąca w skali Roku — **ta sama lista**, tylko czytana w całości
     * i po kolei: siatka ma siedem stałych kolumn od poniedziałku i nie pyta o datę,
     * więc szablon ją przebiega, a nie indeksuje (klucze 1–7 nie mają tu znaczenia).
     * ⚠ Wartości pochodzą z `SKROTY_DNI`, żeby nie dało się zmienić jednego zestawu
     * i zapomnieć o drugim — to jest dokładnie usterka zgłoszona 13.10.2026.
     */
    private const DNI_TYGODNIA = self::SKROTY_DNI;

    /**
     * Te same skróty w nagłówku osi czasu, indeksowane numerem dnia tygodnia ISO-8601 (`N`).
     *
     * ⚠ **JEDNA lista na cały program, a nie dwie** — zgłoszenie Krzysztofa z 13.10.2026:
     * „nazwy dni tygodnia powinny być takie same skróty jak w kalendarzu rok". Przez dwa dni
     * oś czasu miała własne skróty dwuliterowe (`pn wt śr cz pt so nd`), bo mieszczą się
     * w kolumnie o podłodze 26 px. To była zła odpowiedź na prawdziwe ograniczenie:
     * dwa zestawy nazw tej samej rzeczy w dwóch widokach tego samego kalendarza czyta się
     * jako dwa różne programy.
     * ⚠ Ograniczenie zostało rozwiązane tam, gdzie leży, ale **nie podniesieniem podłogi
     * kolumny** — to zdanie stało tu przez pomyłkę, a `--team-day-w` zostaje 26 px (38 px, tyle
     * ile potrzebuje „niedz" z odstępem, dałoby 31 × 38 + 200 = 1378 px, czyli pasek przewijania
     * na pełnym ekranie). Rozstrzyga arkusz: każda komórka nagłówka jest kontenerem zapytania
     * i **cała kolejka skrótów gaśnie, gdy kolumna ma mniej niż 34 px**. Nagłówek ucięty
     * do „nied" kłamałby, a nagłówek bez skrótów jest tym, czym był przed 11.10.2026.
     * ⚠ Dzień tygodnia liczy **serwer**, tak samo jak numer dnia na pasku jednodniowym: ten
     * ekran działa bez JavaScriptu i ma działać dalej, a `date()` w szablonie z tekstowej daty
     * to liczenie w widoku (CLAUDE.md §5).
     */
    private const SKROTY_DNI = [1 => 'pon', 'wt', 'śr', 'czw', 'pt', 'sob', 'niedz'];

    public function __construct(private readonly PracownikRepository $dane)
    {
    }

    /**
     * Nagłówek okresu: nazwa miesiąca i adresy sąsiadów.
     *
     * @return array<string, mixed>
     */
    public static function okres(DateTimeImmutable $pierwszy, string $skala = self::SKALA_MIESIAC): array
    {
        // ⚠ **„Poprzedni"/„Następny" przesuwa o JEDEN OKRES BIEŻĄCEJ SKALI**, nie zawsze o miesiąc.
        // Inaczej w skali roku te dwa przyciski przez jedenaście kliknięć nie zmieniałyby nic
        // widocznego, a w dwunastym przeskakiwałyby rok — czyli wyglądałyby na zepsute.
        $krok = match ($skala) {
            self::SKALA_ROK, self::SKALA_LISTA => '1 year',
            default => '1 month',
        };

        return [
            'miesiac' => $pierwszy->format('Y-m'),
            'rok' => (int) $pierwszy->format('Y'),
            // Nazwa okresu nazywa to, co widać: miesiąc albo rok — nie zawsze miesiąc.
            'nazwa' => match ($skala) {
                self::SKALA_ROK, self::SKALA_LISTA => 'Rok ' . $pierwszy->format('Y'),
                default => self::MIESIACE[(int) $pierwszy->format('n')] . ' ' . $pierwszy->format('Y'),
            },
            'poprzedni' => $pierwszy->modify('-' . $krok)->format('Y-m'),
            'nastepny' => $pierwszy->modify('+' . $krok)->format('Y-m'),
        ];
    }

    /**
     * Skala z adresu — **biała lista** (CLAUDE.md §11). Wartość spoza listy to nie błąd, tylko
     * literówka w adresie: wraca miesiąc, czyli stan, który ten ekran miał od początku.
     */
    public static function skala(string $wartosc): string
    {
        return in_array($wartosc, self::SKALE, true) ? $wartosc : self::SKALA_MIESIAC;
    }

    /**
     * Miesiąc z adresu (`RRRR-MM`) albo bieżący. Wartość spoza formatu nie jest błędem — jest „dziś”
     * (CLAUDE.md §11: biała lista, a nie komunikat o błędzie dla literówki w adresie).
     */
    public static function miesiac(string $wartosc): DateTimeImmutable
    {
        if (preg_match('/^\d{4}-\d{2}$/', $wartosc) === 1) {
            $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $wartosc . '-01');
            if ($parsed !== false) {
                return $parsed->modify('first day of this month');
            }
        }

        return (new DateTimeImmutable('today'))->modify('first day of this month');
    }

    /**
     * Widok „Tylko ja”, skala MIESIĄCA: **ta sama siatka miesiąca, co w skali Roku**, plus lista
     * własnych nieobecności obok niej.
     *
     * ⚠ **Przebudowa 13.10.2026** (zlecenie `2026-10-13-kalendarz-naglowek-i-ksztalt-miesiaca.md`
     * §2, prototyp zatwierdzony w przeglądarce). Do tej pory ten widok wołał `siatka()`, czyli
     * **oś czasu** — tę samą, co „Zespół” i „Firma”, tylko z jednym wierszem. Powód zmiany jest
     * zmierzony i dotyczy liczby osób, a nie gustu:
     *   • dla **jednej osoby** oś czasu to jeden cienki wiersz przez pół ekranu plus kolumna
     *     „OSOBA” z **własnym nazwiskiem oglądającego** — 200 px na informację, którą on zna.
     *     Siatka tygodniowa daje 35 dużych kratek i urlop jako ciągły blok,
     *   • dla **wielu osób** siatka tygodniowa jest zła i to też jest zmierzone (Beta, sierpień
     *     2026, 53 osoby i 43 nieobecności: przy trzech paskach na tydzień widać 18 z 43, a pełny
     *     obraz wymaga 11 pasków jeden pod drugim) — dlatego „Zespół” i „Firma” zostają na osi
     *     czasu, a rozgałęzienie kształtu siedzi tam, gdzie od początku stały dwa widoki: w tej
     *     metodzie i w `widokZespolu()`. Trzeciego `if` nie ma.
     *
     * ⚠ **Nie dokładamy komponentu**: `tygodnieMiesiaca()` i `kratka()` już istnieją i już
     * umieją weekendy, kolor rodzaju, `title` i znacznik „dziś”. Ta metoda wywołuje je raz dla
     * jednego miesiąca — Rok wywołuje je dwanaście razy. Jedno źródło kratki, dwie skale.
     *
     * ⚠ **Lista obok siatki nie jest ozdobą.** Kratka znaczy dzień samym wypełnieniem i nie ma
     * paska z napisem „25–29.05”, który niósł zakres na osi czasu. Zakres, liczbę dni roboczych
     * i źródło wpisu niesie więc lista — bez niej ten widok **traci dane**, a nie tylko wygląd.
     *
     * @param array<string, mixed> $zatrudnienie
     * @return array<string, mixed>
     */
    public function widokJa(array $zatrudnienie, DateTimeImmutable $pierwszy, string $dzis): array
    {
        return $this->widokMiesiaca([$zatrudnienie], $pierwszy, $dzis);
    }

    /**
     * **Ten sam miesiąc dla DOWOLNEJ liczby zatrudnień jednej osoby** (zlecenie Z51 z 19.10.2026).
     *
     * ⚠ Metoda powstała, bo `/dane/pracownik/{id}/kalendarz` ma pokazywać **tę samą siatkę**, co
     * „Tylko ja · Miesiąc” — a ten ekran ogląda OSOBĘ, nie jedno zatrudnienie: 6 z 88 osób
     * w danych roboczych ma etat w dwóch firmach naraz. Drugi komponent siatki znaczyłby, że
     * poprawka wyglądu kratki wchodzi na jednym ekranie, a na drugim nie.
     *
     * ⚠ **Dni wolne to SUMA rozkładów, nieobecności to suma wpisów** — a kolejność wpisów
     * ustala się po scaleniu (`od`, potem `id`), bo „pierwszy wpis wygrywa dzień” straciłoby sens,
     * gdyby wpisy z drugiej firmy stały po wszystkich z pierwszej.
     *
     * @param list<array<string, mixed>> $zatrudnienia niepusta lista zatrudnień JEDNEJ osoby
     * @return array<string, mixed>
     */
    public function widokMiesiaca(array $zatrudnienia, DateTimeImmutable $pierwszy, string $dzis): array
    {
        // ⚠ `mapaDni()` sama wpisuje `$dozwolone = [ja]` i pełny poziom szczegółowości — to
        // własne dane, a identyfikator nie przychodzi z żądania (tak samo jak w skali Roku).
        $mapa = $this->mapaDni($zatrudnienia, $pierwszy, $pierwszy->modify('last day of this month'));
        $siatka = $this->tygodnieMiesiaca($pierwszy, $mapa, $dzis);
        // ⚠ Sklejanie ciągów kadrowych robi `PulpitPracownika::sklej()` — ta sama funkcja,
        // z której korzysta tabela na pulpicie i skala Listy. Druga implementacja tej reguły
        // znaczyłaby, że ten sam urlop ma na trzech ekranach inną liczbę dni. `sklej()`
        // oczekuje kolejności malejącej (tak czyta je pulpit), a zapytanie zakresowe daje
        // rosnącą.
        $pelna = array_map(
            static fn (array $w): array => $w + ['kiedy' => DataDnia::zakres($w['od'], $w['do'])],
            PulpitPracownika::sklej(array_reverse($mapa['wpisy'])),
        );
        $rozklady = [];
        foreach ($zatrudnienia as $zatrudnienie) {
            if ($zatrudnienie['calendar'] !== null) {
                $rozklady[(string) $zatrudnienie['calendar_name']] = true;
            }
        }
        $rozklady = array_keys($rozklady);

        return [
            'dni_tygodnia' => self::DNI_TYGODNIA,
            'tygodnie' => $siatka['tygodnie'],
            // ⚠ **Przycina SERWER, nie CSS** (zlecenie Z14 z 17.10.2026): lista ucięta wysokością
            // pudełka dalej niesie cały HTML, więc przeglądarka, czytnik ekranu i wyszukiwanie
            // w stronie widzą wpisy, których człowiek nie widzi. `lista_wszystkich` jedzie obok,
            // żeby szablon mógł powiedzieć, ile ich naprawdę jest, bez drugiego liczenia.
            'lista' => array_slice($pelna, 0, self::WPISOW_W_MIESIACU),
            'lista_wszystkich' => count($pelna),
            // ⚠ **Wpisy kadrowe w zakresie**, nie wiersze po sklejeniu — ta sama liczba, co
            // przed przebudową (`count($wpisy)` z `siatka()`), żeby zdanie pod tytułem nie
            // zmieniło znaczenia razem z kształtem siatki.
            'nieobecnosci' => count($mapa['wpisy']),
            // Konto bez zatrudnienia tu nie dochodzi (kontroler woła tę metodę tylko dla
            // zatrudnienia), więc stan „brak_zatrudnienia” nie jest tu możliwy — tak samo
            // jak w skali Roku.
            'zrodlo_wolnych' => $rozklady === [] ? 'brak_rozkladu' : 'rozklad',
            'rozklad' => $rozklady === [] ? null : $rozklady[0],
            // ⚠ **Nazwy wszystkich rozkładów, nie tylko pierwszej**: przy dwóch etatach zdanie
            // „dni wolne z rozkładu «X»” byłoby połową prawdy, a wygaszone kratki są sumą obu.
            // Przy jednym zatrudnieniu lista ma jedną pozycję i zdanie brzmi jak dotąd.
            'rozklady' => $rozklady,
        ];
    }

    /**
     * **Jedna mapa dnia dla wszystkich nowych skal** — własne nieobecności i dni wolne
     * w dowolnym zakresie dat (zlecenie 11.10.2026 §1).
     *
     * ⚠ Trzy skale nie budują sobie tego osobno. Rok, kwartał i lista pytają o ten sam zakres
     * tym samym zapytaniem, a różnią się wyłącznie tym, jak go **rysują** — inaczej „ile dni
     * mi zeszło" miałoby trzy implementacje i rozjechałoby się przy pierwszej zmianie reguły
     * (ta sama rodzina co dwa liczniki jednej rzeczy).
     *
     * ⚠ `$dozwolone = [ja]` wpisane TUTAJ, nie przyniesione z żądania: pełna szczegółowość
     * rodzaju (nazwa ze słownika kadr) dotyczy wyłącznie własnych danych.
     *
     * ⚠ **Lista zatrudnień, nie jedno** (od 19.10.2026, zlecenie Z51): karta pracownika pokazuje
     * kalendarz OSOBY, a osoba potrafi mieć etat w dwóch firmach. Zapytanie o nieobecności idzie
     * osobno dla każdej firmy, bo granicę spółki pilnuje `company_id` **w zapytaniu** i nie wolno
     * jej rozluźnić dla wygody jednego ekranu. Przy jednym zatrudnieniu wynik jest identyczny
     * jak przed zmianą.
     *
     * @param list<array<string, mixed>> $zatrudnienia
     * @return array{wolne: array<string, true>, wDniu: array<string, array<string, mixed>>,
     *               wpisy: list<array<string, mixed>>}
     */
    private function mapaDni(array $zatrudnienia, DateTimeImmutable $od, DateTimeImmutable $do): array
    {
        $wolne = [];
        $wpisy = [];
        foreach ($zatrudnienia as $zatrudnienie) {
            $wolne += $this->wolne($zatrudnienie, $od, $do);
            foreach ($this->dane->nieobecnosciZakres(
                (int) $zatrudnienie['company_id'],
                [(int) $zatrudnienie['id']],
                $od->format('Y-m-d'),
                $do->format('Y-m-d'),
            ) as $wpis) {
                $wpisy[] = $wpis;
            }
        }
        // ⚠ Kolejność ustalana PO scaleniu: zapytanie sortuje w obrębie jednej firmy, a „pierwszy
        // wpis wygrywa dzień” i sklejanie ciągów zakładają kolejność rosnącą w całym zbiorze.
        // Przy jednym zatrudnieniu to przestawienie niczego nie zmienia (sort jest stabilny).
        usort($wpisy, static fn (array $a, array $b): int
            => [$a['od'], $a['id']] <=> [$b['od'], $b['id']]);
        $wDniu = [];
        foreach ($wpisy as $wpis) {
            $dzien = new DateTimeImmutable((string) $wpis['od']);
            $koniec = new DateTimeImmutable((string) $wpis['do']);
            while ($dzien <= $koniec) {
                $iso = $dzien->format('Y-m-d');
                // ⚠ Pierwszy wpis wygrywa dzień, tak samo jak w osi czasu: przy nachodzących
                // nieobecnościach (zwolnienie w środku urlopu) żaden dzień nie może zostać bez
                // właściciela, a kratka ma jeden kolor.
                $wDniu[$iso] ??= $wpis;
                $dzien = $dzien->modify('+1 day');
            }
        }

        return ['wolne' => $wolne, 'wDniu' => $wDniu, 'wpisy' => $wpisy];
    }

    /**
     * Jedna kratka dnia dla skali roku i kwartału — **jeden kształt komórki, dwa widoki**.
     *
     * ⚠ Kratka niesie informację **wyłącznie kolorem**, więc `title` z rodzajem i datą nie jest
     * ozdobą: bez niego w odcieniach szarości (achromatopsja, wydruk, tryb wysokiego kontrastu)
     * dzień nieobecności jest nieodróżnialny od dnia roboczego. Zgłoszenie Krzysztofa §0.2:
     * drugiego kanału GRAFICZNEGO w tej turze nie dokładamy, ale informacja musi **istnieć**
     * poza kolorem.
     *
     * @param array<string, true> $wolne
     * @param array<string, array<string, mixed>> $wDniu
     * @return array<string, mixed>
     */
    private static function kratka(
        DateTimeImmutable $dzien,
        array $wolne,
        array $wDniu,
        string $dzis
    ): array {
        $iso = $dzien->format('Y-m-d');
        $wpis = $wDniu[$iso] ?? null;

        return [
            'numer' => (int) $dzien->format('j'),
            'poza' => false,
            'wolny' => self::wolnyDzien($iso, $dzien, $wolne),
            'dzis' => $iso === $dzis,
            'pasek' => $wpis === null ? null : [
                'zrodlo' => $wpis['zrodlo'],
                'nazwa' => self::nazwaPaska($wpis, CalendarVisibility::POZIOM_PELNY),
                'data' => DataDnia::zakres($iso, $iso),
            ],
        ];
    }

    /**
     * **Skala ROKU: dwanaście zwykłych siatek miesięcznych** (zlecenie
     * `2026-10-11-rok-jako-dwanascie-miesiecy.md`, prototyp zatwierdzony w przeglądarce).
     *
     * ⚠ **Pasek 12 × 31 zniknął, a z nim skala kwartału.** Pasek odpowiadał na pytanie „kiedy
     * w tym roku mnie nie było" jednym rzutem oka, ale kolumnami były **numery dni**, więc nie
     * dawało się z niego odczytać dnia tygodnia — a to jedyne, po co człowiek patrzy na kalendarz
     * przed złożeniem wniosku. Kwartał umiał to na trzech miesiącach; teraz umie to cały rok,
     * tą samą siatką, więc trzecia skala była trzecią odpowiedzią na to samo pytanie.
     *
     * ⚠ Dzień **spoza miesiąca** jest **pusty, nie szary**: brak dnia to nie dzień wolny,
     * a dwa różne stany w jednym kolorze to pierwszy krok do nieprawdziwej liczby obok.
     *
     * ⚠ **Suma przy nazwie miesiąca to DNI Z KADR** (`days`), nie dni policzone z siatki — i to
     * jest poprawka zrobiona po pomiarze, nie od razu. Pierwsza wersja liczyła kratki
     * nieobecności, które nie są dniem wolnym, i dała na jednym ekranie **dwie liczby jednej
     * rzeczy**: „34 dni roboczych" w skali roku i „31 dni roboczych" na Liście. Różnica nie była
     * błędem arytmetyki — to **reguła kadr**: wpis ze źródła `kadrowe` (urlop bezpłatny,
     * wychowawczy, nieusprawiedliwiona) zajmuje dni w kalendarzu, ale program kadrowy liczy dla
     * niego **zero dni**. Siatka pokazywała więc nasz własny rachunek, czyli liczenie za kadry.
     *
     * ⚠ Dni idą do **miesiąca, w którym nieobecność się ZACZYNA**, bo kadry podają je dla całego
     * wpisu, a nie w rozbiciu na miesiące. W danych roboczych **zero** nieobecności przechodzi
     * przez przełom miesiąca, więc dziś pytanie jest teoretyczne; gdy taka się pojawi, suma
     * policzy ją tam, gdzie się zaczęła, a kratki będą w dwóch siatkach — i tak to jest nazwane
     * na ekranie, a nie przemilczane.
     * ⚠ Liczbę kratek (dni kalendarzowych nieobecności) zwracamy osobno jako `dni_nieobecnosci`:
     * to pomiar dla bramki, a nie druga odpowiedź na pytanie „ile mi zeszło".
     *
     * @param array<string, mixed> $zatrudnienie
     * @return array<string, mixed>
     */
    public function widokRoku(array $zatrudnienie, int $rok, string $dzis): array
    {
        $od = new DateTimeImmutable($rok . '-01-01');
        $do = new DateTimeImmutable($rok . '-12-31');
        $mapa = $this->mapaDni([$zatrudnienie], $od, $do);

        // ⚠ Dni z kadr w rozbiciu na miesiąc POCZĄTKU wpisu — jedno źródło liczby dni dla
        // siatki miesiąca i dla Listy. `days` bywa `null` („kadry nie podały"), a dla źródła
        // `kadrowe` bywa zerem z reguły programu kadrowego: oba stany zostają jak są.
        $dniKadr = [];
        $nieznane = 0;
        foreach ($mapa['wpisy'] as $wpis) {
            $poczatekWpisu = new DateTimeImmutable((string) $wpis['od']);
            if ((int) $poczatekWpisu->format('Y') !== $rok) {
                continue;
            }
            if ($wpis['dni'] === null) {
                $nieznane++;
                continue;
            }
            $miesiacWpisu = (int) $poczatekWpisu->format('n');
            $dniKadr[$miesiacWpisu] = ($dniKadr[$miesiacWpisu] ?? 0) + (int) $wpis['dni'];
        }

        $miesiace = [];
        $robocze = 0;
        $kratekNieobecnosci = 0;
        foreach (array_keys(self::MIESIACE) as $numer) {
            $pierwszyMiesiaca = new DateTimeImmutable(sprintf('%d-%02d-01', $rok, $numer));
            $siatka = $this->tygodnieMiesiaca($pierwszyMiesiaca, $mapa, $dzis);
            $kratekNieobecnosci += $siatka['kratek_nieobecnosci'];
            $wMiesiacu = $dniKadr[$numer] ?? 0;
            $robocze += $wMiesiacu;
            $miesiace[] = [
                'numer' => $numer,
                'nazwa' => self::MIESIACE[$numer] . ' ' . $pierwszyMiesiaca->format('Y'),
                'tygodnie' => $siatka['tygodnie'],
                // ⚠ Puste, gdy zero: „0" w dwunastu pudełkach to dwanaście liczb, z których
                // żadna nie jest odpowiedzią na pytanie tego ekranu.
                'dni_robocze' => $wMiesiacu > 0 ? $wMiesiacu : null,
            ];
        }

        return [
            'rok' => $rok,
            'dni_tygodnia' => self::DNI_TYGODNIA,
            'miesiace' => $miesiace,
            'dni_robocze' => $robocze,
            'dni_nieznane' => $nieznane,
            'nieobecnosci' => count($mapa['wpisy']),
            // Pomiar dla bramki: ile kratek jest pokolorowanych (dni kalendarzowe nieobecności).
            'dni_nieobecnosci' => $kratekNieobecnosci,
            'zrodlo_wolnych' => $zatrudnienie['calendar'] === null ? 'brak_rozkladu' : 'rozklad',
            'rozklad' => $zatrudnienie['calendar_name'],
        ];
    }

    /**
     * Jedna siatka miesięczna: tygodnie po siedem kratek, pierwszy wiersz od poniedziałku.
     *
     * ⚠ Wiersz zaczyna się od **poniedziałku**, więc dni przed pierwszym dniem miesiąca są
     * pustymi kratkami. Bez tego kolumna „czw" w jednym miesiącu nie byłaby czwartkiem
     * w drugim — a cały ten widok służy właśnie do patrzenia na dni tygodnia.
     *
     * @param array<string, mixed> $mapa wynik `mapaDni()` — klucze `wolne`, `wDniu`, `wpisy`
     * @return array{tygodnie: list<list<array<string, mixed>|null>>, kratek_nieobecnosci: int}
     */
    private function tygodnieMiesiaca(DateTimeImmutable $pierwszyMiesiaca, array $mapa, string $dzis): array
    {
        $ileDni = (int) $pierwszyMiesiaca->format('t');
        $przed = (int) $pierwszyMiesiaca->format('N') - 1;
        $tygodnie = [];
        $tydzien = array_fill(0, $przed, null);
        $kratek = 0;
        for ($d = 1; $d <= $ileDni; $d++) {
            $kratka = self::kratka($pierwszyMiesiaca->modify('+' . ($d - 1) . ' days'), $mapa['wolne'], $mapa['wDniu'], $dzis);
            if ($kratka['pasek'] !== null) {
                $kratek++;
            }
            $tydzien[] = $kratka;
            if (count($tydzien) === 7) {
                $tygodnie[] = $tydzien;
                $tydzien = [];
            }
        }
        if ($tydzien !== []) {
            $tygodnie[] = array_pad($tydzien, 7, null);
        }

        return ['tygodnie' => $tygodnie, 'kratek_nieobecnosci' => $kratek];
    }

    /**
     * **Skala LISTY**: ta sama tabela, co w widoku miesiąca, tylko zakresem jest rok.
     *
     * ⚠ **Żadnego nowego komponentu** (zlecenie §1c — pierwsza wersja prototypu została odrzucona
     * właśnie za to): te same klasy, te same cztery kolumny, ten sam format daty i ten sam
     * partial `lista-nieobecnosci.twig`. Jedyna różnica wobec miesiąca to zakres i to, że
     * podsumowanie podaje **sumę dni roboczych** — w skali roku to liczba, po którą pracownik
     * tu przychodzi, a w skali miesiąca nic nie wnosiła.
     *
     * @param array<string, mixed> $zatrudnienie
     * @return array<string, mixed>
     */
    public function widokListy(array $zatrudnienie, int $rok): array
    {
        $od = new DateTimeImmutable($rok . '-01-01');
        $do = new DateTimeImmutable($rok . '-12-31');
        $mapa = $this->mapaDni([$zatrudnienie], $od, $do);
        // Sklejanie ciągów kadrowych tą samą funkcją co pulpit i widok miesiąca — trzecia
        // implementacja znaczyłaby, że ten sam urlop ma na trzech ekranach inną liczbę dni.
        $wiersze = array_map(
            static fn (array $w): array => $w + ['kiedy' => DataDnia::zakres($w['od'], $w['do'])],
            PulpitPracownika::sklej(array_reverse($mapa['wpisy'])),
        );

        return [
            'rok' => $rok,
            'wiersze' => $wiersze,
            // ⚠ Dwie różne liczby, obie potrzebne i obie nazwane: `nieobecnosci` to wiersze
            // po sklejeniu ciągów, `wpisow_kadrowych` to pozycje przysłane przez kadry.
            // Bramka porównuje z bazą **drugą**, bo tylko ona ma odpowiednik w `external_absence`.
            'nieobecnosci' => count($wiersze),
            'wpisow_kadrowych' => count($mapa['wpisy']),
            'dni_robocze' => array_sum(array_map(
                static fn (array $w): int => (int) ($w['dni'] ?? 0),
                $wiersze,
            )),
            'dni_nieznane' => count(array_filter(
                $wiersze,
                static fn (array $w): bool => $w['dni'] === null,
            )),
        ];
    }

    /**
     * Widok „Zespół”: wiersze to osoby, kolumny to dni miesiąca.
     *
     * ⚠ Dni wolne kolorujemy według **rozkładu osoby oglądającej** i to jest świadome uproszczenie:
     * każdy wiersz może mieć inny rozkład czasu pracy, a jedna siatka ma jeden nagłówek kolumn.
     * Ekran mówi o tym zdaniem pod siatką, zamiast udawać, że wolne jest wspólne.
     *
     * @param array<string, mixed> $zatrudnienie
     * @param list<int> $dozwolone wynik `ZasiegPodgladu` — nigdy surowe wejście
     * @param int $poziom poziom widoczności (`CalendarVisibility`): rozstrzyga SZCZEGÓŁOWOŚĆ
     * @param array<int, string> $nazwyWZasiegu nazwy dla CAŁEGO zasięgu — nie tylko dla `$dozwolone`,
     *        bo przełożony podpisanej osoby może nie być na pokazanej liście
     * @return array<string, mixed>
     */
    public function widokZespolu(
        array $zatrudnienie,
        array $dozwolone,
        DateTimeImmutable $pierwszy,
        string $dzis,
        int $poziom,
        array $nazwyWZasiegu
    ): array {
        return $this->siatka($zatrudnienie, $dozwolone, $pierwszy, $dzis, $poziom, $nazwyWZasiegu);
    }

    /**
     * **Jedno miejsce, w którym powstaje oś czasu** — dla „Zespołu” i „Firmy”.
     *
     * ⚠ **Od 13.10.2026 „Tylko ja” tu NIE WCHODZI** (zlecenie z tego dnia §2): widok jednej
     * osoby rysuje siatkę miesiąca, nie oś czasu. Oś czasu istnieje dla **wielu wierszy** —
     * kolumna nazwisk, przyklejanie nagłówka dni, 31 kolumn i przewijanie w bok mają sens
     * wtedy, gdy wierszy jest kilkadziesiąt.
     *
     * ⚠ Zwraca **sam model dla szablonu**. Do 13.10.2026 oddawała go w parze z surowymi
     * wpisami (`['os' => …, 'wpisy' => …]`), bo widok „Tylko ja” budował z nich listę bez
     * drugiego zapytania. Ten odbiorca zniknął razem z osią czasu na „Tylko ja”, więc para
     * zostałaby kluczem, którego nikt nie czyta — a surowe wiersze kadrowe nie mają po co
     * wychodzić z tej klasy (CLAUDE.md §3: do widoku idzie tylko to, co widok wyświetla).
     *
     * @param array<string, mixed> $zatrudnienie
     * @param list<int> $dozwolone
     * @param array<int, string> $nazwyWZasiegu
     * @return array<string, mixed>
     */
    private function siatka(
        array $zatrudnienie,
        array $dozwolone,
        DateTimeImmutable $pierwszy,
        string $dzis,
        int $poziom,
        array $nazwyWZasiegu
    ): array {
        $ostatni = $pierwszy->modify('last day of this month');
        $dniWMiesiacu = (int) $ostatni->format('j');
        $jaId = (int) $zatrudnienie['id'];
        $wolne = $this->wolne($zatrudnienie, $pierwszy, $ostatni);
        // ⚠ `null`, a nie `0`, gdy oglądający nie ma zatrudnienia: `0` porównane z `NULL`-em
        // w kolumnie przełożonego dawało podpis „przełożony: Ty" przy każdej osobie bez
        // przełożonego (patrz `opisz()`).
        $osoby = self::opisz(
            $this->dane->osoby((int) $zatrudnienie['company_id'], $dozwolone, $jaId),
            $jaId > 0 ? $jaId : null,
            $nazwyWZasiegu,
        );
        $wpisy = $this->dane->nieobecnosciZakres(
            (int) $zatrudnienie['company_id'],
            $dozwolone,
            $pierwszy->format('Y-m-d'),
            $ostatni->format('Y-m-d'),
        );

        $naglowki = [];
        for ($d = 1; $d <= $dniWMiesiacu; $d++) {
            $data = $pierwszy->modify('+' . ($d - 1) . ' days');
            $iso = $data->format('Y-m-d');
            $naglowki[] = [
                'numer' => $d,
                'skrot' => self::SKROTY_DNI[(int) $data->format('N')],
                'wolny' => self::wolnyDzien($iso, $data, $wolne),
                'dzis' => $iso === $dzis,
            ];
        }

        $wiersze = [];
        foreach ($osoby as $osoba) {
            $wiersze[] = [
                'osoba' => $osoba['osoba'],
                'rola' => $osoba['rola'],
                'ja' => $osoba['ja'],
                'komorki' => self::wiersz(
                    array_values(array_filter($wpisy, static fn (array $w): bool => $w['employment_id'] === $osoba['id'])),
                    $pierwszy,
                    $naglowki,
                    $poziom,
                ),
            ];
        }

        return [
            'naglowki' => $naglowki,
            'wiersze' => $wiersze,
            'dni_w_miesiacu' => $dniWMiesiacu,
            'rozklad' => $zatrudnienie['calendar_name'],
            // ⚠ **Liczba nieobecności W POKAZANYM ZAKRESIE**, nie w spółce (§4a.2 zlecenia).
            // Powód jest zmierzony: w październiku 2026 w ALFA jest JEDNA nieobecność
            // na 30 wierszy i leży w 29. wierszu, na trzecim ekranie w dół — administrator
            // wchodzi tu z pytaniem „kto wypada w tym miesiącu" i musi przewinąć trzydzieści
            // pustych wierszy, żeby poznać odpowiedź. To ta sama rodzina co „pusto z filtra
            // i pusto z braku danych": **pusto, bo nikogo nie ma, wygląda identycznie jak
            // pusto, bo nie doszedłem do wiersza z danymi.**
            // ⚠ Liczymy to, co WIDAĆ: `$wpisy` i `$osoby` powstały z `$dozwolone`, czyli
            // z listy po limicie wyświetlania. Zdanie liczące cały zasięg byłoby czwartym
            // zdaniem na ekranie mówiącym o innym zbiorze niż trzy pozostałe.
            'nieobecnosci' => count($wpisy),
            'osob_z_nieobecnoscia' => count(array_unique(array_map(
                static fn (array $w): int => (int) $w['employment_id'],
                $wpisy,
            ))),
            'osob_pokazanych' => count($osoby),
            // ⚠ **Skąd biorą się dni wolne — jedno rozstrzygnięcie, trzy różne zdania.**
            // Konto bez zatrudnienia nie ma rozkładu czasu pracy, a mimo to siatka miała
            // wygaszone komórki i zdanie „Dni wolne są brane z TWOJEGO rozkładu czasu pracy"
            // (§2.2 zlecenia). Zdanie mówiło o czymś, czego to konto nie ma — a wygaszone
            // były same weekendy, dokładane w `wolnyDzien()` niezależnie od rozkładu.
            'zrodlo_wolnych' => $jaId === 0
                ? 'brak_zatrudnienia'
                : ($zatrudnienie['calendar'] === null ? 'brak_rozkladu' : 'rozklad'),
        ];
    }

    /**
     * Komórki jednego wiersza: dzień po dniu, a sąsiadujące dni tej samej nieobecności scalone
     * w jeden pasek rozciągnięty na kilka kolumn.
     *
     * ⚠ Scalanie idzie z **mapy dzień → nieobecność**, nie z zakresów dat. Przy dwóch nachodzących
     * na siebie nieobecnościach (a to się zdarza: zwolnienie w środku urlopu) przeskok „od–do”
     * pochłonąłby drugą i zniknęłaby ona z ekranu bez śladu. Mapa daje każdemu dniu jednego
     * właściciela, więc żaden dzień nie ginie, a komórka z więcej niż jednym wpisem mówi o tym
     * w podpowiedzi.
     *
     * @param list<array<string, mixed>> $wpisy nieobecności JEDNEJ osoby
     * @param list<array{numer: int, skrot: string, wolny: bool, dzis: bool}> $naglowki
     * @return list<array<string, mixed>>
     */
    private static function wiersz(array $wpisy, DateTimeImmutable $pierwszy, array $naglowki, int $poziom): array
    {
        $wDniu = [];
        foreach ($naglowki as $naglowek) {
            $iso = $pierwszy->modify('+' . ($naglowek['numer'] - 1) . ' days')->format('Y-m-d');
            foreach ($wpisy as $wpis) {
                if ($wpis['od'] <= $iso && $wpis['do'] >= $iso) {
                    $wDniu[$naglowek['numer']] ??= ['wpis' => $wpis, 'ile' => 0];
                    $wDniu[$naglowek['numer']]['ile']++;
                }
            }
        }

        $komorki = [];
        $dni = count($naglowki);
        for ($d = 1; $d <= $dni; $d++) {
            $tu = $wDniu[$d] ?? null;
            if ($tu === null) {
                $komorki[] = ['rozpietosc' => 1, 'wolny' => $naglowki[$d - 1]['wolny'],
                    'dzis' => $naglowki[$d - 1]['dzis'], 'pasek' => null];
                continue;
            }
            $koniec = $d;
            $wiele = $tu['ile'] > 1;
            while (
                $koniec + 1 <= $dni
                && isset($wDniu[$koniec + 1])
                && $wDniu[$koniec + 1]['wpis']['id'] === $tu['wpis']['id']
            ) {
                $koniec++;
                $wiele = $wiele || $wDniu[$koniec]['ile'] > 1;
            }
            $komorki[] = [
                'rozpietosc' => $koniec - $d + 1,
                'wolny' => $naglowki[$d - 1]['wolny'],
                'dzis' => $naglowki[$d - 1]['dzis'],
                'pasek' => [
                    'nazwa' => self::nazwaPaska($tu['wpis'], $poziom),
                    'zrodlo' => $tu['wpis']['zrodlo'],
                    'zakres' => DataDnia::zakres($tu['wpis']['od'], $tu['wpis']['do']),
                    // ⚠ **Na pasku stoi ZAKRES DAT, nie nazwa rodzaju** (zlecenie 06.10 §3).
                    // Policzone na sierpniu w BETA: z 17 pasków na ekranie 16 miało napis
                    // „urlop wypoczynkowy", czyli tekst powtarzał legendę szesnaście razy,
                    // a jedyna zmienna i potrzebna rzecz — OD KIEDY DO KIEDY — nie była
                    // na pasku wcale. Rodzaj zostaje na obrysie, w legendzie, w `title`
                    // i w `aria-label`; skrótu nazwy przysłanej z kadr nie wymyślamy
                    // (ta sama reguła co „nie liczymy za kadry").
                    'zakres_krotki' => DataDnia::zakresKrotki($tu['wpis']['od'], $tu['wpis']['do']),
                    // ⚠ **Numer dnia dla paska JEDNODNIOWEGO — z serwera, nie z JavaScriptu**
                    // (zlecenie `2026-10-11-paski-nieobecnosci.md` §2). Do 11.10.2026 taki pasek
                    // miał pusty napis i był bladą plamką bez żadnej informacji; po zmianie
                    // wypełnienia na kolor rodzaju widać go jeszcze mocniej, więc pustka boli
                    // bardziej. Numer bez miesiąca, bo siatka jest jednomiesięczna, a „14.08"
                    // nie wchodzi w 39 px.
                    // ⚠ To numer kolumny, w której pasek STOI (`$naglowki[$d - 1]['numer']`),
                    // a nie dzień początku nieobecności z `od` — przy nieobecności zaczętej
                    // w poprzednim miesiącu te dwie liczby są różne, a człowiek czyta kolumnę.
                    'numer_dnia' => $naglowki[$d - 1]['numer'],
                    'wiele' => $wiele,
                ],
            ];
            $d = $koniec;
        }

        return $komorki;
    }

    /**
     * Opis, SKĄD dana osoba jest na liście — **jedna funkcja, trzy rozłączne stany**:
     * „to Ty", „przełożony: Ty", „przełożony: Nowak Artur" i „bez przełożonego".
     *
     * ⚠ Przy gałęzi głębszej niż jeden poziom to jedyna podpowiedź, dlaczego kogoś widzę —
     * bez niej lista wygląda na przypadkowy zbiór nazwisk i pierwsze pytanie brzmi „a czemu on?".
     * Nazwa przełożonego bierze się z **tej samej listy**: w gałęzi w dół każdy przełożony jest
     * jej częścią, więc nie ma potrzeby drugiego zapytania (a przy okazji nie da się wyciekiem
     * pokazać nazwiska kogoś spoza zasięgu).
     *
     * ⚠ **`NULL` to nie „ja".** Do 06.10.2026 warunek brzmiał `$szef === null || $szef === $ja`
     * i podpisywał „przełożony: Ty" **każdego, kto nie ma przełożonego w ogóle** — a konto
     * administracyjne, które zatrudnienia nie ma, widziało ten podpis przy 25 osobach z 30
     * (zmierzone na ALFA: 30 nieustawionych, 1 szczyt hierarchii, 5 podwładnych). W systemie
     * urlopowym to nie kosmetyka, tylko **informacja o odpowiedzialności**: czytający wnioskuje,
     * że akceptuje urlopy tych osób. Dodatkowo zasłaniało prawdziwy sygnał — „ta osoba nie ma
     * przełożonego" jest stanem, który ma znaczenie (`leave.approve_orphaned`, kolumna
     * „Nieustawionych" na hierarchii), a ekran zamieniał „nikt" na „Ty".
     *
     * ⚠ **Kto jest „Ty", przychodzi PARAMETREM, nie z listy.** Wcześniej funkcja szukała wiersza
     * z `ja => true` w przekazanym zbiorze — czyli podpis zależał od tego, czy mój własny wiersz
     * akurat wpadł w limit wyświetlania albo w wynik szukania. Ta sama lista potrafiła więc
     * opisać tę samą osobę raz jako „przełożony: Ty", a raz jako „przełożony: Kowalski".
     * `null` znaczy „oglądający nie ma zatrudnienia", a wtedy nie jest niczyim przełożonym
     * i podpis „Ty" nie ma prawa się pojawić ani razu.
     *
     * ⚠ **Nazwiska przełożonych przychodzą z ZASIĘGU, nie z przekazanej listy.** Pierwsza wersja
     * budowała je z wierszy w `$osoby` i uzasadniałem to tak: „w gałęzi w dół każdy przełożony
     * jest częścią listy". To było prawdą tylko dla widoku zespołu bez filtru. W praktyce
     * przełożony wypada z listy przy każdym zawężeniu — odznaczeniu w kontrolce, szukaniu,
     * progu wyświetlania — i wtedy zamiast nazwiska pojawiał się zastępnik. Zmierzone na ALFA:
     * 5 wierszy z „przełożony: Siwiec Krzysztof", a po odznaczeniu Siwca w kontrolce **5 wierszy
     * z „inna osoba w gałęzi"**, choć to ta sama, wolna do oglądania osoba.
     *
     * ⚠ Granica prywatności **zostaje i stoi we właściwym miejscu**: mapa nazw powstaje z zasięgu
     * policzonego przez `ZasiegPodgladu`, z warunkiem spółki w zapytaniu. Nazwisko kogoś spoza
     * zasięgu nie ma jak tu wejść. Stare rozwiązanie mieszało dwie różne rzeczy: „nie wolno mi
     * tej osoby widzieć" i „tej osoby nie ma teraz na ekranie".
     *
     * @param list<array{id: int, osoba: string, przelozony: ?int, ja: bool}> $osoby
     * @param int|null $jaId zatrudnienie oglądającego albo `null`, gdy konto go nie ma
     * @param array<int, string> $nazwyWZasiegu mapa `id zatrudnienia → nazwisko i imię`
     * @return list<array<string, mixed>>
     */
    public static function opisz(array $osoby, ?int $jaId, array $nazwyWZasiegu): array
    {
        return array_map(static function (array $o) use ($nazwyWZasiegu, $jaId): array {
            return $o + ['rola' => self::podpisOsoby($o, $nazwyWZasiegu, $jaId)];
        }, $osoby);
    }

    /**
     * Trzy stany, trzy podpisy — i **ani jednego sklejenia**.
     *
     * ⚠ „Nie ma przełożonego" i „przełożonym jesteś Ty" to dwie różne informacje; sklejenie ich
     * jest tą samą usterką co „pusto z filtra i pusto z braku danych wyglądają tak samo".
     * Słowo „bez przełożonego" jest **to samo**, którym mówi o tym ekran hierarchii — żeby
     * dwa ekrany nie opisywały jednego stanu dwoma nazwami.
     *
     * @param array{id: int, osoba: string, przelozony: ?int, ja: bool} $o
     * @param array<int, string> $nazwy
     */
    private static function podpisOsoby(array $o, array $nazwy, ?int $jaId): string
    {
        if ($o['ja']) {
            return 'to Ty';
        }
        $szef = $o['przelozony'];
        if ($szef === null) {
            return 'bez przełożonego';
        }
        if ($jaId !== null && $szef === $jaId) {
            return 'przełożony: Ty';
        }
        // ⚠ Zapis z dwukropkiem, a nie „podwładny Kotwickiego" jak na makiecie: odmiany
        // nazwisk przez przypadki nie mamy (`Wolacz` robi tylko wołacz imion), a „podwładny
        // Pomorska Tatiana" to zły polski w widoku dla pracownika. Dwukropek podaje ten sam
        // fakt bez odmiany i nie wymaga zgadywania, czy nazwisko jest męskie.
        // ⚠ Zastępnik odpala się teraz **wyłącznie wtedy, gdy przełożony jest POZA zasięgiem**
        // oglądającego (inna gałąź, wygaszone zatrudnienie) — i mówi to wprost, zamiast sugerować,
        // że chodzi o kogoś „z gałęzi". Nie podajemy nazwiska, bo nie wolno; nie udajemy,
        // że go nie znamy.
        return 'przełożony: ' . ($nazwy[$szef] ?? 'spoza Twojego zasięgu');
    }

    /**
     * **Jedna funkcja rozstrzygająca, co stoi na pasku** — nazwa ze słownika czy rodzaj ogólny.
     *
     * ⚠ Szczegółowość jest **jednolita dla całej siatki, łącznie z własnym wierszem**
     * (potwierdzone 23.09.2026, `docs/etap3-logika-urlopowa.md` §8b — zapis „własnych
     * nieobecności to nie dotyczy" został wtedy odwołany jako sprzeczny z decyzją z 22.09).
     *
     * ⚠ **Powód jest w tym, po co istnieje ta siatka: do ZESTAWIANIA.** Wiersz o innej
     * szczegółowości niż reszta czyta się jako inny **rodzaj nieobecności**, a nie jako inny
     * poziom uprawnień — czyli ekran zbudowany do porównywania zacząłby kłamać w jedynej rzeczy,
     * do której służy. Utraty dostępu nie ma: pełną nazwę własnego rodzaju widać zawsze
     * w „Tylko ja", niezależnie od poziomu.
     *
     * ⚠ Poziom 2 otwiera nazwy rodzajów **całej załogi**, w tym rodzaje zwolnień — dane
     * o zdrowiu. Dlatego rola nadająca ten poziom ma `requires_2fa = 1`, a decyzja siedzi TU,
     * w jednym miejscu: gdyby stała w szablonie, pierwszy nowy widok pokazałby nazwy bez pytania.
     *
     * @param array<string, mixed> $wpis
     */
    private static function nazwaPaska(array $wpis, int $poziom): string
    {
        return CalendarVisibility::widziRodzaj($poziom)
            ? (string) $wpis['rodzaj']
            : self::ogolny((string) $wpis['zrodlo']);
    }

    /** Rodzaj ogólny ze źródła słownika; nieznane źródło zostaje „nieobecność”, nigdy kodem. */
    public static function ogolny(string $zrodlo): string
    {
        return self::OGOLNE[$zrodlo] ?? 'nieobecność';
    }

    /**
     * Dni nierobocze z rozkładu czasu pracy, dla lat, na które zachodzi widok.
     *
     * @param array<string, mixed> $zatrudnienie
     * @return array<string, true>
     */
    private function wolne(array $zatrudnienie, DateTimeImmutable $pierwszy, DateTimeImmutable $ostatni): array
    {
        // ⚠ Siatka „Tylko ja” zaczyna się w poprzednim miesiącu i kończy w następnym, więc
        // w styczniu i grudniu zachodzi na DWA lata. Jeden rok dałby grudniowe święta bez wygaszenia.
        $lata = [(int) $pierwszy->modify('-7 days')->format('Y') => true,
            (int) $ostatni->modify('+7 days')->format('Y') => true];
        $wolne = [];
        foreach (array_keys($lata) as $rok) {
            foreach ($this->dane->dniNierobocze((int) $zatrudnienie['company_id'], $zatrudnienie['calendar'], $rok) as $dzien) {
                $wolne[$dzien] = true;
            }
        }

        return $wolne;
    }

    /**
     * ⚠ Weekend dokładany do dni wolnych z R2, a nie zamiast nich: przy zatrudnieniu bez rozkładu
     * (`r2_calendar_ref` puste — tak jest dziś w części danych roboczych) lista z R2 jest pusta,
     * a sobota i niedziela nadal są dniami wolnymi. Bez tego kalendarz takiej osoby nie miałby
     * ani jednego wygaszonego dnia.
     *
     * @param array<string, true> $wolne
     */
    private static function wolnyDzien(string $iso, DateTimeImmutable $dzien, array $wolne): bool
    {
        return isset($wolne[$iso]) || (int) $dzien->format('N') >= 6;
    }
}
