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
use Wezurlop\Support\ErrorHandler;
use Wezurlop\Support\Odmiana;
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
    ) {
    }

    /**
     * Komunikaty panelu osoby po udanym zapisie — **biała lista kluczy** (CLAUDE.md §11):
     * do adresu trafia klucz, nigdy treść.
     *
     * @var array<string, string>
     */
    private const KOMUNIKATY_PANELU = [
        'dostep' => 'Dostęp do firm zapisany. Rola pracownika obowiązuje w zaznaczonych firmach.',
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
        AccountService::POWOD_OSTATNI_ADMIN => 'To jedyne aktywne konto administratora tenanta. '
            . 'Najpierw utwórz drugie i poczekaj, aż jego właściciel ustawi hasło — konto zaproszone, '
            . 'które nigdy nie zostało aktywowane, nie jest zabezpieczeniem.',
        AccountService::POWOD_NIE_MA => 'Stan tego konta zmienił się w trakcie — odśwież panel.',
    ];

    /** Rozmiar strony — ten sam co na `/dane` (`DataController::PAGE`), żeby nie były dwie prawdy. */
    private const PAGE = 25;

    /** Sortowanie z białej listy (CLAUDE.md §11). */
    private const SORT = ['nazwisko', 'email', 'konto'];

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

        $total = $this->people->countForAdmin($companyId, $search, $filtr);
        $page = self::page($request, $total, self::PAGE);

        $osoby = $this->people->listForAdmin($companyId, $search, $sort, $asc, self::PAGE, $page['offset'], $filtr);

        $utworzono = filter_var($request->query('utworzono'), FILTER_VALIDATE_INT);
        $reset = filter_var($request->query('zresetowano'), FILTER_VALIDATE_INT);
        $revoked = filter_var($request->query('zakonczono'), FILTER_VALIDATE_INT);

        return $this->view->render('page/web/admin-accounts.twig', [
            'osoby'       => $osoby,
            'strona'      => $page,
            'szukaj'      => $search,
            'sortuj'      => $sort,
            'kierunek'    => $asc ? 'asc' : 'desc',
            'stany'       => $this->people->stateCounters($companyId, $filtr),
            'licencja'    => $this->access->licenceUsage(),
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
                return $this->panelZBledem($personId, $companyId, $admin, self::KOMUNIKATY_ODMOW[$odmowa] ?? $odmowa);
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
        ]);
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
            return $this->panelZBledem($personId, $companyId, $admin, self::KOMUNIKATY_ODMOW[$powod] ?? $powod);
        }

        $dane = $this->panelData($personId, $companyId, $admin);
        $dane['notice'] = $zablokowane
            ? 'Konto odblokowane. Dostępy do spółek wróciły razem z nim.'
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
        $dane = ['error' => null, 'wpisane' => [], 'w_oknie' => $request->isHtmx()];

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
        if ($wpisane['telefon'] !== '' && preg_match('/^[0-9+()\s-]{6,32}$/', $wpisane['telefon']) !== 1) {
            return $this->formularzZBledem($request, 'Numer telefonu może zawierać tylko cyfry, spacje, plus, myślnik i nawiasy.', $wpisane);
        }

        try {
            $accountId = $this->invitations->invite($wpisane['imie'], $wpisane['nazwisko'], $wpisane['email'], $admin->accountId());
        } catch (InvalidArgumentException $e) {
            // Komunikat pochodzi z usługi i mówi konkretnie, co jest nie tak (adres, imię, duplikat).
            return $this->formularzZBledem($request, $e->getMessage(), $wpisane);
        }
        if ($wpisane['telefon'] !== '') {
            $konto = $this->accounts->findById($accountId);
            if ($konto !== null) {
                $this->people->updateContact($konto['person_id'], null, $wpisane['telefon'], null);
            }
        }

        // ⚠ Po udanym POST przekierowanie (CLAUDE.md §3). Przy htmx nagłówkiem `HX-Redirect`,
        // nie `Location` — inaczej przeglądarka wstawiłaby całą stronę do okna modalnego.
        $cel = $this->urls->to('/admin/konta') . '?utworzono=' . $accountId;

        return $request->isHtmx()
            ? Response::html('', 204)->withHeader('HX-Redirect', $cel)
            : Response::redirectFor($request, $cel);
    }

    /** @param array<string, string> $wpisane */
    private function formularzZBledem(Request $request, string $blad, array $wpisane): Response
    {
        $dane = ['error' => $blad, 'wpisane' => $wpisane, 'w_oknie' => $request->isHtmx()];

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
        $dane['notice'] = self::KOMUNIKATY_PANELU[$request->queryString('ok', 16)] ?? null;

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

        $email = AuthService::normalizeEmail($request->postString('email', 254));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return $this->panelZBledem($personId, $companyId, $admin, 'Nieprawidłowy adres e-mail.',
                ['email' => $request->postString('email', 254), 'telefon' => $request->postString('telefon', 32)]);
        }
        // ⚠ Telefon: biała lista znaków, nie czarna. Cyfry, spacje, `+`, `-` i nawiasy —
        // wszystko inne odrzucamy, zamiast „czyścić" i zapisywać coś, czego nikt nie wpisał.
        $telefon = trim($request->postString('telefon', 32));
        if ($telefon !== '' && preg_match('/^[0-9+()\s-]{6,32}$/', $telefon) !== 1) {
            return $this->panelZBledem($personId, $companyId, $admin,
                'Numer telefonu może zawierać tylko cyfry, spacje, plus, myślnik i nawiasy.',
                ['email' => $email, 'telefon' => $telefon]);
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
                ['telefon' => $telefon]);
        }
        $odmowa = $this->odmowaAdresu($email, $personId, $companyId);
        if ($odmowa !== null) {
            return $this->panelZBledem($personId, $companyId, $admin, $odmowa,
                ['email' => $email, 'telefon' => $telefon]);
        }
        if ($zmianaLoginu) {
            $powod = $this->invitations->changeEmail(
                (int) $row['account_id'], $email, $admin->accountId(), $admin->displayName,
            );
            if ($powod !== null) {
                return $this->panelZBledem($personId, $companyId, $admin, ucfirst($powod) . '.',
                    ['email' => $email, 'telefon' => $telefon]);
            }
        }
        $adresDoZapisu = $przedZaproszeniem ? ($email === '' ? null : $email) : ($zmianaLoginu ? $email : $row['email']);
        try {
            if (!$this->people->updateContact($personId, $adresDoZapisu, $telefon === '' ? null : $telefon, $companyId)) {
                return $this->errors->errorPage(404, null, $request->isHtmx());
            }
        } catch (AdresZajetyException) {
            return $this->panelZBledem($personId, $companyId, $admin,
                $this->odmowaAdresu($email, $personId, $companyId) ?? AdresKontaktowy::ODMOWA_BEZ_NAZWISKA,
                ['email' => $email, 'telefon' => $telefon]);
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

        $dane = $this->panelData($personId, $companyId, $admin);
        $dane['notice'] = 'Zapisane.';

        return $request->isHtmx()
            ? $this->view->render('fragments/person-panel.twig', $dane + ['w_szufladzie' => true])
            : Response::redirectFor($request, $this->urls->to('/admin/konta'));
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
            return $this->panelZBledem($personId, $companyId, $admin, ucfirst($powod) . '.');
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
            'firmy'      => $this->people->companiesForAccess($personId),
            'licencja'   => $this->access->licenceUsage(),
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

    private function panelZBledem(int $personId, ?int $companyId, CurrentUser $admin, string $blad, array $wpisane = []): Response
    {
        $dane = $this->panelData($personId, $companyId, $admin);
        if ($dane === null) {
            return $this->errors->errorPage(404, null, true);
        }
        $dane['error'] = $blad;
        $dane['wpisane'] = $wpisane;

        return $this->view->render('fragments/person-panel.twig', $dane + ['w_szufladzie' => true]);
    }

    /**
     * Rozwinięta kontrolka dostępu — lista wszystkich spółek tenanta (§3 zlecenia).
     *
     * ⚠ Treść dociągana po otwarciu, nie renderowana w każdym wierszu z góry. Dwa powody:
     * strona listy przestaje rosnąć o 25 × liczba spółek, a panel pokazuje **stan z bazy**
     * w chwili otwarcia, nie z chwili wygenerowania strony.
     *
     * @param array<string, string> $params
     */
    public function accessPanel(Request $request, array $params): Response
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

        return $this->view->render('fragments/access-panel.twig', [
            'o'        => $row,
            'spolki'   => $this->people->companiesForAccess($personId),
            'licencja' => $this->access->licenceUsage(),
        ]);
    }

    /**
     * Zapis dostępu z kontrolki — jawny, jednym żądaniem dla całej zmiany (§3 zlecenia).
     *
     * ⚠ Nie zapisujemy po kliknięciu pola wyboru: klikanie po kolei czterech spółek to cztery
     * zapisy, cztery wpisy w dzienniku i cztery sprawdzenia limitu, z których trzy środkowe
     * nic nie znaczą.
     *
     * ⚠ Wyłączone pole wyboru w HTML jest podpowiedzią, nie zabezpieczeniem — każdą spółkę
     * sprawdza od nowa `AccessService` i odrzuca dostęp bez etatu oraz ponad limit licencji.
     *
     * @param array<string, string> $params
     */
    public function saveAccess(Request $request, array $params): Response
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
        if ($row['caly_tenant']) {
            // Administrator tenanta obejmuje wszystkie spółki, także przyszłe — odznaczenie
            // jednej nie ma reprezentacji w modelu, więc nie udajemy, że ma.
            return Response::html('', 409);
        }

        // ⚠ Konto może jeszcze nie istnieć — i to jest normalna ścieżka od §4: pierwsze zaznaczenie
        // spółki zakłada konto w stanie `niezaproszone`. Decyzję o tym, o licencji i o etacie
        // podejmuje `AccessService` w JEDNEJ transakcji; kontroler podaje osobę i zestaw spółek.
        $wynik = $this->access->saveCompanyAccess($personId, self::selectedCompanies($request), $admin->accountId());
        $odmowy = [];
        foreach ($wynik['odmowy'] as $powod) {
            $odmowy[$powod] = self::KODY_ODMOW[$powod] ?? 'odmowa';
        }

        // ⚠ **Skąd przyszedł zapis, mówi POLE ŻĄDANIA, nie nagłówek i nie zgadywanie** (zlecenie
        // Z58 §3). Ta sama trasa obsługuje dwie kontrolki: pole firm w panelu osoby i kontrolkę
        // w wierszu listy — i każda potrzebuje w odpowiedzi czegoś innego (panelu albo wiersza).
        // Bez tego panel dostawał w odpowiedzi wiersz tabeli i wstawiał go sobie w środek.
        if ($request->post('panel') !== null) {
            if (!$request->isHtmx()) {
                // Bez JavaScriptu: zwykły POST/Redirect/GET na pełny ekran panelu (CLAUDE.md §3).
                return Response::redirectFor($request,
                    $this->urls->to('/admin/konta/osoba/' . $personId) . '?ok=dostep');
            }
            $dane = $this->panelData($personId, $companyId, $admin);
            if ($dane === null) {
                return $this->errors->errorPage(404, null, true);
            }
            $dane['notice'] = self::KOMUNIKATY_PANELU['dostep'];
            $odp = $this->view->render('fragments/person-panel.twig', $dane + ['w_szufladzie' => true]);

            return $odmowy === []
                ? $odp
                : $odp->withHeader('X-Wezurlop-Odmowa', implode(',', array_values($odmowy)));
        }

        $odp = $this->rowFragment($personId, $companyId, $admin);

        // ⚠ Odmowa nie może przejść niezauważona: wiersz pokazuje stan z bazy, ale sam
        // niezmieniony wiersz wygląda jak „nic się nie stało". Nagłówek niesie KOD, nie tekst
        // i nie nazwę spółki. Dwa powody: nazwa spółki to dane użytkownika (§8 zlecenia — wpisuje
        // ją administrator), a dane użytkownika w nagłówku to droga do wstrzyknięcia nagłówka;
        // po drugie zmierzone — polskie znaki w nagłówku HTTP wychodzą jako kropki.
        // Tekst komunikatu mieszka w JS, tam gdzie reszta komunikatów (`flash.js`).
        return $odmowy === []
            ? $odp
            : $odp->withHeader('X-Wezurlop-Odmowa', implode(',', array_values($odmowy)));
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
    private const KODY_ODMOW = [
        AccessService::POWOD_BRAK_ZATRUDNIENIA => 'brak_etatu',
        AccessService::POWOD_BRAK_LICENCJI     => 'brak_licencji',
        AccessService::POWOD_KONTO_ADMINA      => 'konto_admina',
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
        $surowe = $request->post('osoby');
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
