<?php

declare(strict_types=1);

namespace Wezurlop\Controller\App;

use DateTimeImmutable;
use DateTimeZone;
use Wezurlop\Entity\CurrentUser;
use Wezurlop\Http\Middleware\Authenticate;
use Wezurlop\Http\Request;
use Wezurlop\Http\Response;
use Wezurlop\Repository\CompanyRepository;
use Wezurlop\Repository\EventLogRepository;
use Wezurlop\Repository\JobRepository;
use Throwable;
use Wezurlop\Repository\TenantSettingRepository;
use Wezurlop\Service\CronStan;
use Wezurlop\Service\Mail\MailMessage;
use Wezurlop\Service\Mail\MailSettings;
use Wezurlop\Service\Mail\TransportFactory;
use Wezurlop\Support\Strefa;
use Wezurlop\Support\UrlGenerator;
use Wezurlop\Support\View;

/**
 * Ustawienia tenanta. Poza strefą czasową to nadal rama z Etapu 2 — reszta wartości jest
 * do odczytu, bez zapisu.
 *
 * ⚠ Strefa czasowa jest tu prawdziwym ustawieniem (docs/system-wizualny.md §10 punkt 2):
 * czas w bazie zostaje w UTC, a ta wartość mówi wyłącznie, w czym go pokazujemy. Zapisujemy
 * NAZWĘ strefy z listy PHP — nigdy przesunięcia, bo przesunięcie zmienia się dwa razy w roku.
 */
final class SettingsController
{
    /** Lista do wyboru — krótka i sensowna dla klienta w Polsce; reszta przez wpisanie nazwy nie jest potrzebna. */
    private const STREFY = [
        'Europe/Warsaw', 'Europe/London', 'Europe/Berlin', 'Europe/Kyiv', 'Europe/Vilnius',
        'America/New_York', 'America/Chicago', 'America/Los_Angeles', 'Asia/Dubai', 'Asia/Tokyo',
        'Australia/Sydney', 'Pacific/Auckland', 'UTC',
    ];

    private const NOTICES = [
        'strefa' => 'Strefa czasowa zapisana. Godziny na wszystkich ekranach pokazujemy od teraz w tej strefie.',
        'sonda'  => 'Sonda crona postawiona w kolejce. Podejmie ją najbliższy przebieg crona — '
            . 'odśwież ten ekran za kilka minut.',
        'poczta' => 'Ustawienia poczty zapisane. Wyślij wiadomość próbną, żeby sprawdzić, czy serwer je przyjmuje.',
        'haslo_wyczyszczone' => 'Hasło SMTP wyczyszczone. Serwer, który wymaga uwierzytelnienia, odrzuci teraz wysyłkę.',
        'nazwa'  => 'Nazwa wyświetlana zapisana. Od teraz widać ją na liście kont, w kontrolce dostępu i w filtrze.',
        'nazwa_wyczyszczona' => 'Nazwa wyświetlana wyczyszczona — pokazujemy nazwę z programu kadrowego.',
    ];

    public function __construct(
        private readonly View $view,
        private readonly TenantSettingRepository $settings,
        private readonly CompanyRepository $companies,
        private readonly EventLogRepository $events,
        private readonly MailSettings $poczta,
        private readonly CronStan $cron,
        private readonly JobRepository $jobs,
        private readonly TransportFactory $transporty,
        private readonly UrlGenerator $urls,
    ) {
    }

    /** @param array<string, string> $params */
    public function index(Request $request, array $params): Response
    {
        return $this->render((string) $request->query('ok'), null);
    }

    /**
     * Zapis strefy prezentacji. Wartość spoza listy stref PHP jest odrzucana — do formatowania
     * nigdy nie trafia nazwa, której `DateTimeZone` nie zna (CLAUDE.md §11: biała lista).
     *
     * @param array<string, string> $params
     */
    public function saveTimezone(Request $request, array $params): Response
    {
        $wybrana = $request->postString('strefa', 64);
        if (!Strefa::istnieje($wybrana)) {
            return $this->render('', 'Nieznana nazwa strefy czasowej — wybierz jedną z listy.');
        }
        $poprzednia = $this->settings->get('timezone', Strefa::DOMYSLNA);
        if ($wybrana !== $poprzednia) {
            $user = $request->attribute(Authenticate::USER);
            $accountId = $user instanceof CurrentUser ? $user->accountId() : null;
            $this->settings->set('timezone', $wybrana, $accountId);
            $this->events->record('tenant.timezone_changed', $accountId, null,
                $user instanceof CurrentUser ? $user->displayName : 'admin', 'tenant', null, null,
                ['z' => $poprzednia, 'na' => $wybrana]);
        }

        return Response::redirectFor($request, $this->urls->to('/ustawienia') . '?ok=strefa');
    }

