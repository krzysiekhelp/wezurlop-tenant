<?php

declare(strict_types=1);

namespace Wezurlop\Controller\App;

use DateTimeImmutable;
use Wezurlop\Entity\CurrentUser;
use Wezurlop\Http\Middleware\Authenticate;
use Wezurlop\Http\Request;
use Wezurlop\Http\Response;
use Wezurlop\Repository\CalendarFilterRepository;
use Wezurlop\Repository\EmploymentRepository;
use Wezurlop\Repository\PracownikRepository;
use Wezurlop\Service\CalendarVisibility;
use Wezurlop\Service\KalendarzPracownika;
use Wezurlop\Service\KontekstSpolki;
use Wezurlop\Service\ZasiegPodgladu;
use Wezurlop\Support\Config;
use Wezurlop\Support\ErrorHandler;
use Wezurlop\Support\Odmiana;
use Wezurlop\Support\UrlGenerator;
use Wezurlop\Support\View;

/**
 * „Mój kalendarz” (`/kalendarz`) — jeden ekran, **trzy zasięgi**: „Tylko ja” (siatka miesiąca),
 * „Zespół” (wiersze to osoby, kolumny to dni) i „Firma” (ta sama siatka, cała spółka).
 *
 * ⚠ **Trzeci zasięg, nie trzeci ekran.** Każda pozycja przełącznika istnieje tylko wtedy, gdy ma
 * treść: „Zespół” przy podwładnych, „Firma” przy nadanym poziomie widoczności ≥ 1
 * (`docs/etap3-logika-urlopowa.md` §8b).
 *
 * ⚠ **Poziom i zasięg to dwie niezależne osie.** Zasięg (`ZasiegPodgladu`) rozstrzyga **kogo
 * widać**, poziom (`CalendarVisibility`) — **jak szczegółowo**. Zlanie ich dałoby przełożonego,
 * który widzi nazwy rodzajów, bo ma szeroki zasięg.
 *
 * ⚠ **Kod pyta o UPRAWNIENIE, nigdy o nazwę roli.** Dzięki temu rola kadrowa z Etapu 3 dostanie
 * te same kody i nic nie trzeba będzie scalać ręcznie. Kontroler nie zna nawet kodów uprawnień —
 * pyta `CalendarVisibility`.
 *
 * ⚠ **Zasięg podglądu liczy się przy każdym żądaniu od nowa** (zlecenie §5). Identyfikatory
 * przychodzą trzema drogami — z adresu (`?osoby=`), z formularza kontrolki i z zapamiętanego
 * wyboru — i **wszystkie trzy** przechodzą przez `ZasiegPodgladu::przefiltruj()` przed
 * dotknięciem bazy. Podanie cudzego identyfikatora zatrudnienia nie ma prawa dodać nikogo
 * do wyniku; to modelowy IDOR (CLAUDE.md §10) i bramka ekranowa łamie go celowo.
 *
 * ⚠ **Rozstrzyganie, kogo widać, jest w jednym miejscu** (`wybrane()`): jawne wskazanie w adresie
 * (`?osoby=`) wygrywa, a bez niego widać **cały zasięg minus zapamiętane wyłączenia**. Dwa miejsca
 * decydujące o tym samym stanie rozjechałyby się przy pierwszej zmianie — tę lekcję mamy z paska
 * zmian w hierarchii.
 *
 * ⚠ **Ten sam endpoint, dwie formy odpowiedzi**: pełna strona (ścieżka bez JavaScriptu, adres
 * do wklejenia) i fragment do wymiany karty. Nagłówek `HX-Request` decyduje **wyłącznie o formie**
 * — zalogowanie, uprawnienie, zasięg podglądu i granica spółki sprawdzają się tak samo w obu
 * (CLAUDE.md §5: nie ma „endpointów wewnętrznych, bo używa ich htmx").
 *
 * ⚠ **Domyślnie zaznaczeni są WSZYSCY w zasięgu**, a kontrolka służy do zawężania. Odwrotna
 * kolejność (pierwsza wersja z 22.09.2026) dawała przy pierwszym wejściu widok „Zespół”
 * identyczny z „Tylko ja” — jeden wiersz — czyli przełącznik, który po kliknięciu nie zmienia
 * nic widocznego. Zgłoszone jako „nie widzę swoich pracowników” i słusznie.
 */
final class CalendarController
{
    /** Najwyżej tyle identyfikatorów przyjmujemy z jednego żądania — zespół, nie cała firma. */
    private const OSOB_MAX = 200;

    /** Limit długości `?osoby=` w adresie: 200 identyfikatorów po sześć znaków plus przecinki. */
    private const OSOBY_PARAM_MAX = 1400;

    public function __construct(
        private readonly View $view,
        private readonly PracownikRepository $dane,
        private readonly EmploymentRepository $etaty,
        private readonly KalendarzPracownika $kalendarz,
        private readonly ZasiegPodgladu $zasieg,
        private readonly CalendarFilterRepository $filtr,
        private readonly CalendarVisibility $widocznosc,
        private readonly Config $config,
        private readonly KontekstSpolki $kontekstSpolki,
        private readonly ErrorHandler $errors,
        private readonly UrlGenerator $urls,
    ) {
    }

