<?php

declare(strict_types=1);

namespace Wezurlop\Controller\App;

use Wezurlop\Http\Request;
use Wezurlop\Http\Response;
use Wezurlop\Support\View;

/**
 * /styleguide — wszystkie komponenty na jednym ekranie (docs/szkielet-tenanta.md rozdz. 8).
 * Trasa istnieje WYŁĄCZNIE przy APP_ENV=dev (config/routes.php); w produkcji nie ma jej wcale.
 * Dane są fikcyjne i stałe — strona nie czyta bazy.
 */
final class StyleguideController
{
    public function __construct(private readonly View $view)
    {
    }

    /** @param array<string, string> $params */
    public function index(Request $request, array $params): Response
    {
        return $this->view->render('styleguide.twig', [
            // ⚠ Nazwy tokenów NOWEGO systemu — stare (`navy`, `blue`, `pink`) znikają razem z `tokens.css`.
            'swatches' => ['c-bg', 'c-surface', 'c-surface-mute', 'c-line', 'c-line-strong',
                'c-text', 'c-text-3', 'c-accent', 'c-accent-soft', 'c-warn', 'c-warn-soft',
                'c-danger', 'c-danger-soft', 'c-neutral-soft'],
            'rows' => [
                ['name' => 'Anna Dwuetatowa', 'type' => 'Urlop wypoczynkowy', 'days' => 5, 'status' => 'pending', 'label' => 'Oczekuje'],
                ['name' => 'Jan Jednoetatowy', 'type' => 'Urlop na żądanie', 'days' => 1, 'status' => 'approved', 'label' => 'Zaakceptowany'],
                ['name' => 'Ewa Nowa', 'type' => 'Opieka nad dzieckiem', 'days' => 2, 'status' => 'danger', 'label' => 'Odrzucony'],
            ],
            'codes' => ['XXXX-XXXX', 'XXXX-XXXX', 'XXXX-XXXX', 'XXXX-XXXX'],
        ])->withHeader('Cache-Control', 'no-store');
    }
}
