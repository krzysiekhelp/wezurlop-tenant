<?php

declare(strict_types=1);

namespace Wezurlop\Controller\App;

use DateTimeImmutable;
use Wezurlop\Entity\CurrentUser;
use Wezurlop\Http\Middleware\Authenticate;
use Wezurlop\Http\Request;
use Wezurlop\Http\Response;
use Wezurlop\Repository\DataViewRepository;
use Wezurlop\Service\KalendarzPracownika;
use Wezurlop\Service\PulpitPracownika;
use Wezurlop\Service\Sync\SyncService;
use Wezurlop\Repository\SyncDomainRepository;
use Wezurlop\Repository\ApiKeyRepository;
use Wezurlop\Repository\PermissionRepository;
use Wezurlop\Support\ErrorHandler;
use Wezurlop\Support\RodzajeLimitow;
use Wezurlop\Support\View;

/**
 * `/dane` — podgląd tego, co przyszło z programu kadrowego: firmy, pracownicy, karta pracownika,
 * kalendarz i stan synchronizacji (docs/przebieg-1-integracja.md, etapy A3–A7).
 *
 * ⚠ **Wyłącznie odczyt.** Program kadrowy jest źródłem prawdy; pole edytowalne u nas byłoby
 * gwarantowanym rozjazdem przy najbliższym cyklu. Żadnej akcji POST na tych trasach nie ma.
 *
 * ⚠ Zakres firm liczy się **w zapytaniu**, nie warunkiem po pobraniu (CLAUDE.md §10): konto
 * z przypisaniem do jednej firmy nie zobaczy pracownika drugiej, nawet wpisując jego identyfikator.
 */
final class DataController
{
    private const PAGE = 25;
    private const ABSENCES_PAGE = 20;
    /** Wartość przełącznika „wszystkie” — jedyna nieliczbowa na białej liście zakresu. */
    private const ZAKRES_WSZYSTKIE = 'wszystkie';
    /** ⚠ Limit długości musi zmieścić najdłuższą wartość z białej listy. */
    private const ZAKRES_MAX = 16;
    /** Najwyżej tyle pozycji przy „wszystkie” — z informacją w stopce, gdy lista jest dłuższa. */
    private const WSZYSTKIE_MAX = 200;
    private const RUNS = 30;
    private const RUN_RECORDS_PAGE = 50;
    private const CONFLICTS = 50;

    public function __construct(
        private readonly View $view,
        private readonly DataViewRepository $data,
        private readonly KalendarzPracownika $kalendarz,
        private readonly PermissionRepository $permissions,
        private readonly ApiKeyRepository $apiKeys,
        private readonly SyncDomainRepository $domain,
        private readonly ErrorHandler $errors,
    ) {
    }

    /** @param array<string, string> $params */
    public function companies(Request $request, array $params): Response
    {
        $firmy = $this->data->companies($this->scope($request));

        return $this->view->render('data-companies.twig', [
            'firmy' => $firmy,
            // ⚠ **Kafle liczone z TEJ SAMEJ tablicy, co wiersze niżej** (Z44), a nie drugim
            // zapytaniem: kafel „Pracownicy" ma być sumą liczb, które widać pod nim. Dwa liczniki
            // tej samej rzeczy rozjeżdżają się na pierwszym brakującym warunku — i wtedy ekran
            // sam sobie przeczy, a nikt nie wie, która liczba jest prawdziwa.
            'podsumowanie' => [
                'firm' => count($firmy),
                // ⚠ To suma CZYNNYCH ZATRUDNIEŃ, nie liczba osób: osoba zatrudniona w dwóch
                // firmach jest w tej sumie dwa razy — tak samo jak w dwóch wierszach listy.
                // Podpis kafla mówi „we wszystkich firmach" właśnie dlatego.
                'pracownikow' => array_sum(array_column($firmy, 'employees')),
                // Najświeższy domknięty przebieg którejkolwiek firmy; `null`, gdy nie było żadnego.
                'ostatnia_sync' => self::najswiezszy(array_column($firmy, 'last_sync')),
            ],
            // ⚠ Językiem kadrowej, nie technicznym: ona ma wiedzieć, co z tego wynika dla danych,
            // a nie co to jest odcisk tożsamości. Szczegóły techniczne zostają na ekranie integracji.
            'podwojne_osoby' => $this->rozjazdOsob(),
        ]);
    }

