<?php

declare(strict_types=1);

namespace Wezurlop\Http\Middleware;

use Wezurlop\Entity\CurrentUser;
use Wezurlop\Http\Request;
use Wezurlop\Http\Response;
use Wezurlop\Http\Route;
use Wezurlop\Http\ViewContext;
use Wezurlop\Repository\PermissionRepository;
use Wezurlop\Support\ErrorHandler;

/**
 * Uprawnienie wymagane przez trasę (docs/szkielet-tenanta.md rozdz. 4, pkt 7; CLAUDE.md §10).
 *
 * Uprawnienia z bazy dla aktywnej firmy (plus przypisania na całego tenanta). Trasy niezwiązane
 * z kontekstem firmy ('exempt' => ['context']) sprawdzane względem uprawnień w dowolnej firmie konta.
 */
final class Authorize implements Middleware
{
    public function __construct(
        private readonly PermissionRepository $permissions,
        private readonly ErrorHandler $errors,
        private readonly ViewContext $viewContext,
    ) {
    }

    public function process(Request $request, Route $route, callable $next): Response
    {
        $user = $request->attribute(Authenticate::USER);
        if ($user instanceof CurrentUser) {
            $companyId = $route->isExempt(Route::EXEMPT_CONTEXT)
                ? null
                : $user->activeEmployment()?->companyId;
            $codes = $route->isExempt(Route::EXEMPT_CONTEXT)
                ? $this->permissions->permissionsInAnyCompany($user->accountId())
                : $this->permissions->permissionsFor($user->accountId(), $companyId);
            $user = $user->withPermissions($codes);
            $request = $request->withAttribute(Authenticate::USER, $user);
            $this->viewContext->setUser($user);
        }

        if ($route->permission === null) {
            return $next($request);
        }
        if (!$user instanceof CurrentUser || !$user->can($route->permission)) {
            return $this->errors->errorPage(403, null, $request->isHtmx());
        }

        return $next($request);
    }
}
