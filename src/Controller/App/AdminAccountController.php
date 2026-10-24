<?php

declare(strict_types=1);

namespace Wezurlop\Controller\App;

use InvalidArgumentException;
use Wezurlop\Entity\CurrentUser;
use Wezurlop\Http\Middleware\Authenticate;
use Wezurlop\Http\Request;
use Wezurlop\Http\Response;
use Wezurlop\Repository\AdresZajetyException;
use Wezurlop\Repository\AccountRepository;
use Wezurlop\Repository\EventLogRepository;
use Wezurlop\Repository\JobRepository;
use Wezurlop\Repository\PermissionRepository;
use Wezurlop\Repository\PersonRepository;
use Wezurlop\Security\ClientFingerprint;
use Wezurlop\Service\AccessService;
use Wezurlop\Service\AccountMailService;
use Wezurlop\Service\TerminWysylki;
use Wezurlop\Service\AccountService;
use Wezurlop\Service\AuthService;
use Wezurlop\Service\DeviceService;
use Wezurlop\Service\InvitationService;
use Wezurlop\Service\CalendarVisibility;
use Wezurlop\Service\ZakresWidocznosci;
use Wezurlop\Service\KontekstSpolki;
use Wezurlop\Service\TwoFactorService;
use Wezurlop\Support\AdresKontaktowy;
use Wezurlop\Support\Komunikat;
use Wezurlop\Support\UrlGenerator;
use Wezurlop\Support\Config;
use Wezurlop\Support\ErrorHandler;
use Wezurlop\Support\Odmiana;
use Wezurlop\Support\Telefon;
use Wezurlop\Support\View;

/**
 * Administracja kontami. ⚠ Ekran jest listą OSÓB z kolumną „konto", nie listą kont —
 * zawężenie idzie przez `employment` (PersonRepository), bo osoba zatrudniona w firmie X,
 * której konto nie ma tam roli, przy zawężeniu przez `role_assignment` w ogóle nie istniała.
 */
final class AdminAccountController
{
    public function __construct(
        private readonly View $view,
        private readonly AccountRepository $accounts,
        private readonly PersonRepository $people,
        private readonly InvitationService $invitations,
        private readonly AccessService $access,
        private readonly AccountService $konta,
        private readonly EventLogRepository $events,
        private readonly JobRepository $jobs,
        private readonly TerminWysylki $termin,
        private readonly TwoFactorService $twoFactor,
        private readonly DeviceService $devices,
        private readonly PermissionRepository $permissions,
        private readonly ClientFingerprint $fingerprint,
        private readonly KontekstSpolki $kontekstSpolki,
        private readonly ErrorHandler $errors,
        private readonly UrlGenerator $urls,
        // ⚠ **Ta sama klasa, która rozstrzyga dostęp do kalendarzy** (zlecenie Z58, karta 4
        // panelu). Panel pokazuje jej odpowiedź, a nie własny rachunek: panel mówiący coś
        // innego niż kalendarz byłby gorszy niż panel milczący.
        private readonly CalendarVisibility $widocznosc,
        // ⚠ Zdanie o obowiązkowym 2FA administratorów musi być zgodne z konfiguracją
        // (`ADMIN_2FA_REQUIRED`) — ta sama zasada, co kafel na `/ustawienia` z Z57 U3.
        // Instalacja z wyłączonym wymogiem nie ma prawa obiecywać drugiego składnika.
        private readonly Config $config,
    ) {
    }

    /**
     * Komunikaty panelu osoby po udanym zapisie — **biała lista kluczy** (CLAUDE.md §11):
     * do adresu trafia klucz, nigdy treść.
     *
     * @var array<string, string>
     */
    private const KOMUNIKATY_PANELU = [
        'zapisane' => 'Zapisane. Dane konta i dostęp do firm idą jednym zapisem.',
        // ⚠ Osobny komunikat dla „Zapisz i wyślij" (Z64): bez niego ścieżka bez JavaScriptu
        // mówiłaby „Zapisane" o żądaniu, które zapisało **i** zostawiło zaproszenie w kolejce.
        'zaproszono' => 'Zapisane. Zaproszenie przyjęte do wysyłki.',
    ];

    /**
     * ⚠ Komunikat mówi **co zrobić**, nie tylko czego nie wolno. Powody z serwisu są krótkie
     * (nadają się do dziennika), a to jest ich pełne brzmienie dla człowieka.
     *
     * @var array<string, string>
     */
    private const KOMUNIKATY_ODMOW = [
        AccountService::POWOD_SIEBIE => 'Nie można zablokować konta, na którym jesteś zalogowany — '
            . 'odblokowanie wymagałoby drugiego administratora, a drugiego może nie być.',
        // ⚠ Pierwsze zdanie **mówi fakt**, drugie — co zrobić (brzmienie z Z59 §3 plus droga
        // wyjścia). Zdanie bez drogi wyjścia zostawia administratora z zakazem i bez pomysłu.
        AccountService::POWOD_OSTATNI_ADMIN => 'To ostatni aktywny administrator — nie można go '
            . 'zablokować. Najpierw utwórz drugie konto i poczekaj, aż jego właściciel ustawi hasło: '
            . 'konto zaproszone, które nigdy nie zostało aktywowane, nie jest zabezpieczeniem.',
        AccountService::POWOD_NIE_MA => 'Stan tego konta zmienił się w trakcie — odśwież panel.',
        // ⚠ Odmowa odblokowania mówi, CZEGO brakuje i co z tym zrobić — liczbę dokłada panel,
        // bo tylko on wie, ile dostępów ma to konto. Sam komunikat nie może kłamać liczbą,
        // której serwis nie policzył w tej samej transakcji.
        AccountService::POWOD_BRAK_LICENCJI => 'Nie ma wolnych licencji na odblokowanie tego konta. '
            . 'Odbierz zbędne dostępy innym osobom albo rozszerz licencję.',
    ];

    /** Rozmiar strony — ten sam co na `/dane` (`DataController::PAGE`), żeby nie były dwie prawdy. */
    private const PAGE = 25;

    /** Sortowanie z białej listy (CLAUDE.md §11). */
    private const SORT = ['nazwisko', 'email', 'konto'];

    /**
     * Zakładki listy (zlecenie Z59). ⚠ Klucze jadą w adresie, więc są **białą listą**, a nie
     * napisami do porównania w szablonie.
     */
    private const RODZAJ_PRACOWNICY = 'pracownicy';
    private const RODZAJ_ADMIN = 'administracyjne';

