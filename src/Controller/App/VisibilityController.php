<?php

declare(strict_types=1);

namespace Wezurlop\Controller\App;

use Wezurlop\Entity\CurrentUser;
use Wezurlop\Http\Middleware\Authenticate;
use Wezurlop\Http\Request;
use Wezurlop\Http\Response;
use Wezurlop\Repository\CalendarAccessRepository;
use Wezurlop\Repository\GroupRepository;
use Wezurlop\Service\CalendarVisibility;
use Wezurlop\Service\KontekstSpolki;
use Wezurlop\Service\WidocznoscService;
use Wezurlop\Service\ZakresWidocznosci;
use Wezurlop\Support\ErrorHandler;
use Wezurlop\Support\Odmiana;
use Wezurlop\Support\UrlGenerator;
use Wezurlop\Support\View;

/**
 * `/admin/widocznosc` — **kto widzi czyje nieobecności** (zlecenie Z53 §4).
 *
 * ⚠ **Jeden ekran dla całej firmy, a nie kontrolka w panelu osoby.** Do 19.10.2026 wgląd
 * w kalendarze nadawało się trójstanowym przełącznikiem w `Konta › osoba › Uprawnienia`
 * i to był błąd kształtu: **„kto widzi kogo" jest RELACJĄ**, a panel jednej osoby pokazuje
 * tylko jej połowę. Z tamtego ekranu nie dawało się odpowiedzieć na pytanie „kto widzi
 * kalendarz Nowaka", bo odpowiedź leżała rozsypana po panelach wszystkich pozostałych osób.
 *
 * ⚠ **Kroki okna zmieniają się przez `GET`, zapis idzie `POST`-em** (CLAUDE.md §3). Przełączenie
 * zakładki, szukanie i doczytanie kolejnych dwudziestu pozycji **niczego nie zmieniają**, więc
 * nie mają prawa być `POST`-em; dzięki temu stan okna siedzi w adresie i działa bez JavaScriptu,
 * a htmx tylko wymienia treść okna zamiast przeładowywać stronę.
 *
 * ⚠ **Granica firmy sprawdza się przy KAŻDEJ akcji, po stronie serwera.** Ekran pokazuje
 * wyłącznie podmioty z firmy w belce, ale to nie ekran jest zabezpieczeniem — walidację robi
 * `WidocznoscService`, a identyfikator spoza firmy dostaje odmowę, nie cichy zapis (§4 zlecenia).
 */
final class VisibilityController
{
    /** Ile pozycji doczytuje jedno żądanie listy (§4 zlecenia: „po 20 przy przewijaniu"). */
    private const STRONA = 20;

    /** Najwyżej tyle grup w jednej regule — reguła, nie import całej firmy. */
    private const GRUP_MAX = 100;

    public function __construct(
        private readonly View $view,
        private readonly CalendarAccessRepository $reguly,
        private readonly WidocznoscService $service,
        private readonly CalendarVisibility $widocznosc,
        private readonly GroupRepository $grupy,
        private readonly \Wezurlop\Service\ZasiegPodgladu $zasieg,
        private readonly KontekstSpolki $kontekstSpolki,
        private readonly ErrorHandler $errors,
        private readonly UrlGenerator $urls,
    ) {
    }

    /** @param array<string, string> $params */
    public function index(Request $request, array $params): Response
    {
        $kontekst = $this->kontekst($request);
        if ($kontekst === null) {
            return $this->errors->errorPage(403, null, $request->isHtmx());
        }

        return $this->view->render('visibility.twig', $this->model($request, $kontekst));
    }

    /**
     * Treść okna jako fragment — po przełączeniu kroku, po szukaniu i po doczytaniu listy.
     *
     * ⚠ **Pełnoprawny endpoint** (CLAUDE.md §5): uprawnienie i granica firmy sprawdzają się tu
     * tak samo jak dla pełnej strony. „Używa go tylko htmx" nie istnieje.
     *
     * @param array<string, string> $params
     */
    public function okno(Request $request, array $params): Response
    {
        $kontekst = $this->kontekst($request);
        if ($kontekst === null) {
            return $this->errors->errorPage(403, null, $request->isHtmx());
        }

        return $this->view->render('fragments/visibility-window.twig',
            $this->model($request, $kontekst) + ['htmx' => true]);
    }

    /**
     * **Kolejne dwadzieścia pozycji listy** — dopisywane do tej, która już stoi w oknie.
     *
     * ⚠ Zwraca SAME `<li>`, a nie całe okno: podmiana okna wymazałaby zaznaczenie wraz
     * z pozycją przewinięcia, czyli dokładnie to, czego doczytywanie ma nie robić. Licznik
     * w stopce jedzie obok, poza kolejnością (`hx-swap-oob`), bo to jedyna druga rzecz,
     * która się przy tym zmienia.
     *
     * ⚠ **Pełnoprawny endpoint** (CLAUDE.md §5): uprawnienie i granica firmy sprawdzają się
     * tu tak samo jak dla pełnej strony.
     *
     * @param array<string, string> $params
     */
    public function pozycje(Request $request, array $params): Response
    {
        $kontekst = $this->kontekst($request);
        if ($kontekst === null) {
            return $this->errors->errorPage(403, null, $request->isHtmx());
        }
        $okno = $this->stanOkna($request, $kontekst['spolka']['id']);

        return $this->view->render('fragments/visibility-pozycje.twig', [
            'okno' => $okno,
            'spolka' => $kontekst['spolka'],
            'hx' => true,
            'krok' => $okno['krok'],
            'wybrane' => $okno['krok'] === 2 ? $okno['grupy'] : [$okno['kto_id']],
            'wielokrotny' => $okno['krok'] === 2,
            'licznik_oob' => true,
        ]);
    }

    /** @param array<string, string> $params */
    public function zapisz(Request $request, array $params): Response
    {
        $kontekst = $this->kontekst($request);
        if ($kontekst === null) {
            return $this->errors->errorPage(403, null, $request->isHtmx());
        }
        $stan = $this->stanOkna($request, $kontekst['spolka']['id']);
        $powod = $this->service->zapisz(
            $stan['regula'] > 0 ? $stan['regula'] : null,
            $kontekst['spolka']['id'],
            $stan['kto'],
            $stan['kto_id'],
            $stan['czyje'],
            $stan['grupy'],
            $stan['zakres'],
            $kontekst['konto'],
            $kontekst['aktor'],
        );

        return $this->poZapisie($request, $kontekst, $powod, $stan['regula'] > 0 ? 'zmieniony' : 'nadany');
    }

