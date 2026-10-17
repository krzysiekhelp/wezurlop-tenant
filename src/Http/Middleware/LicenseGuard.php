<?php

declare(strict_types=1);

namespace Wezurlop\Http\Middleware;

use Wezurlop\Entity\CurrentUser;
use Wezurlop\Http\Request;
use Wezurlop\Http\Response;
use Wezurlop\Http\Route;
use Wezurlop\License\LicenseGate;
use Wezurlop\Support\View;

/**
 * Tryb ograniczony licencji (docs/szkielet-tenanta.md rozdz. 4, pkt 6). Po uwierzytelnieniu —
 * komunikat ma zobaczyć zalogowany użytkownik, a ekran logowania ma działać.
 *
 * W trybie ograniczonym przepuszczane są wyłącznie: ekran licencji (uprawnienie admin.license)
 * i trasy sesji częściowej (wylogowanie). Bez nowych wyjątków w łańcuchu.
 */
final class LicenseGuard implements Middleware
{
    public function __construct(
        private readonly LicenseGate $license,
        private readonly View $view,
    ) {
    }

    public function process(Request $request, Route $route, callable $next): Response
    {
        if (!$request->attribute(Authenticate::USER) instanceof CurrentUser
            || !$route->requiresAuthentication()
            || $route->allowsPartial()
            || $route->permission === 'admin.license') {
            return $next($request);
        }
        $state = $this->license->state();
        if (!$state->isLimited()) {
            return $next($request);
        }

        if ($request->isHtmx()) {
            return $this->view->render('fragments/error.twig', ['status' => 403, 'event_id' => null], 403);
        }

        return $this->view->render('license-limited.twig', ['reason' => $state->reasonCode], 403)
            ->withHeader('Cache-Control', 'no-store');
    }
}
