<?php

declare(strict_types=1);

namespace Wezurlop\Controller\App;

use Wezurlop\Entity\CurrentUser;
use Wezurlop\Http\Middleware\Authenticate;
use Wezurlop\Http\Request;
use Wezurlop\Http\Response;
use Wezurlop\Repository\PermissionRepository;
use Wezurlop\Security\ClientFingerprint;
use Wezurlop\Security\Totp;
use Wezurlop\Service\TwoFactorService;
use Wezurlop\Support\UrlGenerator;
use Wezurlop\Support\ErrorHandler;
use Wezurlop\Support\View;

/**
 * Ustawienia 2FA własnego konta (docs/szkielet-tenanta.md rozdz. 6).
 * Kod QR osobnym endpointem jako SVG — w szablonie zwykły <img>, bez |raw.
 */
final class SecurityController
{
    public function __construct(
        private readonly View $view,
        private readonly TwoFactorService $twoFactor,
        private readonly PermissionRepository $permissions,
        private readonly ClientFingerprint $fingerprint,
        private readonly ErrorHandler $errors,
        private readonly UrlGenerator $urls,
    ) {
    }

    /** @param array<string, string> $params */
    public function index(Request $request, array $params): Response
    {
        return $this->renderIndex($this->user($request), null);
    }

    /** @param array<string, string> $params */
    public function start(Request $request, array $params): Response
    {
        $user = $this->user($request);
        if (!$user->twoFactorEnabled) {
            $this->twoFactor->startPairing($user->accountId());
        }

        return Response::redirectFor($request, $this->urls->to('/ustawienia/2fa'));
    }

    /** @param array<string, string> $params */
    public function qr(Request $request, array $params): Response
    {
        $svg = $this->twoFactor->qrSvg($this->user($request)->accountId());
        if ($svg === null) {
            return $this->errors->errorPage(404);
        }

        return (new Response($svg, 200, ['Content-Type' => 'image/svg+xml']))
            ->withHeader('Cache-Control', 'no-store');
    }

    /** @param array<string, string> $params */
    public function enable(Request $request, array $params): Response
    {
        $user = $this->user($request);
        $error = $this->twoFactor->confirmPairing(
            $user->accountId(),
            $request->postString('code', 16),
            $this->fingerprint->ipHash($request->serverParams()),
        );
        if ($error !== null) {
            return $this->renderIndex($user, $error, 422);
        }

        return Response::redirectFor($request, $this->urls->to('/ustawienia/2fa/kody'));
    }

    /** @param array<string, string> $params */
    public function codes(Request $request, array $params): Response
    {
        $codes = $this->twoFactor->codesToShow($this->user($request)->accountId());
        if ($codes === null) {
            return Response::redirectFor($request, $this->urls->to('/ustawienia/2fa'));
        }

        return $this->view->render('two-factor-codes.twig', ['codes' => $codes, 'error' => null])
            ->withHeader('Cache-Control', 'no-store');
    }

    /**
     * Pobranie kodów jako pliku tekstowego — tylko dopóki czekają na potwierdzenie.
     *
     * @param array<string, string> $params
     */
    public function download(Request $request, array $params): Response
    {
        $codes = $this->twoFactor->codesToShow($this->user($request)->accountId());
        if ($codes === null) {
            return $this->errors->errorPage(404);
        }
        $body = "wezurlop — kody zapasowe 2FA\nKażdy kod działa jeden raz.\n\n" . implode("\n", $codes) . "\n";

        return Response::text($body)
            ->withHeader('Content-Disposition', 'attachment; filename="wezurlop-kody-zapasowe.txt"')
            ->withHeader('Cache-Control', 'no-store');
    }

    /** @param array<string, string> $params */
    public function acknowledge(Request $request, array $params): Response
    {
        $user = $this->user($request);
        $codes = $this->twoFactor->codesToShow($user->accountId());
        if ($codes === null) {
            return Response::redirectFor($request, $this->urls->to('/ustawienia/2fa'));
        }
        if ($request->postString('saved', 3) !== '1') {
            return $this->view->render('two-factor-codes.twig', [
                'codes' => $codes,
                'error' => 'Potwierdź, że kody zostały zapisane.',
            ], 422)->withHeader('Cache-Control', 'no-store');
        }
        $this->twoFactor->acknowledgeCodes($user->accountId());

        return Response::redirectFor($request, $this->urls->to('/ustawienia/2fa'));
    }

    /** @param array<string, string> $params */
    public function disable(Request $request, array $params): Response
    {
        $user = $this->user($request);
        $error = $this->twoFactor->disable(
            $user->accountId(),
            $request->postString('password', 4096),
            $request->postString('code', 16),
            $this->fingerprint->ipHash($request->serverParams()),
        );
        if ($error !== null) {
            return $this->renderIndex($user, $error, 422);
        }

        return Response::redirectFor($request, $this->urls->to('/ustawienia/2fa'));
    }

    private function renderIndex(CurrentUser $user, ?string $error, int $status = 200): Response
    {
        // Stan 2FA czytany na nowo (mógł się zmienić w tym żądaniu).
        $enabled = $this->twoFactor->isEnabled($user->accountId());
        $pendingSecret = $enabled ? null : $this->twoFactor->pendingSecret($user->accountId());

        return $this->view->render('two-factor.twig', [
            'enabled'    => $enabled,
            'required'   => $this->permissions->requiresTwoFactor($user->accountId()),
            'pairing'    => $pendingSecret !== null,
            'secret'     => $pendingSecret !== null ? Totp::formatSecret($pendingSecret) : null,
            'codes_left' => $enabled ? $this->twoFactor->unusedBackupCodes($user->accountId()) : 0,
            'error'      => $error,
        ], $status)->withHeader('Cache-Control', 'no-store');
    }

    private function user(Request $request): CurrentUser
    {
        $user = $request->attribute(Authenticate::USER);
        assert($user instanceof CurrentUser);

        return $user;
    }
}