    /**
     * Nazwy wyświetlane spółek (§8 zlecenia).
     *
     * ⚠ Powód istnienia tego ekranu: `BETA SPÓŁKA Z OGRANICZONĄ ODPOWIEDZIALNOŚCIĄ` nie mieści
     * się w kolumnie ani w kontrolce filtra, a skracać w locie nie ma czego — skrót musi wybrać
     * człowiek. Nazwa z R2 zostaje nietknięta i tylko do odczytu.
     *
     * @param array<string, string> $params
     */
    public function companies(Request $request, array $params): Response
    {
        return $this->renderCompanies((string) $request->query('ok'), null);
    }

    /**
     * Zapis nazwy wyświetlanej. Puste pole znaczy „pokazuj nazwę z R2".
     *
     * ⚠ Nazwa jest **danymi użytkownika** — do szablonu idzie przez Twiga jak każdy inny tekst
     * (CLAUDE.md §5), a jednoznaczności pilnuje klucz unikalny w bazie, nie sprawdzenie przed
     * zapisem (wyścig dwóch administratorów).
     *
     * @param array<string, string> $params
     */
    public function saveCompanyName(Request $request, array $params): Response
    {
        $companyId = filter_var($params['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        // ⚠ Wiersz spółki trzymamy, a nie tylko sprawdzamy jego istnienie: **poprzednia nazwa
        // wyświetlana jest potrzebna do dziennika** i to jest ten sam odczyt, nie drugi.
        $spolka = $companyId === false ? null : $this->companies->find($companyId);
        if ($companyId === false || $spolka === null) {
            return $this->renderCompanies('', 'Nie znalazłem takiej firmy.');
        }
        $nazwa = trim($request->postString('nazwa', 200));
        if (mb_strlen($nazwa) > 200) {
            return $this->renderCompanies('', 'Nazwa wyświetlana może mieć najwyżej 200 znaków.');
        }
        $powod = $this->companies->setDisplayName($companyId, $nazwa === '' ? null : $nazwa);
        if ($powod === CompanyRepository::POWOD_KOLIZJA_Z_KADRAMI) {
            // ⚠ Osobny komunikat, bo kolizja jest z kolumną, której człowiek na tym ekranie
            // nie edytuje — „nazwa jest już użyta" kazałoby mu szukać jej w nazwach wyświetlanych,
            // gdzie jej nie ma. Powód: te same dwa wiersze stają potem na liście kont i w
            // kontrolce dostępu, czyli tam, gdzie nadaje się uprawnienia do danych kadrowych.
            return $this->renderCompanies('', 'Ta nazwa jest nazwą innej firmy w programie kadrowym — dwa identyczne wiersze na liście dostępu byłyby nie do rozróżnienia. Wybierz inną.');
        }
        if ($powod === CompanyRepository::POWOD_ZAJETA) {
            // ⚠ Dwie spółki o tej samej nazwie wyświetlanej byłyby nie do rozróżnienia
            // na liście dostępu — a to jest lista, na której nadaje się uprawnienia.
            return $this->renderCompanies('', 'Ta nazwa wyświetlana jest już użyta przez inną firmę — nazwy muszą się różnić.');
        }
        $user = $request->attribute(Authenticate::USER);
        $accountId = $user instanceof CurrentUser ? $user->accountId() : null;
        $this->events->record(
            'tenant.company_display_name',
            $accountId,
            null,
            $user instanceof CurrentUser ? $user->displayName : 'admin',
            'company',
            $companyId,
            $companyId,
            // ⚠ **Wartość PRZED i PO, nie `['ustawiono' => true]`** (zlecenie 08.10 §1).
            // Dawny zapis był ostrożnością wziętą z §14 — ale §14 chroni dane osobowe, hasła
            // i sekrety, a nazwa wyświetlana spółki to **konfiguracja tenanta**, nie dana osobowa.
            // ⚠ Koszt tej ostrożności jest zmierzony i konkretny: 06.10.2026 skrypt pomiarowy
            // nadpisał nazwę wpisaną ręcznie i **nie dało się jej odtworzyć z dziennika**,
            // bo dziennik wiedział tylko, że „ustawiono". Wpis opisujący zmianę, który nie niesie
            // wartości, jest rejestrem wyglądającym na działający.
            ['z' => $spolka['nazwa_wyswietlana'], 'na' => $nazwa === '' ? null : $nazwa],
        );

        return Response::redirectFor(
            $request,
            $this->urls->to('/ustawienia/spolki') . '?ok=' . ($nazwa === '' ? 'nazwa_wyczyszczona' : 'nazwa'),
        );
    }

    private function renderCompanies(string $notice, ?string $error): Response
    {
        return $this->view->render('page/web/settings-companies.twig', [
            'spolki' => $this->companies->listWithCounts(),
            'notice' => self::NOTICES[$notice] ?? null,
            'error'  => $error,
        ]);
    }

    private function render(string $notice, ?string $error): Response
    {
        $strefa = $this->settings->get('timezone', Strefa::DOMYSLNA);

        return $this->view->render('settings.twig', [
            'strefa' => $strefa,
            // Ten sam moment w obu formach — żeby było widać, co robi ustawienie.
            'teraz_utc' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
            // Bieżąca strefa zawsze na liście, nawet gdy ktoś wpisał ją w bazie ręcznie.
            'strefy' => array_values(array_unique([...self::STREFY, $strefa])),
            'notice' => self::NOTICES[$notice] ?? null,
            'error' => $error,
            // Stan poczty widać już na liście ustawień: „nieskonfigurowana" znaczy, że
            // zaproszenia i resety haseł nie docierają do nikogo.
            'poczta_rodzaj' => $this->transporty->rodzaj(),
            // ⚠ Cron: te same dane, które czyta `/diagnostyka` (jedna klasa, dwa ekrany).
            // Tu odpowiadamy na inne pytanie: „co mam wpisać u hostingodawcy".
            'cron' => $this->cron->stan(),
            'groups' => [
                ['title' => 'Weryfikacja dwuetapowa', 'rows' => [
                    ['label' => 'Administrator tenanta', 'value' => 'wymagana'],
                    ['label' => 'Przełożeni i kadry', 'value' => 'do ustalenia (Etap 3)'],
                ]],
                ['title' => 'Powiadomienia', 'rows' => [
                    ['label' => 'Skrzynka na alerty', 'value' => $this->settings->get('alert_email', '— nie ustawiono')],
                ]],
                ['title' => 'Integracja', 'rows' => [
                    ['label' => 'Próg ciszy connectora (klucz w użyciu milczy)', 'value' => $this->settings->get('key_idle_alert_hours', '24') . ' h'],
                    ['label' => 'Próg klucza wydanego i nieużytego', 'value' => $this->settings->get('key_unused_alert_hours', '72') . ' h'],
                ]],
            ],
        ]);
    }
    /**
     * „Sprawdź cron" — stawia sondę w kolejce i wraca na ekran ustawień.
     *
     * ⚠ **Nie uruchamia runnera w żądaniu** i to jest cała różnica wobec przycisku „uruchom
     * teraz", którego tu nie ma: zadanie ma podjąć prawdziwy przebieg crona. Dopóki go nie ma,
     * sonda czeka — i to jest właśnie odpowiedź na pytanie „czy cron działa".
     *
     * @param array<string, string> $params
     */
    public function checkCron(Request $request, array $params): Response
    {
        $this->cron->wyslijSonde();
        $this->events->record('maintenance.cron_probe_requested', $this->accountId($request), null,
            $this->actorLabel($request), 'tenant', null);

        return Response::redirectFor($request, $this->urls->to('/ustawienia') . '?ok=sonda');
    }

    /**
     * Poczta wychodząca (etap 7.2) — ekran ustawień z wysyłką próbną.
     *
     * ⚠ Ustawienia są w BAZIE, nie w `.env`: serwer SMTP klienta zmienia się po instalacji,
     * a edycja `.env` przez FTP to ryzyko literówki w pliku, który przy błędzie wywraca całą
     * aplikację, nie jedną funkcję.
     *
     * @param array<string, string> $params
     */
    public function mail(Request $request, array $params): Response
    {
        return $this->renderMail((string) $request->query('ok'), null, null);
    }

    /**
     * Zapis ustawień poczty.
     *
     * ⚠ Hasło: puste pole znaczy **„nie zmieniaj"**, bo ekran nigdy nie oddaje sekretu
     * do przeglądarki (CLAUDE.md §9). Wyczyszczenie hasła jest osobną, jawną decyzją.
     *
     * @param array<string, string> $params
     */
    public function saveMail(Request $request, array $params): Response
    {
        $host = trim($request->postString('host', 253));
        $port = filter_var($request->postString('port', 5), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        $szyfrowanie = $request->postString('szyfrowanie', 8);
        $uzytkownik = trim($request->postString('uzytkownik', 254));
        $haslo = $request->postString('haslo', 254);
        $nadawca = strtolower(trim($request->postString('nadawca', 254)));
        $nazwa = trim($request->postString('nazwa_nadawcy', 100));
        $wyczysc = $request->postString('wyczysc_haslo', 1) === '1';

        if ($host === '' || $nadawca === '') {
            return $this->renderMail('', 'Podaj serwer SMTP i adres nadawcy — bez nich poczta nie wychodzi.', null);
        }
        if (filter_var($nadawca, FILTER_VALIDATE_EMAIL) === false) {
            return $this->renderMail('', 'Adres nadawcy nie jest prawidłowym adresem e-mail.', null);
        }
        if ($port === false) {
            return $this->renderMail('', 'Port musi być liczbą od 1 do 65535 (zwykle 587 dla TLS albo 465 dla SSL).', null);
        }
        if (!in_array($szyfrowanie, MailSettings::SZYFROWANIA, true)) {
            return $this->renderMail('', 'Wybierz szyfrowanie z listy.', null);
        }

        $this->poczta->zapisz($host, $port, $szyfrowanie, $uzytkownik, $haslo, $nadawca, $nazwa, $wyczysc, $this->accountId($request));
        // ⚠ W dzienniku fakt zmiany i host, NIGDY użytkownik ani hasło (CLAUDE.md §14).
        $this->events->record('tenant.mail_settings_changed', $this->accountId($request), null,
            $this->actorLabel($request), 'tenant', null, null,
            ['host' => $host, 'szyfrowanie' => $szyfrowanie, 'haslo' => $wyczysc ? 'wyczyszczone' : ($haslo !== '' ? 'zmienione' : 'bez zmian')]);

        return Response::redirectFor($request, $this->urls->to('/ustawienia/poczta')
            . '?ok=' . ($wyczysc ? 'haslo_wyczyszczone' : 'poczta'));
    }

    /**
     * Wysyłka próbna.
     *
     * ⚠ **Świadomy wyjątek od reguły „poczta tylko przez kolejkę"** (CLAUDE.md §0): sens tego
     * przycisku polega na tym, że administrator widzi **błąd serwera SMTP od razu**, a nie
     * w logu po pięciu minutach. Limit czasu transportu to 10 s, więc żądanie nie wisi
     * do timeoutu PHP. Wszystkie pozostałe wiadomości idą kolejką.
     *
     * ⚠ Komunikat błędu z PHPMailera pokazujemy **wprost** — bez niego „nie udało się wysłać"
     * nie mówi, czy problem jest w haśle, porcie, czy w certyfikacie. Ten komunikat nie zawiera
     * hasła ani treści wiadomości.
     *
     * @param array<string, string> $params
     */
    public function testMail(Request $request, array $params): Response
    {
        $adres = strtolower(trim($request->postString('adres', 254)));
        if (filter_var($adres, FILTER_VALIDATE_EMAIL) === false) {
            return $this->renderMail('', 'Podaj adres, na który ma pójść wiadomość próbna.', null);
        }
        if (!$this->poczta->skonfigurowana() && $this->transporty->rodzaj() !== 'plik') {
            return $this->renderMail('', TransportFactory::POWOD_BRAK_KONFIGURACJI, null);
        }
        try {
            $this->transporty->transport()->send(new MailMessage(
                $adres,
                'wezurlop: wiadomość próbna',
                "To wiadomość próbna z ekranu ustawień poczty.\n"
                . "Jeśli ją widzisz, wysyłka z tej instalacji działa.\n",
            ));
        } catch (Throwable $e) {
            $this->events->record('tenant.mail_test_failed', $this->accountId($request), null,
                $this->actorLabel($request), 'tenant', null, null, ['rodzaj' => $this->transporty->rodzaj()]);

            return $this->renderMail('', null, ['ok' => false, 'tekst' => $e->getMessage()]);
        }
        $this->events->record('tenant.mail_test_sent', $this->accountId($request), null,
            $this->actorLabel($request), 'tenant', null, null, ['rodzaj' => $this->transporty->rodzaj()]);

        return $this->renderMail('', null, ['ok' => true, 'tekst' => $this->transporty->rodzaj() === 'plik'
            ? 'Zapisana do pliku w var/mail (APP_ENV=dev, poczta nieskonfigurowana) — nie wyszła w świat.'
            : 'Wysłana na ' . $adres . '. Jeśli nie dotarła w ciągu kilku minut, sprawdź folder spamu i logi serwera poczty.']);
    }

    /**
     * @param array{ok: bool, tekst: string}|null $proba
     */
    private function renderMail(string $ok, ?string $error, ?array $proba): Response
    {
        return $this->view->render('page/web/settings-mail.twig', [
            'notice'      => $ok === '' ? null : (self::NOTICES[$ok] ?? null),
            'error'       => $error,
            'proba'       => $proba,
            'host'        => $this->poczta->host(),
            'port'        => $this->poczta->port(),
            'szyfrowanie' => $this->poczta->szyfrowanie(),
            'szyfrowania' => MailSettings::SZYFROWANIA,
            'uzytkownik'  => $this->poczta->uzytkownik(),
            'ma_haslo'    => $this->poczta->maHaslo(),
            'nadawca'     => $this->poczta->nadawca(),
            'nazwa_nadawcy' => $this->poczta->nazwaNadawcy(),
            // ⚠ Nazwa nadawcy to nazwa firmy KLIENTA, nie nasza (docs/wymagania-hostingu.md §1a).
            // Podpowiedź tylko przy jednej spółce — przy dwóch zgadywanie, w czyim imieniu
            // piszemy, jest gorsze niż brak podpowiedzi (ta sama zasada co `singleR2Name()`).
            'nazwa_nadawcy_sugestia' => $this->companies->singleR2Name() ?? '',
            // Stan wymagający reakcji: brak nazwy albo nazwa odziedziczona po nas.
            'nazwa_nadawcy_uwaga' => $this->poczta->nazwaNadawcy() === ''
                || strcasecmp($this->poczta->nazwaNadawcy(), 'wezurlop') === 0,
            'rodzaj'      => $this->transporty->rodzaj(),
            'ostatnia_wysylka' => $this->poczta->ostatniaWysylka(),
            'alert_email' => $this->settings->get('alert_email'),
            // ⚠ Widok kolejki: LICZBY, nigdy treść wiadomości i nigdy adresaci (zlecenie §4).
            // Ekran odpowiada na „czy wysyłka ruszyła", a nie „komu co poszło" — to drugie
            // jest w dzienniku zdarzeń, za osobnym uprawnieniem.
            'kolejka'     => $this->jobs->podsumowanie(),
            'cron'        => $this->cron->stan(),
        ], $error === null ? 200 : 422);
    }

    private function accountId(Request $request): ?int
    {
        $user = $request->attribute(Authenticate::USER);

        return $user instanceof CurrentUser ? $user->accountId() : null;
    }

    private function actorLabel(Request $request): string
    {
        $user = $request->attribute(Authenticate::USER);

        return $user instanceof CurrentUser ? $user->displayName : 'admin';
    }

}