    /**
     * Najpóźniejszy znacznik z listy; `null`, gdy nie ma ani jednego.
     *
     * ⚠ Porównanie napisów wystarcza i jest celowe: znaczniki z bazy mają stały format
     * `RRRR-MM-DD GG:MM:SS` w UTC, więc porządek leksykograficzny jest porządkiem czasu.
     * Parsowanie dat tylko po to, żeby wybrać największą z nich, dokładałoby strefę tam,
     * gdzie jej nie ma.
     *
     * @param list<?string> $znaczniki
     */
    private static function najswiezszy(array $znaczniki): ?string
    {
        $pelne = array_values(array_filter($znaczniki, static fn (?string $z): bool => $z !== null));

        return $pelne === [] ? null : max($pelne);
    }

    /**
     * Wiersze limitów gotowe do pokazania: nazwa po polsku, proporcja wykorzystania, przekroczenie.
     *
     * ⚠ Procent liczy KONTROLER, nie szablon — ale pasek bierze surowe minuty (`max`/`value`
     * natywnego `<progress>`), bo zaokrąglony procent rysowałby pasek odrobinę inny niż liczby
     * obok niego. Procent jest tylko do przeczytania („wykorzystane 12 dni (46%)").
     *
     * ⚠ **Przekroczenie to `remaining_min < 0`, nie `used > start`**: R2 przysyła też korekty
     * (`adjustments_min`), więc te dwa warunki potrafią się rozjechać, a prawdę o stanie niesie
     * pole, które kadry wyliczyły same.
     *
     * @param list<array<string, mixed>> $wiersze
     * @return list<array<string, mixed>>
     */
    private static function opisLimitow(array $wiersze): array
    {
        return array_map(static function (array $w): array {
            $start = (int) ($w['available_start_min'] ?? 0);
            $uzyte = (int) ($w['used_min'] ?? 0);
            $pozostalo = (int) ($w['remaining_min'] ?? 0);

            return $w + [
                'nazwa' => RodzajeLimitow::nazwa((string) $w['kind']),
                // Bez limitu na start procent nie istnieje — „100%" przy zerowym limicie mówiłoby
                // o wykorzystaniu czegoś, czego nie było.
                'procent' => $start > 0 ? (int) round(100 * $uzyte / $start) : null,
                'przekroczony' => $pozostalo < 0,
            ];
        }, $wiersze);
    }

