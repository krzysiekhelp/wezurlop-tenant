<?php

declare(strict_types=1);

namespace Wezurlop\Controller\App;

use Wezurlop\Http\Request;
use Wezurlop\Http\Response;
use Wezurlop\Support\View;

/**
 * Wnioski urlopowe — PUSTE RAMY (Etap 2). Dane przykładowe wprost z kontrolera, bez zapisu.
 */
final class LeaveRequestController
{
    public function __construct(private readonly View $view)
    {
    }

    /** @param array<string, string> $params */
    public function index(Request $request, array $params): Response
    {
        return $this->view->render('requests.twig', [
            'requests' => [
                ['period' => '12–16 maja', 'type' => 'Urlop wypoczynkowy', 'days' => 5, 'status' => 'approved', 'label' => 'Zaakceptowany'],
                ['period' => '2 czerwca', 'type' => 'Urlop na żądanie', 'days' => 1, 'status' => 'pending', 'label' => 'Oczekuje'],
            ],
        ]);
    }

    /** @param array<string, string> $params */
    public function create(Request $request, array $params): Response
    {
        return $this->view->render('new-request.twig', [
            'types' => ['Urlop wypoczynkowy', 'Urlop na żądanie', 'Opieka nad dzieckiem'],
        ]);
    }
}