    /** @param array<string, string> $params */
    public function index(Request $request, array $params): Response
    {
        $user = $request->attribute(Authenticate::USER);
        assert($user instanceof CurrentUser);
        ['spolki' => $spolki, 'spolka' => $spolka, 'zatrudnienie' => $zatrudnienie]
            = $this->kontekstWidoku($request, $user);
        if ($spolka === null) {
            // Konto bez zatrudnienia **i bez nadanego poziomu** — zdanie, nie pusty ekran
            // (decyzja z 22.09, zawężona 23.09 do „i bez uprawnienia").
            // ⚠ Także tu wybór szablonu idzie przez `szablon()`: żądanie htmx dostaje fragment,
            // inaczej w miejsce karty wjechałaby cała strona z powłoką w środku powłoki.
            return $this->view->render(self::szablon($request), [
                'zatrudnienie' => null, 'okres' => null, 'widok' => KalendarzPracownika::WIDOK_JA,
                // ⚠ Skale też muszą tu być: `strict_variables` wywraca szablon na brakującej
                // zmiennej, a ta gałąź renderuje ten sam plik co kalendarz z danymi.
                'skala' => KalendarzPracownika::SKALA_MIESIAC, 'skale' => [],
                'rok' => null, 'lista_roku' => null,
                'ja' => null, 'zespol' => null, 'osoby' => [], 'wybrane' => [], 'ma_zespol' => false,
                'ma_firme' => false, 'ukryci' => 0, 'w_zasiegu' => 0, 'obciete' => 0,
                'poziom' => CalendarVisibility::POZIOM_BRAK, 'zasieg_opis' => null, 'szukaj' => '',
                'spolki' => [], 'spolka' => null, 'osob_w_liscie' => 0, 'pozycji_widoku' => 0,
                'kontekst_podglad' => null, 'widok_odmowa' => null,
                'wyl_poza_lista' => [],
                // ⚠ Zdanie **o rozbieżności ze znacznikiem**, nie o pustym ekranie: od 10.10.2026
                // znacznik w belce stoi też tutaj i pokazuje spółkę, bo konto z rolą tenantową
                // widzi wszystkie spółki. Kalendarz jednak wymaga etatu albo **nadanego poziomu
                // widoczności** (rozstrzygnięcie 08.10: hierarchia jest tenantowa, podgląd
                // kalendarza per spółka) — bez tego przełączanie znacznika niczego tu nie zmienia
                // i wygląda na zepsutą kontrolkę. Mówimy więc wprost, czego brakuje.
                'brak_podgladu' => $this->kontekstSpolki
                    ->rozstrzygnij($user->accountId(), $user->activeEmployment())['spolka'] !== null,
                'tytul' => self::TYTUL,
            ]);
        }

        return $this->view->render(
            self::szablon($request),
            $this->model($request, $user, $zatrudnienie, $spolka, $spolki,
                $request->queryString('widok', 8), $request->queryString('miesiac', 7),
                $request->queryString('skala', 8)),
        );
    }

