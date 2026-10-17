<?php

declare(strict_types=1);

namespace Wezurlop\Controller\App;

use Wezurlop\Http\Request;
use Wezurlop\Http\Response;
use Wezurlop\Support\View;

/**
 * Konto użytkownika: skróty do bezpieczeństwa konta, urządzeń i wyboru firmy.
 */
final class ProfileController
{
    public function __construct(private readonly View $view)
    {
    }

    /** @param array<string, string> $params */
    public function index(Request $request, array $params): Response
    {
        return $this->view->render('profile.twig');
    }
}