    /**
     * Cztery kafle karty pracownika (zlecenie Z46 §2).
     *
     * ⚠ Urlop sumujemy po wszystkich zatrudnieniach TEGO SAMEGO rodzaju: osoba z etatami w dwóch
     * firmach ma dwa limity, a kafel odpowiada na pytanie „ile mam urlopu", nie „ile mam w tej
     * jednej firmie". Rozbicie stoi niżej, na karcie limitów — kafel jest skrótem, nie drugim
     * źródłem prawdy.
     *
     * @param list<array<string, mixed>> $limity
     * @param list<array<string, mixed>> $employments
     * @param array<string, mixed> $nieobecnosci
     * @return array<string, mixed>
     */
    private static function kafleKarty(array $limity, array $employments, array $nieobecnosci): array
    {
        $urlopowe = array_values(array_filter(
            $limity,
            static fn (array $w): bool => $w['kind'] === PulpitPracownika::LIMIT_URLOPOWY,
        ));
        $z0 = $employments[0] ?? null;

        return [
            'ma_limit' => $urlopowe !== [],
            'pozostalo_min' => array_sum(array_map(static fn (array $w): int => (int) ($w['remaining_min'] ?? 0), $urlopowe)),
            'start_min' => array_sum(array_map(static fn (array $w): int => (int) ($w['available_start_min'] ?? 0), $urlopowe)),
            'uzyte_min' => array_sum(array_map(static fn (array $w): int => (int) ($w['used_min'] ?? 0), $urlopowe)),
            // Rok limitu, a nie rok bieżący: przy limicie sprzed roku kafel ma mówić, czego dotyczy.
            'rok_limitu' => $urlopowe === [] ? null : max(array_map(static fn (array $w): int => (int) $w['rok'], $urlopowe)),
            'dni_nieobecnosci' => (int) ($nieobecnosci['dni'] ?? 0),
            'fte' => $z0['fte'] ?? null,
            'norma_min' => $z0 === null ? null : ($z0['leave_norm_min'] ?? null),
            'rozklad' => $z0 === null ? null : ($z0['calendar_name'] ?? $z0['calendar'] ?? null),
        ];
    }

    /**
     * Porządek listy pracowników: JEDNA lista zamiast dwóch (zlecenie Z45 §3).
     *
     * ⚠ Dwie listy („Sortuj" i „Kierunek") kazały wybierać dwa razy, żeby dostać jedną
     * odpowiedź — a cztery z sześciu sensownych kombinacji nikt nigdy nie wybrał. Lista mówi
     * teraz, co się stanie z danymi („Zatrudnieni najpóźniej"), a nie po czym i w którą stronę.
     *
     * ⚠ Wartość jest ROZKŁADANA po stronie serwera na tę samą parę, która sterowała listą
     * wcześniej (`$sort`, `$asc`), bo to ona idzie do białej listy kolumn w repozytorium
     * (CLAUDE.md §4). Nazwy kolumn nie da się sparametryzować i to się nie zmienia.
     *
     * @var array<string, array{napis: string, sort: string, asc: bool}>
     */
    private const PORZADKI = [
        'nazwisko-az'           => ['napis' => 'Nazwisko A–Z', 'sort' => 'nazwisko', 'asc' => true],
        'nazwisko-za'           => ['napis' => 'Nazwisko Z–A', 'sort' => 'nazwisko', 'asc' => false],
        'zatrudnienie-najpozno' => ['napis' => 'Zatrudnieni najpóźniej', 'sort' => 'zatrudnienie', 'asc' => false],
        'zatrudnienie-najwczes' => ['napis' => 'Zatrudnieni najwcześniej', 'sort' => 'zatrudnienie', 'asc' => true],
        'etat-malejaco'         => ['napis' => 'Etat malejąco', 'sort' => 'etat', 'asc' => false],
        'etat-rosnaco'          => ['napis' => 'Etat rosnąco', 'sort' => 'etat', 'asc' => true],
    ];

    /** Stare wartości `sortuj` (przed Z45) → klucz porządku przy kierunku rosnącym i malejącym. */
    private const PORZADKI_STARE = [
        'nazwisko'     => ['nazwisko-az', 'nazwisko-za'],
        'zatrudnienie' => ['zatrudnienie-najwczes', 'zatrudnienie-najpozno'],
        'etat'         => ['etat-rosnaco', 'etat-malejaco'],
    ];

