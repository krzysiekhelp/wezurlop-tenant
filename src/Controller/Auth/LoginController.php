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
use Wezurlop\Support\UrlGenerator;
use Wezurlop\Support\View;

/**
 * Logowanie hasłem i wylogowanie (docs/szkielet-tenanta.md rozdz. 6).
 */
final class LoginController
{
    /** Jeden komunikat dla wszystkich niepowodzeń — także opóźnienia (nie zdradza istnienia konta). */
    private const FAILED = 'Nieprawidłowy login lub hasło. Przy kolejnych próbach odczekaj chwilę — jeśli nie pamiętasz hasła, zresetuj je.';

    /** Powody przekierowania na ekran logowania — biała lista (CLAUDE.md §11). */
    private const REASONS = [
        'wygasla'         => 'Sesja wygasła. Zaloguj się ponownie.',
        'wylogowano'      => 'Wylogowano.',
        'sesja-niewazna'  => 'Sesja jest nieważna (np. po zmianie hasła). Zaloguj się ponownie.',
        'haslo-zmienione' => 'Hasło zostało zmienione. Zaloguj się nowym hasłem.',
        'konto-aktywne'   => 'Konto jest aktywne. Zaloguj się ustawionym hasłem.',
        'sesja-zakonczona' => 'Ta sesja została zakończona (np. z innego urządzenia). Zaloguj się ponownie.',
        // ⚠ Ten powód wystawia WYŁĄCZNIE `Authenticate`, gdy działającą sesję przerwała blokada
        // konta (zlecenie `blokady-przyjete-i-etap6` §4). Zwykłe logowanie go nie używa: hasło
        // błędne i konto zablokowane dają ten sam `FAILED`, bo inaczej ekran logowania
        // odpowiadałby na pytanie „czy takie konto istnieje" (CLAUDE.md §9).
        'konto-zablokowane' => 'Dostęp do tego konta został zablokowany, dlatego praca została przerwana. '
            . 'Jeśli to pomyłka, skontaktuj się z administratorem systemu.',
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

        return $this->render(is_string($reason) ? (self::REASONS[$reason] ?? null) : null, null, '');
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

    private function render(?string $notice, ?string $error, string $email, int $status = 200): Response
    {
        return $this->view->render('page/auth/login.twig', [
            'notice' => $notice,
            'error'  => $error,
            'email'  => $email,
        ], $status);
    }
}