    /**
     * **Jedno miejsce, w którym rozstrzyga się, którą spółkę i które zatrudnienie pokazuje
     * kalendarz** — a także co ma stać na liście rozwijanej wyboru spółki.
     *
     * Kolejność:
     * 1. lista spółek = spółki **moich aktywnych zatrudnień** ∪ spółki z **nadanym poziomem**
     *    widoczności (bez powtórzeń),
     * 2. spółka = `?spolka=` albo `spolka` z ciała żądania, **z tej białej listy**; wartość spoza
     *    listy wraca do spółki kontekstu, a nie do błędu (CLAUDE.md §11),
     * 3. bez wskazania → spółka bieżącego kontekstu zatrudnienia, a gdy go nie ma → pierwsza
     *    z listy; lista pusta → `null`, czyli zdanie zamiast kalendarza,
     * 4. zatrudnienie = **moje** aktywne zatrudnienie w wybranej spółce albo `null`
     *    (konto z nadanym poziomem, ale bez etatu w tej spółce).
     *
     * ⚠ **Dlaczego to się zmieniło 07.10.2026.** Dotąd przy aktywnym zatrudnieniu lista spółek
     * była ustawiana na pustą (`$zatrudnienie === null ? $spolki : []`), bo założyliśmy, że
     * pracownikowi spółkę wybiera przełącznik kontekstu. Zmierzone na koncie pracownika
     * z etatami w dwóch spółkach: w kalendarzu **nie ma ani kontrolki spółki, ani przełącznika
     * kontekstu** (nie ma go też w nawigacji) — czyli spółki nie da się zmienić z tego ekranu
     * wcale. Założenie było fałszywe i kosztowało pracownika dostęp do połowy własnych danych.
     *
     * ⚠ **`?spolka=` jest parametrem WIDOKU, nie przełącznikiem kontekstu.** Nie zmieniamy stanu
     * sesji żądaniem GET (CLAUDE.md §3) — kalendarz patrzy na inną własną spółkę, a kontekst
     * (pulpit, wnioski) zostaje tam, gdzie go ustawiono na `/kontekst`. To ta sama mechanika,
     * którą od 23.09 ma konto z nadanym poziomem, więc kontrolka jest jedna dla obu rodzajów kont.
     *
     * ⚠ Każde zatrudnienie bierze się z `activeForAccount()`, czyli **z zapytania ograniczonego
     * do tego konta** — identyfikator z żądania nigdy nie wskazuje zatrudnienia wprost (IDOR, §10).
     *
     * @return array{spolki: list<array{id: int, nazwa: string, zatrudnienie_id: ?int}>,
     *     spolka: array{id: int, nazwa: string, z_adresu: bool, poza_zakresem: bool}|null,
     *     zatrudnienie: array<string, mixed>|null}
     */
    private function kontekstWidoku(Request $request, CurrentUser $user): array
    {
        $etaty = $this->etaty->activeForAccount($user->accountId());
        $spolki = [];
        // ⚠ Identyfikator zatrudnienia idzie razem z nazwą, bo od 07.10.2026 **rozstrzyga
        // o znaczeniu pozycji w kontrolce**: spółka z etatem przełącza kontekst całej sesji
        // (`POST /kontekst/{id}`), spółka bez etatu tylko widok kalendarza (zlecenie §3).
        $etatWSpolce = [];
        foreach ($etaty as $etat) {
            $spolki[(int) $etat['company_id']] = (string) $etat['company_name'];
            $etatWSpolce[(int) $etat['company_id']] ??= (int) $etat['id'];
        }
        foreach ($this->widocznosc->spolkiZPoziomem($user->accountId()) as $s) {
            $spolki[(int) $s['id']] ??= (string) $s['nazwa'];
        }
        if ($spolki === []) {
            return ['spolki' => [], 'spolka' => null, 'zatrudnienie' => null];
        }
        // Kolejność po nazwie wyświetlanej — ta sama reguła co w pozostałych wykazach spółek.
        uasort($spolki, static fn (string $a, string $b): int => strcoll($a, $b));
        $lista = [];
        foreach ($spolki as $id => $nazwa) {
            $lista[] = ['id' => $id, 'nazwa' => $nazwa, 'zatrudnienie_id' => $etatWSpolce[$id] ?? null];
        }

        // ⚠ **Spółka przychodzi z adresu ALBO z formularza** — i to nie jest wygoda, tylko
        // naprawa usterki znalezionej 06.10.2026 przy dowodzie z §2b. Dotąd czytaliśmy wyłącznie
        // `?spolka=` z adresu, a kontrolka wyboru osób wysyła `spolka` **w ciele POST-a** (jej
        // `action` nie ma parametrów). Skutek: zapis wyboru dokonany na kalendarzu BETA trafiał
        // do filtru ALFA — pierwszej spółki z białej listy. Zmierzone: wiersz
        // `calendar_filter(company_id = 3, excluded_ids = [])` po kliknięciu „Odznacz wszystkich"
        // na spółce 4. ⚠ Nic nie wyciekało (oba zakresy są na białej liście tego konta), ale
        // decyzja człowieka lądowała w cudzym stanie, a ekran pokazywał sukces.
        // ⚠ Kolejność jest jednoznaczna, bo zbiory są rozłączne: GET nie ma ciała, a `action`
        // formularza nie ma parametrów w adresie.
        // ⚠ **Od 10.10.2026 spółkę podaje znacznik w belce** (zlecenie
        // `2026-10-10-globalny-wybor-spolki.md` §1), a nie własna kontrolka tego ekranu.
        // `?spolka=` z adresu honoruje `KontekstSpolki::dlaEkranu()` jako widok na jedno
        // żądanie — stare odnośniki w zakładkach dalej działają, ale aplikacja ich nie generuje.
        // ⚠ `spolka` **z ciała POST-a zostaje i ma pierwszeństwo**: kontrolka wyboru osób wiąże
        // zapisany filtr ze spółką, dla której ekran był wyrenderowany. Tego pola nie wolno
        // zdjąć razem z parametrem adresu — to na nim stoi naprawa usterki z 06.10 opisanej niżej.
        $zPostu = filter_var($request->postString('spolka', 12), FILTER_VALIDATE_INT);
        $zBelki = $this->kontekstSpolki->dlaEkranu(
            $user->accountId(),
            $user->activeEmployment(),
            $request->queryString('spolka', 12),
        );
        $zadana = is_int($zPostu) ? $zPostu : ($zBelki['spolka']['id'] ?? null);
        $kontekst = $user->activeEmployment();
        $wybrana = null;
        if (is_int($zadana) && isset($spolki[$zadana])) {
            $wybrana = $zadana;
        } elseif ($kontekst !== null) {
            foreach ($etaty as $etat) {
                if ((int) $etat['id'] === $kontekst->employmentId && isset($spolki[(int) $etat['company_id']])) {
                    $wybrana = (int) $etat['company_id'];
                    break;
                }
            }
        }
        $wybrana ??= $lista[0]['id'];

        $employmentId = null;
        foreach ($etaty as $etat) {
            if ((int) $etat['company_id'] === $wybrana) {
                $employmentId = (int) $etat['id'];
                break;
            }
        }

        return [
            'spolki' => $lista,
            // ⚠ `z_adresu` i `poza_zakresem` jadą do szablonu, bo ekran ma **powiedzieć**,
            // czemu pokazuje inną spółkę niż znacznik w belce. Milczenie w tym miejscu
            // wygląda jak usterka znacznika.
            'spolka' => ['id' => $wybrana, 'nazwa' => $spolki[$wybrana],
                'z_adresu' => $zBelki['z_adresu'] && $wybrana === ($zBelki['spolka']['id'] ?? null),
                'poza_zakresem' => ($zBelki['spolka']['id'] ?? null) !== null
                    && $wybrana !== $zBelki['spolka']['id'] && !is_int($zPostu)],
            'zatrudnienie' => $employmentId === null
                ? null
                : $this->dane->mojeZatrudnienie($employmentId, $user->accountId()),
        ];
    }

