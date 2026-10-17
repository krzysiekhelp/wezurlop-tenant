<?php

declare(strict_types=1);

namespace Wezurlop\Http\Middleware;

use Wezurlop\Entity\CurrentUser;
use Wezurlop\Http\Request;
use Wezurlop\Http\Response;
use Wezurlop\Http\Route;
use Wezurlop\Repository\PermissionRepository;
use Wezurlop\Service\TwoFactorService;

/**
 * Wymuszenie 2FA (docs/szkielet-tenanta.md rozdz. 4, pkt 4; rozdz. 6, pkt 7).
 *
 * - konto z rolą wymagającą 2FA (tenant_admin), które go nie ma → tylko ekran parowania,
 * - kody zapasowe czekające na potwierdzenie „zapisałem je” → tylko ekran kodów.
 * Wyjątek: trasy 'exempt' => ['2fa'] (parowanie, kody, wylogowanie).
 */
final class Require2fa implements Middleware
{
    public function __construct(
        private readonly PermissionRepository $permissions,
        private readonly TwoFactorService $twoFactor,
        private readonly string $setupUrl,
        private readonly string $codesUrl,
        private readonly bool $adminTwoFactorRequired = true,
    ) {
    }

    public function process(Request $request, Route $route, callable $next): Response
    {
        $user = $request->attribute(Authenticate::USER);
        if (!$user instanceof CurrentUser || !$route->requiresAuthentication() || $route->isExempt(Route::EXEMPT_2FA)) {
            return $next($request);
        }
        if ($this->twoFactor->hasCodesToShow($user->accountId())) {
            return Response::redirectFor($request, $this->codesUrl);
        }
        // ⚠ Wyłączony wymóg 2FA to stan obniżony (ADMIN_2FA_REQUIRED=0) — widoczny na /diagnostyka.
        if ($this->adminTwoFactorRequired && !$user->twoFactorEnabled && $this->permissions->requiresTwoFactor($user->accountId())) {
            return Response::redirectFor($request, $this->setupUrl);
        }

        return $next($request);
    }
}