    /**
     * Porządek z adresu: klucz nowej listy albo STARA para `sortuj` + `kierunek`.
     *
     * ⚠ Stara para zostaje obsługiwana, zamiast zniknąć: prowadzą do niej zakładki, nasze
     * własne zrzuty ekranu i odnośniki w raportach — a martwy adres, który po cichu pokazuje
     * inną kolejność, jest gorszy niż jedna gałąź `if` (ta sama zasada, co przy `/ustawienia/spolki`).
     *
     * @return array{0: string, 1: string, 2: bool} klucz porządku, kolumna, rosnąco?
     */
    private function porzadek(Request $request): array
    {
        $wybrany = $request->queryString('sortuj', 24);
        if (isset(self::PORZADKI[$wybrany])) {
            return [$wybrany, self::PORZADKI[$wybrany]['sort'], self::PORZADKI[$wybrany]['asc']];
        }
        if (isset(self::PORZADKI_STARE[$wybrany])) {
            $klucz = self::PORZADKI_STARE[$wybrany][$request->queryString('kierunek', 4) === 'desc' ? 1 : 0];

            return [$klucz, self::PORZADKI[$klucz]['sort'], self::PORZADKI[$klucz]['asc']];
        }

        return ['nazwisko-az', 'nazwisko', true];
    }

    /**
     * Czy któryś klucz obsługuje firmy z dwóch instalacji connectora — wtedy ta sama osoba
     * zatrudniona w takich firmach jest u nas widziana jako dwie.
     *
     * @return array{osob: int}|null
     */
    private function rozjazdOsob(): ?array
    {
        foreach ($this->apiKeys->all() as $key) {
            if ($key['status'] === 'active' && SyncService::identitySplit($this->domain->companiesForKey($key['id'])) !== null) {
                return ['osob' => $this->domain->peopleInManyCompanies()];
            }
        }

        return null;
    }

    /** @param array<string, string> $params */
    public function employees(Request $request, array $params): Response
    {
        $scope = $this->scope($request);
        $company = $this->data->company(self::id($params), $scope);
        if ($company === null) {
            return $this->errors->errorPage(404, null, $request->isHtmx());
        }
        $search = trim($request->queryString('szukaj', 100));
        [$porzadek, $sort, $asc] = $this->porzadek($request);
        $total = $this->data->countEmployees($company['id'], $search);
        $page = self::page($request, $total, self::PAGE);
        $rok = (int) (new DateTimeImmutable('today'))->format('Y');

        return $this->view->render('data-employees.twig', [
            'firma' => $company,
            'pracownicy' => $this->data->employees($company['id'], $search, $sort, $asc, self::PAGE, $page['offset'], $rok),
            'rok' => $rok,
            'szukaj' => $search,
            // Jedna wartość dla jednej listy — rozkłada ją serwer (patrz `porzadek()`).
            'porzadek' => $porzadek,
            'porzadki' => self::PORZADKI,
            'strona' => $page,
        ]);
    }

    /**
     * Nieobecności jako fragment (docs/system-wizualny.md §5.8): przełącznik lat i stronicowanie
     * wymieniają jedną kartę, zamiast przeładowywać dokument i przerzucać widok na górę strony.
     *
     * ⚠ Pełnoprawny endpoint: zalogowanie sprawdza middleware, uprawnienie `data.view` — trasa,
     * a przynależność osoby do firm użytkownika — `scope()` poniżej, dokładnie tak samo jak
     * w akcji pełnego ekranu (CLAUDE.md §5: nie ma „endpointów wewnętrznych, bo używa ich htmx”).
     *
     * @param array<string, string> $params
     */
    public function absences(Request $request, array $params): Response
    {
        $scope = $this->scope($request);
        $person = $this->data->person(self::id($params), $scope);
        if ($person === null) {
            return $this->errors->errorPage(404, null, $request->isHtmx());
        }

        return $this->view->render('fragments/employee-absences.twig', $this->absenceCard($request, $person, $scope));
    }