    /** @param array<string, string> $params */
    public function usun(Request $request, array $params): Response
    {
        $kontekst = $this->kontekst($request);
        if ($kontekst === null) {
            return $this->errors->errorPage(403, null, $request->isHtmx());
        }
        $powod = $this->service->usun(
            (int) ($params['id'] ?? 0),
            $kontekst['spolka']['id'],
            $kontekst['konto'],
            $kontekst['aktor'],
        );

        return $this->poZapisie($request, $kontekst, $powod, 'odebrany');
    }

    /**
     * Po zapisie — **przekierowanie z wynikiem w adresie** (POST/Redirect/GET, CLAUDE.md §3).
     *
     * ⚠ Komunikat jedzie parametrem, a nie renderowaniem strony wprost z `POST`-a: inaczej
     * odświeżenie powtarzałoby nadanie dostępu.
     *
     * @param array<string, mixed> $kontekst
     */
    private function poZapisie(Request $request, array $kontekst, ?string $powod, string $wynik): Response
    {
        $adres = $this->urls->to('/admin/widocznosc') . '?spolka=' . $kontekst['spolka']['id']
            . ($powod === null ? '&wynik=' . $wynik : '&odmowa=' . $powod);

        return Response::redirectFor($request, $adres);
    }

    /**
     * Model ekranu: kafle, wiersze reguł i stan okna.
     *
     * @param array<string, mixed> $kontekst
     * @return array<string, mixed>
     */
    private function model(Request $request, array $kontekst): array
    {
        $companyId = $kontekst['spolka']['id'];
        $reguly = $this->reguly->reguly($companyId);
        $nazwy = $this->nazwy($companyId);
        // ⚠ Nazwiska osób celu **jednym zapytaniem na cały ekran**, nie jednym na regułę:
        // dwadzieścia reguł po trzy osoby to dwadzieścia zapytań, czyli ta sama choroba,
        // co licznik liczony w pętli.
        $osobyCelu = $this->reguly->osobyRegul($companyId, array_merge([], ...array_map(
            static fn (array $r): array => $r['target_kind'] === ZakresWidocznosci::CZYJE_OSOBY
                ? $r['grupy']
                : [],
            $reguly,
        )));
        $wiersze = [];
        $zeSzczegolami = 0;
        $podmioty = [];
        foreach ($reguly as $r) {
            if (CalendarAccessRepository::stala((string) $r['subject_kind'])) {
                continue;                       // oba wiersze stałe renderują się osobno
            }
            $wiersze[] = $this->wiersz($r, $nazwy, $osobyCelu);
            $zeSzczegolami += $r['scope'] === ZakresWidocznosci::ZE_SZCZEGOLAMI ? 1 : 0;
            $podmioty[$r['subject_kind'] . ':' . self::podmiotId($r)] = true;
        }
        $zakresDomyslny = $this->widocznosc->zakresPrzelozonych($companyId);
        $przelozonych = $this->reguly->ilePrzelozonychRazem($companyId);
        $administratorow = $this->reguly->ileAdministratorow($companyId);

        return [
            'spolka' => $kontekst['spolka'],
            'spolki' => $kontekst['spolki'],
            'spolka_z_adresu' => $kontekst['z_adresu'],
            'spolka_z_zasobu' => false,
            'spolka_fallback' => $kontekst['fallback'],
            'reguly' => $wiersze,
            'zakres_domyslny' => $zakresDomyslny,
            'domyslna_id' => self::idStalej($reguly, ZakresWidocznosci::KTO_PRZELOZENI),
            // ⚠ Druga reguła stała: „Administratorzy › Cała firma" (zlecenie Z55). Wiersz stoi
            // pod przełożonymi i ma ołówek bez kosza — tak samo i z tego samego powodu.
            'administratorow_id' => self::idStalej($reguly, ZakresWidocznosci::KTO_ADMINI),
            'zakres_administratorow' => $this->widocznosc->zakresAdministratorow($companyId),
            'administratorow_opis' => $administratorow . ' ' . Odmiana::kont($administratorow)
                . ' z prawem zarządzania widocznością · reguła domyślna firmy',
            'przelozonych' => $przelozonych,
            'przelozonych_opis' => $przelozonych . ' ' . Odmiana::osoby($przelozonych) . ' z podwładnymi',
            'nazwy_zakresow' => ZakresWidocznosci::nazwy(),
            'opisy_zakresow' => ZakresWidocznosci::opisy(),
            // ⚠ **Trzy formy słowa „osoba" dla stopki listy** (zlecenie Z57 U4). Słownik odmian
            // zostaje w `Support\Odmiana` — w przeglądarce zostaje sam wybór formy po liczbie.
            // Przepisanie słów do JavaScriptu znaczyłoby drugie miejsce, w którym mieszka
            // polska odmiana, i rozjazd przy pierwszej zmianie brzmienia.
            'formy_osoby' => implode('|', [Odmiana::osoby(1), Odmiana::osoby(2), Odmiana::osoby(5)]),
            'kafle' => [
                'dodatkowych' => count($wiersze),
                'osob' => count($podmioty),
                'ze_szczegolami' => $zeSzczegolami,
            ],
            'okno' => $this->stanOkna($request, $companyId),
            'okno_otwarte' => $request->queryString('okno', 2) === '1',
            'notice' => self::KOMUNIKATY[$request->queryString('wynik', 16)] ?? null,
            'error' => self::ODMOWY[$request->queryString('odmowa', 16)] ?? null,
        ];
    }

