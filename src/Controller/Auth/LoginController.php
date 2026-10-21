<?php

declare(strict_types=1);

namespace Wezurlop\Controller\Auth;

use Wezurlop\Http\ClientKind;
use Wezurlop\Http\Middleware\Authenticate;
use Wezurlop\Http\Request;
use Wezurlop\Http\Response;
use Wezurlop\Repository\EventLogRepository;
use Wezurlop\Security\AuthSession;
use Wezurlop\Security\OneTimeToken;
use Wezurlop\Security\ClientFingerprint;
use Wezurlop\Service\AuthService;
use Wezurlop\Support\Komunikat;
use Wezurlop\Support\UrlGenerator;
use Wezurlop\Support\View;

/**
 * Logowanie hasłem i wylogowanie (docs/szkielet-tenanta.md rozdz. 6).
 */
final class LoginController
{
    /** Jeden komunikat dla wszystkich niepowodzeń — także opóźnienia (nie zdradza istnienia konta). */
    // ⚠ Jedno zdanie wiodące i jedno dopowiedzenie (Z47) — droga „nie pamiętam hasła" stoi
    // odnośnikiem na tym samym ekranie, więc komunikat nie musi jej powtarzać.
    private const FAILED = 'Nieprawidłowy login lub hasło. Przy kolejnych próbach odczekaj chwilę.';

    /**
     * Powody przekierowania na ekran logowania — biała lista (CLAUDE.md §11), każdy z RODZAJEM
     * komunikatu.
     *
     * ⚠ **Rodzaj per powód, a nie jeden dla wszystkich** (zgłoszenie z 21.10.2026): do tego dnia
     * ekran stawiał `notice_rodzaj => 'sukces'` na sztywno, z uzasadnieniem „powody z REASONS to
     * potwierdzenia czynności". Dla „Wylogowano." to prawda, ale dla pozostałych już nie —
     * i widać to było na ekranie: **„Sesja wygasła" z zielonym ptaszkiem**, a nawet „Dostęp do
     * tego konta został zablokowany" jako sukces. Zielony znaczy „udało się to, co robiłeś";
     * wygaśnięcie sesji nie jest niczyją czynnością, a blokada jest odmową.
     *
     * Podział jest więc taki: SUKCES tylko tam, gdzie człowiek coś zrobił i to się udało
     * (wylogowanie, zmiana hasła, aktywacja konta), INFO tam, gdzie stan po prostu nastąpił
     * (wygaśnięcie, nieważna sesja, zakończenie z innego urządzenia), UWAGA przy odmowie
     * dostępu — bo tam jest co zrobić (skontaktować się z administratorem).
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const REASONS = [
        'wygasla'         => ['Sesja wygasła. Zaloguj się ponownie.', Komunikat::INFO],
        'wylogowano'      => ['Wylogowano.', Komunikat::SUKCES],
        'sesja-niewazna'  => ['Sesja jest nieważna (np. po zmianie hasła). Zaloguj się ponownie.', Komunikat::INFO],
        'haslo-zmienione' => ['Hasło zostało zmienione. Zaloguj się nowym hasłem.', Komunikat::SUKCES],
        'konto-aktywne'   => ['Konto jest aktywne. Zaloguj się ustawionym hasłem.', Komunikat::SUKCES],
        'sesja-zakonczona' => ['Ta sesja została zakończona (np. z innego urządzenia). Zaloguj się ponownie.', Komunikat::INFO],
        // ⚠ Ten powód wystawia WYŁĄCZNIE `Authenticate`, gdy działającą sesję przerwała blokada
        // konta (zlecenie `blokady-przyjete-i-etap6` §4). Zwykłe logowanie go nie używa: hasło
        // błędne i konto zablokowane dają ten sam `FAILED`, bo inaczej ekran logowania
        // odpowiadałby na pytanie „czy takie konto istnieje" (CLAUDE.md §9).
        'konto-zablokowane' => ['Dostęp do tego konta został zablokowany. '
            . 'Jeśli to pomyłka, skontaktuj się z administratorem systemu.', Komunikat::UWAGA],
    ];

    public function __construct(
        private readonly View $view,
        private readonly AuthService $auth,
        private readonly AuthSession $session,
        private readonly ClientFingerprint $fingerprint,
        private readonly EventLogRepository $events,
        private readonly UrlGenerator $urls,
    ) {
    }

    /** @param array<string, string> $params */
    public function form(Request $request, array $params): Response
    {
        if ($request->attribute(Authenticate::USER) !== null) {
            return Response::redirectFor($request, $this->urls->to('/'));
        }
        if ($this->session->level() === AuthSession::PARTIAL) {
            return Response::redirectFor($request, $this->urls->to('/logowanie/kod'));
        }
        $reason = $request->query('powod');
        $powod = is_string($reason) ? (self::REASONS[$reason] ?? null) : null;

        return $this->render($powod[0] ?? null, null, '', 200, $powod[1] ?? Komunikat::SUKCES);
    }

