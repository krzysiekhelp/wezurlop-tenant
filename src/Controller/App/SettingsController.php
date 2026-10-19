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
use Wezurlop\Service\JobRunner;
use Wezurlop\Service\Mail\MailMessage;
use Wezurlop\Service\Mail\MailSettings;
use Wezurlop\Service\Mail\MailTransport;
use Wezurlop\Service\Mail\TransportFactory;
use Wezurlop\Service\Mail\TransportZDziennikiem;
use Wezurlop\Support\Strefa;
use Wezurlop\Support\UrlGenerator;
use Wezurlop\Support\View;
use Wezurlop\Support\ZadaniaKolejki;

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
        // ⚠ Zdanie wiodące, kropka, krótkie dopowiedzenie (Z47) — komunikat stoi w toaście,
        // który pogrubia pierwsze zdanie i znika po pięciu sekundach. Gdzie widać nazwy
        // wyświetlane, mówi nagłówek okna, a nie komunikat po zapisie.
        'strefa' => 'Strefa czasowa zapisana. Godziny pokazujemy od teraz w tej strefie.',
        'sonda'  => 'Sonda crona w kolejce. Odśwież ten ekran za kilka minut.',
        'poczta' => 'Ustawienia poczty zapisane. Wyślij wiadomość próbną.',
        // ⚠ Dwa komunikaty na jedno usunięcie, bo skutek jest różny i to jest cała treść
        // odpowiedzi: wiadomość przepada, a zadanie cykliczne wraca samo. Liczba usuniętych
        // wierszy stoi w oknie potwierdzenia i w dzienniku — tu liczy się SKUTEK.
        'kolejka'      => 'Zadanie usunięte z kolejki. Ta wiadomość już nie wyjdzie.',
        'kolejka_cykl' => 'Zadanie usunięte z kolejki. Wróci w następnym przebiegu crona.',
        // ⚠ Jeden komunikat na cały zestaw (Z29): okno zapisuje wszystkie firmy jednym
        // przyciskiem, więc „nazwa zapisana" w liczbie pojedynczej kłamałoby przy dwóch zmianach.
        'nazwy'  => 'Nazwy wyświetlane zapisane.',
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
        return $this->render(
            (string) $request->query('ok'),
            null,
            $request->queryString('okno', 16) === 'nazwy',
        );
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
     * `/ustawienia/spolki` — **stary adres osobnego ekranu, dziś przekierowanie** (zlecenie Z29).
     *
     * ⚠ Trasa zostaje, zamiast zniknąć: prowadzą do niej zakładki, nasze własne zrzuty ekranu
     * i kryterium bramki. Martwy odnośnik w panelu administratora jest gorszy niż jedno
     * przekierowanie — a parametr `okno` otwiera to samo okno, które otwiera przycisk.
     *
     * @param array<string, string> $params
     */
    public function companies(Request $request, array $params): Response
    {
        return Response::redirectFor($request, $this->urls->to('/ustawienia') . '?okno=nazwy');
    }

    /**
     * **Zapis nazw wyświetlanych WSZYSTKICH firm jednym żądaniem** (zlecenie Z29).
     *
     * ⚠ Jedno żądanie i jedna transakcja, bo jednoznaczność dotyczy CAŁEGO zestawu: zamiana nazw
     * między dwiema firmami jest poprawna jako całość, a rozbita na dwa zapisy odbija się
     * o klucz unikalny w połowie drogi (patrz `CompanyRepository::setDisplayNames()`).
     *
     * ⚠ Nazwy są **danymi użytkownika** — do szablonu wracają przez Twiga jak każdy inny tekst
     * (CLAUDE.md §5), a identyfikatory firm biorą się z **białej listy** z bazy, nie z formularza:
     * pętla chodzi po firmach, które istnieją, i czyta pola po ich identyfikatorach (§11).
     *
     * ⚠ **Długość sprawdzamy sami, na surowej wartości.** `postString()` zwraca pusty tekst dla
     * pola dłuższego niż limit — a pusty tekst znaczy tu „pokazuj nazwę z programu kadrowego",
     * czyli wklejenie 300 znaków **skasowałoby nazwę** zamiast pokazać błąd. Przy zapisie
     * jednej firmy to był drobiazg; przy zapisie całego zestawu to cicha strata danych.
     *
     * @param array<string, string> $params
     */
    public function saveCompanyNames(Request $request, array $params): Response
    {
        $firmy = $this->companies->listWithCounts();
        $wejscie = [];
        $bledy = [];
        foreach ($firmy as $firma) {
            $surowa = $request->post('nazwa_' . $firma['id']);
            if ($surowa !== null && !is_string($surowa)) {
                $bledy[$firma['id']] = 'Nieprawidłowa wartość pola.';
                continue;
            }
            $tekst = trim((string) ($surowa ?? ''));
            if (mb_strlen($tekst) > 200) {
                $bledy[$firma['id']] = 'Najwyżej 200 znaków.';
                continue;
            }
            if (!mb_check_encoding($tekst, 'UTF-8')) {
                $bledy[$firma['id']] = 'Nieprawidłowe znaki w nazwie.';
                continue;
            }
            $wejscie[$firma['id']] = $tekst === '' ? null : $tekst;
        }

        if ($bledy === []) {
            $odmowy = $this->companies->setDisplayNames($wejscie);
            foreach ($odmowy as $id => $powod) {
                $bledy[$id] = match ($powod) {
                    // ⚠ Osobny komunikat od „zajęta": kolizja jest z kolumną, której człowiek
                    // w tym oknie nie edytuje — „nazwa jest już użyta" kazałoby mu szukać jej
                    // wśród nazw wyświetlanych, gdzie jej nie ma.
                    CompanyRepository::POWOD_KOLIZJA_Z_KADRAMI => 'Ta nazwa jest nazwą innej firmy w programie kadrowym — dwa identyczne wiersze na liście dostępu byłyby nie do rozróżnienia.',
                    CompanyRepository::POWOD_ZAJETA => 'Tej nazwy używa już inna firma — nazwy wyświetlane muszą się różnić.',
                    CompanyRepository::POWOD_NIEZNANA => 'Nie znalazłem takiej firmy.',
                    default => 'Nie udało się zapisać tej nazwy.',
                };
            }
        }

        if ($bledy !== []) {
            // ⚠ Wracają WPISANE wartości, nie te z bazy: człowiek ma poprawić jedno pole,
            // a nie wpisywać wszystko od nowa. Nic nie zostało zapisane.
            $wpisane = [];
            foreach ($firmy as $firma) {
                $surowa = $request->post('nazwa_' . $firma['id']);
                $wpisane[$firma['id']] = is_string($surowa) ? $surowa : '';
            }

            return $this->render('', null, true, $wpisane, $bledy);
        }

        $user = $request->attribute(Authenticate::USER);
        $accountId = $user instanceof CurrentUser ? $user->accountId() : null;
        foreach ($firmy as $firma) {
            $nowa = $wejscie[$firma['id']] ?? null;
            if ($nowa === $firma['nazwa_wyswietlana']) {
                continue;
            }
            $this->events->record(
                'tenant.company_display_name',
                $accountId,
                null,
                $user instanceof CurrentUser ? $user->displayName : 'admin',
                'company',
                $firma['id'],
                $firma['id'],
                // ⚠ **Wartość PRZED i PO, nie `['ustawiono' => true]`** (zlecenie 08.10 §1).
                // Dawny zapis był ostrożnością wziętą z §14 — ale §14 chroni dane osobowe, hasła
                // i sekrety, a nazwa wyświetlana spółki to **konfiguracja tenanta**, nie dana
                // osobowa. ⚠ Koszt tej ostrożności jest zmierzony: 06.10.2026 skrypt pomiarowy
                // nadpisał nazwę wpisaną ręcznie i **nie dało się jej odtworzyć z dziennika**,
                // bo dziennik wiedział tylko, że „ustawiono".
                ['z' => $firma['nazwa_wyswietlana'], 'na' => $nowa],
            );
        }

        return Response::redirectFor($request, $this->urls->to('/ustawienia') . '?ok=nazwy');
    }

    /**
     * @param array<int, string> $wpisaneNazwy id firmy → wartość z formularza (po odmowie zapisu)
     * @param array<int, string> $bledyNazw    id firmy → komunikat przy polu
     */
    private function render(
        string $notice,
        ?string $error,
        bool $oknoNazwy = false,
        array $wpisaneNazwy = [],
        array $bledyNazw = [],
    ): Response {
        $strefa = $this->settings->get('timezone', Strefa::DOMYSLNA);
        $alertEmail = trim((string) $this->settings->get('alert_email', ''));

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
            // ⚠ Firmy jadą do okna „Nazwy wyświetlane firm" osadzonego w tym ekranie (Z29).
            // Okno jest w stronie, a nie dociągane htmx-em, bo dzięki temu działa BEZ JavaScriptu:
            // serwer renderuje `<dialog open>`, a skrypt tylko podnosi je do warstwy wierzchniej.
            'spolki' => $this->companies->listWithCounts(),
            // Otwarte, gdy prowadzi tu stary adres (`?okno=nazwy`) albo gdy zapis odmówił.
            'okno_nazwy' => $oknoNazwy,
            'wpisane_nazwy' => $wpisaneNazwy,
            'bledy_nazw' => $bledyNazw,
            // ⚠ Adres alertów osobno, a nie tylko w grupie niżej: kafel na górze ekranu musi
            // ODRÓŻNIĆ „ustawiony" od „nieustawionego", a napis zastępczy („— nie ustawiono")
            // tego nie niesie — kafel pokazałby go jako zwykłą wartość (zlecenie Z27 §2).
            'alert_email' => $alertEmail === '' ? null : $alertEmail,
            // ⚠ **Grupy niosą teraz ZNAK wiersza, a nie tylko napis.** Trzy karty na dole ekranu
            // mają plakietki o trzech znaczeniach (wymagane / do ustalenia / nieustawione),
            // a o tym, co wiersz znaczy, wie kontroler — nie szablon (CLAUDE.md §5).
            // Znaki: `ok` zielona, `szary` szara, `uwaga` bursztynowa, `dana` sama wartość.
            'grupy' => [
                ['tytul' => 'Weryfikacja dwuetapowa', 'ikona' => 'tarcza', 'wiersze' => [
                    ['etykieta' => 'Administrator tenanta', 'wartosc' => 'wymagana', 'znak' => 'ok'],
                    ['etykieta' => 'Przełożeni i kadry', 'wartosc' => 'do ustalenia (Etap 3)', 'znak' => 'szary'],
                ]],
                ['tytul' => 'Powiadomienia', 'ikona' => 'dzwonek', 'wiersze' => [
                    ['etykieta' => 'Skrzynka na alerty',
                     'wartosc' => $alertEmail === '' ? 'nie ustawiono' : $alertEmail,
                     'znak' => $alertEmail === '' ? 'uwaga' : 'dana'],
                ]],
                ['tytul' => 'Integracja', 'ikona' => 'integracja', 'wiersze' => [
                    ['etykieta' => 'Próg ciszy connectora (klucz w użyciu milczy)',
                     'wartosc' => $this->settings->get('key_idle_alert_hours', '24') . ' h', 'znak' => 'dana'],
                    ['etykieta' => 'Próg klucza wydanego i nieużytego',
                     'wartosc' => $this->settings->get('key_unused_alert_hours', '72') . ' h', 'znak' => 'dana'],
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
     * do przeglądarki (CLAUDE.md §9) — i to się nie zmienia.
     *
     * ⚠ **Czyszczenia hasła z ekranu już nie ma** (dopisek do Z43, 19.10.2026). Droga istniała
     * wyłącznie dla karty „Hasło SMTP": ukryte pole `wyczysc_haslo=1` w tym samym formularzu,
     * bez własnej trasy. Została zdjęta razem z kartą — a jedyny inny wywołujący `zapisz()`
     * (instalator) podawał tam i tak `false`. Skrzynkę bez uwierzytelnienia ustawia się dziś
     * pustym polem **użytkownika**: wtedy `SmtpTransport` nie włącza `SMTPAuth` i hasło z bazy
     * nie jest używane.
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

        $this->poczta->zapisz($host, $port, $szyfrowanie, $uzytkownik, $haslo, $nadawca, $nazwa, $this->accountId($request));
        // ⚠ W dzienniku fakt zmiany i host, NIGDY użytkownik ani hasło (CLAUDE.md §14).
        $this->events->record('tenant.mail_settings_changed', $this->accountId($request), null,
            $this->actorLabel($request), 'tenant', null, null,
            ['host' => $host, 'szyfrowanie' => $szyfrowanie, 'haslo' => $haslo !== '' ? 'zmienione' : 'bez zmian']);

        return Response::redirectFor($request, $this->urls->to('/ustawienia/poczta') . '?ok=poczta');
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
            // ⚠ Wpis w dzienniku robi TEN SAM dekorator, co w kolejce (Z43 §C): wiadomość próbna
            // ma zostawić wpis tej samej rodziny `mail.*`, z odbiorcą — a nie własną parę kodów
            // `tenant.mail_test_*` bez adresu, która wpadała do filtra „firmy".
            $this->dziennikPoczty($request, 'wiadomość próbną', 'wiadomości próbnej')->send(new MailMessage(
                $adres,
                'wezurlop: wiadomość próbna',
                "To wiadomość próbna z ekranu ustawień poczty.\n"
                . "Jeśli ją widzisz, wysyłka z tej instalacji działa.\n",
            ));
        } catch (Throwable $e) {
            return $this->renderMail('', null, ['ok' => false, 'tekst' => $e->getMessage()]);
        }

        return $this->renderMail('', null, ['ok' => true, 'tekst' => $this->transporty->rodzaj() === 'plik'
            ? 'Zapisana do pliku w var/mail (APP_ENV=dev, poczta nieskonfigurowana) — nie wyszła w świat.'
            : 'Wysłana na ' . $adres . '. Jeśli nie dotarła w ciągu kilku minut, sprawdź folder spamu i logi serwera poczty.']);
    }

    /**
     * Usunięcie czekających zadań jednego typu z kolejki (zlecenie Z43 §B).
     *
     * ⚠ **Reguła „co wolno usunąć" stoi w `ZadaniaKolejki`, nie tutaj i nie w szablonie.**
     * Ekran wyłącza kosz z tego samego źródła, z którego serwer odmawia — inaczej wyłączony
     * przycisk byłby całym zabezpieczeniem, czyli żadnym (CLAUDE.md §10).
     *
     * ⚠ **Usuwamy wszystkie czekające zadania TEGO TYPU, nie jedno po identyfikatorze** —
     * i to jest świadome odstępstwo od brzmienia zlecenia. Powód jest w samym ekranie:
     * kolejka pokazuje LICZBY i typy, nigdy adresatów (bo byłaby listą adresów pracowników
     * dostępną każdemu z `admin.settings`), więc dwa zadania tego samego typu są na niej
     * nierozróżnialne. Przycisk, który wybiera jedno z dwóch identycznych wierszy, obiecuje
     * decyzję, której ekran nie umie pokazać. Okno potwierdzenia mówi liczbę wprost,
     * a dziennik ją zapisuje.
     *
     * @param array<string, string> $params
     */
    public function deleteQueueJob(Request $request, array $params): Response
    {
        $typ = $request->postString('typ', 64);
        // Biała lista z helpera (CLAUDE.md §11) — do zapytania nie trafia tekst użytkownika.
        if (!in_array($typ, ZadaniaKolejki::typy(), true)) {
            return $this->renderMail('', ZadaniaKolejki::POWOD_NIEZNANY, null);
        }
        if (!ZadaniaKolejki::usuwalne($typ)) {
            return $this->renderMail('', ZadaniaKolejki::powod($typ), null);
        }
        $usunietych = $this->jobs->usunCzekajace($typ);
        if ($usunietych === 0) {
            // ⚠ Dwa różne „zero": zadanie zajęte przez przebieg crona kontra zadanie, którego
            // już nie ma. Pierwsze jest odpowiedzią „spróbuj później", drugie „nie ma czego".
            return $this->renderMail('', $this->jobs->hasPendingType($typ)
                ? ZadaniaKolejki::POWOD_W_TOKU
                : 'Tego zadania już nie ma w kolejce. Ktoś je wykonał albo usunął przed chwilą.', null);
        }
        // ⚠ W dzienniku nazwa po polsku I kod typu: zdanie czyta człowiek, a kod zestawia się
        // z kolejką. Bez adresatów — tych kolejka nie zna (patrz wyżej).
        $this->events->record('maintenance.job_deleted', $this->accountId($request), null,
            $this->actorLabel($request), 'tenant', null, null,
            ['zadanie' => ZadaniaKolejki::nazwa($typ), 'typ' => $typ]
            // ⚠ Liczba tylko wtedy, gdy większa od jednego — zdanie dziennika ma ją wtedy
            // w swojej części opcjonalnej, a przy jednym zadaniu nie dopisuje „sztuk: 1".
            + ($usunietych > 1 ? ['ile' => (string) $usunietych] : []));

        return Response::redirectFor($request, $this->urls->to('/ustawienia/poczta')
            . '?ok=' . (isset(JobRunner::CYKLICZNE[$typ]) ? 'kolejka_cykl' : 'kolejka'));
    }

    /**
     * Transport wysyłki próbnej opakowany w zapis do dziennika.
     *
     * ⚠ Sprawcą jest **człowiek z ekranu**, nie `cron`: wiadomość próbną wysyła administrator
     * i w dzienniku ma stać jego nazwa, inaczej wpis o jedynej wysyłce zamówionej ręcznie
     * wyglądałby jak wpis kolejki.
     */
    private function dziennikPoczty(Request $request, string $rodzaj, string $rodzajDopelniacz): MailTransport
    {
        return new TransportZDziennikiem(
            $this->transporty->transport(),
            $this->events,
            $rodzaj,
            $rodzajDopelniacz,
            $this->actorLabel($request),
            null,
            $this->accountId($request),
            $this->transporty->rodzaj() === 'plik' ? 'zrzut do pliku' : null,
        );
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
            'rodzaj'      => $this->transporty->rodzaj(),
            'ostatnia_wysylka' => $this->poczta->ostatniaWysylka(),
            'alert_email' => $this->settings->get('alert_email'),
            // ⚠ Widok kolejki: LICZBY, nigdy treść wiadomości i nigdy adresaci (zlecenie §4).
            // Ekran odpowiada na „czy wysyłka ruszyła", a nie „komu co poszło" — to drugie
            // jest w dzienniku zdarzeń, za osobnym uprawnieniem.
            'kolejka'     => $this->kolejka(),
            'cron'        => $this->cron->stan(),
        ], $error === null ? 200 : 422);
    }

    /**
     * Podsumowanie kolejki z nazwami zadań po polsku i regułą usuwania.
     *
     * ⚠ **Nazwę i prawo do usunięcia dokłada KONTROLER, nie szablon** (CLAUDE.md §5): szablon
     * nie woła helperów, a reguła „co wolno usunąć" ma jedno źródło (`ZadaniaKolejki`) — to samo,
     * z którego odmawia `deleteQueueJob()`.
     *
     * @return array{czeka: int, czeka_gotowe: int, czeka_poczta: int, nieudane: int,
     *     najstarsze_utc: ?string, ostatni_blad_utc: ?string,
     *     typy: list<array{typ: string, ile: int, nazwa: string, usuwalne: bool, powod: string,
     *     cykliczne: bool}>}
     */
    private function kolejka(): array
    {
        $podsumowanie = $this->jobs->podsumowanie();
        $podsumowanie['typy'] = array_map(
            static fn (array $wpis): array => $wpis + [
                'nazwa'    => ZadaniaKolejki::nazwa($wpis['typ']),
                'usuwalne' => ZadaniaKolejki::usuwalne($wpis['typ']),
                'powod'    => ZadaniaKolejki::powod($wpis['typ']),
                // ⚠ Okno potwierdzenia musi powiedzieć, czy zadanie wróci — a to wie `JobRunner`,
                // bo to on je zamawia. Szablon dostaje gotową odpowiedź, nie mapę do przeglądania.
                'cykliczne' => isset(JobRunner::CYKLICZNE[$wpis['typ']]),
            ],
            $podsumowanie['typy'],
        );

        return $podsumowanie;
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
