<?php

declare(strict_types=1);

namespace Wezurlop\Controller\App;

use InvalidArgumentException;
use Wezurlop\Entity\CurrentUser;
use Wezurlop\Http\Middleware\Authenticate;
use Wezurlop\Http\Request;
use Wezurlop\Http\Response;
use Wezurlop\Http\Session;
use Wezurlop\Repository\ApiKeyRepository;
use Wezurlop\Repository\EventLogRepository;
use Wezurlop\Repository\SyncDomainRepository;
use Wezurlop\Repository\SyncRepository;
use Wezurlop\Repository\TenantSettingRepository;
use Wezurlop\Service\Sync\ApiKeyService;
use Wezurlop\Service\Sync\SyncService;
use Wezurlop\Support\Cisza;
use Wezurlop\Support\ErrorHandler;
use Wezurlop\Support\Nip;
use Wezurlop\Support\Strefa;
use Wezurlop\Support\UrlGenerator;
use Wezurlop\Support\View;

/**
 * /admin/integracja — klucze instalacji connectora, zgłoszenia firm, strumienie i przebiegi synchronizacji.
 * Uprawnienie `integration.manage` działa wyłącznie z przypisania na całego tenanta (permission.tenant_only)
 * — menu i trasa sprawdzają dokładnie to samo, bez dodatkowych warunków tutaj.
 *
 * ⚠ Jawny klucz trafia do sesji (a sesja jest w bazie) WYŁĄCZNIE na czas przekierowania po POST:
 * odczyt jednorazowy (usunięty z sesji przy renderowaniu), TTL 5 minut na wypadek, gdyby nikt strony
 * nie otworzył. Odświeżenie pokazuje „klucz był już wyświetlony”; drogą po nowy jest wygenerowanie
 * następnego (poprzedni unieważniony od razu). W bazie jest wyłącznie SHA-256.
 */