    /**
     * Dane karty nieobecności — jedno źródło dla pełnego ekranu i dla fragmentu.
     *
     * @param array<string, mixed> $person
     * @param list<int>|null $scope
     * @return array<string, mixed>
     */
    private function absenceCard(Request $request, array $person, ?array $scope): array
    {
        $employments = $this->data->employments($person['id'], $scope);
        $ids = array_map(static fn (array $e): int => $e['id'], $employments);
        // ⚠ Lata biorą się z DANYCH tej osoby, nie z kalendarza: osoba z historią 2023–2026 dostaje
        // cztery przyciski, osoba z jednym rokiem — jeden. Sztywne „bieżący i poprzedni” było
        // założeniem o danych, przez które nieobecności z 2024 i starsze były niedostępne z ekranu.
        $lata = $this->data->absenceYears($ids);
        // ⚠ Długość 16, nie 8: wartość „wszystkie” ma dziewięć znaków i przy limicie 8 nigdy nie
        // mogła się dopasować do białej listy — przycisk oddawał to samo co rok bieżący.
        $surowy = $request->queryString('rok', self::ZAKRES_MAX);
        // Wartość spoza białej listy wraca do domyślnej (CLAUDE.md §11) — a domyślną jest NAJNOWSZY
        // rok z danymi tej osoby, nie rok bieżący: karta pracownika, który ostatnią nieobecność miał
        // w 2024, nie ma się otwierać na pustej tabeli.
        $domyslny = $lata === [] ? (int) (new DateTimeImmutable('today'))->format('Y') : $lata[0];
        $wybrany = filter_var($surowy, FILTER_VALIDATE_INT);
        $zakres = $surowy === self::ZAKRES_WSZYSTKIE
            ? null
            : (in_array($wybrany, $lata, true) ? $wybrany : $domyslny);
        $podsumowanie = $this->data->absenceSummary($ids, $zakres);
        // ⚠ Przy „wszystkie” pokazujemy najwyżej 200 najnowszych pozycji i mówimy o tym w stopce.
        // Ciche ucięcie listy w systemie kadrowym jest niedopuszczalne, a osoba z dwudziestoletnim
        // stażem potrafi mieć kilkaset wierszy.
        // ⚠ Stronicowanie liczy się po **wszystkich** wierszach, także wycofanych — bo tabela
        // je pokazuje (z plakietką). Licznik obowiązujących stał tu do 10.10.2026 i przy osobie
        // z samymi wycofanymi dałby **zero stron** nad niepustą tabelą.
        $limit = $zakres === null
            ? min($podsumowanie['wszystkich'], self::WSZYSTKIE_MAX)
            : $podsumowanie['wszystkich'];
        $page = self::page($request, $limit, self::ABSENCES_PAGE);

        return [
            'osoba' => $person,
            'nieobecnosci' => $this->data->absences($ids, self::ABSENCES_PAGE, $page['offset'], $zakres),
            'podsumowanie_nieobecnosci' => $podsumowanie,
            'lata' => $lata,
            'zakres' => $zakres,
            'strona' => $page,
            // Ile pozycji pokazujemy naprawdę i czy lista została przycięta.
            'pokazano_najwyzej' => $limit,
            'przyciete' => $zakres === null && $podsumowanie['liczba'] > self::WSZYSTKIE_MAX,
        ];
    }