    /**
     * Stan okna z żądania — **wszystko przez białe listy** (CLAUDE.md §11).
     *
     * ⚠ Wartość spoza listy nie jest błędem do pokazania, tylko powrotem do stanu
     * początkowego: adres bywa stary albo wklejony, a okno ma się otworzyć, nie wywrócić.
     *
     * @return array<string, mixed>
     */
    private function stanOkna(Request $request, int $companyId): array
    {
        $wartosc = static fn (string $pole, int $limit): string => $request->method() === 'POST'
            ? $request->postString($pole, $limit)
            : $request->queryString($pole, $limit);
        $regula = (int) $wartosc('regula', 12);
        // ⚠ **Wybór z listy przychodzi JEDNYM polem** `rodzaj:id`, a nie dwoma. Dwa pola
        // znaczyłyby stan, w którym rodzaj mówi „grupa", a identyfikator wskazuje konto —
        // i trzeba by pilnować ich zgodności w trzech miejscach. Jedno pole nie da się rozjechać.
        $wybor = explode(':', $wartosc('wybor', 32), 2);
        $kto = $wybor[0] !== '' ? $wybor[0] : $wartosc('kto', 16);
        $ktoId = count($wybor) === 2 ? (int) $wybor[1] : (int) $wartosc('kto_id', 12);
        $kto = in_array($kto, [ZakresWidocznosci::KTO_PRZELOZENI, ZakresWidocznosci::KTO_ADMINI,
            ZakresWidocznosci::KTO_PRZELOZONY, ZakresWidocznosci::KTO_KONTO,
            ZakresWidocznosci::KTO_GRUPA], true)
            ? $kto
            : ZakresWidocznosci::KTO_PRZELOZENI;
        // ⚠ **Domyślny cel zależy od tego, KOGO wskazano** (decyzja Krzysztofa 19.10.2026).
        // Dla osoby i grupy to „cała firma", bo tak nadaje się dostęp najczęściej; dla przełożonego
        // — „podwładni", bo po to się go wskazuje. Domyślność to NIE to samo, co wymuszenie:
        // wybrany jawnie cel wygrywa (poza „Wszystkimi przełożonymi", którzy innego mieć nie mogą).
        $czyjeZadany = $wartosc('czyje', 16);
        $czyje = in_array($czyjeZadany, [ZakresWidocznosci::CZYJE_PODWLADNI, ZakresWidocznosci::CZYJE_FIRMA,
            ZakresWidocznosci::CZYJE_GRUPY, ZakresWidocznosci::CZYJE_OSOBY], true)
            ? $czyjeZadany
            : ($kto === ZakresWidocznosci::KTO_PRZELOZONY || $kto === ZakresWidocznosci::KTO_PRZELOZENI
                ? ZakresWidocznosci::CZYJE_PODWLADNI
                : ZakresWidocznosci::CZYJE_FIRMA);
        // ⚠ **Wskazanie przełożonego Z LISTY ustawia „Podwładnych"** — ale tylko w chwili
        // wskazania (gdy w żądaniu jest `wybor`). Późniejsze kliknięcie „Cała firma" w kroku 2
        // przychodzi bez `wybor` i zostaje uszanowane; inaczej tej pozycji nie dałoby się wybrać.
        if (count($wybor) === 2 && $wybor[0] === ZakresWidocznosci::KTO_PRZELOZONY) {
            $czyje = ZakresWidocznosci::CZYJE_PODWLADNI;
        }
        // ⚠ Obie reguły stałe mają dokładnie jeden możliwy cel — i rozstrzyga o tym serwer,
        // a nie wyszarzenie pozycji w oknie: „Wszyscy przełożeni" swoich podwładnych,
        // „Administratorzy" całą firmę.
        if ($kto === ZakresWidocznosci::KTO_PRZELOZENI) {
            $czyje = ZakresWidocznosci::CZYJE_PODWLADNI;
        } elseif ($kto === ZakresWidocznosci::KTO_ADMINI) {
            $czyje = ZakresWidocznosci::CZYJE_FIRMA;
        } elseif ($czyje === ZakresWidocznosci::CZYJE_PODWLADNI
            && $kto === ZakresWidocznosci::KTO_KONTO && $ktoId > 0
            && !$this->maEtat($companyId, $ktoId)) {
            // ⚠ Konto bez etatu w tej firmie nie ma gałęzi — krok 2 ustawia się na „całą firmę"
            // (decyzja Krzysztofa 19.10.2026). Serwis i tak odrzuciłby taki zapis; tutaj
            // chodzi o to, żeby okno nie pokazywało wyboru, który zawsze kończy się odmową.
            $czyje = ZakresWidocznosci::CZYJE_FIRMA;
        }
        // ⚠ **Jedna lista celu na wszystkie rodzaje** — grupy i osoby jadą tym samym polem,
        // bo reguła ma dokładnie JEDEN rodzaj celu (`target_kind`), więc dwie listy naraz nie
        // istnieją. Rodzaju nie zgaduje się z zawartości: mówi go `czyje`.
        $cel = array_slice($this->identyfikatory($request, 'grupy'), 0, self::GRUP_MAX);
        // ⚠ **W zakładce „Osoby" o celu rozstrzyga ZAZNACZENIE, a nie zakładka.** „Cała firma"
        // jest pierwszą pozycją tej samej listy, co ludzie (dopisek z 19.10.2026) — nie ma więc
        // osobnego przełącznika, który mógłby mówić co innego niż to, co widać w ramce.
        // ⚠ **Gdy w żądaniu jest i „cała firma", i wskazane osoby — wygrywa „cała firma".**
        // Tak zdarza się bez JavaScriptu (pola wykluczają się wtedy tylko wzrokowo) i przy
        // żądaniu z ręki. Wygrywa zbiór SZERSZY, bo on i tak obejmuje tamte osoby, więc wynik
        // jest przewidywalny; odwrotne rozstrzygnięcie dawałoby dostęp węższy niż zaznaczony
        // i nikt by nie wiedział, dlaczego. Przeglądarka tylko to pokazuje (`modules/widocznosc.js`).
        $calaFirma = $wartosc('cala_firma', 2) === '1';
        if ($czyje === ZakresWidocznosci::CZYJE_FIRMA || $czyje === ZakresWidocznosci::CZYJE_OSOBY) {
            $czyje = $calaFirma || $cel === []
                ? ZakresWidocznosci::CZYJE_FIRMA
                : ZakresWidocznosci::CZYJE_OSOBY;
        }
        $krok = (int) $wartosc('krok', 2);
        $krok = $krok >= 1 && $krok <= 3 ? $krok : 1;
        // ⚠ **Reguła stała otwiera się WYŁĄCZNIE na kroku zakresu** (zlecenia Z78 i Z79):
        // ani podmiotu, ani celu nie da się w niej wskazać, więc kroki 1 i 2 nie mają czego
        // pokazać. Rozstrzyga to SERWER, a nie wyszarzenie kafelków: `krok=1` dopisany do adresu
        // z ręki pokazywałby listę przełożonych i pasek „Kto widzi" dla reguły, w której te pola
        // są stałe — czyli wybór, który i tak zostanie odrzucony przy zapisie (`WidocznoscService`).
        if (CalendarAccessRepository::stala($kto)) {
            $krok = 3;
        }
        // ⚠ **Jedna liczba osób celu, nie dwie.** „Cała firma · dziś N osób" i „pokazano 20 z N"
        // muszą mówić o TYM SAMYM zbiorze, więc obie biorą go z jednego zapytania — razem
        // z pominięciem podmiotu z kroku 1. Zmierzone przed poprawką: pozycja mówiła „36 osób",
        // a licznik pod listą „20 z 35", bo jedna liczba brała całą załogę, a druga listę bez
        // podmiotu. Różnica była prawdziwa i właśnie dlatego nie wolno jej pokazywać dwiema
        // liczbami bez wyjaśnienia: to ta sama reguła, co „dwa liczniki jednej rzeczy".
        $pomin = $this->pominOsoby($companyId, $kto, $ktoId);
        $osobCelu = $krok === 2 ? $this->reguly->ileOsobFirmy($companyId, '', $pomin) : 0;

        return [
            'regula' => max(0, $regula),
            // ⚠ **Zmiana, a nie nowy dostęp — także wtedy, gdy wiersza w bazie jeszcze NIE MA.**
            // „Każdy przełożony" stoi na liście reguł od pierwszego wejścia na ekran, bo zakres
            // domyślny obowiązuje bez zapisu; dopóki nikt go nie ruszył, reguły w tabeli nie ma
            // i ołówek niósłby `regula=0`, czyli tytuł „Nowy dostęp do kalendarza" i przycisk
            // „Nadaj dostęp" (zgłoszone 20.10.2026). To ta sama reguła, którą człowiek widzi
            // na liście — więc okno ma mówić „zmień", a decyduje o tym PODMIOT, nie to, czy
            // zapis już kiedyś nastąpił. Pierwszy zapis ją utworzy; dla człowieka to nadal
            // zmiana jednej, istniejącej pozycji, bo drugiej takiej mieć nie można
            // (sygnatura `supervisor_all|subordinates` jest jedna na firmę).
            'zmiana' => $regula > 0 || CalendarAccessRepository::stala($kto),
            // Reguła stała: w oknie zostaje wyłącznie krok zakresu, bo podmiotu ani celu
            // nie da się w niej wskazać.
            'stala' => CalendarAccessRepository::stala($kto),
            'krok' => $krok,
            'kto' => $kto,
            'kto_id' => CalendarAccessRepository::stala($kto) ? 0 : max(0, $ktoId),
            // Czy wskazane konto ma etat w tej firmie — od tego zależy, czy wolno mu dać
            // „Podwładnych" (kroku 2 nie wolno zostawić ekranowi do rozstrzygnięcia).
            'kto_bez_etatu' => $kto === ZakresWidocznosci::KTO_KONTO && $ktoId > 0
                && !$this->maEtat($companyId, $ktoId),
            'czyje' => $czyje,
            'grupy' => $cel,
            'zakres' => ZakresWidocznosci::zakres($wartosc('zakres', 20)),
            'szukaj' => trim($wartosc('szukaj', 100)),
            // ⚠ **Offset, nie rosnący limit.** Doczytywanie dopisuje kolejne dwadzieścia pozycji
            // do listy, która już stoi na ekranie — gdyby każde doczytanie pobierało całość
            // od początku, trzydziesta strona kosztowałaby sześćset wierszy i wymazałaby
            // zaznaczenie razem z pozycją przewinięcia.
            'od' => max(0, (int) $wartosc('od', 6)),
            // ⚠ Zachęta w polu szukania nazywa to, czego się szuka — jedno pole na trzy listy
            // z jednym napisem „szukaj…" kazałoby się domyślać, którą właśnie widać.
            'zacheta' => match (true) {
                $krok === 2 => $czyje === ZakresWidocznosci::CZYJE_GRUPY ? 'szukaj grupy…' : 'szukaj osoby…',
                $kto === ZakresWidocznosci::KTO_GRUPA => 'szukaj grupy…',
                $kto === ZakresWidocznosci::KTO_KONTO => 'szukaj osoby…',
                default => 'szukaj przełożonego…',
            },
            'listy' => $this->listy($companyId, $krok, $kto, $czyje, $pomin, $wartosc('szukaj', 100),
                max(0, (int) $wartosc('od', 6))),
            // ⚠ Stopka listy mówi **co wybrano**, a nie tylko ile pokazano: przy liście doczytywanej
            // partiami wybrana pozycja bywa wyżej, poza polem widzenia, i bez tego napisu znika
            // z ekranu razem z pewnością, że w ogóle coś wybrano.
            'wybrano' => $this->wybrano($companyId, $krok, $czyje, $kto, $ktoId, $cel, $osobCelu),
            // ⚠ Liczby do kroku 2 liczą się **w chwili odczytu**, tak samo jak sam dostęp —
            // zdanie „dziś N osób" ma mówić prawdę o dzisiaj, a nie o chwili zapisu reguły.
            'podwladnych' => $krok === 2 && $kto !== ZakresWidocznosci::KTO_PRZELOZENI
                ? $this->ilePodwladnych($companyId, $kto, $ktoId)
                : 0,
            'pracownikow' => $osobCelu,
            // ⚠ Nazwa podmiotu z kroku 1 jest potrzebna w kroku 2 („Podwładni: X") — i jest
            // **osobna od `wybrano`**, bo tamto opisuje wybór BIEŻĄCEGO kroku i w kroku 2 mówi
            // o grupach celu. Jedno pole na dwa znaczenia rozjeżdża się przy pierwszym użyciu.
            'kto_nazwa' => $this->nazwaPodmiotu($companyId, $kto, $ktoId),
        ];
    }