    /**
     * Lista OSÓB z kolumną „konto" (zlecenie `2026-09-21-konta-budowa.md` §1).
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $admin = $request->attribute(Authenticate::USER);
        assert($admin instanceof CurrentUser);
        // ⚠ `?? -1` zostaje świadomie: administrator firmy bez aktywnego zatrudnienia widzi PUSTĄ
        // listę, a nie wszystko. Uprawnienie nie bierze się z braku danych.
        $companyId = $this->scope($admin);

        $search = trim($request->queryString('szukaj', 100));
        $sort = in_array($request->queryString('sortuj', 20), self::SORT, true)
            ? $request->queryString('sortuj', 20)
            : 'nazwisko';
        $asc = $request->queryString('kierunek', 4) !== 'desc';
        // ⚠ Spółka ekranu bierze się z JEDNEGO znacznika w belce (zlecenie
        // `2026-10-10-globalny-wybor-spolki.md` §1), nie z własnego `<select>`. Lista dozwolonych
        // spółek dalej jest białą listą — tylko liczy ją `KontekstSpolki` z uprawnień czytanych
        // w tym żądaniu, a nie zapytanie o wszystkie spółki tenanta.
        //
        // ⚠ **Pozycja „wszystkie" zniknęła razem z `<select>`** i jest to strata świadoma:
        // ekran jest spółkowy (`zakres => 'spolka'` w `config/routes.php`), a znacznik nie ma
        // stanu „wszystkie". Gdyby lista wszystkich osób tenanta była potrzebna, tańsza droga
        // to zakres `tenant` dla tej trasy i brak filtra w ogóle — nie druga kontrolka.
        $kontekst = $this->kontekstSpolki->dlaEkranu(
            $admin->accountId(),
            $admin->activeEmployment(),
            $request->query('spolka'),
        );
        $filtr = $kontekst['spolka']['id'] ?? null;

        // ⚠ **Zakładka z ADRESU, z białej listy** (zlecenie Z59 §1, CLAUDE.md §11): wartość spoza
        // listy znaczy „pracownicy", a nie błąd — adres wklejony po zmianie nazwy ma pokazać
        // listę, nie ekran awarii. Działa bez JavaScriptu, bo to zwykły odnośnik `GET`.
        $rodzaj = $request->queryString('rodzaj', 20) === self::RODZAJ_ADMIN
            ? self::RODZAJ_ADMIN
            : self::RODZAJ_PRACOWNICY;

        // ⚠ **Filtr zatrudnienia z ADRESU, z białej listy** (zlecenie Z84 A1): zwykły `GET`,
        // więc działa bez JavaScriptu i łączy się z szukaniem, sortowaniem i stronicowaniem —
        // wszystkie cztery jadą tym samym adresem. Wartość spoza listy znaczy „pracujący",
        // czyli stan domyślny, a nie błąd (CLAUDE.md §11).
        $zatrudnienie = in_array($request->queryString('zatrudnienie', 16),
            [PersonRepository::ZATRUDNIENIE_PRACUJACY, PersonRepository::ZATRUDNIENIE_BYLI,
                PersonRepository::ZATRUDNIENIE_WSZYSCY], true)
            ? $request->queryString('zatrudnienie', 16)
            : PersonRepository::ZATRUDNIENIE_PRACUJACY;
        $stany = $this->people->stateCounters($companyId, $filtr);
        // ⚠ Licznik w zakładce liczy TEN SAM zbiór, który ta zakładka pokazuje — bez szukanego
        // tekstu. Inaczej po wpisaniu nazwiska zakładka mówiłaby „Pracownicy 1", czyli o liście,
        // a nie o zbiorze, do którego się przełącza (ta sama zasada, co w kaflach).
        $liczniki = [
            self::RODZAJ_PRACOWNICY => $stany['osoby'],
            self::RODZAJ_ADMIN => $this->people->ileAdministracyjnych(),
        ];

        // ⚠ **Konta administracyjne są NIEZALEŻNE od firmy w belce**: konto bez etatu nie należy
        // do żadnej firmy, więc zawężenie wycięłoby z listy wszystko — i właśnie dlatego dotąd
        // nie dawały się znaleźć (zmierzone: szukanie po nazwisku administratora dawało
        // „Nikogo nie znaleziono").
        $total = $rodzaj === self::RODZAJ_ADMIN
            ? $this->people->ileAdministracyjnych($search)
            : $this->people->countForAdmin($companyId, $search, $filtr, $zatrudnienie);
        $page = self::page($request, $total, self::PAGE);

        $osoby = $rodzaj === self::RODZAJ_ADMIN
            ? $this->people->listaAdministracyjnych($search, $sort, $asc, self::PAGE, $page['offset'])
            : $this->people->listForAdmin($companyId, $search, $sort, $asc, self::PAGE,
                $page['offset'], $filtr, $zatrudnienie);

        $utworzono = filter_var($request->query('utworzono'), FILTER_VALIDATE_INT);
        $reset = filter_var($request->query('zresetowano'), FILTER_VALIDATE_INT);
        $revoked = filter_var($request->query('zakonczono'), FILTER_VALIDATE_INT);

        return $this->view->render('page/web/admin-accounts.twig', [
            'osoby'       => $osoby,
            'strona'      => $page,
            'szukaj'      => $search,
            'zatrudnienie' => $zatrudnienie,
            // ⚠ Liczby przy segmentach liczy JEDNO zapytanie, żeby się do siebie dodawały —
            // i liczą ten sam zakres i to samo szukanie, co lista (patrz `employmentCounters()`).
            // ⚠ Zakładka kont administracyjnych nie ma zatrudnień, więc filtr jej nie dotyczy:
            // segmenty renderują się tylko przy zakładce pracowników.
            'zatrudnienie_liczniki' => $rodzaj === self::RODZAJ_ADMIN
                ? [PersonRepository::ZATRUDNIENIE_PRACUJACY => 0,
                    PersonRepository::ZATRUDNIENIE_BYLI => 0,
                    PersonRepository::ZATRUDNIENIE_WSZYSCY => 0]
                : $this->people->employmentCounters($companyId, $search, $filtr),
            'sortuj'      => $sort,
            'kierunek'    => $asc ? 'asc' : 'desc',
            'stany'       => $stany,
            'rodzaj'      => $rodzaj,
            'liczniki'    => $liczniki,
            // Zdanie o 2FA na formularzu i w notce pod tabelą musi być zgodne z konfiguracją
            // (ta sama zasada, co kafel 2FA z Z57 U3).
            'dwuetapowe_wymagane' => $this->config->adminTwoFactorRequired,
            'licencja'    => $this->access->licenceUsage(),
            // ⚠ **Fakt całego tenanta, nie wiersza** (zlecenie Z87): licencja ma jedną pulę
            // dostępów, więc „nie ma wolnego miejsca" dotyczy wszystkich naraz. Pytamy tą samą
            // funkcją, którą serwer odmawia odblokowania — „czy zmieści się jeszcze jeden dostęp".
            'brak_licencji' => $this->brakWolnejLicencji(),
            'spolka'      => $kontekst['spolka'],
            'z_adresu'    => $kontekst['z_adresu'],
            'fallback'    => $kontekst['fallback'],
            'current_id'  => $admin->accountId(),
            'utworzono_id' => $utworzono === false ? null : $utworzono,
            'reset_id'    => $reset === false ? null : $reset,
            'revoked_id'  => $revoked === false ? null : $revoked,
        ])->withHeader('Cache-Control', 'no-store');
    }

    /**
     * Zapis adresu kontaktowego wprost z wiersza listy (htmx), bez przeładowania strony.
     *
     * ⚠ To pełnoprawny endpoint, nie „wewnętrzny, bo używa go tylko htmx" (CLAUDE.md §5):
     * sprawdza zalogowanie i uprawnienie przez middleware, a **zakres osoby w zapytaniu**.
     * Odpowiedź renderuje **stan z bazy**, a nie to, co przyszło w żądaniu — inaczej ekran
     * pokazałby sukces tam, gdzie zapis się nie udał.
     *
     * @param array<string, string> $params
     */
    public function saveEmail(Request $request, array $params): Response
    {
        $admin = $request->attribute(Authenticate::USER);
        assert($admin instanceof CurrentUser);
        $personId = filter_var($params['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($personId === false) {
            return $this->errors->errorPage(404, null, $request->isHtmx());
        }
        $companyId = $this->scope($admin);
        $row = $this->people->rowForAdmin($personId, $companyId);
        if ($row === null) {
            return $this->errors->errorPage(404, null, $request->isHtmx());
        }
        // ⚠ Adres jest edytowalny z listy dopóki konto nie zostało ZAPROSZONE (§6 zlecenia) —
        // czyli także wtedy, gdy istnieje konto `niezaproszone`: adres nie jest jeszcze niczyim
        // loginem, bo nikt go nie dostał. Po zaproszeniu adres jest loginem i zmiana idzie panelem
        // osoby, z wpisem w dzienniku i unieważnieniem starego zaproszenia. Wyłączone pole w HTML
        // jest podpowiedzią, ta odmowa jest zabezpieczeniem (CLAUDE.md §10, §11).
        if ($row['account_id'] !== null && $row['account_status'] !== AccountRepository::STAN_NIEZAPROSZONE) {
            return Response::html('', 409);
        }
        // ⚠ BRAK pola to nie to samo co pole PUSTE. Pole `disabled` nie jest wysyłane, więc gdyby
        // brak znaczył „wyczyść", wyłączona kontrolka kasowałaby adres przy pierwszym żądaniu
        // wysłanym obok interfejsu. Kasuje wyłącznie jawnie puste pole.
        if ($request->post('adres') === null) {
            return Response::html('', 422);
        }
        $email = AuthService::normalizeEmail($request->postString('adres', 254));
        // Puste pole znaczy „wyczyść adres" — to jedyny sposób wycofania literówki przed wysyłką.
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            // ⚠ 422 BEZ treści do podmiany. Odpowiedź błędna nie jest wstawiana do strony
            // (CLAUDE.md §5), więc wiersz zostaje z wpisanym tekstem — literówki nie trzeba
            // wpisywać od nowa — a komunikat pokazuje wspólna obsługa `htmx:responseError`.
            return Response::html('', 422);
        }
        // ⚠ Jeden adres = jedna osoba (zlecenie Z40). Sprawdzenie jest PO STRONIE SERWERA,
        // bo pole w wierszu nie wie nic o pozostałych osobach, a adres kontaktowy staje się
        // loginem w dniu zaproszenia — duplikat ujawniłby się dopiero wtedy, jako zaproszenie,
        // którego nie da się wysłać.
        $odmowa = $this->odmowaAdresu($email, $personId, $companyId);
        if ($odmowa !== null) {
            // ⚠ Treść jedzie jako `text/plain`, bo pasek komunikatu wstawia ją przez `textContent`
            // (CLAUDE.md §6). Bez tego wiersz pokazywałby ogólne „dane są nieprawidłowe”,
            // czyli komunikat, po którym nie wiadomo, co poprawić.
            return Response::text($odmowa, 422);
        }
        try {
            if (!$this->people->updateContactEmail($personId, $email === '' ? null : $email, $companyId)) {
                return $this->errors->errorPage(404, null, $request->isHtmx());
            }
        } catch (AdresZajetyException) {
            return Response::text($this->odmowaAdresu($email, $personId, $companyId)
                ?? AdresKontaktowy::ODMOWA_BEZ_NAZWISKA, 422);
        }
        $this->events->record(
            'person.email_set',
            $admin->accountId(),
            null,
            null,
            'person',
            $personId,
            null,
            // ⚠ Sam adres NIE idzie do dziennika — to dana osobowa (CLAUDE.md §14). Zapisujemy,
            // że adres ustawiono albo wyczyszczono, i kto to zrobił.
            ['ustawiono' => $email !== ''],
        );

        // ⚠ Potwierdzenie zapisu idzie NAGŁÓWKIEM, bo przy htmx nie ma przeładowania strony,
        // a sama podmiana wiersza nie mówi, że zapis się udał (zlecenie Z47). Treść pokazuje
        // ten sam toast, co odmowę — jedno miejsce na komunikaty, nie dwa.
        return $this->rowFragment($personId, $companyId, $admin)->withHeader(
            'HX-Trigger',
            Komunikat::naglowek($email === '' ? 'Adres wyczyszczony.' : 'Adres zapisany.'),
        );
    }

