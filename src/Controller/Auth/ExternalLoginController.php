<?php

declare(strict_types=1);

namespace Wezurlop\Controller\Auth;

use Wezurlop\Http\Request;
use Wezurlop\Http\Response;
use Wezurlop\Support\View;

/**
 * Logowanie przez Google/Apple (broker auth.wezurlop.pl) — w szkielecie tylko miejsce i przyciski
 * (docs/szkielet-tenanta.md rozdz. 6). Pełna integracja to osobny blok.
 */
final class ExternalLoginController
{
    private const PROVIDERS = ['google' => 'Google', 'apple' => 'Apple'];

    public function __construct(private readonly View $view)
    {
    }

    /** @param array<string, string> $params */
    public function start(Request $request, array $params): Response
    {
        $provider = self::PROVIDERS[$params['provider'] ?? ''] ?? null;

        return $this->view->render('page/auth/external-login.twig', ['provider' => $provider], 501);
    }
}