    /**
     * Listy do kroków 1 i 2 — **tylko ta, którą widać**: zapytanie o pozostałe byłoby odczytem,
     * z którego nikt nie skorzysta.
     *
     * ⚠ **Numer kroku przychodzi PARAMETREM, a nie z żądania drugi raz** (poprawka z Z79).
     * Wcześniej ta metoda czytała `krok` sama, więc po wymuszeniu kroku zakresu dla reguły stałej
     * w `stanOkna()` miałaby o nim inne zdanie niż okno, które renderuje: `krok=1` w adresie dawałby
     * zapytanie o listę przełożonych do okna, w którym żadnej listy nie ma. Jedna wielkość,
     * jedno miejsce odczytu.
     *
     * @return array<string, mixed>
     */
    private function listy(int $companyId, int $krok, string $kto, string $czyje, array $pomin, string $szukaj, int $od): array
    {
        $szukaj = trim($szukaj);
        if ($krok === 3) {
            // Krok zakresu nie ma listy — odczyt byleby był to odczyt, z którego nikt nie skorzysta.
            return ['pozycje' => [], 'razem' => 0, 'rodzaj' => 'brak', 'od' => 0];
        }
        if ($krok === 2) {
            if ($czyje === ZakresWidocznosci::CZYJE_GRUPY) {
                return $this->listaGrup($companyId, $szukaj, $od);
            }
            // ⚠ **Zakładka „Osoby" pokazuje tę samą listę przy „Całej firmie" i przy wskazanych
            // osobach** — bo „Cała firma" jest jej pierwszą pozycją, a nie osobnym ekranem.
            // Gdyby lista znikała po zaznaczeniu „Całej firmy", wybór „jednak tylko tych trzech"
            // wymagałby cofnięcia się zakładką, czyli kroku wstecz po własnym kliknięciu.
            if ($czyje === ZakresWidocznosci::CZYJE_FIRMA || $czyje === ZakresWidocznosci::CZYJE_OSOBY) {
                return [
                    'pozycje' => $this->reguly->osobyFirmy($companyId, $szukaj, self::STRONA, $od, $pomin),
                    'razem' => $this->reguly->ileOsobFirmy($companyId, $szukaj, $pomin),
                    'rodzaj' => 'osoba',
                    'od' => $od,
                ];
            }

            return ['pozycje' => [], 'razem' => 0, 'rodzaj' => 'brak', 'od' => 0];
        }

        // ⚠ Zakładka „Przełożony" pokazuje TĘ SAMĄ listę niezależnie od tego, czy wybrano
        // „Wszystkich przełożonych", czy jeszcze nikogo: inaczej po wejściu w zakładkę lista
        // byłaby pusta i wyglądałaby na zepsutą.
        return match ($kto) {
            ZakresWidocznosci::KTO_PRZELOZENI, ZakresWidocznosci::KTO_PRZELOZONY => [
                'pozycje' => $this->opiszPrzelozonych(
                    $companyId,
                    $this->reguly->przelozeni($companyId, $szukaj, self::STRONA, $od),
                ),
                'razem' => $this->reguly->ilePrzelozonych($companyId, $szukaj),
                'rodzaj' => 'przelozony',
                'od' => $od,
            ],
            ZakresWidocznosci::KTO_GRUPA => $this->listaGrup($companyId, $szukaj, $od),
            ZakresWidocznosci::KTO_KONTO => [
                'pozycje' => array_map(
                    static fn (array $k): array => ['id' => $k['id'], 'nazwa' => $k['osoba'],
                        'opis' => self::skadDostep($k['zrodlo']), 'etat' => $k['etat']],
                    $this->reguly->kandydaciKonta($companyId, $szukaj, self::STRONA, $od),
                ),
                'razem' => $this->reguly->ileKandydatow($companyId, $szukaj),
                'rodzaj' => 'konto',
                'od' => $od,
            ],
            default => ['pozycje' => [], 'razem' => 0, 'rodzaj' => 'brak', 'od' => 0],
        };
    }

