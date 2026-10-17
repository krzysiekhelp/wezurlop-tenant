<?php

declare(strict_types=1);

namespace Wezurlop\Support;

use Twig\Environment;
use Wezurlop\Http\Response;

/**
 * Renderowanie szablonów Twig do odpowiedzi HTTP — jedyna droga powstawania HTML (CLAUDE.md §5).
 *
 * Kontroler nie wie, który layout zostanie użyty: nazwa bez katalogu („dashboard.twig”) trafia
 * do page/web/ albo page/mobile/ zależnie od wariantu (docs/szkielet-tenanta.md rozdz. 8).
 * Nazwy z katalogiem (page/auth/…, fragments/…) są używane wprost.
 */
final class View
{
    public function __construct(
        private readonly Environment $twig,
        private readonly UrlGenerator $urls,
    ) {
    }

    /** @param array<string, mixed> $data tylko dane do wyświetlenia (CLAUDE.md §3) */
    public function render(string $template, array $data = [], int $status = 200): Response
    {
        return Response::html($this->twig->render($this->resolve($template), $data), $status);
    }

    public function resolve(string $template): string
    {
        if (str_contains($template, '/')) {
            return $template;
        }
        if (preg_match('/^[a-z0-9-]+\.twig$/', $template) !== 1) {
            throw new \InvalidArgumentException('Nieprawidłowa nazwa widoku');
        }

        return 'page/' . $this->urls->variant() . '/' . $template;
    }
}