    /** @param array<string, string> $params */
    public function submit(Request $request, array $params): Response
    {
        $email = trim($request->postString('email', 254));
        $password = $request->postString('password', 4096);
        if ($email === '' || $password === '') {
            return $this->render(null, self::FAILED, $email, 422);
        }

        // Rodzaj klienta czytany raz, przy logowaniu; dalej decyduje sesja (rozdz. 8). Bez wpływu na dostęp.
        $client = ClientKind::fromRequest($request);
        $result = $this->auth->attempt($email, $password, $this->fingerprint->ipHash($request->serverParams()), $client->client);
        if ($result === null) {
            return $this->render(null, self::FAILED, $email, 422);
        }

        if ($result['two_factor']) {
            $this->session->beginPartial($result['account_id'], $client);

            return Response::redirectFor($request, $this->urls->to('/logowanie/kod'));
        }
        $this->session->complete($result['account_id'], $client);

        return Response::redirectFor($request, $this->urls->to('/'));
    }

    /** @param array<string, string> $params */
    public function logout(Request $request, array $params): Response
    {
        $accountId = $this->session->accountId();
        $this->session->logout();
        if ($accountId !== null) {
            $this->events->record('auth.logout', $accountId, $this->fingerprint->ipHash($request->serverParams()));
        }

        return Response::redirectFor($request, $this->urls->to('/logowanie') . '?powod=wylogowano');
    }

    /**
     * „Wyloguj się i ustaw hasło dla tego konta" — jedyne miejsce, w którym otwarcie linku
     * do hasła kończy sesję, i **wyłącznie po kliknięciu przycisku**.
     *
     * ⚠ Gdyby wylogowanie działo się samo przy otwarciu linku, wystarczyłoby podesłać komuś
     * dowolny link, żeby wyrzucić go z pracy w połowie wniosku (zlecenie §2.2).
     *
     * ⚠ Token wraca tą samą drogą, którą przyszedł — z formularza do adresu. Nie zapisujemy go
     * nigdzie po drodze (CLAUDE.md §9, §14).
     *
     * @param array<string, string> $params
     */
    public function logoutToLink(Request $request, array $params): Response
    {
        $token = $request->postString('token', 64);
        $rodzaj = $request->postString('rodzaj', 16) === 'reset' ? 'reset' : 'zaproszenie';
        $accountId = $this->session->accountId();
        $this->session->logout();
        if ($accountId !== null) {
            $this->events->record('auth.logout', $accountId, $this->fingerprint->ipHash($request->serverParams()),
                null, null, null, null, ['powod' => 'link_hasla']);
        }
        // ⚠ Biała lista celów (CLAUDE.md §11): dwie ścieżki i nic poza nimi. Token wraca
        // w adresie, bo tak wygląda ten link od początku.
        $sciezka = $rodzaj === 'reset' ? '/haslo/nowe' : '/zaproszenie';
        if (!OneTimeToken::isWellFormed($token)) {
            return Response::redirectFor($request, $this->urls->to('/logowanie') . '?powod=wylogowano');
        }

        return Response::redirectFor($request, $this->urls->to($sciezka) . '?token=' . rawurlencode($token));
    }

    private function render(
        ?string $notice,
        ?string $error,
        string $email,
        int $status = 200,
        string $rodzaj = Komunikat::SUKCES,
    ): Response {
        return $this->view->render('page/auth/login.twig', [
            'notice' => $notice,
            // ⚠ Rodzaj przychodzi z `REASONS` razem ze zdaniem — jedno miejsce, bo inaczej
            // zdanie i jego barwa rozjadą się przy pierwszym nowym powodzie (tak właśnie
            // „Sesja wygasła" dostała zielony ptaszek). Wyjaśnienie po wygasłym tokenie
            // renderuje `Http\OdnowienieFormularza` i ma własny rodzaj.
            'notice_rodzaj' => $rodzaj,
            'error'  => $error,
            'email'  => $email,
        ], $status);
    }
}