    /**
     * Zatrudnienia podmiotu z kroku 1 — **nie pokazujemy ich w kroku 2**.
     *
     * ⚠ Dostępu do samego siebie się nie nadaje: własne nieobecności każdy widzi w pełni
     * niezależnie od reguł, więc taka pozycja na liście obiecywałaby zmianę, której nie ma.
     * Przy `KTO = Osoba` ten sam zbiór odrzuca też `WidocznoscService` przy zapisie — tutaj
     * chodzi o to, żeby nie dało się tego nawet wskazać (ekran nie jest zabezpieczeniem).
     *
     * ⚠ **Przy `KTO = Grupa` nie pomijamy nikogo** (decyzja Krzysztofa 19.10.2026): widzów jest
     * wtedy kilkunastu i wyrzucenie ich z celu zabierałoby dostęp do kogoś, kto przypadkiem
     * wszedł do tej samej grupy. Każdy z nich i tak nie zobaczy siebie przez tę regułę,
     * bo własne dane widzi bez niej.
     *
     * @return list<int>
     */
    private function pominOsoby(int $companyId, string $kto, int $ktoId): array
    {
        if ($ktoId === 0) {
            return [];
        }

        return match ($kto) {
            ZakresWidocznosci::KTO_KONTO => $this->reguly->etatyKonta($companyId, $ktoId),
            ZakresWidocznosci::KTO_PRZELOZONY => [$ktoId],
            default => [],
        };
    }

    /**
     * Druga linia wiersza konta — **skąd to konto ma dostęp do tej firmy** (zlecenie Z55).
     *
     * ⚠ Mówi o DOSTĘPIE, nie o zatrudnieniu, bo to on rozstrzyga, czy regułę wolno zapisać.
     * Konto z przypisaniem na cały tenant nie ma etatu w żadnej firmie, a mimo to jest
     * prawidłowym podmiotem reguły — i administrator ma to widzieć od razu, zamiast dowiadywać
     * się z odmowy zapisu.
     */
    private static function skadDostep(string $zrodlo): string
    {
        return match ($zrodlo) {
            'tenant' => 'dostęp do wszystkich firm · bez etatu',
            'dostep' => 'bez etatu w tej firmie',
            default => '',
        };
    }