    /**
     * Kod powodu, dla którego widok jest inny od żądanego — albo `null`.
     *
     * ⚠ **Reagujemy tylko na ZNANE nazwy widoków.** Wartość bzdurna (`?widok=bzdura`) to nie
     * odmowa, tylko zepsuty adres, a zdanie „nie znam takiego widoku" byłoby szumem — i musiałoby
     * albo przemilczeć, co dostało, albo wypisać na ekran tekst z żądania. Milczenie w tym
     * jednym przypadku jest świadome i zapisane w raporcie, nie przeoczone.
     *
     * ⚠ Dwa powody, nie jeden: „nie masz podwładnych" i „nie masz nadanego podglądu spółki"
     * to różne rzeczy i różne drogi wyjścia — pierwsza zależy od hierarchii, druga od nadania
     * uprawnienia przez administratora. Jedno zdanie na oba przypadki kazałoby człowiekowi
     * zgadywać, czego mu brakuje.
     */
    private static function powodOdmowyWidoku(string $zadany, string $widok, bool $maEtat): ?string
    {
        if ($zadany === $widok) {
            return null;
        }
        // ⚠ Trzeci powód, dodany po pomiarze: konto administracyjne **bez zatrudnienia** też
        // dostawało „nie masz podwładnych". Zdanie było prawdziwe, ale wskazywało złą przyczynę —
        // takie konto nie ma zatrudnienia, więc nie ma gałęzi hierarchii, a nie „ma gałąź bez
        // podwładnych". Zdanie, które myli przyczynę, wysyła człowieka w złe miejsce.
        if (!$maEtat && $zadany === KalendarzPracownika::WIDOK_ZESPOL) {
            return 'brak_zatrudnienia';
        }

        return match ($zadany) {
            KalendarzPracownika::WIDOK_ZESPOL => 'brak_podwladnych',
            KalendarzPracownika::WIDOK_FIRMA => 'brak_poziomu',
            default => null,
        };
    }

    /**
     * Nazwa spółki, w której **pracuję** (kontekst zatrudnienia), albo `null`.
     *
     * Używana wyłącznie wtedy, gdy kalendarz pokazuje spółkę **bez mojego etatu** — żeby zdanie
     * pod kontrolką mogło powiedzieć, gdzie w takim razie liczą się limity i wnioski.
     */
    private function spolkaPracy(CurrentUser $user): ?string
    {
        $kontekst = $user->activeEmployment();
        if ($kontekst === null) {
            return null;
        }
        foreach ($this->etaty->activeForAccount($user->accountId()) as $etat) {
            if ((int) $etat['id'] === $kontekst->employmentId) {
                return (string) $etat['company_name'];
            }
        }

        return null;
    }

    /**
     * Szablon odpowiedzi: fragment dla htmx, pełna strona dla zwykłego żądania.
     *
     * ⚠ To jedyne miejsce, w którym `HX-Request` cokolwiek zmienia — i zmienia **formę**,
     * nie uprawnienia.
     */
    private static function szablon(Request $request): string
    {
        return $request->isHtmx() ? 'fragments/calendar.twig' : 'calendar.twig';
    }

