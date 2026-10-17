<?php

declare(strict_types=1);

namespace Wezurlop\Controller\Auth;

use Wezurlop\Http\Middleware\Authenticate;
use Wezurlop\Http\Request;
use Wezurlop\Http\Response;
use Wezurlop\Repository\AccountRepository;
use Wezurlop\Repository\EventLogRepository;
use Wezurlop\Security\AuthSession;
use Wezurlop\Security\ClientFingerprint;
use Wezurlop\Service\TwoFactorService;
use Wezurlop\Support\UrlGenerator;
use Wezurlop\Support\View;

/**
 * Drugi krok logowania: kod z aplikacji albo kod zapasowy (sesja częściowa, 5 minut).
 * Po poprawnym kodzie identyfikator sesji zmieniany drugi raz (CLAUDE.md §9).
 */
final class TwoFactorController
{
    private const FAILED = 'Kod jest nieprawidłowy. Przy kolejnych próbach odczekaj chwilę.';

    public function __construct(
        private readonly View $view,
        private readonly TwoFactorService $twoFactor,
        private readonly AuthSession $session,
        private readonly AccountRepository $accounts,
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

        return $this->render(null, $request->query('tryb') === 'zapasowy');
    }

    /** @param array<string, string> $params */
    public function verify(Request $request, array $params): Response
    {
        $accountId = $request->attribute(Authenticate::PARTIAL_ACCOUNT);
        if (!is_int($accountId)) {
            // Sesja pełna — drugi krok już za nią.
            return Response::redirectFor($request, $this->urls->to('/'));
        }
        $backup = $request->postString('mode', 10) === 'backup';
        $code = $request->postString('code', 32);
        $ipHash = $this->fingerprint->ipHash($request->serverParams());

        $ok = $code !== '' && ($backup
            ? $this->twoFactor->verifyBackupCode($accountId, $code, $ipHash)
            : $this->twoFactor->verifyTotp($accountId, $code, $ipHash));
        if (!$ok) {
            return $this->render(self::FAILED, $backup, 422);
        }

        $this->session->complete($accountId);
        $this->accounts->touchLastLogin($accountId);
        $this->events->record('auth.login_success', $accountId, $ipHash, null, null, null, null, [
            'second_factor' => $backup ? 'backup_code' : 'totp',
            'client'        => $this->session->client()?->client ?? 'web',
        ]);

        return Response::redirectFor($request, $this->urls->to('/'));
    }

    private function render(?string $error, bool $backup, int $status = 200): Response
    {
        return $this->view->render('page/auth/two-factor.twig', [
            'error'  => $error,
            'backup' => $backup,
        ], $status);
    }
}