    /** Nazwa podmiotu reguły do zdań w oknie — pusta, gdy jeszcze nic nie wskazano. */
    private function nazwaPodmiotu(int $companyId, string $kto, int $ktoId): string
    {
        if ($kto === ZakresWidocznosci::KTO_PRZELOZENI) {
            return 'Wszyscy przełożeni';
        }
        if ($ktoId === 0) {
            return '';
        }
        $nazwy = $this->nazwy($companyId);

        return match ($kto) {
            ZakresWidocznosci::KTO_PRZELOZONY => $nazwy['przelozony'][$ktoId]['nazwa'] ?? '',
            ZakresWidocznosci::KTO_GRUPA => $nazwy['grupa'][$ktoId]['nazwa'] ?? '',
            default => $nazwy['konto'][$ktoId]['nazwa'] ?? '',
        };
    }

    /**
     * Ilu podwładnych ma dziś wskazany podmiot — do zdania w kroku 2.
     *
     * ⚠ Przy grupie liczymy gałęzie **wszystkich jej członków** bez powtórzeń: grupa nie jest
     * węzłem hierarchii (§8a: etykieta, nie warstwa zatwierdzania), więc „podwładni grupy"
     * znaczy „podwładni każdego z osobna".
     */
    private function ilePodwladnych(int $companyId, string $kto, int $ktoId): int
    {
        if ($ktoId === 0) {
            return 0;
        }
        $etaty = match ($kto) {
            ZakresWidocznosci::KTO_PRZELOZONY => [$ktoId],
            ZakresWidocznosci::KTO_GRUPA => $this->reguly->zatrudnieniaGrup($companyId, [$ktoId]),
            default => [],
        };
        $widziani = [];
        foreach ($etaty as $etat) {
            foreach ($this->zasieg->dozwolone($companyId, $etat) as $id) {
                $widziani[$id] = true;
            }
        }
        // Własne wiersze podmiotów nie są podwładnymi — gałąź zawiera korzeń.
        foreach ($etaty as $etat) {
            unset($widziani[$etat]);
        }

        return count($widziani);
    }

    /**
     * Napis „wybrano: …" do stopki listy — **składany na serwerze**, bo to ta sama nazwa,
     * która stoi w wierszu, i nie ma powodu, żeby powstawała drugi raz w przeglądarce.
     *
     * @param list<int> $grupy
     */
    private function wybrano(int $companyId, int $krok, string $czyje, string $kto, int $ktoId, array $grupy, int $osobCelu): string
    {
        if ($krok === 2) {
            // ⚠ „Cała firma" mówi **ile osób obejmuje dzisiaj** — tą samą liczbą, co pozycja
            // listy i licznik pod nią. Liczba przychodzi z zewnątrz, bo powstaje raz.
            if ($czyje === ZakresWidocznosci::CZYJE_FIRMA) {
                return 'wybrano: cała firma · ' . $osobCelu . ' ' . Odmiana::osoby($osobCelu);
            }
            if ($czyje === ZakresWidocznosci::CZYJE_OSOBY) {
                return 'wybrano ' . count($grupy) . ' ' . Odmiana::osoby(count($grupy));
            }
            if ($grupy === []) {
                return '';
            }
            $osob = 0;
            foreach ($this->grupy->lista($companyId) as $g) {
                if (in_array((int) $g['id'], $grupy, true)) {
                    $osob += (int) $g['osob'];
                }
            }

            return 'wybrano ' . count($grupy) . ' · ' . $osob . ' ' . Odmiana::osoby($osob);
        }
        if ($kto === ZakresWidocznosci::KTO_PRZELOZENI) {
            return 'wybrano: Wszyscy przełożeni';
        }
        if ($ktoId === 0) {
            return '';
        }
        $nazwy = $this->nazwy($companyId);
        $nazwa = match ($kto) {
            ZakresWidocznosci::KTO_PRZELOZONY => $nazwy['przelozony'][$ktoId]['nazwa'] ?? null,
            ZakresWidocznosci::KTO_GRUPA => $nazwy['grupa'][$ktoId]['nazwa'] ?? null,
            default => $nazwy['konto'][$ktoId]['nazwa'] ?? null,
        };

        return $nazwa === null ? '' : 'wybrano: ' . $nazwa;
    }

    /**
     * Czy to konto ma czynny etat w tej firmie — **wyłącznie do wyszarzenia pozycji w oknie**.
     * O tym, czy regułę wolno zapisać, rozstrzyga `WidocznoscService`, przy zapisie.
     */
    private function maEtat(int $companyId, int $accountId): bool
    {
        foreach ($this->reguly->kandydaciKonta($companyId, '', 1000, 0) as $k) {
            if ($k['id'] === $accountId) {
                return $k['etat'];
            }
        }

        return false;
    }

    /**
     * Wiersze listy przełożonych — **z liczbą podwładnych z CAŁEJ GAŁĘZI**.
     *
     * ⚠ Liczbę liczy `ZasiegPodgladu`, czyli ta sama funkcja, która rozstrzyga o dostępie.
     * Zapytanie z repozytorium podaje bezpośrednich podwładnych i to właśnie od tego rozjeżdżały
     * się kroki 1 i 2 ekranu: „4 podwładnych" obok „dziś 6 osób" u tej samej osoby.
     *
     * ⚠ Druga linia łączy liczbę i grupy środkową kropką — składa ją serwer, bo szablon ma
     * wyświetlać, a nie zszywać napisów (CLAUDE.md §5).
     *
     * @param list<array{id: int, osoba: string, podwladnych: int, grupy: list<string>}> $wiersze
     * @return list<array<string, mixed>>
     */
    private function opiszPrzelozonych(int $companyId, array $wiersze): array
    {
        $galezie = $this->zasieg->podwladnych(
            $companyId,
            array_map(static fn (array $p): int => $p['id'], $wiersze),
        );

        return array_map(static function (array $p) use ($galezie): array {
            $ile = $galezie[$p['id']] ?? 0;

            return [
                'id' => $p['id'],
                'nazwa' => $p['osoba'],
                'opis' => implode(' · ', array_merge(
                    [$ile . ' ' . Odmiana::podwladnych($ile)],
                    $p['grupy'],
                )),
                'etat' => true,
            ];
        }, $wiersze);
    }