    /**
     * Model widoku — **jedno miejsce** dla pełnej strony, fragmentu i odpowiedzi po zapisie.
     *
     * ⚠ Trzy drogi do tego samego ekranu to trzy okazje, żeby policzyć zasięg inaczej. Dlatego
     * liczy się go tu raz: `dozwolone` → `wybrane` → liczba ukrytych.
     *
     * @param array<string, mixed> $zatrudnienie
     * @return array<string, mixed>
     */
    private function model(
        Request $request,
        CurrentUser $user,
        ?array $zatrudnienie,
        array $spolka,
        array $spolki,
        string $widokZadany,
        string $miesiac,
        string $skalaZadana = KalendarzPracownika::SKALA_MIESIAC
    ): array {
        $pierwszy = KalendarzPracownika::miesiac($miesiac);
        $dzis = (new DateTimeImmutable('today'))->format('Y-m-d');
        $jaId = $zatrudnienie === null ? 0 : (int) $zatrudnienie['id'];

        // ⚠ Poziom czytany z bazy przy KAŻDYM żądaniu — odebranie uprawnienia działa natychmiast
        // (CLAUDE.md §8), a nie „po wylogowaniu".
        $poziom = $this->widocznosc->poziomKonta($user->accountId(), $spolka['id']);
        $zasieg = $this->zasieg->dlaWidoku(
            $widokZadany,
            $spolka['id'],
            $zatrudnienie === null ? null : $jaId,
            CalendarVisibility::widziSpolke($poziom),
        );
        $widok = $zasieg['widok'];
        $dozwolone = $zasieg['dozwolone'];
        // ⚠ **Skala dotyczy wyłącznie „Tylko ja"** (zlecenie 11.10.2026 §1 i §4): w widokach
        // zespołu i firmy wraca miesiąc, bo rok dla 53 osób to siatka, której nikt nie przeczyta
        // (zmierzone przy odrzuceniu siatki miesiąca dla wielu osób — 11 pasków jeden pod drugim).
        // Adres ze skalą na widoku zespołu nie jest więc błędem, tylko wraca do miesiąca.
        $skala = $widok === KalendarzPracownika::WIDOK_JA && $zatrudnienie !== null
            ? KalendarzPracownika::skala($skalaZadana)
            : KalendarzPracownika::SKALA_MIESIAC;

        // Kontekst dla usługi kalendarza: konto bez zatrudnienia nie ma własnego rozkładu
        // czasu pracy, więc dni wolne zostają na weekendach i ekran mówi to wprost.
        $kontekst = [
            'id' => $jaId,
            'company_id' => $spolka['id'],
            'company' => $spolka['nazwa'],
            'calendar' => $zatrudnienie === null ? null : $zatrudnienie['calendar'],
            'calendar_name' => $zatrudnienie === null ? null : $zatrudnienie['calendar_name'],
        ];

        // ⚠ Nazwy dla CAŁEGO zasięgu, nie dla pokazanych: podpis „przełożony: …" musi podać
        // nazwisko także wtedy, gdy przełożony nie jest akurat na liście (odznaczony w kontrolce,
        // odcięty szukaniem, poza progiem). Granicę prywatności pilnuje sam `$dozwolone`.
        $nazwyWZasiegu = $this->dane->nazwyZasiegu($spolka['id'], $dozwolone);
        $wybrane = $this->wybrane($request, $user->accountId(), $spolka['id'], $dozwolone);
        // ⚠ **LIMIT WYŚWIETLANIA, nie filtr.** Obcięcie nie idzie do bazy i nie zamienia się
        // w wyłączenia: zapamiętujemy wyłącznie to, co człowiek sam odznaczył. Gdyby limit dopisał
        // 23 wyłączenia, filtr stałby się migawką i pracownik przyjęty jutro nie pojawiłby się
        // nikomu na kalendarzu — dokładnie ta usterka, dla której reguła wyłączeń powstała
        // (`docs/etap3-logika-urlopowa.md` §8b).
        $limit = $this->config->calendarPickerLimit();
        $pokazane = array_slice($wybrane, 0, $limit);
        $szukaj = $request->queryString('szukaj', 100);
        $lista = $widok === KalendarzPracownika::WIDOK_JA
            ? ['osoby' => [], 'razem' => 0]
            : $this->dane->osobyZasiegu($spolka['id'], $dozwolone, $jaId, $szukaj, $limit);

        return [
            'zatrudnienie' => $zatrudnienie,
            'spolka' => $spolka,
            // Przełącznik spółek WYŁĄCZNIE dla konta bez zatrudnienia: przy zatrudnieniu spółkę
            // wybiera istniejący przełącznik kontekstu i drugi byłby drugim właścicielem stanu.
            // ⚠ Lista spółek idzie do szablonu ZAWSZE, nie tylko dla konta bez zatrudnienia:
            // kontrolka chowa się sama przy jednej pozycji (`partials/combo-spolki.twig`),
            // a warunek „tylko bez etatu" zabierał pracownikowi z dwoma etatami jedyną drogę
            // zmiany spółki w kalendarzu (zmierzone 07.10.2026).
            'spolki' => $spolki,
            'okres' => KalendarzPracownika::okres($pierwszy, $skala),
            'skala' => $skala,
            // ⚠ Pozycje przełącznika skal powstają TUTAJ, a nie w szablonie: szablon ma
            // wyświetlać, a nie rozstrzygać, które stany ekranu istnieją. Przełącznik stoi
            // tylko przy „Tylko ja", bo tylko tam skala coś zmienia.
            'skale' => $widok === KalendarzPracownika::WIDOK_JA && $zatrudnienie !== null
                ? [
                    ['kod' => KalendarzPracownika::SKALA_MIESIAC, 'nazwa' => 'Miesiąc'],
                    ['kod' => KalendarzPracownika::SKALA_ROK, 'nazwa' => 'Rok'],
                    ['kod' => KalendarzPracownika::SKALA_LISTA, 'nazwa' => 'Lista'],
                ]
                : [],
            'widok' => $widok,
            'poziom' => $poziom,
            'ma_zespol' => $zasieg['galaz'] > 1,
            'ma_firme' => CalendarVisibility::widziSpolke($poziom),
            // ⚠ **Ile pozycji ma przełącznik widoku** — liczone tu, bo szablon ma wyświetlać,
            // a nie sumować warunki. Przy jednej pozycji przełącznika NIE MA (§3 zlecenia
            // z wieczora 06.10): kontrolka bez drugiego wyboru to przycisk bez działania.
            'pozycji_widoku' => ($zatrudnienie !== null ? 1 : 0)
                + ($zasieg['galaz'] > 1 ? 1 : 0)
                + (CalendarVisibility::widziSpolke($poziom) ? 1 : 0),
            // ⚠ Każda skala buduje się **tylko wtedy, gdy jest wybrana** — inaczej jedno żądanie
            // pytałoby bazę o miesiąc i o cały rok naraz, a widać zawsze jedno.
            'ja' => $skala === KalendarzPracownika::SKALA_MIESIAC
                && $widok === KalendarzPracownika::WIDOK_JA && $zatrudnienie !== null
                ? $this->kalendarz->widokJa($zatrudnienie, $pierwszy, $dzis)
                : null,
            'rok' => $skala === KalendarzPracownika::SKALA_ROK && $zatrudnienie !== null
                ? $this->kalendarz->widokRoku($zatrudnienie, (int) $pierwszy->format('Y'), $dzis)
                : null,
            'lista_roku' => $skala === KalendarzPracownika::SKALA_LISTA && $zatrudnienie !== null
                ? $this->kalendarz->widokListy($zatrudnienie, (int) $pierwszy->format('Y'))
                : null,
            'zespol' => $widok === KalendarzPracownika::WIDOK_JA
                ? null
                : $this->kalendarz->widokZespolu($kontekst, $pokazane, $pierwszy, $dzis, $poziom, $nazwyWZasiegu),
            // Lista kontrolki powstaje **po stronie serwera** z zasięgu i zawiera wyłącznie osoby,
            // które wolno oglądać — a nie wszystkich i ukrywanie pozostałych w szablonie.
            // Szukanie też idzie serwerem, tym samym mechanizmem co kandydat na przełożonego.
            'osoby' => KalendarzPracownika::opisz($lista['osoby'], $jaId > 0 ? $jaId : null, $nazwyWZasiegu),
            'osob_w_liscie' => $lista['razem'],
            // Licznik „Zaznaczono N z M" w stopce kontrolki — liczony TU, nie w szablonie
            // (CLAUDE.md §5: w szablonie są pętle i warunki wyświetlania, nie rachunki).
            // JavaScript przelicza go na żywo, ale wartość początkowa musi być prawdziwa
            // także bez niego.
            // ⚠ Wyłączenia OBOWIĄZUJĄCE, ale jeszcze niezapisane — kontrolka odsyła je
            // w ukrytych polach dla osób **poza** pokazaną listą, żeby decyzja o kimś, kto
            // wypadł z wyniku szukania, nie zginęła. Pokazanych wyraża pole wyboru.
            'wyl_poza_lista' => array_values(array_diff(
                $this->wykluczeniaBiezace($request, $user->accountId(), $spolka['id'], $dozwolone),
                array_map(static fn (array $o): int => $o['id'], $lista['osoby']),
            )),
            'zaznaczonych_w_liscie' => count(array_intersect(
                array_map(static fn (array $o): int => $o['id'], $lista['osoby']),
                $pokazane,
            )),
            'wybrane' => $pokazane,
            'szukaj' => $szukaj,
            // ⚠ **Trzy różne powody niepełnej listy = trzy różne zdania** (§4.4 zlecenia):
            // `ukryci` — własny filtr, `obciete` — limit wyświetlania, `zasieg_opis` — zasięg.
            // Jedno zdanie na trzy przypadki to ten sam błąd co „pusto z filtra i pusto z braku
            // danych wyglądają tak samo".
            'ukryci' => count($dozwolone) - count($wybrane),
            'obciete' => count($wybrane) - count($pokazane),
            'w_zasiegu' => count($dozwolone),
            'zasieg_opis' => self::opisZasiegu($widok, $zasieg, $spolka['nazwa'], $poziom),
            // ⚠ Nazwa spółki pracy, gdy kalendarz pokazuje spółkę **bez mojego etatu** (nadany
            // podgląd). Łata `kontekst_rozjazd` z tego samego dnia **została usunięta razem
            // z wariantem C** (zlecenie §4): pozycja spółki z etatem przełącza teraz kontekst,
            // więc rozjazd „patrzę na A, pracuję w B" nie jest już stanem, o którym trzeba
            // ostrzegać. Zostaje stan trwały: spółka do podglądu, w której nie pracuję.
            'kontekst_podglad' => $zatrudnienie !== null ? null : $this->spolkaPracy($user),
            // ⚠ **Dlaczego pokazujemy inny widok, niż poproszono** — albo `null`, gdy to ten sam.
            // Zmierzone 08.10.2026 na koncie pracownika bez podwładnych i bez nadanego poziomu:
            // `?widok=zespol`, `?widok=firma` **i wartość bzdurna** dawały identyczny ekran
            // i identyczną ciszę. Adres bywa stary (ktoś stracił podwładnych po zmianie
            // hierarchii) albo wklejony od kolegi — wtedy ekran pokazuje coś innego, niż
            // człowiek poprosił, i nie mówi o tym ani słowa.
            // ⚠ Rozstrzygnięcie jest TUTAJ, a nie w szablonie: szablon wybiera zdanie do kodu,
            // a nie liczy warunki (CLAUDE.md §5).
            'widok_odmowa' => self::powodOdmowyWidoku($widokZadany, $widok, $zatrudnienie !== null),
            'tytul' => self::TYTUL,
        ];
    }