    /** @param array<string, string> $params */
    public function employee(Request $request, array $params): Response
    {
        $scope = $this->scope($request);
        $person = $this->data->person(self::id($params), $scope);
        if ($person === null) {
            return $this->errors->errorPage(404, null, $request->isHtmx());
        }
        $employments = $this->data->employments($person['id'], $scope);
        $ids = array_map(static fn (array $e): int => $e['id'], $employments);
        $rokBiezacy = (int) (new DateTimeImmutable('today'))->format('Y');
        $karta = $this->absenceCard($request, $person, $scope);

        // ⚠ Dzielnik minut na dni to **480, tak samo jak na pulpicie pracownika** (rozstrzygnięcie
        // Marcina 22.09.2026). Wcześniej ten ekran dzielił przez `normaUrlopowaMin` kalendarza,
        // więc przy dniówce innej niż 8 h pracownik i kadrowa czytali z tych samych minut DWIE
        // RÓŻNE liczby dni — bez sposobu rozstrzygnięcia, która jest ważna. Cała ta warstwa
        // istnieje po to, żeby pokazywać tę samą liczbę co program kadrowy, a `ksztalt-danych-r2.md`
        // (rekord `limit`) mówi „zawsze 480”. Jedna formuła, jedno miejsce: `WorkTime`.
        // ⚠ Pytanie o dniówkę 12 h (co pokazuje R2 przy `normaUrlopowaMin = 720`) jest w `docs/audyt.md`
        // jako pytanie do autora connectora — do odpowiedzi obowiązuje 480.
        $limity = $this->data->balances($ids);

        $podsumowanie = self::opisLimitow(self::summarise($limity, $employments));

        return $this->view->render('data-employee.twig', array_merge($karta, [
            'zatrudnienia' => $employments,
            'limity' => $limity,
            'podsumowanie_limitow' => $podsumowanie,
            'rok' => $rokBiezacy,
            // ⚠ Kafle liczone z TYCH SAMYCH wierszy, które stoją niżej na karcie „Limity z kadr",
            // a nie drugim przebiegiem po danych: kafel „Pozostało urlopu" ma być sumą tego,
            // co widać pod nim (ta sama zasada, co w kaflach na `/dane`).
            'kafle' => self::kafleKarty($podsumowanie, $employments, $karta['podsumowanie_nieobecnosci']),
            'miesiac' => (new DateTimeImmutable('today'))->format('Y-m'),
        ]));
    }

    /**
     * Widok miesięczny: nieobecności i dni wolne z kalendarza R2.
     *
     * @param array<string, string> $params
     */
    public function calendar(Request $request, array $params): Response
    {
        $scope = $this->scope($request);
        $person = $this->data->person(self::id($params), $scope);
        if ($person === null) {
            return $this->errors->errorPage(404, null, $request->isHtmx());
        }
        // ⚠ **Zatrudnienia z TEGO SAMEGO zakresu, co osoba** — konto przypisane do jednej firmy
        // nie dostanie tu etatu z drugiej, bo granica jest w zapytaniu, nie w warunku po pobraniu
        // (CLAUDE.md §10). Osoba potrafi mieć ich kilka i wszystkie jadą do jednej siatki.
        $employments = $this->data->employments($person['id'], $scope);
        $pierwszy = KalendarzPracownika::miesiac($request->queryString('miesiac', 7));

        return $this->view->render('data-calendar.twig', [
            'osoba' => $person,
            // ⚠ Nazwa okresu i adresy sąsiadów z `KalendarzPracownika::okres()`, czyli z tego
            // samego miejsca co `/kalendarz`. Własna tablica nazw miesięcy stała tu do 19.10.2026
            // i była drugą prawdą o tym samym — dodać miesiąc trzeba by w dwóch plikach.
            'okres' => KalendarzPracownika::okres($pierwszy),
            // ⚠ **Ta sama metoda, która rysuje „Tylko ja · Miesiąc”** (zlecenie Z51): jedna siatka,
            // jedna lista wpisów, jedno przycinanie do pięciu pozycji. Ekran różni się wyłącznie
            // tym, czyje dane podaje i dokąd prowadzą przyciski.
            'mies' => $this->kalendarz->widokMiesiaca(
                $employments,
                $pierwszy,
                (new DateTimeImmutable('today'))->format('Y-m-d'),
            ),
        ]);
    }

    /** @param array<string, string> $params */
    public function runs(Request $request, array $params): Response
    {
        return $this->view->render('data-sync.twig', ['przebiegi' => $this->data->runs($this->scope($request), self::RUNS)]);
    }