    /** @return array<string, mixed> */
    private function listaGrup(int $companyId, string $szukaj, int $od): array
    {
        $wszystkie = $this->grupy->lista($companyId);
        $pasujace = $szukaj === ''
            ? $wszystkie
            : array_values(array_filter(
                $wszystkie,
                static fn (array $g): bool => mb_stripos((string) $g['nazwa'], $szukaj) !== false,
            ));

        return [
            'pozycje' => array_map(
                // ⚠ `osob` jedzie OBOK opisu, a nie zamiast niego: stopka listy w przeglądarce
                // (`modules/widocznosc.js`, zlecenie Z57 U4) dodaje liczby wybranych grup i osób,
                // a wyłuskiwanie ich z napisu „12 osób" byłoby parsowaniem własnego tekstu.
                static fn (array $g): array => ['id' => (int) $g['id'], 'nazwa' => (string) $g['nazwa'],
                    'opis' => $g['osob'] . ' ' . Odmiana::osoby((int) $g['osob']), 'etat' => true,
                    'osob' => (int) $g['osob']],
                array_slice($pasujace, $od, self::STRONA),
            ),
            'razem' => count($pasujace),
            'rodzaj' => 'grupa',
            'od' => $od,
        ];
    }

    /**
     * Nazwy podmiotów do wierszy listy — **trzy zapytania na ekran**, nie trzy na wiersz.
     *
     * @return array<string, array<int, array{nazwa: string, opis: string}>>
     */
    private function nazwy(int $companyId): array
    {
        $konta = [];
        foreach ($this->reguly->kandydaciKonta($companyId, '', 1000, 0) as $k) {
            $konta[$k['id']] = ['nazwa' => $k['osoba'], 'opis' => self::skadDostep($k['zrodlo'])];
        }
        $przelozeni = [];
        foreach ($this->reguly->przelozeni($companyId, '', 1000, 0) as $p) {
            $przelozeni[$p['id']] = ['nazwa' => $p['osoba'],
                'opis' => $p['podwladnych'] . ' ' . Odmiana::podwladnych($p['podwladnych'])];
        }
        $grupy = [];
        foreach ($this->grupy->lista($companyId) as $g) {
            $grupy[(int) $g['id']] = ['nazwa' => (string) $g['nazwa'],
                'opis' => $g['osob'] . ' ' . Odmiana::osoby((int) $g['osob'])];
        }

        return ['konto' => $konta, 'przelozony' => $przelozeni, 'grupa' => $grupy];
    }

    /**
     * Jeden wiersz listy reguł — **gotowy do wyświetlenia**, bez rachunków w szablonie
     * (CLAUDE.md §5).
     *
     * @param array<string, mixed> $regula
     * @param array<string, array<int, array{nazwa: string, opis: string}>> $nazwy
     * @return array<string, mixed>
     */
    private function wiersz(array $regula, array $nazwy, array $osobyCelu = []): array
    {
        $id = self::podmiotId($regula);
        // ⚠ **Zwolnieni zostają w wierszu, przekreśleni** (decyzja Krzysztofa 19.10.2026).
        // Reguły nie sprzątamy po zwolnieniu: dostęp znika sam, bo każde zapytanie o dostęp
        // bierze wyłącznie czynne zatrudnienia. Administrator ma zobaczyć, co w regule zostało,
        // i sam zdecydować — wiersz bez śladu po zwolnionym kazałby zgadywać, czemu cel
        // się skurczył. Przekreślenie to ta sama konwencja, co na `/admin/konta` (Z32).
        // ⚠ Pętla idzie po MAPIE NAZWISK, nie po identyfikatorach reguły: mapa przychodzi
        // z bazy posortowana alfabetycznie, a identyfikatory mówią tylko, kto wszedł wcześniej.
        $osoby = [];
        if ($regula['target_kind'] === ZakresWidocznosci::CZYJE_OSOBY) {
            foreach ($osobyCelu as $etat => $osoba) {
                if (in_array($etat, $regula['grupy'], true)) {
                    $osoby[] = ['nazwa' => $osoba['nazwa'], 'czynne' => $osoba['czynne']];
                }
            }
            // Zatrudnienie skasowane z bazy nie wywraca wiersza — stoi na końcu jako ślad.
            foreach ($regula['grupy'] as $etat) {
                if (!array_key_exists($etat, $osobyCelu)) {
                    $osoby[] = ['nazwa' => '— (usunięty)', 'czynne' => false];
                }
            }
        }
        $czynnych = count(array_filter($osoby, static fn (array $o): bool => $o['czynne']));
        // ⚠ Dwa nazwiska plus reszta, a nie trzy: przy trzech i tak zmieściłaby się cała lista,
        // więc skrót zaczyna się dopiero tam, gdzie naprawdę skraca. Pełna lista jedzie
        // w `title` — także ze zwolnionymi, bo to ona mówi, co w regule stoi.
        $skrot = count($osoby) > 3 ? array_slice($osoby, 0, 2) : $osoby;
        $podmiot = match ($regula['subject_kind']) {
            ZakresWidocznosci::KTO_KONTO => $nazwy['konto'][$id] ?? null,
            ZakresWidocznosci::KTO_PRZELOZONY => $nazwy['przelozony'][$id] ?? null,
            ZakresWidocznosci::KTO_GRUPA => $nazwy['grupa'][$id] ?? null,
            default => null,
        };

        return [
            'id' => $regula['id'],
            'kto_rodzaj' => $regula['subject_kind'],
            // Osoby celu do wyświetlenia: skrót z przekreśleniami, reszta liczbą, całość w `title`.
            'czyje_osoby' => $skrot,
            'czyje_reszta' => count($osoby) - count($skrot),
            'czyje_reszta_opis' => 'i ' . (count($osoby) - count($skrot)) . ' '
                . Odmiana::inne(count($osoby) - count($skrot)),
            'czyje_pelne' => implode(', ', array_map(
                static fn (array $o): string => $o['nazwa'],
                $osoby,
            )),
            // ⚠ Reguła **zostaje**, ale mówi o sobie, że już nic nie daje: ani jednej czynnej
            // osoby w celu znaczy dostęp do niczego. Usuwa ją administrator koszem, świadomie.
            'czyje_nieczynna' => $osoby !== [] && $czynnych === 0,
            'kto_id' => $id,
            // ⚠ Podmiot skasowany między odczytem listy a odczytem nazw nie wywraca ekranu:
            // wiersz mówi wtedy „— (usunięty)" i da się go skasować koszem.
            'kto' => $podmiot['nazwa'] ?? '— (usunięty)',
            'kto_opis' => $podmiot['opis'] ?? '',
            'czyje_rodzaj' => $regula['target_kind'],
            'czyje' => match ($regula['target_kind']) {
                ZakresWidocznosci::CZYJE_PODWLADNI => 'Swoi podwładni (cała gałąź)',
                ZakresWidocznosci::CZYJE_GRUPY => implode(', ', array_map(
                    static fn (int $g): string => $nazwy['grupa'][$g]['nazwa'] ?? '— (usunięta)',
                    $regula['grupy'],
                )),
                ZakresWidocznosci::CZYJE_OSOBY => $czynnych . ' ' . Odmiana::osoby($czynnych),
                default => 'Cała firma',
            },
            // ⚠ Do okna zmiany jadą **tylko czynne** osoby celu: zwolnionego nie ma na liście
            // wyboru, więc wysłany w zapisie dostałby odmowę „cel" — a to wyglądałoby na
            // zepsuty ołówek. Zwolnieni wypadają z reguły przy pierwszym zapisie i zostają
            // w dzienniku jako wartości sprzed zmiany.
            'grupy' => $regula['target_kind'] === ZakresWidocznosci::CZYJE_OSOBY
                ? array_values(array_filter(
                    $regula['grupy'],
                    static fn (int $etat): bool => $osobyCelu[$etat]['czynne'] ?? false,
                ))
                : $regula['grupy'],
            'zakres' => $regula['scope'],
        ];
    }