    /**
     * Nagłówek ekranu — **jedna wartość dla `h1`, tytułu dokumentu i okruchów**, we wszystkich
     * trzech widokach ta sama.
     *
     * ⚠ Droga do tej jednej wartości jest ważniejsza niż ona sama. Najpierw było wpisane
     * „Mój kalendarz”, co nad widokiem całej spółki było **nieprawdą** tego samego rodzaju co
     * „przełożony: Ty”. Potem nazwa zależna od widoku („Kalendarz spółki”, „Kalendarz zespołu”)
     * — nieprawdę zdjęła, ale dołożyła **czwarte** miejsce mówiące o kontekście. Dziś kontekst
     * niosą: przełącznik widoku, przycisk wyboru spółki i nagłówek karty. Trzy miejsca
     * wystarczą, więc tytuł jest krótki (rozstrzygnięcie Krzysztofa z 06.10 wieczorem).
     *
     * ⚠ **Skutek uboczny, który domyka rzecz zgłoszoną wcześniej jako otwarta:** tytuł dokumentu
     * nie zawiera już niczego, co się zmienia — ani widoku, ani miesiąca — więc nie ma jak
     * zwietrzeć przy wymianie fragmentu. Problem znika, zamiast być obchodzony nagłówkiem.
     *
     * ⚠ Stała, a nie `match`: jeden tytuł to jedna wartość. Funkcja zostaje, bo zostaje
     * **jedno miejsce**, w którym ta nazwa jest zapisana.
     */
    private const TYTUL = 'Kalendarz';