final class AdminIntegrationController
{
    private const NEW_KEY = 'admin.api_key_new';
    private const SHOWN_KEY = 'admin.api_key_shown';
    private const KEY_TTL_SECONDS = 300;
    private const NOTICES = [
        'firmy'       => 'Zakres klucza zapisany.',
        'uniewazniony' => 'Klucz unieważniony — connector z tym kluczem dostanie 401 przy następnym żądaniu.',
        'skasowany'   => 'Unieważniony klucz skasowany — zniknął z wykazu. W dzienniku zdarzeń został wpis z jego nazwą i prefiksem.',
        'wydany'      => 'Klucz wydany. Skopiuj go i wklej w konfiguracji connectora — pokazujemy go tylko teraz.',
        'wymieniony'  => 'Nowy klucz wydany. Skopiuj go i wklej w connectorze — dotychczasowy działa do chwili, w której nowy po raz pierwszy zakończy przebieg.',
        'zatwierdzona' => 'Firma zatwierdzona. Wróć do connectora i uruchom test połączenia ponownie — dane zaczną się synchronizować.',
        'odrzucona'   => 'Zgłoszenie odrzucone — connector przestanie zgłaszać tę firmę do czasu cofnięcia decyzji.',
        'przywrocona' => 'Odrzucenie cofnięte — zgłoszenie znów czeka na decyzję.',
        'przeglad'    => 'Przegląd kontrolny zostanie wykonany w następnym przebiegu connectora.',
        'reset'       => 'Instalacja wyczyszczona — jesteś na początku ścieżki podłączenia. Wygeneruj klucz i wklej go w konfiguracji connectora.',
        'tozsamosc'   => 'Nowy odcisk tożsamości zaakceptowany — strumień tej firmy odblokowany. Osoby przyjdą pod nowymi identyfikatorami, więc powiązania trzeba będzie odtworzyć.',
    ];

    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly ApiKeyService $service,
        private readonly ApiKeyRepository $keys,
        private readonly SyncRepository $sync,
        private readonly SyncDomainRepository $domain,
        private readonly EventLogRepository $events,
        private readonly TenantSettingRepository $settings,
        private readonly ErrorHandler $errors,
        private readonly UrlGenerator $urls,
    ) {
    }

    /** @param array<string, string> $params */
    public function index(Request $request, array $params): Response
    {
        if ($this->admin($request) === null) {
            return $this->errors->errorPage(404, null, $request->isHtmx());
        }

        $notice = $request->query('reset') !== null ? 'reset' : (string) $request->query('ok');

        return $this->render(null, 200, $notice);
    }

    /**
     * Kreator podłączenia jako osobny ekran: cztery kroki, zgłoszenia firm do zatwierdzenia i stan
     * połączenia w jednym miejscu. Pełny ekran integracji (klucze, strumienie, przebiegi) zostaje osobno —
     * tam jest zarządzanie, tu prowadzenie za rękę przy pierwszym podłączeniu.
     *
     * @param array<string, string> $params
     */
    public function connect(Request $request, array $params): Response
    {
        if ($this->admin($request) === null) {
            return $this->errors->errorPage(404, null, $request->isHtmx());
        }
        // Klucz pokazywany raz — czytany TU, a nie w odpytywanym fragmencie: inaczej pierwsze
        // odpytanie stanu zjadłoby klucz, zanim administrator zdążyłby go skopiować.
        $new = $this->session->get(self::NEW_KEY);
        $this->session->set(self::NEW_KEY, null);
        if (is_array($new) && (int) ($new['at'] ?? 0) + self::KEY_TTL_SECONDS < time()) {
            $new = null;
        }

        return $this->view->render('page/web/connect.twig', $this->connectData() + [
            'notice'  => self::NOTICES[(string) $request->query('ok')] ?? null,
            'new_key' => is_array($new) ? ['key' => (string) $new['key']] : null,
        ]);
    }

    /**
     * Stan kreatora jako fragment — to samo, co widać na ekranie, policzone od nowa z bazy.
     * ⚠ Pełnoprawny endpoint: uprawnienie sprawdza ta sama trasa co ekran (docs/api-connector.md nie
     * ma tu nic do rzeczy — to reguła §5 o fragmentach htmx).
     *
     * @param array<string, string> $params
     */
    public function connectState(Request $request, array $params): Response
    {
        if ($this->admin($request) === null) {
            return $this->errors->errorPage(404, null, $request->isHtmx());
        }
        $data = $this->connectData();
        // ⚠ Odpytywanie ma SPRAWDZAĆ, czy coś przyszło, a nie przerysowywać ekran co pięć sekund.
        // Ten sam znacznik stanu co poprzednio → 204 i htmx niczego nie podmienia: nie mruga ekran,
        // nie znika tekst wpisywany w polu i nie leci niepotrzebny HTML.
        if ($request->queryString('znacznik', 64) === $data['znacznik']) {
            return Response::text('', 204);
        }

        return $this->view->render('fragments/connect-wizard.twig', $data);
    }

    /**
     * Dane kreatora — jedno źródło dla ekranu i dla odpytywanego fragmentu.
     *
     * @return array<string, mixed>
     */
    private function connectData(): array
    {
        $keys = $this->keys->all();
        $streams = $this->sync->streams();
        $summary = $this->sync->dataSummary();
        $signups = array_values(array_filter($this->keys->signups(), static fn (array $s): bool => $s['status'] === 'pending'));
        $lastConnection = null;
        foreach ($keys as $k) {
            $lastConnection = $k['last_used_at'] !== null && ($lastConnection === null || $k['last_used_at'] > $lastConnection)
                ? $k['last_used_at'] : $lastConnection;
        }
        $runs = $this->sync->recentRuns(1);
        $run = $runs[0] ?? null;

        $kreator = $this->wizard($keys, $signups, $streams, $summary, $lastConnection);
        // Znacznik stanu: zmienia się dokładnie wtedy, gdy zmienia się to, co widać w kreatorze.
        $znacznik = substr(hash('sha256', json_encode([
            array_map(static fn (array $k): string => $k['stan'], $kreator['kroki']),
            array_map(static fn (array $x): int => (int) $x['id'], $signups),
            $lastConnection,
            $summary['ostatnia_synchronizacja'],
            $summary['pracownicy'],
            $summary['nieobecnosci'],
            count($streams),
        ], JSON_THROW_ON_ERROR)), 0, 32);

        return [
            'kreator' => $kreator,
            'znacznik' => $znacznik,
            'signups' => $signups,
            'podsumowanie' => $summary,
            'ostatnie_polaczenie' => $lastConnection,
            'wersja_connectora' => $run === null || $run['connector_version'] === null ? null : (string) $run['connector_version'],
            'firmy' => array_map(static fn (array $s): array => ['id' => $s['id'], 'name' => $s['name'], 'nip' => $s['nip']], $streams),
            // Fragment renderuje się i z okna, i ze strony — wartości domyślne, bo Twig ma strict_variables.
            'nowy_klucz' => null,
            'komunikat' => null,
            'ma_klucz' => array_filter($keys, static fn (array $k): bool => $k['status'] === 'active') !== [],
            'klucz_id' => (array_values(array_filter($keys, static fn (array $k): bool => $k['status'] === 'active'))[0]['id'] ?? null),
        ];
    }

    /** @param array<string, string> $params */
    public function issue(Request $request, array $params): Response
    {
        $admin = $this->admin($request);
        if ($admin === null) {
            return $this->errors->errorPage(404, null, $request->isHtmx());
        }
        try {
            $key = $this->service->issue($request->postString('nazwa', 100), self::companyIds($request), $admin->accountId());
        } catch (InvalidArgumentException $e) {
            if (self::fromWizard($request)) {
                return $this->view->render('fragments/connect-wizard.twig',
                    array_merge($this->connectData(), ['komunikat' => $e->getMessage()]), 422);
            }

            return $this->render($e->getMessage(), 422);
        }

        return $this->showOnce($request, $key);
    }

    /**
     * Klucz był już wyświetlony (albo przepadł po 5 minutach) — nowy od razu, poprzedni unieważniony
     * (nikt go jeszcze nie wpisał do connectora albo został ujawniony).
     *
     * @param array<string, string> $params
     */
    public function regenerate(Request $request, array $params): Response
    {
        $admin = $this->admin($request);
        $id = self::id($params);
        $old = $id === null ? null : $this->keys->find($id);
        if ($admin === null || $old === null || $old['status'] !== 'active') {
            return $this->errors->errorPage(404, null, $request->isHtmx());
        }
        try {
            $key = $this->service->regenerate($id, $admin->accountId());
        } catch (InvalidArgumentException $e) {
            return $this->render($e->getMessage(), 422);
        }

        return $this->showOnce($request, $key);
    }

    /** @param array<string, string> $params */
    public function reopen(Request $request, array $params): Response
    {
        $admin = $this->admin($request);
        $id = self::id($params);
        if ($admin === null || $id === null || !$this->service->reopenSignup($id, $admin->accountId())) {
            return $this->errors->errorPage(404, null, $request->isHtmx());
        }

        return $this->done($request, 'przywrocona');
    }

    /** @param array{id: int, key: string} $key */
    private function showOnce(Request $request, array $key, string $notice = 'wydany'): Response
    {
        // ⚠ Kreator w oknie modalnym ma domknąć CAŁĄ ścieżkę: po wydaniu klucza wracamy fragmentem
        // do okna (z kluczem pokazanym raz), zamiast przerzucać administratora na osobny ekran.
        if (self::fromWizard($request)) {
            return $this->view->render('fragments/connect-wizard.twig',
                array_merge($this->connectData(), ['nowy_klucz' => $key['key']]));
        }
        $this->session->set(self::NEW_KEY, ['id' => $key['id'], 'key' => $key['key'], 'at' => time()]);

        // ⚠ Klucz jedzie przez sesję, a w adresie zostaje wyłącznie nazwa komunikatu: adresy trafiają
        // do logów serwera i proxy (CLAUDE.md §13, docs §5.7).
        return Response::redirectFor($request, $this->urls->to(self::backPath($request)) . '?ok=' . $notice);
    }

    /** Żądanie z kreatora obsługiwane przez htmx — odpowiedzią jest fragment, nie przekierowanie. */
    private static function fromWizard(Request $request): bool
    {
        return $request->isHtmx() && $request->postString('wroc', 16) === 'kreator';
    }

    /**
     * Skąd przyszedł formularz: kreator podłączenia czy pełny ekran integracji. ⚠ Wyłącznie biała lista
     * dwóch ścieżek — wartość z żądania nigdy nie trafia do `Location` (open redirect, CLAUDE.md §11).
     */
    private static function backPath(Request $request): string
    {
        return $request->postString('wroc', 16) === 'kreator' ? '/admin/podlaczenie' : '/admin/integracja';
    }

    /**
     * Krok potwierdzenia operacji nieodwracalnej (docs/system-wizualny.md §5.6).
     *
     * ⚠ `GET` POKAZUJE pytanie i nie zmienia niczego, `POST` wykonuje (CLAUDE.md §3). To osobny krok
     * po stronie serwera, a nie `confirm()` ani `hx-confirm`: potwierdzenie ma pokazać KONSEKWENCJE
     * — czyj to klucz, kiedy był używany i co przestanie działać — a tego okienko przeglądarki
     * nie pokaże, i znika razem z wyłączonym JavaScriptem.
     *
     * @param array<string, string> $params
     */
    public function confirmRevoke(Request $request, array $params): Response
    {
        $admin = $this->admin($request);
        $id = self::id($params);
        $key = $id === null ? null : $this->keyRow($id);
        if ($admin === null || $key === null) {
            return $this->errors->errorPage(404, null, $request->isHtmx());
        }

        return $this->confirmation($request, [
            'tytul' => 'Unieważnić klucz „' . $key['name'] . '”?',
            'wstep' => 'Connector, który używa tego klucza, dostanie 401 przy następnym żądaniu '
                . 'i synchronizacja kadr zatrzyma się natychmiast. Klucza nie da się przywrócić.',
            'szczegoly' => $this->keyDetails($key),
            'skutki' => [
                'Synchronizacja tej firmy zatrzymuje się od razu, bez okresu przejściowego.',
                'Żeby ją wznowić, trzeba wydać nowy klucz i wpisać go w konfiguracji connectora na serwerze klienta.',
                'Dane już przyjęte zostają — kursory i rekordy należą do firmy, nie do klucza.',
            ],
            'akcja' => '/admin/integracja/klucze/' . $key['id'] . '/uniewaznij',
            'przycisk' => 'Unieważnij klucz',
            'pola' => ['potwierdzam' => '1'],
        ]);
    }

    /**
     * Krok potwierdzenia skasowania unieważnionego klucza.
     *
     * ⚠ **Ekran mówi, co dokładnie zniknie, a co zostanie** — bo to operacja nieodwracalna
     * i jedyna w tym miejscu, której nie da się cofnąć unieważnieniem. Liczba przebiegów
     * pochodzi z pomiaru, nie z założenia: `sync_run.api_key_id` ma `ON DELETE SET NULL`,
     * więc przebiegi zostają, ale tracą wskazanie klucza.
     *
     * @param array<string, string> $params
     */
    public function confirmDelete(Request $request, array $params): Response
    {
        $admin = $this->admin($request);
        $id = self::id($params);
        // ⚠ Klucz czynny nie ma tego ekranu i nie dostaje 403 z wyjaśnieniem: 404, tak jak
        // wszędzie tutaj. Istnienie zasobu, którego nie wolno ruszyć, też jest informacją.
        // Warunek „unieważniony" siedzi w nazwie funkcji, nie w `if` poniżej.
        $key = $id === null ? null : $this->keyRowUniewazniony($id);
        if ($admin === null || $key === null) {
            return $this->errors->errorPage(404, null, $request->isHtmx());
        }
        $przebiegow = $this->keys->przebiegowKlucza((int) $key['id']);

        return $this->confirmation($request, [
            'tytul' => 'Skasować unieważniony klucz „' . $key['name'] . '”?',
            'wstep' => 'Klucz jest unieważniony i nic nim już nie uwierzytelnisz. Skasowanie usuwa '
                . 'go z tego wykazu na stałe — tej operacji nie da się cofnąć.',
            'szczegoly' => $this->keyDetails($key),
            'skutki' => array_values(array_filter([
                'Klucz znika z wykazu i z przypisań firm.',
                $przebiegow > 0
                    ? 'Przebiegi synchronizacji ZOSTAJĄ (' . $przebiegow . '), ale tracą informację, '
                        . 'który klucz je uwierzytelnił — daty, firma, statystyki i wersje connectora bez zmian.'
                    : 'Ten klucz nie uwierzytelnił ani jednego przebiegu, więc historia nic nie traci.',
                'W dzienniku zdarzeń zostaje wpis z nazwą i prefiksem klucza — ślad przeżywa wiersz.',
                'Unieważnienia to nie cofa i nie odzyskuje: nowy klucz wydaje się osobno.',
            ])),
            'akcja' => '/admin/integracja/klucze/' . $key['id'] . '/usun',
            'przycisk' => 'Skasuj klucz',
            'pola' => ['potwierdzam' => '1'],
        ]);
    }

    /** @param array<string, string> $params */
    public function confirmReplace(Request $request, array $params): Response
    {
        $admin = $this->admin($request);
        $id = self::id($params);
        $key = $id === null ? null : $this->keyRow($id);
        if ($admin === null || $key === null) {
            return $this->errors->errorPage(404, null, $request->isHtmx());
        }

        // ⚠ Klucz wydany i jeszcze nieużyty zginie przy wydaniu następnego — potwierdzenie ma to
        // powiedzieć PRZED wykonaniem, razem z datą jego wydania (zlecenie 21.09, model docelowy).
        $nieuzyty = $key['last_used_at'] === null;
        $skutki = $nieuzyty
            ? [
                'Dotychczasowy klucz nigdy nie był użyty przez connector — zostanie unieważniony od razu.',
                'Nowy klucz trzeba wpisać w konfiguracji connectora; bez niego synchronizacja nie ruszy.',
                'Kursory i rekordy należą do firmy, nie do klucza: wydanie klucza nie powoduje ponownego ładowania danych.',
            ]
            : [
                'Dotychczasowy klucz będzie działał do chwili, w której nowy po raz pierwszy zakończy synchronizację '
                    . '— wtedy zostanie unieważniony automatycznie.',
                'Nie ma terminu: dopóki nowy klucz nie trafi do connectora, synchronizacja działa na starym.',
                'Kursory i rekordy należą do firmy, nie do klucza: wydanie klucza nie powoduje ponownego ładowania danych.',
            ];

        return $this->confirmation($request, [
            'tytul' => 'Wydać nowy klucz w miejsce „' . $key['name'] . '”?',
            'wstep' => $nieuzyty
                ? 'Nowy klucz zobaczysz tylko raz, zaraz po wydaniu. Poprzedni klucz — wydany '
                    . $this->czas((string) $key['created_at']) . ' i nigdy nieużyty — zostanie unieważniony.'
                : 'Nowy klucz zobaczysz tylko raz, zaraz po wydaniu. Synchronizacja nie przerywa się: '
                    . 'stary klucz działa, dopóki nowy nie przejmie.',
            'szczegoly' => $this->keyDetails($key),
            'skutki' => $skutki,
            'akcja' => '/admin/integracja/klucze/' . $key['id'] . '/wymien',
            'przycisk' => 'Wydaj nowy klucz',
            'pola' => [],
        ]);
    }

    /** @param array<string, string> $params */
    public function confirmReject(Request $request, array $params): Response
    {
        $admin = $this->admin($request);
        $id = self::id($params);
        $signup = null;
        foreach ($this->keys->signups() as $s) {
            $signup = $s['id'] === $id && $s['status'] === 'pending' ? $s : $signup;
        }
        if ($admin === null || $signup === null) {
            return $this->errors->errorPage(404, null, $request->isHtmx());
        }

        return $this->confirmation($request, [
            'tytul' => 'Odrzucić zgłoszenie firmy „' . $signup['name'] . '”?',
            'wstep' => 'Firma nie powstanie w aplikacji, a connector przestanie ją zgłaszać do czasu cofnięcia decyzji. '
                . 'Dane tej firmy nie będą przyjmowane.',
            'szczegoly' => [
                ['Nazwa z programu kadrowego', $signup['name']],
                ['NIP', $signup['nip']],
                ['Zgłoszona', $this->czas((string) $signup['created_at'])],
            ],
            'skutki' => [
                'Connector dostanie odpowiedź „firma poza zakresem” i przestanie wysyłać jej dane.',
                'Decyzję można cofnąć na tym ekranie — zgłoszenie wróci wtedy do listy oczekujących.',
            ],
            'akcja' => '/admin/integracja/zgloszenia/' . $signup['id'] . '/odrzuc',
            'przycisk' => 'Odrzuć zgłoszenie',
            'pola' => [],
        ]);
    }

    /** @param array<string, string> $params */
    public function confirmIdentity(Request $request, array $params): Response
    {
        $admin = $this->admin($request);
        $id = self::id($params);
        $streams = array_column($this->sync->streams(), null, 'id');
        if ($admin === null || $id === null || !isset($streams[$id]) || $streams[$id]['identity_pending'] === null) {
            return $this->errors->errorPage(404, null, $request->isHtmx());
        }
        $stream = $streams[$id];

        return $this->confirmation($request, [
            'tytul' => 'Zaakceptować nowy odcisk tożsamości firmy ' . $stream['name'] . '?',
            'wstep' => 'Connector zgłasza inną tożsamość osób niż dotychczas. Akceptacja odblokowuje strumień, '
                . 'ale osoby przyjdą pod nowymi identyfikatorami — powiązań nie da się odtworzyć automatycznie.',
            'szczegoly' => [
                ['Firma', $stream['name']],
                ['Odcisk zapamiętany', (string) ($stream['identity'] ?? '—')],
                ['Odcisk zgłaszany teraz', (string) $stream['identity_pending']],
            ],
            'skutki' => [
                'Jeśli to były przenosiny bez przeniesienia sekretu — lepiej przywrócić sekret na connectorze zamiast akceptować.',
                'Po akceptacji osoby z tej firmy powstaną jako nowe: dopasowanie po dotychczasowych identyfikatorach przestanie działać.',
                'Decyzja trafia do dziennika zdarzeń razem z tym, kto ją podjął.',
            ],
            'akcja' => '/admin/integracja/firmy/' . $id . '/tozsamosc',
            'przycisk' => 'Zaakceptuj nowy odcisk',
            'pola' => [],
        ]);
    }

    /**
     * Jedno miejsce renderujące potwierdzenie: fragment do okna modalnego albo pełny ekran.
     * ⚠ `HX-Request` decyduje WYŁĄCZNIE o formie odpowiedzi — uprawnienie sprawdziła już trasa
     * i `admin()` wyżej (CLAUDE.md §5: nagłówek nie jest zabezpieczeniem).
     *
     * @param array<string, mixed> $dane
     */
    private function confirmation(Request $request, array $dane): Response
    {
        $dane['akcja'] = $this->urls->to((string) $dane['akcja']);
        $dane['powrot'] = $this->urls->to('/admin/integracja');

        return $this->view->render(
            $request->isHtmx() ? 'fragments/confirm.twig' : 'page/web/confirm.twig',
            $dane
        );
    }

    /**
     * Klucze, których firmy zgłaszają różne odciski tożsamości.
     *
     * @param list<array<string, mixed>> $keys wiersze z repozytorium
     * @return list<array{klucz: string, odciski: list<array{odcisk: string, firmy: string}>}>
     */
    private function rozjazdOdciskow(array $keys): array
    {
        $wynik = [];
        foreach ($keys as $k) {
            if ($k['status'] !== 'active') {
                continue;
            }
            $firmy = $this->domain->companiesForKey($k['id']);
            if (SyncService::identitySplit($firmy) === null) {
                continue;
            }
            $grupy = [];
            foreach ($firmy as $c) {
                if ($c['fingerprint'] !== null) {
                    $grupy[$c['fingerprint']][] = $c['name'];
                }
            }
            $wynik[] = [
                'klucz' => (string) $k['name'],
                'odciski' => array_map(
                    static fn (string $odcisk, array $nazwy): array => ['odcisk' => $odcisk, 'firmy' => implode(', ', $nazwy)],
                    array_keys($grupy),
                    array_values($grupy),
                ),
            ];
        }

        return $wynik;
    }

    /**
     * Najdłuższa cisza wśród kluczy aktywnych albo `null`, gdy connector się odzywa.
     *
     * @param list<array<string, mixed>> $keys wiersze z repozytorium (status), nie dane dla szablonu
     * @return array{godzin: int, prog: int}|null
     */
    private static function cisza(array $keys, int $prog): ?array
    {
        $godzin = null;
        foreach ($keys as $k) {
            // ⚠ Klucz nigdy nieużyty to NIE cisza connectora — to klucz czekający na wklejenie.
            // Gdyby wpadał tutaj, wydanie nowego klucza zapalałoby „Connector milczy” mimo
            // działającej synchronizacji na starym.
            if ($k['status'] !== 'active' || $k['last_used_at'] === null
                || !Cisza::przekroczona($k['last_used_at'], (string) $k['created_at'], $prog)) {
                continue;
            }
            $ile = Cisza::godziny(Cisza::odniesienie($k['last_used_at'], (string) $k['created_at']));
            $godzin = $godzin === null ? $ile : max($godzin, $ile);
        }

        return $godzin === null ? null : ['godzin' => $godzin, 'prog' => $prog];
    }

    /** Znacznik z bazy (UTC) w strefie tenanta — potwierdzenia też są dla człowieka (docs §10). */
    private function czas(string $utc): string
    {
        return Strefa::czas($utc, $this->settings->get('timezone', Strefa::DOMYSLNA));
    }

    /**
     * Klucz z listy (nazwa, prefiks, zakres, ostatnie użycie) — `find()` zwraca za mało, żeby
     * potwierdzenie mogło powiedzieć, czego dotyczy.
     *
     * @return array<string, mixed>|null
     */
    private function keyRow(int $id): ?array
    {
        foreach ($this->keys->all() as $key) {
            if ($key['id'] === $id && $key['status'] === 'active') {
                return $key;
            }
        }

        return null;
    }

    /**
     * Wiersz klucza **UNIEWAŻNIONEGO** — osobna funkcja, bo `keyRow()` z definicji zwraca tylko
     * czynne i wszystkie dotychczasowe potwierdzenia (unieważnienie, wymiana) tego właśnie chcą.
     *
     * ⚠ Dopisana po pomiarze: ekran potwierdzenia skasowania wołał `keyRow()` i **zawsze dawał
     * 404**, czyli przycisk „Skasuj" prowadził w pustkę, a samo kasowanie działało tylko wtedy,
     * gdy żądanie `POST` zbudowało się ręcznie. Dwie wąskie funkcje, każda mówiąca, co przyjmuje,
     * są tu lepsze niż jedna luźna, przy której każdy wołający musi pamiętać o sprawdzeniu stanu.
     *
     * @return array<string, mixed>|null
     */
    private function keyRowUniewazniony(int $id): ?array
    {
        foreach ($this->keys->all() as $key) {
            if ($key['id'] === $id && $key['status'] !== 'active') {
                return $key;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $key
     * @return list<array{0: string, 1: string}>
     */
    private function keyDetails(array $key): array
    {
        $nips = $key['nips'] === []
            ? '— brak firm w zakresie'
            : implode(', ', array_map(static fn (string $nip): string => Nip::human($nip), $key['nips']));

        return [
            ['Klucz', $key['name'] . ' (' . $key['key_prefix'] . '…)'],
            ['Zakres (NIP)', $nips],
            ['Ostatnie użycie', $key['last_used_at'] === null ? 'jeszcze nie był używany' : $this->czas((string) $key['last_used_at'])],
            ['Wydany', $this->czas((string) $key['created_at'])],
        ];
    }

    /** @param array<string, string> $params */
    public function setCompanies(Request $request, array $params): Response
    {
        $admin = $this->admin($request);
        $id = self::id($params);
        if ($admin === null || $id === null || $this->keys->find($id) === null) {
            return $this->errors->errorPage(404, null, $request->isHtmx());
        }
        try {
            $this->service->setCompanies($id, self::companyIds($request), $admin->accountId());
        } catch (InvalidArgumentException $e) {
            return $this->render($e->getMessage(), 422);
        }

        return $this->done($request, 'firmy');
    }

    /** Wydanie nowego klucza: pokazany raz; stary działa do przejęcia albo — gdy nigdy nie był użyty — ginie od razu. */
    public function replace(Request $request, array $params): Response
    {
        $admin = $this->admin($request);
        $id = self::id($params);
        if ($admin === null || $id === null) {
            return $this->errors->errorPage(404, null, $request->isHtmx());
        }
        try {
            $key = $this->service->replace($id, $admin->accountId());
        } catch (InvalidArgumentException $e) {
            return $this->render($e->getMessage(), 422);
        }

        return $this->showOnce($request, $key, 'wymieniony');
    }

    /** @param array<string, string> $params */
    public function revoke(Request $request, array $params): Response
    {
        $admin = $this->admin($request);
        $id = self::id($params);
        if ($admin === null || $id === null) {
            return $this->errors->errorPage(404, null, $request->isHtmx());
        }
        if ($request->postString('potwierdzam', 3) !== '1') {
            return $this->render('Zaznacz potwierdzenie — unieważnienie zatrzymuje connector od razu.', 422);
        }
        if (!$this->service->revoke($id, $admin->accountId())) {
            return $this->errors->errorPage(404, null, $request->isHtmx());
        }

        return $this->done($request, 'uniewazniony');
    }

    /**
     * Skasowanie unieważnionego klucza.
     *
     * ⚠ Trzy przeszkody, każda w innym miejscu i żadna nie zastępuje pozostałych: uprawnienie
     * na trasie, potwierdzenie w formularzu i warunek `status <> 'active'` **w zapytaniu**
     * (`ApiKeyRepository::usun()`). Ukryty przycisk nie jest żadną z nich.
     *
     * @param array<string, string> $params
     */
    public function delete(Request $request, array $params): Response
    {
        $admin = $this->admin($request);
        $id = self::id($params);
        if ($admin === null || $id === null) {
            return $this->errors->errorPage(404, null, $request->isHtmx());
        }
        if ($request->postString('potwierdzam', 3) !== '1') {
            return $this->render('Zaznacz potwierdzenie — skasowania klucza nie da się cofnąć.', 422);
        }
        if (!$this->service->usun($id, $admin->accountId())) {
            return $this->errors->errorPage(404, null, $request->isHtmx());
        }

        return $this->done($request, 'skasowany');
    }

    /** @param array<string, string> $params */
    public function approve(Request $request, array $params): Response
    {
        $admin = $this->admin($request);
        $id = self::id($params);
        if ($admin === null || $id === null) {
            return $this->errors->errorPage(404, null, $request->isHtmx());
        }
        try {
            $this->service->approveSignup($id, $admin->accountId());
        } catch (InvalidArgumentException $e) {
            return $this->render($e->getMessage(), 422);
        }

        return $this->done($request, 'zatwierdzona');
    }

    /** @param array<string, string> $params */
    public function reject(Request $request, array $params): Response
    {
        $admin = $this->admin($request);
        $id = self::id($params);
        if ($admin === null || $id === null || !$this->service->rejectSignup($id, $admin->accountId())) {
            return $this->errors->errorPage(404, null, $request->isHtmx());
        }

        return $this->done($request, 'odrzucona');
    }

    /** Przegląd kontrolny na żądanie (np. po odtworzeniu bazy z kopii) — w następnym przebiegu tej firmy. */
    /**
     * Jawna decyzja administratora: nowy odcisk tożsamości zastępuje zapamiętany i odblokowuje strumień.
     * ⚠ Bez cichego nadpisania i bez domyślnego „tak” — to jest zgoda na to, że osoby przyjdą pod nowymi
     * identyfikatorami, czyli na odtwarzanie powiązań.
     *
     * @param array<string, string> $params
     */
    public function acceptIdentity(Request $request, array $params): Response
    {
        $admin = $this->admin($request);
        $id = self::id($params);
        $streams = array_column($this->sync->streams(), null, 'id');
        if ($admin === null || $id === null || !isset($streams[$id]) || $streams[$id]['identity_pending'] === null) {
            return $this->errors->errorPage(404, null, $request->isHtmx());
        }
        $stary = $streams[$id]['identity'];
        $nowy = (string) $streams[$id]['identity_pending'];
        $this->domain->setIdentityFingerprint($id, $nowy);
        $this->events->record('sync.identity_accepted', $admin->accountId(), null, $admin->displayName, 'company', $id, $id,
            ['stary' => $stary, 'nowy' => $nowy]);

        return $this->done($request, 'tozsamosc');
    }

    public function forceReview(Request $request, array $params): Response
    {
        $admin = $this->admin($request);
        $id = self::id($params);
        $companies = array_column($this->sync->streams(), null, 'id');
        if ($admin === null || $id === null || !isset($companies[$id])) {
            return $this->errors->errorPage(404, null, $request->isHtmx());
        }
        $this->sync->ensureStream($id);
        $this->sync->setReviewRequired($id, true);
        $this->events->record('sync.review_requested', $admin->accountId(), null, $admin->displayName, 'company', $id, $id);

        return $this->done($request, 'przeglad');
    }

    /**
     * Cztery kroki podłączenia. ⚠ Każdy krok mówi, CO ZROBIĆ DALEJ i gdzie — to jest różnica między
     * ekranem a kreatorem. Osoba u klienta robi to raz w życiu i nie wie, co to 403.
     *
     * @param list<array<string, mixed>> $keys
     * @param list<array<string, mixed>> $signups
     * @param list<array<string, mixed>> $companies
     * @return array{kroki: list<array{nazwa: string, stan: string, co_dalej: ?string}>, gotowe: bool}
     */
    private function wizard(array $keys, array $signups, array $companies, array $summary, ?string $lastConnection): array
    {
        // ⚠ wizard() dostaje wiersze prosto z repozytorium (status), nie dane dla szablonu (state).
        $hasKey = array_filter($keys, static fn (array $k): bool => $k['status'] === 'active') !== [];
        $connected = $lastConnection !== null;
        $hasCompany = $companies !== [];
        $synced = $summary['ostatnia_synchronizacja'] !== null;
        $waiting = $signups[0]['name'] ?? null;
        // ⚠ `akcja` mówi szablonowi, co postawić PRZY kroku. Administrator ma zrobić to, co krok każe,
        // bez szukania formularza gdzie indziej na stronie — to była pierwsza uwaga z testu u klienta.
        $steps = [
            ['nazwa' => 'Klucz', 'zrobione' => $hasKey, 'akcja' => 'klucz',
                'co_dalej' => 'Wygeneruj klucz i wklej go w konfiguracji connectora.'],
            ['nazwa' => 'Połączenie', 'zrobione' => $connected, 'akcja' => null,
                'co_dalej' => 'Czekam na pierwsze połączenie z connectora. Klucz wklejony? Kliknij w connectorze „Testuj połączenie”.'],
            ['nazwa' => 'Firma', 'zrobione' => $hasCompany, 'akcja' => 'firma',
                'co_dalej' => $waiting !== null
                    ? 'Connector zgłosił firmę ' . $waiting . '. Sprawdź, czy to właściwa firma, i zatwierdź ją poniżej.'
                    : 'Czekam, aż connector zgłosi firmę. Jeśli połączenie działa, zgłoszenie pojawi się tu samo.'],
            ['nazwa' => 'Dane', 'zrobione' => $synced, 'akcja' => null,
                'co_dalej' => 'Firma zatwierdzona. Wróć do connectora i kliknij „Testuj połączenie” jeszcze raz.'],
        ];
        $current = true;
        $out = [];
        foreach ($steps as $step) {
            $isCurrent = $current && !$step['zrobione'];
            $out[] = [
                'nazwa' => $step['nazwa'],
                'stan' => $step['zrobione'] ? 'zrobione' : ($isCurrent ? 'biezacy' : 'czeka'),
                'co_dalej' => $isCurrent ? $step['co_dalej'] : null,
                'akcja' => $step['akcja'],
            ];
            $current = $current && $step['zrobione'];
        }

        // ⚠ Odpytywanie włączamy WYŁĄCZNIE tam, gdzie czekamy na connectora. Gdy piłka jest po stronie
        // administratora (wpisuje nazwę klucza, decyduje o zgłoszeniu), podmiana fragmentu co pięć sekund
        // tylko przeszkadza — potrafi skasować wpisywany tekst.
        $waitingForConnector = ($hasKey && !$connected)
            || ($connected && !$hasCompany && $signups === [])
            || ($hasCompany && !$synced);

        return [
            'kroki' => $out,
            'gotowe' => $hasKey && $connected && $hasCompany && $synced,
            'czeka_na_connector' => $waitingForConnector,
        ];
    }

    private function render(?string $error, int $status = 200, string $notice = ''): Response
    {
        // Odczyt jednorazowy: klucz znika z sesji, ZANIM strona zostanie wyrenderowana.
        $new = $this->session->get(self::NEW_KEY);
        $this->session->set(self::NEW_KEY, null);
        $shown = $this->session->get(self::SHOWN_KEY);
        $this->session->set(self::SHOWN_KEY, null);
        if (is_array($new) && (int) ($new['at'] ?? 0) + self::KEY_TTL_SECONDS < time()) {
            $shown = ['id' => (int) $new['id'], 'expired' => true];
            $new = null;
        } elseif (is_array($new)) {
            // Przy następnym otwarciu strony: „klucz był już wyświetlony” (i tylko tyle).
            $this->session->set(self::SHOWN_KEY, ['id' => (int) $new['id'], 'expired' => false]);
        }
        $keys = $this->keys->all();
        $names = array_column($keys, 'name', 'id');
        $progCiszy = (int) $this->settings->get('key_idle_alert_hours', '24');
        // ⚠ Osobny próg: świeżo wydany klucz bywa wklejany następnego dnia roboczego,
        // a cisza działającego connectora po dobie to już awaria.
        $progNieuzytego = (int) $this->settings->get('key_unused_alert_hours', '72');

        $streams = $this->sync->streams();
        $runs = $this->sync->recentRuns(20);
        $summary = $this->sync->dataSummary();
        $signupsPending = array_values(array_filter($this->keys->signups(), static fn (array $s): bool => $s['status'] === 'pending'));
        $lastConnection = null;
        foreach ($keys as $k) {
            $lastConnection = $k['last_used_at'] !== null && ($lastConnection === null || $k['last_used_at'] > $lastConnection)
                ? $k['last_used_at'] : $lastConnection;
        }
        $connector = $runs[0] ?? null;

        return $this->view->render('page/web/admin-integration.twig', [
            'kreator'  => $this->wizard($keys, $signupsPending, $streams, $summary, $lastConnection),
            'podsumowanie' => $summary,
            'connector' => $connector === null ? null : [
                'ostatnie_polaczenie' => $lastConnection,
                'wersja' => $connector['connector_version'] === null ? null : (string) $connector['connector_version'],
                'wersja_r2' => $connector['r2_version'] === null ? null : (string) $connector['r2_version'],
                'stan' => $connector['connector_state'] === null ? null : (string) $connector['connector_state'],
                'przebiegi_dzis' => $summary['przebiegi_dzis'],
                'bledy_dzis' => $summary['bledy_dzis'],
            ],
            'error'    => $error,
            'notice'   => self::NOTICES[$notice] ?? null,
            'new_key'  => is_array($new) ? ['key' => (string) $new['key'], 'name' => $names[(int) $new['id']] ?? null] : null,
            'shown_key' => is_array($shown) && isset($names[(int) $shown['id']])
                ? ['id' => (int) $shown['id'], 'name' => $names[(int) $shown['id']], 'expired' => (bool) $shown['expired']] : null,
            'firmy'    => array_map(static fn (array $s): array => ['id' => $s['id'], 'nip' => $s['nip'], 'name' => $s['name']], $streams),
            'keys'     => array_map(static fn (array $k): array => [
                'id' => $k['id'], 'name' => $k['name'], 'prefix' => $k['key_prefix'],
                // Trzy stany i ani jednego więcej (model z 20.09.2026): klucz w użyciu, klucz wydany
                // i jeszcze nieużyty, klucz unieważniony. Stan wynika z danych, nie z osobnej kolumny.
                'state' => $k['status'] !== 'active' ? 'revoked' : ($k['last_used_at'] === null ? 'issued' : 'active'),
                'replaced' => $k['replaced_by_id'] !== null, 'last_used_at' => $k['last_used_at'],
                'created_at' => $k['created_at'], 'nips' => $k['nips'],
                // ⚠ „Unieważniony” i „zastąpiony” to dwa różne zdarzenia — jeden napis dla obu każe
                // administratorowi zgadywać, co się stało z kluczem (zlecenie 21.09 §2.1).
                // ⚠ Klucz bez firm uwierzytelnia się poprawnie i NIE DOSTAJE ŻADNYCH DANYCH —
                // najcichsza z możliwych awarii przy wdrożeniu u nowego klienta.
                'bez_firm' => $k['status'] === 'active' && $k['nips'] === [],
                'powod' => $k['status'] === 'active'
                    ? null
                    : ($k['replaced_by_id'] !== null ? 'zastąpiony nowym kluczem' : 'unieważniony przez administratora'),
                // Cisza klucza: connector może przestać się odzywać bez żadnego błędu (§2.2).
                // Liczona od ostatniego użycia, a przy kluczu nigdy nieużytym — od wydania.
                'milczy' => $k['status'] === 'active' && Cisza::przekroczona($k['last_used_at'], (string) $k['created_at'], $progCiszy)
                    ? Cisza::godziny(Cisza::odniesienie($k['last_used_at'], (string) $k['created_at']))
                    : null,
                // Klucz wydany i nigdy nieużyty dłużej niż próg: stan wymagający uwagi, nie awaria.
                'czeka_godzin' => $k['status'] === 'active' && $k['last_used_at'] === null
                    && Cisza::przekroczona(null, (string) $k['created_at'], $progNieuzytego)
                    ? Cisza::godziny((string) $k['created_at'])
                    : null,
            ], $keys),
            // Jedna cisza dla całego ekranu: najdłużej milczący klucz aktywny (zwykle jedyny).
            'cisza'    => self::cisza($keys, $progCiszy),
            // ⚠ Rozjazd odcisków tożsamości: firmy jednego klucza obsługują dwie instalacje connectora.
            // Stan wymagający uwagi — strumień działa, ale osoby z obu firm nie będą scalane.
            'rozjazd'  => $this->rozjazdOdciskow($keys),
            'osob_w_wielu_firmach' => $this->domain->peopleInManyCompanies(),
            'signups'  => $signupsPending,
            'rejected' => array_values(array_filter($this->keys->signups(), static fn (array $s): bool => $s['status'] === 'rejected')),
            'streams'  => array_map(static fn (array $s): array => [
                'id' => $s['id'], 'name' => $s['name'], 'nip' => $s['nip'], 'review_required' => $s['review_required'],
                'identity' => $s['identity'], 'identity_pending' => $s['identity_pending'],
                'last_review_at' => $s['last_review_at'], 'last_run_at' => $s['last_run_at'],
                'connector_state' => is_string($s['connector']['stan'] ?? null) ? $s['connector']['stan'] : null,
                'connector_version' => is_string($s['connector']['wersjaConnectora'] ?? null) ? $s['connector']['wersjaConnectora'] : null,
            ], $streams),
            'runs'     => array_map(static function (array $r): array {
                $stats = is_string($r['stats']) ? json_decode($r['stats'], true) : [];
                $stats = is_array($stats) ? $stats : [];
                $rekordy = 0;
                foreach (is_array($stats['dane'] ?? null) ? $stats['dane'] : [] as $zbior) {
                    $rekordy += (int) ($zbior['nowe'] ?? 0) + (int) ($zbior['zmienione'] ?? 0) + (int) ($zbior['usuniete'] ?? 0);
                }

                return [
                    'id' => (int) $r['id'],
                    'started_at' => (string) $r['started_at'],
                    'nip' => (string) $r['nip'],
                    'company' => (string) $r['company'],
                    'status' => (string) $r['status'],
                    'phase' => (string) $r['phase'],
                    'connector_state' => $r['connector_state'] === null ? null : (string) $r['connector_state'],
                    'seconds' => $r['seconds'] === null ? null : (int) $r['seconds'],
                    'rekordy' => $rekordy,
                    'konflikty' => (int) $r['conflicts'],
                    'pelne' => in_array('pelny', array_values(is_array($stats['tryby'] ?? null) ? $stats['tryby'] : []), true),
                ];
            }, $runs),
        ], $status)->withHeader('Cache-Control', 'no-store');
    }

    private function done(Request $request, string $notice): Response
    {
        if (self::fromWizard($request)) {
            return $this->view->render('fragments/connect-wizard.twig',
                array_merge($this->connectData(), ['komunikat' => self::NOTICES[$notice] ?? null]));
        }

        return Response::redirectFor($request, $this->urls->to(self::backPath($request)) . '?ok=' . $notice);
    }

    /** Uprawnienie sprawdził Authorize na trasie (integration.manage); tu tylko zalogowany użytkownik. */
    private function admin(Request $request): ?CurrentUser
    {
        $admin = $request->attribute(Authenticate::USER);

        return $admin instanceof CurrentUser ? $admin : null;
    }

    /** @param array<string, string> $params */
    private static function id(array $params): ?int
    {
        $id = filter_var($params['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $id === false ? null : $id;
    }

    /**
     * Zaznaczone firmy z formularza. ⚠ Zakres klucza to wybór firm ISTNIEJĄCYCH — pola na ręczny NIP nie ma,
     * bo to ono dawało dwa źródła prawdy i pętlę przy pierwszym podłączeniu (rozstrzygnięcie 20.09.2026).
     *
     * @return list<int>
     */
    private static function companyIds(Request $request): array
    {
        $raw = $request->post('firmy');
        $ids = [];
        foreach (is_array($raw) ? array_slice($raw, 0, 500) : [] as $value) {
            $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id !== false) {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
