<?php

declare(strict_types=1);

namespace Wezurlop\Controller\Auth;

use Wezurlop\Http\Request;
use Wezurlop\Http\Response;
use Wezurlop\Security\ClientFingerprint;
use Wezurlop\Security\Password;
use Wezurlop\Http\LinkHaslaView;
use Wezurlop\Service\PasswordResetService;
use Wezurlop\Support\UrlGenerator;
use Wezurlop\Support\View;

/**
 * Reset hasła: prośba (zawsze ta sama odpowiedź) i ustawienie nowego hasła z jednorazowego linku.
 */
final class ResetController
{
    public function __construct(
        private readonly View $view,
        private readonly PasswordResetService $reset,
        private readonly LinkHaslaView $linkHasla,
        private readonly ClientFingerprint $fingerprint,
        private readonly UrlGenerator $urls,
    ) {
    }

    /** @param array<string, string> $params */
    public function request(Request $request, array $params): Response
    {
        return $this->view->render('page/auth/reset-request.twig', [
            'sent'   => $request->query('wyslano') === '1',
            // Slot na spokojny komunikat po wygasłym tokenie (Z56 §1) — tu zawsze pusty.
            'notice' => null,
            'notice_rodzaj' => 'info',
            'email'  => '',
        ]);
    }

    /** @param array<string, string> $params */
    public function send(Request $request, array $params): Response
    {
        $email = trim($request->postString('email', 254));
        if ($email !== '') {
            // Tylko zapis zadania w kolejce — żądanie kończy się od razu, bez kontaktu z SMTP.
            $this->reset->request($email, $this->fingerprint->ipHash($request->serverParams()));
        }

        // Ta sama odpowiedź niezależnie od istnienia konta i od limitu.
        return Response::redirectFor($request, $this->urls->to('/haslo/reset') . '?wyslano=1');
    }

    /** @param array<string, string> $params */
    public function form(Request $request, array $params): Response
    {
        $token = $request->query('token');
        $konto = $this->reset->accountIdForToken($token);
        if ($konto === null) {
            return $this->view->render('page/auth/link-invalid.twig', ['kind' => 'reset'], 410)
                ->withHeader('Cache-Control', 'no-store');
        }
        $odpowiedz = $this->linkHasla->odpowiedz($request, $konto, 'reset', (string) $token);
        if ($odpowiedz !== null) {
            return $odpowiedz;
        }

        return $this->renderForm((string) $token, null);
    }

    /** @param array<string, string> $params */
    public function save(Request $request, array $params): Response
    {
        $token = $request->postString('token', 64);
        $password = $request->postString('password', 4096);
        if ($password !== $request->postString('password_repeat', 4096)) {
            return $this->renderForm($token, 'Hasła nie są identyczne.', 422);
        }
        $konto = $this->reset->accountIdForToken($token);
        $error = $this->reset->complete($token, $password, $this->fingerprint->ipHash($request->serverParams()));
        if ($error !== null) {
            return $this->renderForm($token, $error, 422);
        }
        if ($konto === null) {
            return Response::redirectFor($request, $this->urls->to('/logowanie') . '?powod=haslo-zmienione');
        }

        // ⚠ `complete()` unieważnia WSZYSTKIE sesje konta (§9) — ta nowa powstaje po tym,
        // więc użytkownik zostaje zalogowany, a wcześniejsze urządzenia wylatują. Tak ma być.
        return $this->linkHasla->poUstawieniuHasla($request, $konto);
    }

    private function renderForm(string $token, ?string $error, int $status = 200): Response
    {
        return $this->view->render('page/auth/reset-password.twig', [
            'token'      => $token,
            'notice'     => null,
            'notice_rodzaj' => 'info',
            'error'      => $error,
            'min_length' => Password::MIN_LENGTH,
        ], $status)->withHeader('Cache-Control', 'no-store');
    }
}