    /**
     * Zdanie o tym, **co się właśnie widzi i skąd** (§3 zlecenia).
     *
     * ⚠ Nadanie zbyt szerokie ma być widoczne dla tego, kto je dostał — inaczej człowiek
     * z poziomem 2 nie ma skąd wiedzieć, że patrzy na nazwy rodzajów całej załogi.
     *
     * @param array{widok: string, dozwolone: list<int>, galaz: int, spolka: int} $zasieg
     */
    private static function opisZasiegu(string $widok, array $zasieg, string $spolka, int $poziom): ?string
    {
        if ($widok === KalendarzPracownika::WIDOK_FIRMA) {
            return 'Widzisz nieobecności całej firmy ' . $spolka . ' — nadane uprawnienie'
                . (CalendarVisibility::widziRodzaj($poziom) ? ', z nazwami rodzajów.' : ', bez nazw rodzajów.');
        }
        if ($widok === KalendarzPracownika::WIDOK_ZESPOL) {
            // ⚠ Zdanie o ZASIĘGU, nie o filtrze: „dalej nie sięgasz" ma inny powód niż
            // „filtr ukrył" i inny niż „pokazuję 30 z 53".
            $wSpolce = $zasieg['spolka'] > 0 ? ' z ' . $zasieg['spolka'] . ' w spółce ' . $spolka : '';

            return 'Widzisz swoją gałąź: ' . $zasieg['galaz'] . ' ' . Odmiana::osoby($zasieg['galaz']) . $wSpolce . '.';
        }

        return null;
    }

    /**
     * Sama kontrolka wyboru osób — fragment do szukania bez zwijania pudełka.
     *
     * ⚠ **Pełnoprawny endpoint** (CLAUDE.md §5): zasięg, poziom i granica spółki liczą się tu
     * dokładnie tak samo jak dla pełnej strony, bo idą przez ten sam `model()`. Nagłówek
     * `HX-Request` nie ma tu żadnego znaczenia — kontrolka jest fragmentem z definicji,
     * a bez JavaScriptu szukanie idzie na pełną stronę `/kalendarz`.
     *
     * @param array<string, string> $params
     */
    public function kontrolka(Request $request, array $params): Response
    {
        $user = $request->attribute(Authenticate::USER);
        assert($user instanceof CurrentUser);
        ['spolki' => $spolki, 'spolka' => $spolka, 'zatrudnienie' => $zatrudnienie]
            = $this->kontekstWidoku($request, $user);
        if ($spolka === null) {
            return $this->errors->errorPage(404, null, $request->isHtmx());
        }

        return $this->view->render(
            'fragments/calendar-picker.twig',
            $this->model($request, $user, $zatrudnienie, $spolka, $spolki,
                $request->queryString('widok', 8), $request->queryString('miesiac', 7),
                $request->queryString('skala', 8))
            + ['htmx' => true],
        );
    }

    /**
     * Zapis wyboru osób — `POST`, bo zmienia stan (CLAUDE.md §3), a potem przekierowanie.
     *
     * @param array<string, string> $params
     */
    public function osoby(Request $request, array $params): Response
    {
        $user = $request->attribute(Authenticate::USER);
        assert($user instanceof CurrentUser);
        ['spolki' => $spolki, 'spolka' => $spolka, 'zatrudnienie' => $zatrudnienie]
            = $this->kontekstWidoku($request, $user);
        if ($spolka === null) {
            return Response::redirect($this->urls->to('/kalendarz'));
        }
        // ⚠ Zasięg do zapisu liczy TA SAMA funkcja co zasięg widoku — inaczej wyłączenia
        // powstałyby na innym zbiorze, niż ten, który człowiek widział, klikając.
        $poziom = $this->widocznosc->poziomKonta($user->accountId(), $spolka['id']);
        $zasieg = $this->zasieg->dlaWidoku(
            $request->postString('widok', 8),
            $spolka['id'],
            $zatrudnienie === null ? null : (int) $zatrudnienie['id'],
            CalendarVisibility::widziSpolke($poziom),
        );
        $dozwolone = $zasieg['dozwolone'];
        // ⚠ **Zapisujemy stan BIEŻĄCY kontrolki — tą samą funkcją, którą kontrolka się renderuje.**
        // Jeden właściciel na jedną regułę: gdyby zapis liczył wyłączenia inaczej niż widok,
        // ekran pokazywałby jedno, a baza zapamiętywała drugie — i nikt by się nie dowiedział.
        // ⚠ Cudzy identyfikator nie ma jak wylądować w tabeli: wszystko przechodzi przez
        // `wZasiegu($dozwolone)` wewnątrz `wykluczeniaBiezace()`, a lista nie starzeje się
        // po cichu, bo zapisujemy WYŁĄCZENIA, nie włączenia (osoba przyjęta jutro jest widoczna).
        $this->filtr->zapisz(
            $user->accountId(),
            $spolka['id'],
            $this->wykluczeniaBiezace($request, $user->accountId(), $spolka['id'], $dozwolone),
        );

        $miesiac = KalendarzPracownika::miesiac($request->postString('miesiac', 7));

        // ⚠ Fragment po zapisie renderuje **aktualny stan z bazy**, a nie to, co przyszło
        // w żądaniu (CLAUDE.md §5): `model()` czyta zasięg i zapamiętane wyłączenia od nowa,
        // więc ekran nie pokaże sukcesu tam, gdzie zapis się nie udał.
        $widok = $zasieg['widok'];
        if ($request->isHtmx()) {
            return $this->view->render(
                'fragments/calendar.twig',
                $this->model($request, $user, $zatrudnienie, $spolka, $spolki, $widok,
                    $miesiac->format('Y-m'), $request->queryString('skala', 8)),
            );
        }

        return Response::redirect(
            $this->urls->to('/kalendarz') . '?widok=' . $widok . '&miesiac=' . $miesiac->format('Y-m')
        );
    }

