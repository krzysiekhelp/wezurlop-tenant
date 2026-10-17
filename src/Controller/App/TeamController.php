<?php

declare(strict_types=1);

namespace Wezurlop\Controller\App;

use Wezurlop\Http\Request;
use Wezurlop\Http\Response;
use Wezurlop\Support\View;

/**
 * Zespół — PUSTA RAMA (Etap 2): stan pusty, bez danych kadrowych.
 */
final class TeamController
{
    public function __construct(private readonly View $view)
    {
    }

    /** @param array<string, string> $params */
    public function index(Request $request, array $params): Response
    {
        return $this->view->render('team.twig', ['members' => []]);
    }
}