    /** @param list<array<string, mixed>> $reguly */
    private static function idStalej(array $reguly, string $ktoRodzaj): int
    {
        foreach ($reguly as $r) {
            if ($r['subject_kind'] === $ktoRodzaj) {
                return (int) $r['id'];
            }
        }

        return 0;
    }

    /** @param array<string, mixed> $regula */
    private static function podmiotId(array $regula): int
    {
        return match ($regula['subject_kind']) {
            ZakresWidocznosci::KTO_KONTO => (int) $regula['subject_account_id'],
            ZakresWidocznosci::KTO_PRZELOZONY => (int) $regula['subject_employment_id'],
            ZakresWidocznosci::KTO_GRUPA => (int) $regula['subject_group_id'],
            default => 0,
        };
    }

    /**
     * Identyfikatory z żądania — **rzutowane i odsiane**, nigdy wprost do zapytania.
     *
     * @return list<int>
     */
    private function identyfikatory(Request $request, string $pole): array
    {
        $surowe = $request->method() === 'POST' ? $request->post($pole) : $request->query($pole);
        if (!is_array($surowe)) {
            return [];
        }

        return ZakresWidocznosci::uporzadkuj(array_map(
            static fn (mixed $v): int => is_scalar($v) ? (int) $v : 0,
            array_slice($surowe, 0, self::GRUP_MAX),
        ));
    }

    /**
     * Firma z belki — ta sama droga co na `/admin/grupy`.
     *
     * @return array<string, mixed>|null
     */
    private function kontekst(Request $request): ?array
    {
        $user = $request->attribute(Authenticate::USER);
        if (!$user instanceof CurrentUser) {
            return null;
        }
        $wynik = $this->kontekstSpolki->dlaEkranu(
            $user->accountId(),
            $user->activeEmployment(),
            $request->queryString('spolka', 12) !== ''
                ? $request->queryString('spolka', 12)
                : $request->postString('spolka', 12),
        );
        if ($wynik['spolka'] === null) {
            return null;
        }

        return [
            'spolki' => $wynik['spolki'],
            'spolka' => ['id' => $wynik['spolka']['id'], 'nazwa' => $wynik['spolka']['nazwa']],
            'konto' => $user->accountId(),
            'aktor' => $user->displayName,
            'z_adresu' => $wynik['z_adresu'],
            'fallback' => $wynik['fallback'],
        ];
    }

    /** @var array<string, string> */
    private const KOMUNIKATY = [
        'nadany' => 'Dostęp nadany.',
        'zmieniony' => 'Dostęp zmieniony.',
        'odebrany' => 'Dostęp odebrany.',
    ];

    /**
     * ⚠ Zdania mówią, **co jest nie tak z żądaniem**, a nie „błąd zapisu": administrator ma
     * wiedzieć, czy poprawić wybór, czy szukać dalej.
     *
     * @var array<string, string>
     */
    private const ODMOWY = [
        WidocznoscService::POWOD_DUPLIKAT => 'Taka reguła już jest — ten sam odbiorca, ten sam zakres osób i ten sam poziom szczegółów.',
        WidocznoscService::POWOD_PODMIOT => 'Wybrana osoba lub grupa nie należy do tej firmy.',
        WidocznoscService::POWOD_CEL => 'Wybierz, czyje nieobecności mają być widoczne — przy „wybranych grupach" trzeba wskazać co najmniej jedną.',
        WidocznoscService::POWOD_BEZ_ETATU => 'Ta osoba nie jest zatrudniona w tej firmie, więc nie ma podwładnych — wybierz całą firmę albo wskazane grupy.',
        WidocznoscService::POWOD_DOMYSLNA => 'Reguły dla wszystkich przełożonych nie da się usunąć — można jej tylko zmienić zakres.',
        WidocznoscService::POWOD_BRAK => 'Tej reguły już nie ma.',
        WidocznoscService::POWOD_SAM_SIEBIE => 'Nie nadaje się dostępu do własnych nieobecności — widać je zawsze w widoku „Tylko ja".',
    ];
}