    /**
     * **Wyłączenia obowiązujące W TEJ CHWILI** — zapisane w bazie albo niezapisane, przyniesione
     * w żądaniu. Jedna funkcja dla DWÓCH dróg: renderowania kontrolki po szukaniu i zapisu
     * przyciskiem „Pokaż".
     *
     * ⚠ **Powód jej istnienia jest zmierzony.** Dotąd szukanie w kontrolce renderowało listę
     * ze stanu ZAPISANEGO, więc odznaczenie pięciu osób i wpisanie czegokolwiek w szukajkę
     * kasowało te pięć decyzji: zmierzone „Zaznaczono 31 z 36" przed szukaniem i „9 z 9" po.
     * Człowiek nie dostawał żadnego sygnału — wyglądało to jak samoczynne zaznaczenie wszystkich.
     *
     * ⚠ **Stan niezapisany wraca do przeglądarki i przychodzi z powrotem** (`wyl[]` dla osób
     * poza pokazaną listą, pola wyboru dla pokazanych). Dzięki temu decyzja o kimś, kto wypadł
     * z wyniku szukania, **nie ginie** — a to jest ta sama reguła, którą pilnuje
     * `wykluczeniaPoZapisie()`: rusza się wyłącznie to, co człowiek widział.
     *
     * ⚠ Znacznika „zaznacz wszystko" **nie ma i nie trzeba**: „Zaznacz wszystkich" to w przeglądarce
     * zaznaczenie pokazanych **plus wyczyszczenie `wyl[]`**, czyli pusta podstawa. Przeglądarka
     * wyraża zamiar stanem, nie flagą — jedna rzecz mniej do rozjechania się.
     *
     * @param list<int> $dozwolone
     * @return list<int>
     */
    private function wykluczeniaBiezace(Request $request, int $accountId, int $companyId, array $dozwolone): array
    {
        // Brak znacznika stanu = żądanie nie przyszło z kontrolki (zwykłe wejście na ekran).
        if (self::zZadania($request, 'stan') === '1') {
            return ZasiegPodgladu::wykluczeniaPoZapisie(
                ZasiegPodgladu::wZasiegu(self::zListy(self::tablicaZZadania($request, 'wyl')), $dozwolone),
                ZasiegPodgladu::wZasiegu(self::zListy(self::tablicaZZadania($request, 'widoczne')), $dozwolone),
                ZasiegPodgladu::wZasiegu(self::zListy(self::tablicaZZadania($request, 'osoby')), $dozwolone),
            );
        }

        return $this->filtr->wykluczenia($accountId, $companyId);
    }

    /**
     * Pole kontrolki z żądania — **z ciała albo z adresu**, bo ta sama kontrolka wysyła się
     * dwiema metodami: `GET` przy szukaniu (odczyt) i `POST` przy zapisie (CLAUDE.md §3).
     * Zbiory są rozłączne, więc kolejność jest jednoznaczna.
     */
    private static function zZadania(Request $request, string $klucz): string
    {
        $zCiala = $request->postString($klucz, 16);

        return $zCiala !== '' ? $zCiala : $request->queryString($klucz, 16);
    }

    /** To samo dla pól wielokrotnych (`osoby[]`, `widoczne[]`, `wyl[]`). */
    private static function tablicaZZadania(Request $request, string $klucz): mixed
    {
        return $request->post($klucz) ?? $request->query($klucz);
    }

    /**
     * Kogo widać: jawne wskazanie w adresie, a bez niego cały zasięg minus zapamiętane wyłączenia.
     *
     * ⚠ Obie drogi kończą się przejściem przez zasięg — pierwsza przez `przefiltruj()` (bo
     * identyfikatory przyszły z zewnątrz), druga przez `bezWykluczonych()` (bo lista **startuje**
     * z zasięgu, a wyłączenia mogą tylko odejmować). Żadna nie potrafi niczego dodać.
     *
     * @param list<int> $dozwolone
     * @return list<int>
     */
    private function wybrane(Request $request, int $accountId, int $companyId, array $dozwolone): array
    {
        // ⚠ `?osoby=1,2,3` w ADRESIE to inna droga niż `osoby[]` z kontrolki: pierwsza jest jawnym
        // wskazaniem do wklejenia i zakładkowania, druga — zaznaczeniem na liście. Rozróżnia je
        // znacznik `stan`, który wysyła tylko kontrolka.
        $zAdresu = $request->queryString('osoby', self::OSOBY_PARAM_MAX);
        if ($zAdresu !== '' && self::zZadania($request, 'stan') !== '1') {
            return ZasiegPodgladu::przefiltruj(self::zListy($zAdresu), $dozwolone);
        }

        return ZasiegPodgladu::bezWykluczonych(
            $dozwolone,
            $this->wykluczeniaBiezace($request, $accountId, $companyId, $dozwolone),
        );
    }

    /**
     * Identyfikatory z wejścia: `1,2,3` z adresu albo tablica z formularza. Wyłącznie liczby
     * dodatnie, bez duplikatów, najwyżej `OSOB_MAX` (CLAUDE.md §11: biała lista i otypowanie).
     *
     * @return list<int>
     */
    private static function zListy(mixed $surowe): array
    {
        $czesci = is_string($surowe) ? explode(',', $surowe) : (is_array($surowe) ? $surowe : []);
        $ids = [];
        foreach ($czesci as $czesc) {
            if (!is_string($czesc) && !is_int($czesc)) {
                continue;
            }
            $id = filter_var($czesc, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id !== false && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
            if (count($ids) >= self::OSOB_MAX) {
                break;
            }
        }

        return $ids;
    }
}
