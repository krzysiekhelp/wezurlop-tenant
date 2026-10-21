<?php

declare(strict_types=1);

namespace Wezurlop\Controller\App;

use Wezurlop\Entity\CurrentUser;
use Wezurlop\Http\DatabaseSessionHandler;
use Wezurlop\Http\Middleware\Authenticate;
use Wezurlop\Http\Request;
use Wezurlop\Http\Response;
use Wezurlop\Http\Session;
use Wezurlop\Repository\AccountRepository;
use Wezurlop\Repository\EventLogRepository;
use Wezurlop\Repository\PersonRepository;
use Wezurlop\Security\AuthSession;
use Wezurlop\Security\ClientFingerprint;
use Wezurlop\Service\PasswordResetService;
use Wezurlop\Support\Komunikat;
use Wezurlop\Support\Telefon;
use Wezurlop\Support\UrlGenerator;
use Wezurlop\Support\WidokKonta;

/**
 * **„Moje konto"** — dane, typ konta, dostęp i bezpieczeństwo na jednym ekranie
 * (zlecenie Z69, projekt zaakceptowany przez Krzysztofa 21.10.2026).
 *
 * ⚠ **Okna otwiera ADRES, nie JavaScript** (`?okno=telefon`, `?okno=haslo`, `?okno=2fa`…).
 * Dzięki temu cała strona działa bez skryptów — formularz wysyła POST, serwer przekierowuje
 * i renderuje `<dialog open>`. JavaScript (`modules/konto.js`) dokłada wyłącznie wygodę:
 * formatowanie numeru, przeskok między okienkami kodu i kopiowanie. ⚠ Z tego samego powodu
 * odmowa wraca jako **pełna strona z otwartym oknem i komunikatem**, a nie jako fragment:
 * bez JS nie ma czego podmienić.
 *
 * ⚠ **Ten kontroler nie dotyka 2FA.** Parowanie, włączenie, wyłączenie i kody zapasowe zostają
 * w `SecurityController` — ekran się przeniósł, logika nie. Dwa miejsca wołające
 * `confirmPairing()` to dwa miejsca, w których można zapomnieć o liczniku prób.
 */
final class ProfileController
{
    public function __construct(
        private readonly WidokKonta $widok,
        private readonly Session $session,
        private readonly AccountRepository $accounts,
        private readonly PersonRepository $people,
        private readonly PasswordResetService $hasla,
        private readonly AuthSession $auth,
        private readonly ClientFingerprint $fingerprint,
        private readonly EventLogRepository $events,
        private readonly UrlGenerator $urls,
    ) {
    }

    /** @param array<string, string> $params */
    public function index(Request $request, array $params): Response
    {
        return $this->strona($request, $this->user($request));
    }

    /**
     * Zapis własnego numeru telefonu.
     *
     * ⚠ **Walidacja po stronie serwera jest TĄ walidacją**: podpowiedź „9 cyfr, bez zera
     * i bez +48" w oknie jest dla człowieka, a nie dla bezpieczeństwa (CLAUDE.md §6, §11).
     *
     * @param array<string, string> $params
     */
    public function telefon(Request $request, array $params): Response
    {
        $user = $this->user($request);
        $wpisane = $request->postString('telefon', 32);
        if (!Telefon::poprawny($wpisane)) {
            return $this->strona($request, $user, 'telefon', Telefon::ODMOWA, 422, $wpisane);
        }

        return $this->zapiszTelefon($request, $user, Telefon::doZapisu($wpisane), 'telefon-zapisany');
    }

    /** @param array<string, string> $params */
    public function telefonUsun(Request $request, array $params): Response
    {
        return $this->zapiszTelefon($request, $this->user($request), null, 'telefon-usuniety');
    }

    /**
     * Zmiana własnego hasła (decyzja Krzysztofa: prawdziwa zmiana, nie link resetu).
     *
     * ⚠ Po udanej zmianie **odświeżamy znacznik tej sesji** — bez tego `Authenticate`
     * wylogowałby także tę przeglądarkę, bo jej uwierzytelnienie jest starsze niż świeże
     * `password_changed_at` (patrz `AuthSession::odswiezPoZmianieHasla()`).
     *
     * @param array<string, string> $params
     */
    public function haslo(Request $request, array $params): Response
    {
        $user = $this->user($request);
        $odmowa = $this->hasla->changeOwn(
            $user->accountId(),
            $request->postString('obecne', 4096),
            $request->postString('nowe', 4096),
            $request->postString('powtorzone', 4096),
            $this->fingerprint->ipHash($request->serverParams()),
            DatabaseSessionHandler::hash($this->session->id()),
        );
        if ($odmowa !== null) {
            return $this->strona($request, $user, 'haslo', $odmowa, 422);
        }
        $this->auth->odswiezPoZmianieHasla();

        return $this->poZapisie($request, 'haslo-zmienione');
    }