    /**
     * Potwierdzenie blokady albo odblokowania — pokazuje **SKUTEK**, nie pyta „czy na pewno".
     *
     * ⚠ Renderuje się w tej samej szufladzie co panel, a nie w osobnym oknie: potwierdzenie
     * dotyczy konta, które administrator właśnie ma przed sobą, a druga nakładka nad nakładką
     * to jeden ekran do zgubienia się.
     *
     * @param array<string, string> $params
     */
    public function blockConfirm(Request $request, array $params): Response
    {
        $admin = $request->attribute(Authenticate::USER);
        assert($admin instanceof CurrentUser);
        $companyId = $this->scope($admin);
        $personId = filter_var($params['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $dane = $personId === false ? null : $this->panelData($personId, $companyId, $admin);
        if ($dane === null || $dane['o']['account_id'] === null) {
            return $this->errors->errorPage(404, null, $request->isHtmx());
        }
        $odblokowanie = $dane['o']['account_status'] === AccountRepository::STAN_ZABLOKOWANE
            || $dane['o']['account_status'] === 'do_odblokowania';
        // ⚠ Ekran potwierdzenia, który rysuje się do końca, a dopiero POST mówi „nie wolno",
        // to atrapa — i to przy operacji bez drogi powrotu. Odmowę pokazujemy TUTAJ, w panelu,
        // zamiast rysować czynny przycisk „Zablokuj konto".
        if (!$odblokowanie) {
            $odmowa = $this->konta->blockRefusal((int) $dane['o']['account_id'], $admin->accountId());
            if ($odmowa !== null) {
                return $this->panelZBledem($personId, $companyId, $admin,
                    self::KOMUNIKATY_ODMOW[$odmowa] ?? $odmowa, [], 'ogolny', $request->isHtmx());
            }
        }

        return $this->view->render('fragments/account-block-confirm.twig', $dane + [
            // ⚠ `strict_variables` w Twigu (CLAUDE.md §5) wyłapuje brakującą zmienną błędem,
            // a nie cichą pustką — i właśnie to zrobił przy pierwszym wywołaniu.
            'w_szufladzie' => $request->isHtmx(),
            'odblokowanie' => $odblokowanie,
            // ⚠ Skutek licencyjny liczony PRZED decyzją: odblokowanie zajmuje dostępy z powrotem
            // i może przekroczyć limit, a blokada je zwalnia.
            'licencja'     => $this->access->licenceUsage(),
            'panel'        => $this->skutekBlokady((int) $dane['o']['account_id'],
                (string) $dane['o']['account_status'], $odblokowanie),
        ]);
    }

    /**
     * **Czy w licencji zmieści się jeszcze jeden dostęp** — jedno pytanie na ekran (zlecenie Z87).
     *
     * ⚠ Pytamy tą samą funkcją, którą serwer odmawia odblokowania (`brakujeLicencji()`), tylko
     * o jeden dostęp: wiersz listy ma mówić prawdę o tym, co się stanie po kliknięciu „Odblokuj".
     *
     * ⚠ Jedno zapytanie na EKRAN, nie na wiersz: licznik przechodzi po wszystkich przypisaniach
     * tenanta, więc pytanie przy każdym z dwudziestu pięciu wierszy byłoby tą samą pracą
     * wykonaną dwadzieścia pięć razy. Licencja ma jedną pulę, więc odpowiedź i tak jest wspólna.
     */
    private function brakWolnejLicencji(): bool
    {
        $licencja = $this->access->licenceUsage();

        return AccessService::brakujeLicencji(1, $licencja['limit'], $licencja['uzyte']) > 0;
    }

    /** Nazwy stanów konta słowami — jedno miejsce, bo stoją w zdaniu, nie w plakietce. */
    private const STAN_SLOWAMI = [
        AccountRepository::STAN_AKTYWNE => 'konto aktywne',
        AccountRepository::STAN_ZAPROSZONE => 'konto zaproszone',
        AccountRepository::STAN_NIEZAPROSZONE => 'konto bez zaproszenia',
        AccountRepository::STAN_ZABLOKOWANE => 'konto zablokowane',
        'do_odblokowania' => 'konto zablokowane',
    ];

    /**
     * **Liczby i zdania panelu blokady** (zlecenie Z85): kafel osoby, trzy kolumny licencji
     * i zdanie pod nimi.
     *
     * ⚠ Wszystko składa się TUTAJ, nie w szablonie: każde z tych zdań odmienia rzeczownik przez
     * liczbę, a polska odmiana ma w tym repozytorium jedno miejsce (`Support\Odmiana`).
     *
     * ⚠ Licencje tego konta liczy `licensedAccessesOfAccount()` — ta sama reguła, co licznik
     * na `/licencja`. Panel brał do 24.10.2026 licznik `dostepy` z wiersza listy, który liczy
     * też dostępy bez aktywnego zatrudnienia: u osoby z wygasłym etatem obiecywał zwolnienie
     * licencji, której nikt nie zajmował.
     *
     * @return array{stan_slowami: string, zdanie_kafla: string, licencje: array<string, mixed>}
     */
    private function skutekBlokady(int $accountId, string $stan, bool $odblokowanie): array
    {
        $zajmuje = $this->accounts->licensedAccessesOfAccount($accountId);
        $licencja = $this->access->licenceUsage();
        $skutek = AccessService::skutekNaLicencje($zajmuje, $licencja['limit'], $licencja['uzyte'], $odblokowanie);
        $firmy = $zajmuje . ' ' . Odmiana::firmyDopelniacz($zajmuje);
        // ⚠ Ta sama funkcja, którą serwer odmawia odblokowania (`brakujeNaOdblokowanie()` liczy
        // nią w transakcji) — więc wyłączony przycisk i odmowa serwera to jedna liczba, nie dwie.
        $brakuje = $odblokowanie
            ? AccessService::brakujeLicencji($zajmuje, $licencja['limit'], $licencja['uzyte'])
            : 0;
        // ⚠ Zdanie „nic się nie zmieni" to NIE uprzejmość, tylko odpowiedź na pytanie, które
        // ekran zadaje trzema kolumnami: przy zerze dostępów wszystkie trzy liczby są równe
        // i bez tego zdania administrator szukałby, czego nie doczytał.
        $zdanie = $zajmuje === 0
            ? ($odblokowanie
                ? 'Odblokowanie nie zmieni liczby wolnych licencji.'
                : 'Zablokowanie nie zmieni liczby wolnych licencji.')
            : ($odblokowanie
                ? 'Odblokowanie zajmie ' . $zajmuje . ' ' . Odmiana::licencje($zajmuje) . ' (dostęp do ' . $firmy . ').'
                : 'Zablokowanie zwolni ' . $zajmuje . ' ' . Odmiana::licencje($zajmuje) . ' (dostęp do ' . $firmy . ').');

        // ⚠ **Nazwy kolumn składa kontroler razem z liczbami**, a nie szablon warunkiem:
        // przy licencji bez limitu trzecia kolumna pokazuje LICZBĘ DOSTĘPÓW, więc nazwa „wolne
        // po zablokowaniu" byłaby wtedy podpisem pod inną wielkością. Zmierzone: kolumna mówiła
        // „WOLNE PO ZABLOKOWANIU 1" przy licencji bez limitu, gdzie wolnych jest bez liku.
        $bezLimitu = $skutek['limit'] === null;
        $trzecia = $bezLimitu
            ? ($odblokowanie ? 'Zajmie się' : 'Zwolni się')
            : ($odblokowanie ? 'Wolne po odblokowaniu' : 'Wolne po zablokowaniu');
        $kolumny = [
            ['nazwa' => 'W licencji', 'wartosc' => $bezLimitu ? 'bez limitu' : (string) $skutek['limit']],
            ['nazwa' => 'Wolne teraz', 'wartosc' => $bezLimitu ? 'bez limitu' : (string) $skutek['wolne_teraz']],
            ['nazwa' => $trzecia,
                'wartosc' => (string) ($bezLimitu ? $skutek['zmiana'] : $skutek['wolne_potem']), 'akcent' => true],
        ];

        return [
            'stan_slowami' => self::STAN_SLOWAMI[$stan] ?? 'konto',
            'zdanie_kafla' => (self::STAN_SLOWAMI[$stan] ?? 'konto') . ' · dostęp do ' . $firmy,
            'licencje' => $skutek + [
                'zdanie' => $zdanie,
                'odblokowanie' => $odblokowanie,
                'kolumny' => $kolumny,
                'brakuje' => $brakuje,
                'zdanie_braku' => $brakuje === 0
                    ? ''
                    // ⚠ Po „brakuje" stoi DOPEŁNIACZ i jest niezmienny: „brakuje 1 licencji",
                    // „brakuje 5 licencji". Odmiana przez liczbę dałaby tu „brakuje 1 licencję"
                    // — ta sama pułapka, co „Potrzeba 23 licencji" w oknie zaproszeń.
                    : 'Brakuje ' . $brakuje . ' licencji — odbierz zbędne dostępy innym osobom '
                        . 'albo rozszerz licencję, żeby odblokować to konto.',
            ],
        ];
    }

    /**
     * Ręczna blokada albo odblokowanie konta.
     *
     * ⚠ Jedna trasa na obie strony przełącznika, bo decyzja zależy od STANU KONTA w bazie,
     * nie od tego, co przyszło w żądaniu. Dwie trasy znaczyłyby, że dwa żądania wysłane
     * jednocześnie mogą się wyprzedzić i zostawić stan, którego nikt nie chciał.
     *
     * @param array<string, string> $params
     */
    public function toggleBlock(Request $request, array $params): Response
    {
        $admin = $request->attribute(Authenticate::USER);
        assert($admin instanceof CurrentUser);
        $companyId = $this->scope($admin);
        $personId = filter_var($params['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $row = $personId === false ? null : $this->people->rowForAdmin($personId, $companyId);
        if ($row === null || $row['account_id'] === null) {
            return $this->errors->errorPage(404, null, $request->isHtmx());
        }
        $zablokowane = $row['account_status'] === AccountRepository::STAN_ZABLOKOWANE
            || $row['account_status'] === 'do_odblokowania';
        $ipHash = $this->fingerprint->ipHash($request->serverParams());

        // ⚠ Kontroler NIE zna reguł blokady — pyta serwis i pokazuje jego odpowiedź.
        // Dwie reguły (blokada siebie, ostatni aktywny administrator) stoją w jednym miejscu,
        // w transakcji z `FOR UPDATE`; tutaj zostaje tylko przekazanie komunikatu.
        $powod = $zablokowane
            ? $this->konta->unblock($row['account_id'], $admin->accountId(), $ipHash, $admin->displayName)
            : $this->konta->block($row['account_id'], $admin->accountId(), $ipHash, $admin->displayName);
        if ($powod !== null) {
            return $this->panelZBledem($personId, $companyId, $admin,
                self::KOMUNIKATY_ODMOW[$powod] ?? $powod, [], 'ogolny', $request->isHtmx());
        }

        $dane = $this->panelData($personId, $companyId, $admin);
        $dane['notice'] = $zablokowane
            // ⚠ „firm", nie „spółek" (rozstrzygnięcie z 11.10.2026) — a kryterium bramki
            // nie łapało tej odmiany, bo „spółek" nie zawiera rdzenia „spółk".
            ? 'Konto odblokowane. Dostępy do firm wróciły razem z nim.'
            : 'Konto zablokowane. Sesje zakończone, dostępy zwolnione z licencji.';

        return $request->isHtmx()
            ? $this->view->render('fragments/person-panel.twig', $dane + ['w_szufladzie' => true])
            : Response::redirectFor($request, $this->urls->to('/admin/konta'));
    }

    /**
     * Formularz konta administracyjnego (etap 4 zlecenia `konta-budowa-2`).
     *
     * ⚠ To **jedyna ręczna ścieżka** tworzenia osoby i konta. Pracownika nie da się tu dodać
     * i nie jest to brak funkcji: pracownicy przychodzą z R2 i pojawiają się na liście sami.
     *
     * @param array<string, string> $params
     */
    public function adminAccountForm(Request $request, array $params): Response
    {
        $dane = ['error' => null, 'wpisane' => [], 'w_oknie' => $request->isHtmx(),
            // Zdanie o obowiązkowym 2FA tylko wtedy, gdy wymóg naprawdę działa (Z59 §4).
            'dwuetapowe_wymagane' => $this->config->adminTwoFactorRequired];

        return $request->isHtmx()
            ? $this->view->render('fragments/admin-account-form.twig', $dane)
            : $this->view->render('page/web/admin-account.twig', $dane);
    }

    /**
     * Utworzenie konta administracyjnego i wysłanie zaproszenia.
     *
     * ⚠ Rola jest **zablokowana** na administratorze tenanta — nie przychodzi z żądania i nie
     * da się jej podmienić. Powód: osoba dodana tą ścieżką nie ma zatrudnienia, a dostęp
     * pracowniczy wolno nadać wyłącznie do spółki z aktywnym etatem, więc rola pracownika
     * dałaby konto, które loguje się i natychmiast widzi odmowę.
     *
     * ⚠ Hasła NIE ustawiamy: idzie zaproszenie na podany adres (CLAUDE.md §15 — tworzący
     * nie ma znać hasła właściciela konta).
     *
     * @param array<string, string> $params
     */
    public function createAdminAccount(Request $request, array $params): Response
    {
        $admin = $request->attribute(Authenticate::USER);
        assert($admin instanceof CurrentUser);
        // ⚠ Konto administracyjne obejmuje CAŁY tenant, więc tworzyć je może wyłącznie ktoś,
        // kto sam ma zakres całego tenanta. Administrator jednej spółki nie może tą drogą
        // wyprodukować sobie konta z szerszym zakresem, niż ma sam (CLAUDE.md §10).
        if (!$this->permissions->hasTenantWideRole($admin->accountId())) {
            return $this->errors->errorPage(403, null, $request->isHtmx());
        }
        $wpisane = [
            'imie'     => trim($request->postString('imie', 100)),
            'nazwisko' => trim($request->postString('nazwisko', 100)),
            'email'    => AuthService::normalizeEmail($request->postString('email', 254)),
            'telefon'  => trim($request->postString('telefon', 32)),
        ];
        // ⚠ Ta sama decyzja, co w panelu i na „Moim koncie" (Z73) — jedna funkcja rozstrzyga
        // o kształcie numeru w całej aplikacji.
        [$telefonNowego, $odmowaNowego] = Telefon::dlaZapisu($wpisane['telefon']);
        if ($odmowaNowego !== null) {
            return $this->formularzZBledem($request, $odmowaNowego, $wpisane);
        }

        try {
            $accountId = $this->invitations->invite($wpisane['imie'], $wpisane['nazwisko'], $wpisane['email'], $admin->accountId());
        } catch (InvalidArgumentException $e) {
            // Komunikat pochodzi z usługi i mówi konkretnie, co jest nie tak (adres, imię, duplikat).
            return $this->formularzZBledem($request, $e->getMessage(), $wpisane);
        }
        if ($telefonNowego !== null) {
            $konto = $this->accounts->findById($accountId);
            if ($konto !== null) {
                $this->people->updateContact($konto['person_id'], null, $telefonNowego, null);
            }
        }

        // ⚠ Po udanym POST przekierowanie (CLAUDE.md §3). Przy htmx nagłówkiem `HX-Redirect`,
        // nie `Location` — inaczej przeglądarka wstawiłaby całą stronę do okna modalnego.
        // ⚠ Powrót na ZAKŁADKĘ kont administracyjnych (zlecenie Z59 §4): nowe konto nie ma etatu,
        // więc na liście pracowników go nie ma — przekierowanie bez zakładki pokazywało listę,
        // na której właśnie utworzonego konta nie widać.
        $cel = $this->urls->to('/admin/konta') . '?rodzaj=' . self::RODZAJ_ADMIN . '&utworzono=' . $accountId;

        return $request->isHtmx()
            ? Response::html('', 204)->withHeader('HX-Redirect', $cel)
            : Response::redirectFor($request, $cel);
    }

    /** @param array<string, string> $wpisane */
    private function formularzZBledem(Request $request, string $blad, array $wpisane): Response
    {
        $dane = ['error' => $blad, 'wpisane' => $wpisane, 'w_oknie' => $request->isHtmx(),
            'dwuetapowe_wymagane' => $this->config->adminTwoFactorRequired];

        // ⚠ Kod 200, nie 422 — ta sama zasada co w panelu osoby: odrzucenie wartości nie jest
        // błędem serwera, a htmx nie wstawia odpowiedzi błędnych, więc komunikat by nie dotarł.
        return $request->isHtmx()
            ? $this->view->render('fragments/admin-account-form.twig', $dane)
            : $this->view->render('page/web/admin-account.twig', $dane);
    }

    /**
     * Panel osoby — szuflada z prawej strony listy (etap 3 zlecenia `konta-budowa-2`).
     *
     * ⚠ Panel dzieli się ostro na dwie części: **dane z kadr tylko do odczytu** i dane, które
     * są nasze. Powód nie jest estetyczny — następna synchronizacja nadpisze wszystko, co
     * przyszło z R2, więc pole do edycji nad takimi danymi obiecywałoby zmianę, która zniknie.
     *
     * @param array<string, string> $params
     */
    public function personPanel(Request $request, array $params): Response
    {
        $admin = $request->attribute(Authenticate::USER);
        assert($admin instanceof CurrentUser);
        $companyId = $this->scope($admin);
        $personId = filter_var($params['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($personId === false) {
            return $this->errors->errorPage(404, null, $request->isHtmx());
        }
        $dane = $this->panelData($personId, $companyId, $admin);
        if ($dane === null) {
            return $this->errors->errorPage(404, null, $request->isHtmx());
        }
        // ⚠ Komunikat po zapisie bez JavaScriptu — tą samą drogą, co na pozostałych ekranach
        // (`?ok=` z białej listy, nigdy tekst z adresu).
        $ok = $request->queryString('ok', 16);
        $dane['notice'] = self::KOMUNIKATY_PANELU[$ok] ?? null;
        // ⚠ **Termin wysyłki także bez JavaScriptu** (Z64): ze skryptem panel przychodzi
        // z odpowiedzi zapisu i mówi „wyjdzie w ciągu około 5 minut", bez skryptu wracał
        // przekierowaniem i to zdanie gubił — czyli ta sama czynność kończyła się dwoma
        // różnymi komunikatami. „Przyjęto do wysyłki" bez terminu jest pustym zdaniem (Z29).
        $dane['notice_termin'] = $ok === 'zaproszono' ? 'Zaproszenie' : null;

        // Fragment do szuflady, pełny ekran bez JavaScriptu — ta sama treść w obu przypadkach.
        // ⚠ `w_szufladzie` rozstrzyga, czy „Zamknij" ma co zamykać. Bez tego na ekranie
        // zapasowym stałby przycisk, który nic nie robi — czyli atrapa.
        return $request->isHtmx()
            ? $this->view->render('fragments/person-panel.twig', $dane + ['w_szufladzie' => true])
            : $this->view->render('page/web/person-panel.twig', $dane + ['w_szufladzie' => false]);
    }

    /**
     * Zapis danych kontaktowych z panelu: adres i telefon jednym „Zapisz zmiany".
     *
     * ⚠ Adres podlega tej samej regule co w wierszu listy (§6): wolno go zmieniać, dopóki konto
     * nie zostało zaproszone. Telefon wolno zmieniać zawsze — nie jest loginem.
     *
     * @param array<string, string> $params
     */
    public function savePerson(Request $request, array $params): Response
    {
        $admin = $request->attribute(Authenticate::USER);
        assert($admin instanceof CurrentUser);
        $companyId = $this->scope($admin);
        $personId = filter_var($params['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($personId === false) {
            return $this->errors->errorPage(404, null, $request->isHtmx());
        }
        $row = $this->people->rowForAdmin($personId, $companyId);
        if ($row === null) {
            return $this->errors->errorPage(404, null, $request->isHtmx());
        }
        $przedZaproszeniem = $row['account_id'] === null
            || $row['account_status'] === AccountRepository::STAN_NIEZAPROSZONE;
        // ⚠ **„Zapisz i wyślij" to JEDNO żądanie** (zlecenie Z64): przycisk zaproszenia stoi
        // w formularzu panelu i dokłada do niego tę flagę, więc adres i zaproszenie idą razem.
        // Powód jest w kolejności: dopóki były to dwa żądania, istniał stan „adres wpisany,
        // nie zapisany, a zaproszenie poszło na stary" — albo odwrotnie.
        $chceZaprosic = $request->postString('wyslij_zaproszenie', 4) === '1';

        $email = AuthService::normalizeEmail($request->postString('email', 254));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return $this->panelZBledem($personId, $companyId, $admin, 'Nieprawidłowy adres e-mail.',
                ['email' => $request->postString('email', 254), 'telefon' => $request->postString('telefon', 32)],
                'dane', $request->isHtmx());
        }
        // ⚠ **Telefon przechodzi przez `Support\Telefon`, tak samo jak na „Moim koncie"**
        // (zlecenie Z73, decyzja Krzysztofa). Dotąd stała tu własna biała lista znaków
        // (cyfry, spacje, plus, myślnik, nawiasy, 6–32 znaki) — przepuszczała numery
        // zagraniczne i wewnętrzne, więc w jednej kolumnie bazy stały dwa kształty. Numerów
        // zagranicznych świadomie nie obsługujemy; pusty numer znaczy USUNIĘCIE.
        $telefon = trim($request->postString('telefon', 32));
        // Do bazy idzie JEDNA postać (`+48XXXXXXXXX`) albo `null` — nigdy to, co przyszło.
        [$telefonDoZapisu, $odmowaTelefonu] = Telefon::dlaZapisu($telefon);
        if ($odmowaTelefonu !== null) {
            return $this->panelZBledem($personId, $companyId, $admin, $odmowaTelefonu,
                ['email' => $email, 'telefon' => $telefon], 'dane', $request->isHtmx());
        }

        // ⚠ Adres jest edytowalny, DOPÓKI KONTO NIE JEST AKTYWNE (rozstrzygnięcie 22.09.2026).
        // Konto zaproszone nie ma hasła i nikt nie potwierdził, że ten adres do niego należy —
        // zamrażanie loginu, którego właściciel jeszcze nie istnieje, chroni tylko literówkę.
        $aktywne = $row['account_status'] === AccountRepository::STAN_AKTYWNE;
        $zmianaLoginu = !$przedZaproszeniem && !$aktywne && $email !== '' && $email !== $row['email'];
        if (!$przedZaproszeniem && $aktywne && $email !== '' && $email !== $row['email']) {
            // ⚠ Pole jest w HTML wyłączone; ta odmowa jest zabezpieczeniem (CLAUDE.md §10).
            return $this->panelZBledem($personId, $companyId, $admin,
                'Konto jest aktywne — adres jest jego loginem i zmienia się osobną ścieżką, '
                . 'której dziś nie ma.',
                ['telefon' => $telefon], 'dane', $request->isHtmx());
        }
        $odmowa = $this->odmowaAdresu($email, $personId, $companyId);
        if ($odmowa !== null) {
            return $this->panelZBledem($personId, $companyId, $admin, $odmowa,
                ['email' => $email, 'telefon' => $telefon], 'dane', $request->isHtmx());
        }
        if ($zmianaLoginu) {
            $powod = $this->invitations->changeEmail(
                (int) $row['account_id'], $email, $admin->accountId(), $admin->displayName,
            );
            if ($powod !== null) {
                return $this->panelZBledem($personId, $companyId, $admin, ucfirst($powod) . '.',
                    ['email' => $email, 'telefon' => $telefon], 'dane', $request->isHtmx());
            }
        }
        // ⚠ **Odmowa zaproszenia rozstrzyga się TUTAJ — przed zapisem czegokolwiek** (Z64):
        // „błąd walidacji = nic nie zapisane, nic nie wysłane". Gdyby ta sama odmowa przyszła
        // po zapisie, adres byłby zapisany, zaproszenie nie, a komunikat mówiłby o jednym
        // z dwóch skutków. Adres, którego dotyczy zaproszenie, to ten, który zaraz zapiszemy:
        // wpisany w tym żądaniu albo już zapisany w koncie, nigdy puste miejsce.
        if ($chceZaprosic) {
            $adresZaproszenia = $email !== '' ? $email : (string) ($row['email'] ?? '');
            if (!$przedZaproszeniem) {
                // Konto zaproszone albo aktywne ma własne drogi („Wyślij ponownie", reset
                // hasła); stąd nie zaprasza się go po raz drugi (CLAUDE.md §10).
                return $this->panelZBledem($personId, $companyId, $admin,
                    'To konto już istnieje — zaproszenie wysyła się tylko raz, a ponowienie '
                    . 'ma własny przycisk.',
                    ['email' => $email, 'telefon' => $telefon], 'ogolny', $request->isHtmx());
            }
            if ($adresZaproszenia === '') {
                return $this->panelZBledem($personId, $companyId, $admin,
                    'Zaproszenie wymaga adresu e-mail — wpisz adres w tym polu.',
                    ['email' => $email, 'telefon' => $telefon], 'dane', $request->isHtmx());
            }
        }

        // ⚠ Dostęp zapisujemy **po walidacji danych konta, przed ich zapisem** (Z61 §1):
        // zły adres nie ma prawa zmienić dostępu, a odmowa licencji nie ma prawa wywrócić
        // zapisu telefonu — każda część wraca z komunikatem przy swojej karcie.
        [$komunikatDostepu, $odmowyDostepu] = $this->zapiszDostepZPanelu($request, $personId, $admin, $row);

        $adresDoZapisu = $przedZaproszeniem ? ($email === '' ? null : $email) : ($zmianaLoginu ? $email : $row['email']);
        try {
            if (!$this->people->updateContact($personId, $adresDoZapisu, $telefonDoZapisu, $companyId)) {
                return $this->errors->errorPage(404, null, $request->isHtmx());
            }
        } catch (AdresZajetyException) {
            return $this->panelZBledem($personId, $companyId, $admin,
                $this->odmowaAdresu($email, $personId, $companyId) ?? AdresKontaktowy::ODMOWA_BEZ_NAZWISKA,
                ['email' => $email, 'telefon' => $telefon], 'dane', $request->isHtmx());
        }
        $this->events->record(
            'person.contact_changed',
            $admin->accountId(),
            null,
            null,
            'person',
            $personId,
            null,
            // ⚠ Ani adresu, ani numeru w dzienniku — to dane osobowe (CLAUDE.md §14).
            ['adres' => $adresDoZapisu !== null, 'telefon' => $telefon !== ''],
        );

        // ⚠ **Kolejka maila JAKO OSTATNIA** (Z64): walidacja › zapis › zadanie. Zaproszenie
        // wychodzi na adres, który jest już w bazie, a nie na ten, który „zaraz zapiszemy" —
        // gdyby zapis padł, nie poleciałby link do konta, którego nie ma.
        // ⚠ Wysyłki tu nie ma: `inviteExisting()` zakłada konto, wydaje token i **zostawia
        // zadanie w kolejce** (CLAUDE.md §0 — poczta nigdy w żądaniu użytkownika).
        $odmowaZaproszenia = null;
        $zaproszono = false;
        if ($chceZaprosic) {
            $wynikZaproszenia = $this->invitations->inviteExisting(
                $personId,
                (string) $adresDoZapisu,
                $admin->accountId(),
            );
            $odmowaZaproszenia = $wynikZaproszenia['powod'];
            $zaproszono = $odmowaZaproszenia === null;
        }

        $dane = $this->panelData($personId, $companyId, $admin);
        $dane['notice'] = $komunikatDostepu === null ? 'Zapisane.' : 'Zapisane. ' . $komunikatDostepu;
        if ($zaproszono) {
            // ⚠ Zdanie wiodące, kropka, krótkie dopowiedzenie (Z47) — i termin z faktycznej
            // częstotliwości crona, nie „w ciągu kilku minut" (Z29).
            $dane['notice'] = 'Zapisane. Zaproszenie przyjęte do wysyłki.'
                . ($komunikatDostepu === null ? '' : ' ' . $komunikatDostepu);
            $dane['notice_termin'] = 'Zaproszenie';
        }
        $dane['error_dostepu'] = $odmowyDostepu;
        if ($odmowaZaproszenia !== null) {
            // Zapis się udał, zaproszenie nie — i ekran mówi dokładnie to, zamiast jednego
            // zdania o „częściowym" skutku.
            $dane['error'] = 'Dane zapisane, ale zaproszenia nie wysłano: ' . $odmowaZaproszenia . '.';
        }

        if ($request->isHtmx()) {
            return $this->view->render('fragments/person-panel.twig', $dane + ['w_szufladzie' => true]);
        }
        $ok = $zaproszono ? 'zaproszono' : 'zapisane';

        return Response::redirectFor($request, $this->urls->to('/admin/konta/osoba/' . $personId) . '?ok=' . $ok);
    }

    /**
     * **Dostęp do firm zapisywany RAZEM z danymi konta** (zlecenie Z61 §1).
     *
     * ⚠ Kolejność jest treścią, nie szczegółem. Najpierw sprawdzamy dane konta — to jedyna
     * część, którą da się odrzucić **bez zapisu czegokolwiek** (format adresu, duplikat, zakaz
     * zmiany loginu aktywnego konta). Dopiero potem idzie dostęp, w transakcji `AccessService`,
     * a na końcu zapis danych konta. Dzięki temu nie istnieje stan „adres zapisany, dostęp nie
     * dlatego, że adres był zły": przy złym adresie nic się nie zapisuje, a dostęp wchodzi
     * albo wraca z własną odmową przy swojej karcie.
     *
     * ⚠ **Pole firm nieobecne w żądaniu znaczy „nie dotykaj dostępu"**, a nie „odbierz
     * wszystkie". Panel konta administracyjnego i panel osoby bez konta nie renderują tego
     * pola wcale, a formularz bez pól wyboru wygląda w żądaniu dokładnie tak samo jak formularz,
     * w którym wszystko odznaczono. Dlatego decyduje **ukryte pole `firmy_w_formularzu`**,
     * które renderuje się razem z kontrolką — a nie obecność samych zaznaczeń.
     *
     * @return array{0: ?string, 1: ?string} komunikat o zapisie i treść odmowy (albo null)
     */
    private function zapiszDostepZPanelu(Request $request, int $personId, CurrentUser $admin, array $row): array
    {
        if ($request->post('firmy_w_formularzu') === null || $row['caly_tenant']) {
            return [null, null];
        }
        $wynik = $this->access->saveCompanyAccess($personId, self::selectedCompanies($request), $admin->accountId());
        $odmowy = [];
        foreach ($wynik['odmowy'] as $powod) {
            $odmowy[$powod] = self::ODMOWY_DOSTEPU[$powod] ?? 'Część zmian dostępu odrzucono.';
        }
        $zmiana = $wynik['nadane'] !== [] || $wynik['odebrane'] !== [];
        $komunikat = $zmiana
            ? 'Dostęp do firm: nadane ' . count($wynik['nadane']) . ', odebrane ' . count($wynik['odebrane']) . '.'
            : null;

        return [$komunikat, $odmowy === [] ? null : implode(' ', array_values($odmowy))];
    }

    /**
     * Dane panelu — trzy zapytania na panel, nie jedno na wiersz.
     *
     * @return array<string, mixed>|null
     */
    /**
     * „Wyślij ponownie" (zlecenie `wyslij-ponownie` §2).
     *
     * ⚠ Ponowienie jest **normalnym biegiem rzeczy**, nie sytuacją wyjątkową: zaproszenie
     * wygasa po 7 dniach, a poczta bywa w spamie. Dawne zdanie „zaproszenia nie wysyła się
     * drugi raz" wycofane 22.09.2026 — bez tej drogi literówka w adresie blokowała osobę
     * na zawsze (`account.email` i `account.person_id` są `UNIQUE`).
     *
     * @param array<string, string> $params
     */
    public function resendInvitation(Request $request, array $params): Response
    {
        $admin = $request->attribute(Authenticate::USER);
        assert($admin instanceof CurrentUser);
        $companyId = $this->scope($admin);
        $personId = filter_var($params['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($personId === false) {
            return $this->errors->errorPage(404, null, $request->isHtmx());
        }
        $row = $this->people->rowForAdmin($personId, $companyId);
        if ($row === null || $row['account_id'] === null) {
            return $this->errors->errorPage(404, null, $request->isHtmx());
        }
        $powod = $this->invitations->resend($row['account_id'], $admin->accountId(), $admin->displayName);
        if ($powod !== null) {
            return $this->panelZBledem($personId, $companyId, $admin, ucfirst($powod) . '.', [], 'ogolny',
                $request->isHtmx());
        }
        $dane = $this->panelData($personId, $companyId, $admin);
        if ($dane === null) {
            return $this->errors->errorPage(404, null, true);
        }
        // ⚠ Komunikat mówi KIEDY — to jedyne pytanie, które ma człowiek po kliknięciu.
        // Zmierzone: wiadomość wyszła 3 min 15 s po kliknięciu, a „wyjdzie kolejką" nie mówiło nic.
        // Samo zdanie składa `partials/termin-wysylki.twig` — kontroler nie formatuje dat.
        $dane['notice'] = 'Poprzedni link przestał działać.';
        $dane['notice_termin'] = 'Zaproszenie';

        return $this->view->render('fragments/person-panel.twig', $dane + ['w_szufladzie' => true]);
    }

    private function panelData(int $personId, ?int $companyId, CurrentUser $admin): ?array
    {
        $osoba = $this->people->panelPerson($personId, $companyId);
        if ($osoba === null) {
            return null;
        }
        $row = $this->people->rowForAdmin($personId, $companyId);

        // ⚠ Ta sama reguła zadana pytająco — żeby przycisk mówił prawdę PRZED kliknięciem.
        // Decyduje i tak `AccountService::block()` w transakcji; to jest wyłącznie wygląd.
        $odmowaBlokady = $osoba['account_id'] === null
            ? null
            : $this->konta->blockRefusal($osoba['account_id'], $admin->accountId());

        // ⚠ Ta sama reguła zadana pytająco dla ponowienia: przycisk ma mówić, ile zostało
        // do wolnego ponowienia, ZANIM ktoś kliknie (decyduje i tak `resend()`).
        $odstep = $osoba['account_id'] === null ? 0 : $this->invitations->pozostalyOdstep($osoba['account_id']);
        $wKolejce = $osoba['account_id'] !== null
            && $this->jobs->hasPendingFor(AccountMailService::JOB_INVITATION, $osoba['account_id']);
        // ⚠ Kiedy wydano ostatni link i co się stało z wysyłką — bo „zaproszony" nie odpowiada
        // na pytanie „to dlaczego tego nie ma w skrzynce" (§3). Dwa różne fakty, dwa źródła:
        // link wydaje `invitation`, a wysyłkę wykonuje kolejka i tylko ona zna `finished_at`.
        // ⚠ Nie tylko KIEDY wydano, ale też czy ten link jeszcze DZIAŁA (zmierzone: panel
        // pisał „Link wydany 12:50:59" o linku unieważnionym 45 minut później).
        $link = $osoba['account_id'] === null
            ? ['wydany_utc' => null, 'stan' => 'brak']
            : $this->invitations->ostatniLink($osoba['account_id']);
        $zadanie = $osoba['account_id'] === null
            ? null
            : $this->jobs->ostatnieZadanieDla(AccountMailService::JOB_INVITATION, $osoba['account_id']);

        $zatrudnienia = $this->people->panelEmployments($personId, (int) date('Y'));
        // ⚠ Reguły widoczności wiszą na ZATRUDNIENIU w konkretnej firmie (hierarchia i grupy
        // też), więc panel pyta o etat z firmy w belce. Konto bez etatu w tej firmie dalej
        // może mieć wgląd — z reguły osobistej albo z uprawnień administratora — i wtedy
        // pytamy z `null`, dokładnie jak kalendarz.
        // ⚠ **Firma z BELKI, nie z zawężenia listy.** `scope()` zwraca `null` dla konta
        // z rolą tenantową i znaczy wtedy „bez zawężenia" — czyli dla administratora tenanta
        // nie ma żadnej firmy, a karta widoczności musi dotyczyć konkretnej: reguły wiszą
        // na firmie. Bierzemy tę samą, którą pokazuje znacznik w belce, i z tego samego
        // źródła (`KontekstSpolki::dlaEkranu()`), z którego bierze ją ekran widoczności.
        $zBelki = $this->kontekstSpolki->dlaEkranu($admin->accountId(), $admin->activeEmployment(), null);
        $spolkaWidocznosci = $zBelki['spolka'] === null ? null : (int) $zBelki['spolka']['id'];
        $etatWidocznosci = null;
        foreach ($zatrudnienia as $z) {
            if ($z['company_id'] === $spolkaWidocznosci && $z['aktywne']) {
                $etatWidocznosci = $z['id'];
                break;
            }
        }
        $widocznosc = [
            'spolka' => $zBelki['spolka'] === null ? '' : (string) $zBelki['spolka']['nazwa'],
            'adres'  => $this->urls->to('/admin/widocznosc'),
            'zrodla' => $osoba['account_id'] === null || $spolkaWidocznosci === null
                ? []
                : $this->widocznosc->zrodlaWidza($osoba['account_id'], $spolkaWidocznosci, $etatWidocznosci),
        ];

        return [
            'o'          => $osoba,
            'wiersz'     => $row,
            'odstep_ponowienia' => $odstep,
            'zaproszenie_w_kolejce' => $wKolejce,
            'zaproszenie_wydane_utc' => $link['wydany_utc'],
            'zaproszenie_link' => $link['stan'],
            'zaproszenie_zadanie' => $zadanie,
            // Stan „kiedy to wyjdzie" z faktycznej częstotliwości crona, nie wpisany w tekst.
            'termin' => $this->termin->stan(),
            'odmowa_blokady' => $odmowaBlokady === null ? null : (self::KOMUNIKATY_ODMOW[$odmowaBlokady] ?? $odmowaBlokady),
            'zatrudnienia' => $zatrudnienia,
            // ⚠ Lista par „rola + firma" (`accessRows()`) **zniknęła z panelu w Z58**: karta
            // „Dostęp do firm" pokazuje teraz POLE WYBORU, czyli ten sam zbiór w postaci, którą
            // da się zmienić. Dwie reprezentacje tego samego (plakietki + kontrolka) znaczyły
            // dwa miejsca do rozjechania się — a zdanie „Rola i firma idą w parze, w bazie to
            // jeden wiersz" opisywało model bazy, nie decyzję administratora.
            // ⚠ **Pole wyboru firm renderuje się RAZEM z panelem** (zlecenie Z58 §3): lista
            // rozwija się w miejscu, pod polem, więc nie ma czego dociągać po kliknięciu —
            // a dociąganie znaczyłoby, że bez JavaScriptu nie da się jej w ogóle otworzyć.
            // ⚠ Wartość POLA telefonu, nie surowa kolumna (Z73): prefiks `+48` jest napisem
            // w kontrolce, więc do pola idzie sam numer. Numer zapisany kiedyś w innym kształcie
            // `Telefon::doPola()` oddaje bez zmian — pole pokazuje to, co jest, a poprawki wymaga
            // dopiero zapis.
            // ⚠ **`$osoba`, nie `$row`** — i bez `?? null`. Pierwsza wersja (Z73) brała numer
            // z `$row`, czyli z WIERSZA LISTY, który tej kolumny nie ma; `?? null` zamieniło
            // pomyłkę w ciche puste pole i panel przestał pokazywać zapisany numer. Zmierzone:
            // przy `+48600100200` w bazie pole było puste. Bez `??` brakujący klucz zgłosi się sam.
            'telefon_pole' => Telefon::doPola($osoba['phone']),
            'firmy'      => $this->people->companiesForAccess($personId),
            'licencja'   => $this->access->licenceUsage(),
            'brak_licencji' => $this->brakWolnejLicencji(),
            // Karta „Widoczność kalendarza" — tylko podgląd, z funkcji egzekwującej dostęp.
            'widocznosc' => $widocznosc,
            // ⚠ **Kontrolka „Podgląd kalendarzy" ZNIKŁA stąd 19.10.2026** (zlecenie Z53 §3), bo
            // „kto widzi kogo" jest **relacją**, a panel jednej osoby pokazuje połowę każdej
            // relacji. Od Z58 stoi tu karta POGLĄDOWA (`widocznosc` wyżej): mówi, skąd ta osoba
            // ma wgląd, i prowadzi na ekran, który o tym rozstrzyga.
            'nazwy_zakresow' => ZakresWidocznosci::nazwy(),
            // Formy słowa „inna/inne/innych" dla napisu w polu wyboru firm — słownik odmian
            // zostaje w `Support\Odmiana`, w przeglądarce zostaje sam wybór formy po liczbie.
            'formy_innych' => implode('|', [Odmiana::inne(1), Odmiana::inne(2), Odmiana::inne(5)]),
            'rok'        => (int) date('Y'),
            'current_id' => $admin->accountId(),
            'notice'     => null,
            // ⚠ Trzy slot-y komunikatu: ogólny (karta „Konto"), przy danych konta i przy dostępie
            // do firm — każda karta odpowiada za swoją odmowę (Z61 §1).
            'error_danych' => null,
            'error_dostepu' => null,
            // Nazwa tego, co przyjęto do wysyłki, albo null gdy komunikat nie dotyczy poczty
            // (`strict_variables` — brakująca zmienna to błąd, nie cicha pustka).
            'notice_termin' => null,
            'error'      => null,
            // Wartości wpisane przez użytkownika, gdy walidacja odrzuciła zapis — żeby nie
            // trzeba było wpisywać ich od nowa. Reszta panelu i tak idzie ze stanu bazy.
            'wpisane'    => [],
        ];
    }

    /**
     * Panel z komunikatem błędu — stan z bazy, plus to, co użytkownik wpisał.
     *
     * ⚠ Kod **200**, nie 422. Zmierzone: htmx nie wstawia odpowiedzi błędnych do strony
     * (CLAUDE.md §5), więc komunikat walidacji nigdy nie docierał do panelu i użytkownik
     * widział tylko ogólny pasek „wpisane dane są nieprawidłowe". Odrzucenie wartości nie jest
     * błędem serwera — żądanie zostało zrozumiane i odpowiedziane, więc odpowiedź jest poprawna,
     * a treścią jest panel z komunikatem, co poprawić.
     *
     * @param array<string, string> $wpisane
     */
    /**
     * Czy ten adres jest już czyjś — i jak to powiedzieć administratorowi (zlecenie Z40).
     *
     * ⚠ Pusty adres NIE jest duplikatem: brak adresu to brak wartości, a kont i osób bez adresu
     * może być dowolnie wiele (`UNIQUE` pomija `NULL` — ta sama zasada co w migracji
     * `20260921140001`). Bez tego warunku wyczyszczenie pola odbijałoby się o „adres zajęty”.
     *
     * @return string|null gotowa treść odmowy albo `null`, gdy adres jest wolny
     */
    private function odmowaAdresu(string $email, int $personId, ?int $companyId): ?string
    {
        if ($email === '') {
            return null;
        }

        return AdresKontaktowy::odmowa($this->people->ktoMaAdres($email, $personId, $companyId));
    }

    /**
     * Panel z komunikatem błędu — **przy tej karcie, której błąd dotyczy** (zlecenie Z61 §1).
     *
     * ⚠ Trzy slot-y, bo trzy różne karty mogą odmówić: dane konta (format adresu, duplikat,
     * zamrożony login), dostęp do firm (licencja, brak etatu) i karta „Konto" (blokada,
     * ponowienie zaproszenia). Jeden wspólny komunikat u góry panelu kazał zgadywać, które
     * pole poprawić — a przy dwóch częściach zapisu w jednym żądaniu to zgadywanie podwaja się.
     *
     * @param 'dane'|'ogolny' $gdzie
     * @param array<string, string> $wpisane
     */
    private function panelZBledem(
        int $personId,
        ?int $companyId,
        CurrentUser $admin,
        string $blad,
        array $wpisane = [],
        string $gdzie = 'dane',
        bool $htmx = true
    ): Response {
        $dane = $this->panelData($personId, $companyId, $admin);
        if ($dane === null) {
            return $this->errors->errorPage(404, null, $htmx);
        }
        $dane[$gdzie === 'dane' ? 'error_danych' : 'error'] = $blad;
        $dane['wpisane'] = $wpisane;

        // ⚠ **Bez JavaScriptu odmowa musi być EKRANEM, nie samym fragmentem** (naprawa 20.10.2026
        // przy Z64). Ta metoda renderowała zawsze fragment panelu, więc żądanie bez htmx
        // (przeglądarka bez skryptów albo ekran zapasowy `/admin/konta/osoba/{id}`) dostawało
        // w odpowiedzi goły kawałek HTML-a: komunikat tam był, ale bez powłoki, bez belki
        // i bez arkusza. Ścieżka zapasowa, która wygląda na awarię, nie jest ścieżką zapasową.
        return $htmx
            ? $this->view->render('fragments/person-panel.twig', $dane + ['w_szufladzie' => true])
            : $this->view->render('page/web/person-panel.twig', $dane + ['w_szufladzie' => false]);
    }



    /**
     * Identyfikatory spółek z kontrolki — białą listą typów.
     *
     * @return list<int>
     */
    private static function selectedCompanies(Request $request): array
    {
        $surowe = $request->post('spolki');
        if (!is_array($surowe)) {
            return [];
        }
        $out = [];
        foreach ($surowe as $wartosc) {
            $id = filter_var($wartosc, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id !== false && !in_array($id, $out, true)) {
                $out[] = $id;
            }
        }

        return $out;
    }

    /**
     * Powody odmowy → kody w nagłówku. Wartości są **nasze**, ASCII i skończone;
     * na tekst tłumaczy je `dropdown-dostep.js`.
     *
     * @var array<string, string>
     */
    /**
     * Odmowy zapisu dostępu **zdaniami** — do panelu osoby (zlecenie Z61 §1).
     *
     * ⚠ **Zdania, nie kody.** Do Z61 te same odmowy jechały jako KODY w nagłówku HTTP
     * (`X-Wezurlop-Odmowa`), bo odbierał je JavaScript rozwijanej kontrolki w wierszu listy —
     * nagłówek nie niesie polskich znaków. Kontrolka zniknęła razem z nagłówkiem i z mapą kodów:
     * odmowa wraca teraz w treści panelu, przy karcie, której dotyczy. Brzmienie jak dawniej
     * w `flash.js`, tylko „spółka" → „firma" — jedno słowo w całym interfejsie.
     *
     * @var array<string, string>
     */
    private const ODMOWY_DOSTEPU = [
        AccessService::POWOD_BRAK_ZATRUDNIENIA => 'Część firm pominięto: osoba nie ma tam aktywnego zatrudnienia.',
        AccessService::POWOD_BRAK_LICENCJI     => 'Część firm pominięto: brak wolnych licencji.',
        AccessService::POWOD_KONTO_ADMINA      => 'Pominięto: to konto jest administratorem tenanta, '
            . 'a administrator nie może mieć dostępu pracowniczego.',
    ];


    /**
     * Wysyłka partii zaproszeń (zlecenie `konta-budowa-2` §1).
     *
     * ⚠ Dwa kroki, nie jeden: **zaproszenie** (konto = możliwość zalogowania się) i osobno
     * **nadanie dostępu** do spółek z aktywnego zatrudnienia. Licencję kosztuje tylko drugi krok,
     * więc partia zaproszeń nie przerywa się na limicie — kończy się i mówi, komu dostępu nie dała.
     *
     * @param array<string, string> $params
     */
    /**
     * **Okno potwierdzenia wysyłki zaproszeń** (zlecenie Z84 cz. C) — `GET`, bo niczego
     * nie zmienia.
     *
     * ⚠ **Wysyłka przestaje być jednym kliknięciem w pasku** i to jest sedno tej zmiany:
     * zaproszenie zakłada konta, zajmuje licencje i wysyła pocztę na prawdziwe adresy —
     * czyli robi trzy rzeczy nieodwracalne, a decyzja o dostępie siedziała dotąd w polu wyboru
     * nad tabelą, gdzie łatwo jej nie zauważyć. Okno pokazuje KOGO to dotyczy, ILE licencji
     * zajmie i co się stanie, gdy dostępu nie nadać.
     *
     * ⚠ Ten sam model i ten sam szablon treści idą do okna (htmx) i na pełny ekran (bez
     * JavaScriptu) — wzorzec z `fragments/confirm.twig`.
     *
     * @param array<string, string> $params
     */
    public function inviteConfirm(Request $request, array $params): Response
    {
        $admin = $request->attribute(Authenticate::USER);
        assert($admin instanceof CurrentUser);
        $companyId = $this->scope($admin);
        // ⚠ Wybór i przełącznik dostępu czytamy z ADRESU: to krok potwierdzenia, więc musi dać
        // się odświeżyć i wrócić do niego przyciskiem „wstecz". Token CSRF nie jedzie tą drogą
        // — niesie go dopiero formularz wysyłki w oknie (zlecenie Z55: sekretów nie ma w adresach).
        $wybrane = self::selectedIds($request);
        // ⚠ **Towarzysz pola wyboru: „to żądanie przyszło Z OKNA"** — ta sama sztuczka, co
        // `firma_widoczna` w oknie widoczności (Z80 A4), i z tego samego powodu. Odznaczone pole
        // wyboru nie jedzie w żądaniu WCALE, więc bez tego znacznika „człowiek odznaczył dostęp"
        // jest nieodróżnialne od „okno otwiera się pierwszy raz" — a te dwa stany mają dać
        // przeciwne odpowiedzi: pierwszy OFF, drugi ON (zaznaczone domyślnie, zlecenie C2).
        // Zmierzone przed poprawką: odznaczenie przełącznika nie zmieniało nic, bo brak pola
        // czytaliśmy jako „włączone".
        $zOkna = $request->queryString('dostep_widoczny', 2) === '1';
        $nadajDostep = $zOkna
            ? $request->queryString('nadaj_dostep', 4) === '1'
            : true;
        $dane = $this->podgladZaproszen($wybrane, $companyId, $nadajDostep)
            + ['powrot' => $this->urls->to('/admin/konta')];

        // ⚠ `w_oknie` mówi fragmentowi, czy ma własny nagłówek. W oknie modalnym musi go mieć
        // (okno nie ma belki), a na pełnym ekranie nazwa stoi już w belce strony — ten sam napis
        // dwa razy na jednym ekranie wyłapała bramka ekranowa, nie oko.
        return $request->isHtmx()
            ? $this->view->render('fragments/invite-confirm.twig', $dane + ['w_oknie' => true])
            : $this->view->render('page/web/invite-confirm.twig', $dane + ['w_oknie' => false]);
    }

    /**
     * **Model okna potwierdzenia — i jednocześnie bramka licencji** (zlecenie Z84 cz. C).
     *
     * ⚠ Jedna funkcja na okno i na odmowę serwera: liczba „zajmie X licencji" w oknie i liczba,
     * którą serwer sprawdza przy wysyłce, muszą być TĄ SAMĄ liczbą. Policzone osobno rozjechałyby
     * się przy pierwszej zmianie warunku, a objawem byłoby okno obiecujące wysyłkę, którą serwer
     * odrzuca (albo odwrotnie — co gorsze).
     *
     * ⚠ **Do okna wchodzą tylko osoby, które DA SIĘ zaprosić** — te same warunki, co w `inviteBatch()`:
     * w zakresie, z adresem e-mail i bez konta (albo z kontem `niezaproszone`, bo zaproszenie
     * takiego konta nie zakłada, tylko przestawia jego stan). Osoby odsiane są policzone i mają
     * swoje zdanie nad listą — inaczej „N osób dostanie e-mail" nie zgadzałoby się z zaznaczeniem
     * i nikt by nie wiedział, gdzie zniknęła różnica.
     *
     * ⚠ **Licencje liczy `AccessService::licenceUsage()`, czyli ta sama funkcja, co `/licencja`**
     * (zlecenie C3, decyzja z 21.09.2026): licencja liczy DOSTĘPY, nie konta — więc osoba
     * z dwoma zatrudnieniami zajmuje dwie. Dlatego „zajmie X" to suma firm z zatrudnienia
     * po wybranych osobach, a nie ich liczba.
     *
     * @param list<int> $wybrane
     * @return array<string, mixed>
     */
    private function podgladZaproszen(array $wybrane, ?int $companyId, bool $nadajDostep): array
    {
        $osoby = $this->people->forInvitation($wybrane, $companyId);
        $doWysylki = [];
        $bezAdresu = 0;
        $zKontem = 0;
        foreach ($wybrane as $personId) {
            $osoba = $osoby[$personId] ?? null;
            if ($osoba === null) {
                // Spoza zakresu albo osoby nie ma — do okna nie wchodzi i nie liczy się nigdzie.
                continue;
            }
            if ($osoba['konto_status'] !== null
                && $osoba['konto_status'] !== AccountRepository::STAN_NIEZAPROSZONE) {
                $zKontem++;
                continue;
            }
            if ((string) ($osoba['email'] ?? '') === '') {
                $bezAdresu++;
                continue;
            }
            $firm = count($osoba['spolki']);
            $doWysylki[] = [
                'person_id' => $personId,
                'name' => $osoba['name'],
                'email' => (string) $osoba['email'],
                // ⚠ Sama LICZBA firm, bez nazw (zlecenie C2): nazwy rozpychałyby wiersz okna,
                // a pytanie brzmi „ile licencji", nie „które firmy".
                'firm' => $firm,
                'firm_opis' => $firm . ' ' . Odmiana::firmy($firm),
            ];
        }
        $potrzeba = 0;
        if ($nadajDostep) {
            foreach ($doWysylki as $w) {
                $potrzeba += $w['firm'];
            }
        }
        $licencja = $this->access->licenceUsage();
        $wolne = $licencja['limit'] === null
            ? null
            : max(0, (int) $licencja['limit'] - (int) $licencja['uzyte']);

        $ile = count($doWysylki);
        // ⚠ `null` w `wolne` znaczy licencję BEZ limitu — wtedy nie brakuje nigdy i ostrzeżenia
        // nie ma (zlecenie C2). Zero to inna rzecz niż brak limitu i nie wolno ich zlepić.
        $brakuje = AccessService::brakujeLicencji(
            $potrzeba,
            $licencja['limit'] === null ? null : (int) $licencja['limit'],
            (int) $licencja['uzyte'],
        );

        return [
            'osoby' => $doWysylki,
            // ⚠ **W ukrytych polach okna jedzie CAŁE zaznaczenie, nie tylko osoby z listy** —
            // i to jest poprawka z pomiaru, nie ozdoba. Przełącznik dostępu dociąga okno od nowa
            // tymi polami; przy samych „do wysyłki" osoby bez adresu wypadały z żądania, więc
            // zdanie „2 osoby pominięto — brak adresu e-mail" ZNIKAŁO po pierwszym kliknięciu
            // (zmierzone 24.10.2026). Przy wysyłce te osoby i tak odsiewa `inviteBatch()`
            // z własnym powodem — a ekran wyniku mówi o nich tak jak dotąd.
            'wybrane' => $wybrane,
            'ile' => $ile,
            'bez_adresu' => $bezAdresu,
            'z_kontem' => $zKontem,
            'nadaj_dostep' => $nadajDostep,
            'potrzeba' => $potrzeba,
            'licencja' => $licencja,
            'wolne' => $wolne,
            'brakuje' => $brakuje,
            // ⚠ **Zdania składa KONTROLER, nie szablon** (CLAUDE.md §5): każde z nich odmienia
            // rzeczownik przez liczbę, a polska odmiana ma w tym repozytorium jedno miejsce
            // (`Support\Odmiana`). Szablon z `? :` po liczbie byłby drugim takim miejscem.
            // ⚠ Liczba czasownika idzie z `Odmiana`, nie z `$ile === 1`: po dopełniaczu mnogim
            // („0 osób", „25 osób") orzeczenie jest POJEDYNCZE. Zmierzone na oknie wysyłki.
            // ⚠ Przy zerze zdanie z liczbą nie jest zdaniem o niczym — jest zdaniem, które każe
            // się domyślać („0 osób dostanie e-mail" brzmi jak stan przejściowy). Mówimy wprost,
            // co z tego wynika, a liczby odsianych stoją w zdaniach niżej.
            'zdanie_lead' => $ile === 0
                ? 'Nikt z zaznaczonych nie dostanie zaproszenia.'
                : $ile . ' ' . Odmiana::osoby($ile)
                    . (Odmiana::czasownikMnogi($ile) ? ' dostaną' : ' dostanie')
                    . ' e-mail z odnośnikiem do ustawienia hasła.',
            'zdanie_bez_adresu' => $bezAdresu === 0
                ? ''
                // ⚠ BIERNIK, nie mianownik: „pominięto kogo?". Pierwsza wersja składała
                // „1 osoba pominięto" — mianownik różni się właśnie w liczbie pojedynczej.
                // Samo „pominięto" jest nieosobowe i nie odmienia się wcale.
                : $bezAdresu . ' ' . Odmiana::osobyBiernik($bezAdresu) . ' pominięto — brak adresu e-mail.',
            'zdanie_z_kontem' => $zKontem === 0
                ? ''
                // ⚠ Mianownik (to podmiot) plus liczba czasownika z `Odmiana`: „1 osoba ma",
                // „2 osoby mają", „5 osób ma". Warunek po `=== 1` dawał „2 osoby ma".
                : $zKontem . ' ' . Odmiana::osoby($zKontem)
                    . (Odmiana::czasownikMnogi($zKontem) ? ' mają' : ' ma')
                    . ' już konto — zaproszenia nie wysyła się drugi raz.',
            // Zaznaczony: ile zajmie i ile zostanie (resztę pokazujemy TYLKO przy limicie).
            'zdanie_licencji' => $nadajDostep
                ? 'Zajmie ' . $potrzeba . ' ' . Odmiana::licencje($potrzeba)
                    . ($licencja['limit'] === null
                        ? '.'
                        : ' — zostanie ' . max(0, ($wolne ?? 0) - $potrzeba)
                            . ' z ' . $licencja['limit'] . '.')
                : 'Osoby zalogują się, ale nie zobaczą żadnych danych, dopóki nie nadasz '
                    . 'dostępu ręcznie.',
            'zdanie_braku' => $brakuje === 0
                ? ''
                // ⚠ **„Potrzeba" rządzi DOPEŁNIACZEM, a ten jest niezmienny: „licencji"** —
                // i dlatego nie ma tu `Odmiana::licencje()`, choć liczba stoi obok. Biernik
                // („Potrzeba 23 licencje") jest błędem, który widać na ostrzeżeniu o licencjach,
                // czyli w zdaniu czytanym najuważniej. Ta sama pułapka, co `osobyDopelniacz()`:
                // o formie decyduje POZYCJA W ZDANIU, nie liczebnik.
                : 'Potrzeba ' . $potrzeba . ' licencji, wolnych jest '
                    . ($wolne ?? 0) . '. Brakuje ' . $brakuje . ' — zmniejsz wybór, wyślij bez '
                    . 'dostępu albo rozszerz licencję.',
            'przycisk' => 'Wyślij ' . $ile . ' ' . Odmiana::zaproszenia($ile),
        ];
    }

    public function inviteBatch(Request $request, array $params): Response
    {
        $admin = $request->attribute(Authenticate::USER);
        assert($admin instanceof CurrentUser);
        $companyId = $this->scope($admin);
        $wybrane = self::selectedIds($request);
        // ⚠ Przełącznik „nadaj też dostęp" jest domyślnie włączony w formularzu, ale decyzję
        // czyta się z tego, co PRZYSZŁO. Brak pola znaczy „nie nadawaj" (CLAUDE.md §10:
        // domyślnie odmawiaj), a nie „weź domyślną wartość z szablonu".
        $nadajDostep = $request->postString('nadaj_dostep', 4) === '1';

        // ⚠ **Bramka licencji PRZED pierwszym zaproszeniem** (zlecenie Z84 C2: „serwer też
        // odmawia"). Bez niej partia przechodziłaby do połowy: konta powstają i poczta wychodzi,
        // a dostępy zaczynają się odbijać od limitu w środku pętli — człowiek dostaje wtedy
        // zaproszonych ludzi BEZ dostępu i musi odkręcać to ręcznie. Liczy to samo, co okno
        // potwierdzenia (`podgladZaproszen()`), bo to musi być jedna liczba.
        // ⚠ Wyłączony przełącznik dostępu nie zajmuje licencji, więc wtedy bramki nie ma.
        $podglad = $this->podgladZaproszen($wybrane, $companyId, $nadajDostep);
        if ($podglad['brakuje'] > 0) {
            $odmowa = [
                'zaproszono' => 0,
                'z_dostepem' => 0,
                'dostepow' => 0,
                // ⚠ Ten sam klucz, co w wyniku udanej partii: `strict_variables` w Twigu
                // wywala render na brakującej zmiennej, a ten fragment jest jeden dla obu dróg.
                'zdanie_dostepow' => '',
                'pominieto' => array_map(static fn (array $o): array => [
                    'osoba' => $o['name'], 'powod' => AccessService::POWOD_BRAK_LICENCJI,
                ], $podglad['osoby']),
                'wybranych' => count($wybrane),
                'powrot' => $this->urls->to('/admin/konta'),
                'termin' => $this->termin->stan(),
            ];

            return $request->isHtmx()
                ? $this->view->render('fragments/invite-result.twig', $odmowa)
                : $this->view->render('page/web/invite-result.twig', $odmowa);
        }

        $osoby = $this->people->forInvitation($wybrane, $companyId);
        $zaproszono = 0;
        $osobyZDostepem = 0;
        $dostepow = 0;
        $pominieto = [];
        foreach ($wybrane as $personId) {
            $osoba = $osoby[$personId] ?? null;
            if ($osoba === null) {
                // Spoza zakresu albo osoby nie ma — nie mówimy, która to z tych dwóch rzeczy.
                continue;
            }
            // ⚠ „Ma już konto" sprawdzamy TUTAJ, przed adresem. Zmierzone: osoba z kontem ma adres
            // w `account.email`, a nie w `person.email_private`, więc bez tego warunku wynik mówił
            // „brak adresu e-mail" komuś, kto konto ma — i administrator szukałby adresu zamiast
            // zobaczyć prawdziwy powód. Sprawdzenie w `inviteExisting()` zostaje: ono łapie
            // wyścig z drugim administratorem, to tutaj podaje właściwy powód.
            //
            // ⚠ Konto `niezaproszone` NIE jest powodem odmowy (§4 zlecenia): zaproszenie takiego
            // konta nie zakłada, tylko przestawia jego stan i dopisuje adres. Zmierzone przed
            // poprawką: partia odrzucała „ma już konto" każdemu, komu wcześniej nadano dostęp,
            // czyli dokładnie tym osobom, dla których ten stan powstał.
            if ($osoba['konto_status'] !== null && $osoba['konto_status'] !== AccountRepository::STAN_NIEZAPROSZONE) {
                $pominieto[] = ['osoba' => $osoba['name'], 'powod' => InvitationService::POWOD_MA_KONTO];
                continue;
            }
            $wynik = $this->invitations->inviteExisting($personId, (string) ($osoba['email'] ?? ''), $admin->accountId());
            if ($wynik['powod'] !== null) {
                $pominieto[] = ['osoba' => $osoba['name'], 'powod' => $wynik['powod']];
                continue;
            }
            $zaproszono++;
            if (!$nadajDostep || $osoba['spolki'] === []) {
                continue;
            }
            // ⚠ `addCompanyAccess`, nie `saveCompanyAccess`: przełącznik mówi „nadaj TEŻ dostęp
            // do spółek z zatrudnienia", a nie „ustaw dostęp dokładnie na te spółki". Konto
            // `niezaproszone` mogło już mieć dostęp do spółki, w której etat wygasł — partia
            // zaproszeń nie jest miejscem, w którym taki dostęp ma cicho zniknąć.
            $dostep = $this->access->addCompanyAccess($personId, $osoba['spolki'], $admin->accountId());
            foreach ($dostep['odmowy'] as $powod) {
                // Osoba jest już zaproszona — dostęp to osobna odmowa, więc mówimy o niej osobno.
                $pominieto[] = ['osoba' => $osoba['name'], 'powod' => $powod];
            }
            $dostepow += count($dostep['nadane']);
            $osobyZDostepem += $dostep['nadane'] === [] ? 0 : 1;
        }

        $dane = [
            'zaproszono'  => $zaproszono,
            'z_dostepem'  => $osobyZDostepem,
            'dostepow'    => $dostepow,
            // ⚠ Zdanie w nawiasie składa KONTROLER (CLAUDE.md §5): odmienia „dostęp" przez
            // liczbę, a w szablonie stało bez odmiany — „2 dostępów do spółek" miało i złą
            // formę, i słowo zakazane w interfejsie (zlecenie Z86).
            'zdanie_dostepow' => $dostepow === $osobyZDostepem
                ? ''
                : '(' . $dostepow . ' ' . Odmiana::dostepy($dostepow) . ' do firm)',
            'pominieto'   => $pominieto,
            'wybranych'   => count($wybrane),
            'powrot'      => $this->urls->to('/admin/konta'),
            // ⚠ „Przyjęto do wysyłki" bez terminu jest pustym zdaniem — ekran mówi KIEDY,
            // z faktycznej częstotliwości crona tej instalacji (zlecenie §2).
            'termin'      => $this->termin->stan(),
        ];

        // Fragment do okna modalnego, pełny ekran dla przeglądarki bez JavaScriptu — ten sam
        // szablon treści w obu przypadkach (wzorzec z `fragments/confirm.twig`).
        return $request->isHtmx()
            ? $this->view->render('fragments/invite-result.twig', $dane)
            : $this->view->render('page/web/invite-result.twig', $dane);
    }

    /**
     * Identyfikatory osób z pola wyboru — białą listą typów, z limitem długości partii.
     *
     * @return list<int>
     */
    private static function selectedIds(Request $request): array
    {
        // ⚠ **Ta sama biała lista dla `POST`-a wysyłki i dla `GET`-a potwierdzenia** (Z84 C1):
        // krok potwierdzenia musi rozumieć wybór DOKŁADNIE tak samo jak wysyłka, bo pokazuje
        // liczby, które wysyłka ma potem osiągnąć. Drugi parser znaczyłby okno liczące z innego
        // zestawu osób niż ten, który pójdzie do zapisu.
        $surowe = $request->method() === 'POST' ? $request->post('osoby') : $request->query('osoby');
        if (!is_array($surowe)) {
            return [];
        }
        $out = [];
        foreach ($surowe as $wartosc) {
            $id = filter_var($wartosc, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id !== false && !in_array($id, $out, true)) {
                $out[] = $id;
            }
            if (count($out) >= self::BATCH_MAX) {
                break;
            }
        }

        return $out;
    }

    /**
     * ⚠ Górny limit partii. Powód nie jest ozdobny: jedna osoba to zaproszenie plus zapytanie
     * o dostęp na spółkę, a `max_execution_time` na hostingu bywa 30 s (CLAUDE.md §0). Rozmiar
     * strony listy to 25, więc jedno zaznaczenie „wszystkich na ekranie" mieści się z zapasem.
     */
    private const BATCH_MAX = 50;

    private function scope(CurrentUser $admin): ?int
    {
        return $this->permissions->hasTenantWideRole($admin->accountId())
            ? null
            : ($admin->activeEmployment()?->companyId ?? -1);
    }

    /** Jeden wiersz listy po zmianie — stan czytany z bazy. */
    private function rowFragment(int $personId, ?int $companyId, CurrentUser $admin): Response
    {
        $row = $this->people->rowForAdmin($personId, $companyId);
        if ($row === null) {
            return $this->errors->errorPage(404, null, true);
        }
        return $this->view->render('fragments/person-row.twig', [
            'o'          => $row,
            'current_id' => $admin->accountId(),
            'brak_licencji' => $this->brakWolnejLicencji(),
        ]);
    }

    /**
     * Numer strony z zapytania, przycięty do istniejącego zakresu.
     *
     * @return array{numer: int, stron: int, offset: int, razem: int}
     */
    private static function page(Request $request, int $total, int $size): array
    {
        $pages = max(1, (int) ceil($total / $size));
        $number = filter_var($request->query('strona'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => $pages]]);
        $number = $number === false ? 1 : $number;

        return ['numer' => $number, 'stron' => $pages, 'offset' => ($number - 1) * $size, 'razem' => $total];
    }

    /** Zakończenie wszystkich sesji konta (odejście pracownika, podejrzenie przejęcia). */
    public function revokeSessions(Request $request, array $params): Response
    {
        $admin = $request->attribute(Authenticate::USER);
        assert($admin instanceof CurrentUser);
        $targetId = $this->targetInScope($admin, $params);
        if ($targetId === null) {
            return $this->errors->errorPage(404, null, $request->isHtmx());
        }
        $this->devices->revokeAllByAdmin($targetId, $admin->accountId(), $admin->displayName, $this->fingerprint->ipHash($request->serverParams()));

        return Response::redirectFor($request, $this->urls->to('/admin/konta') . '?zakonczono=' . $targetId);
    }

    /** @param array<string, string> $params */
    public function resetTwoFactor(Request $request, array $params): Response
    {
        $admin = $request->attribute(Authenticate::USER);
        assert($admin instanceof CurrentUser);
        $targetId = $this->targetInScope($admin, $params);
        if ($targetId === null || !$this->twoFactor->resetByAdmin(
            $targetId,
            $admin->accountId(),
            $admin->displayName,
            $this->fingerprint->ipHash($request->serverParams()),
        )) {
            return $this->errors->errorPage(404, null, $request->isHtmx());
        }

        return Response::redirectFor($request, $this->urls->to('/admin/konta') . '?zresetowano=' . $targetId);
    }

    /**
     * Zakres: administrator całego tenanta — dowolne konto; administrator firmy — tylko konta tej firmy.
     *
     * @param array<string, string> $params
     */
    private function targetInScope(CurrentUser $admin, array $params): ?int
    {
        $targetId = filter_var($params['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($targetId === false) {
            return null;
        }
        $inScope = $this->permissions->hasTenantWideRole($admin->accountId())
            || ($admin->activeEmployment() !== null
                && $this->permissions->accountInCompany($targetId, $admin->activeEmployment()->companyId));

        return $inScope ? $targetId : null;
    }
}
