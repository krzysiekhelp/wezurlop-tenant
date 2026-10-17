<?php

declare(strict_types=1);

namespace Wezurlop\Controller\Auth;

use Wezurlop\Http\Request;
use Wezurlop\Http\Response;
use Wezurlop\Security\ClientFingerprint;
use Wezurlop\Security\Password;
use Wezurlop\Http\LinkHaslaView;
use Wezurlop\Service\InvitationService;
use Wezurlop\Support\UrlGenerator;
use Wezurlop\Support\View;

/**
 * Przyjęcie zaproszenia: ustawienie pierwszego hasła. Bez automatycznego logowania.
 */
final class InvitationController
{
    public function __construct(
        private readonly View $view,
        private readonly InvitationService $invitations,
        private readonly LinkHaslaView $linkHasla,
        private readonly ClientFingerprint $fingerprint,
        private readonly UrlGenerator $urls,
    ) {
    }

    /** @param array<string, string> $params */
    public function form(Request $request, array $params): Response
    {
        $token = $request->query('token');
        $konto = $this->invitations->accountIdForToken($token);
        if ($konto === null) {
            return $this->view->render('page/auth/link-invalid.twig', ['kind' => 'invitation'], 410)
                ->withHeader('Cache-Control', 'no-store');
        }
        // ⚠ Zalogowana sesja zmienia znaczenie tego linku — decyzja w jednym miejscu dla obu
        // ścieżek linku (`Service\LinkHasla`), bo reguła jest ta sama.
        $odpowiedz = $this->linkHasla->odpowiedz($request, $konto, 'zaproszenie', (string) $token);
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
        // ⚠ Konto z tokenu czytamy PRZED zapisem, bo zapis token zużywa — a po zapisie
        // trzeba wiedzieć, kogo zalogować.
        $konto = $this->invitations->accountIdForToken($token);
        $error = $this->invitations->accept($token, $password, $this->fingerprint->ipHash($request->serverParams()));
        if ($error !== null) {
            return $this->renderForm($token, $error, 422);
        }
        if ($konto === null) {
            return Response::redirectFor($request, $this->urls->to('/logowanie') . '?powod=konto-aktywne');
        }

        // ⚠ Świeża sesja właściciela tego konta (§2.3) — jedna droga dla obu linków.
        return $this->linkHasla->poUstawieniuHasla($request, $konto);
    }

    private function renderForm(string $token, ?string $error, int $status = 200): Response
    {
        return $this->view->render('page/auth/invitation.twig', [
            'token'      => $token,
            'error'      => $error,
            'min_length' => Password::MIN_LENGTH,
        ], $status)->withHeader('Cache-Control', 'no-store');
    }
}