    /**
     * Jedna droga zapisu numeru — dla zmiany i dla usunięcia.
     *
     * ⚠ **W dzienniku NIE MA numeru i nie będzie**, choć zlecenie pisało „wartość poprzednia":
     * telefon to dana osobowa (CLAUDE.md §14), a dziennik widzą administratorzy tenanta.
     * Zapisujemy więc to, co wystarcza do audytu — czy numer jest i czy był — dokładnie tak,
     * jak robi to zmiana kontaktów w panelu osoby (`['adres' => bool, 'telefon' => bool]`).
     */
    private function zapiszTelefon(Request $request, CurrentUser $user, ?string $numer, string $ok): Response
    {
        $konto = $this->accounts->findById($user->accountId());
        if ($konto === null) {
            return $this->strona($request, $user, 'telefon', 'Nie udało się zapisać numeru.', 422);
        }
        $personId = (int) $konto['person_id'];
        $bylo = $this->people->phone($personId) !== null;
        $this->people->updatePhone($personId, $numer);
        $this->events->record(
            'person.contact_changed',
            $user->accountId(),
            $this->fingerprint->ipHash($request->serverParams()),
            null,
            'person',
            $personId,
            null,
            ['telefon' => $numer !== null, 'bylo' => $bylo, 'wlasny' => true],
        );

        return $this->poZapisie($request, $ok);
    }

    /** Po udanym zapisie: przekierowanie (PRG) z `?ok=`, a dla htmx toast tym samym kanałem. */
    private function poZapisie(Request $request, string $ok): Response
    {
        $odpowiedz = Response::redirectFor($request, $this->urls->to('/konto') . '?ok=' . $ok);
        $tekst = self::POTWIERDZENIA[$ok] ?? null;

        return $tekst === null ? $odpowiedz : $odpowiedz->withHeader('HX-Trigger', Komunikat::naglowek($tekst));
    }

    /** Zdania potwierdzeń — jedno miejsce, bo te same teksty czyta toast i treść strony. */
    public const POTWIERDZENIA = [
        'telefon-zapisany'  => 'Numer telefonu zapisany.',
        'telefon-usuniety'  => 'Numer telefonu usunięty.',
        'haslo-zmienione'   => 'Hasło zmienione. Pozostałe urządzenia zostały wylogowane.',
        'kody-nowe'         => 'Nowe kody zapasowe gotowe. Stare przestały działać.',
        '2fa-wlaczone'      => 'Weryfikacja dwuetapowa włączona.',
        '2fa-wylaczone'     => 'Weryfikacja dwuetapowa wyłączona.',
        'sesja-zakonczona'  => 'Urządzenie wylogowane.',
        'sesje-zakonczone'  => 'Pozostałe urządzenia wylogowane.',
    ];

    /**
     * Pełna strona „Moje konto" — przez `WidokKonta`, bo tę samą stronę renderuje też
     * `SecurityController` (odmowa 2FA wraca pełną stroną z otwartym oknem).
     *
     * ⚠ `okno` i `error` przychodzą z akcji, a nie z adresu, gdy coś się nie udało: `?okno=`
     * służy do OTWARCIA okna, a komunikat w adresie dałby się podstawić i wyświetlić człowiekowi
     * dowolne zdanie „od aplikacji".
     */
    private function strona(
        Request $request,
        CurrentUser $user,
        ?string $okno = null,
        ?string $error = null,
        int $status = 200,
        string $wpisanyTelefon = '',
    ): Response {
        $zQuery = $request->query('okno');
        $ok = $request->query('ok');
        $tryb = $request->query('tryb');

        return $this->widok->strona(
            $user,
            $okno ?? (is_string($zQuery) && $zQuery !== '' ? $zQuery : null),
            $error,
            $status,
            null,
            is_string($ok) ? (self::POTWIERDZENIA[$ok] ?? null) : null,
            $wpisanyTelefon,
            // ⚠ Biała lista, nie przepisanie parametru: z adresu wolno przyjść wyłącznie
            // słowu „zapasowy" (CLAUDE.md §11).
            $tryb === 'zapasowy' ? 'zapasowy' : '',
        );
    }

    private function user(Request $request): CurrentUser
    {
        $user = $request->attribute(Authenticate::USER);
        assert($user instanceof CurrentUser);

        return $user;
    }
}