    /** @param array<string, string> $params */
    public function run(Request $request, array $params): Response
    {
        $run = $this->data->run(self::id($params), $this->scope($request));
        if ($run === null) {
            return $this->errors->errorPage(404, null, $request->isHtmx());
        }
        $counts = $this->data->runRecordCounts($run['id'], $run['company_id']);
        $page = self::page($request, $counts['razem'], self::RUN_RECORDS_PAGE);

        return $this->view->render('data-sync-run.twig', [
            'przebieg' => $run,
            'liczby' => $counts,
            'rekordy' => $this->data->runRecords($run['id'], $run['company_id'], self::RUN_RECORDS_PAGE, $page['offset']),
            'konflikty' => $this->data->conflicts($run['id'], $run['company_id'], self::CONFLICTS),
            'strona' => $page,
        ]);
    }

    /**
     * Podsumowanie limitów: po jednym wierszu na zatrudnienie i rodzaj, z wartościami **roku
     * najnowszego** — bo to on obowiązuje.
     *
     * ⚠ Lat NIE sumujemy i jest to świadome: urlop zaległy z poprzedniego roku program kadrowy
     * ma już wliczony w pozycję „zaległy” roku bieżącego, więc dodanie obu policzyłoby te same dni
     * dwa razy. Zamiast liczby, której nie da się obronić, pokazujemy rok obowiązujący i listę lat,
     * które przyszły z kadr.
     *
     * @param list<array<string, mixed>> $balances
     * @param list<array<string, mixed>> $employments
     * @return list<array<string, mixed>>
     */
    private static function summarise(array $balances, array $employments): array
    {
        $companies = [];
        foreach ($employments as $employment) {
            $companies[$employment['id']] = $employment['company'];
        }
        $rows = [];
        foreach ($balances as $b) {
            $key = $b['employment_id'] . '|' . $b['kind'];
            $known = $rows[$key] ?? null;
            $lata = $known === null ? [] : $known['lata'];
            $lata[] = $b['year'];
            // Wiersze przychodzą posortowane rokiem malejąco, więc pierwszy trafiony rok jest najnowszy.
            $rows[$key] = $known !== null
                ? ['lata' => $lata] + $known
                : [
                    'lata' => $lata,
                    'firma' => $companies[$b['employment_id']] ?? '—',
                    'employment_id' => $b['employment_id'],
                    'kind' => $b['kind'],
                    'rok' => $b['year'],
                    'accrued' => $b['accrued'],
                    'available_start_min' => $b['available_start_min'],
                    'used_min' => $b['used_min'],
                    'remaining_min' => $b['remaining_min'],
                    'on_demand_days' => $b['on_demand_days'],
                ];
            $rows[$key]['lata'] = $lata;
        }
        foreach ($rows as $key => $row) {
            rsort($row['lata']);
            $rows[$key] = $row;
        }

        return array_values($rows);
    }

    /**
     * Zakres firm konta: `null` = przypisanie na całego tenanta (widzi wszystko), lista — tylko te firmy,
     * `[]` — konto bez aktywnego zatrudnienia i bez roli tenanta (nie widzi niczego).
     *
     * @return list<int>|null
     */
    private function scope(Request $request): ?array
    {
        $user = $request->attribute(Authenticate::USER);
        if (!$user instanceof CurrentUser) {
            return [];
        }
        if ($this->permissions->hasTenantWideRole($user->accountId())) {
            return null;
        }
        $companyId = $user->activeEmployment()?->companyId;

        return $companyId === null ? [] : [$companyId];
    }

    /**
     * @return array{numer: int, stron: int, offset: int, razem: int}
     */
    private static function page(Request $request, int $total, int $size): array
    {
        $pages = max(1, (int) ceil($total / $size));
        $number = filter_var($request->query('strona'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => $pages]]);
        $number = $number === false ? 1 : $number;

        return ['numer' => $number, 'stron' => $pages, 'offset' => ($number - 1) * $size, 'razem' => $total];
    }

    private static function id(array $params): int
    {
        return (int) ($params['id'] ?? 0);
    }
}
