<?php

declare(strict_types=1);

namespace Wezurlop\Http\Middleware;

use Wezurlop\Http\Request;
use Wezurlop\Http\Response;
use Wezurlop\Http\Route;

/**
 * Element łańcucha middleware (docs/szkielet-tenanta.md rozdz. 4). Kolejność łańcucha jest częścią
 * zabezpieczenia — każdy element zakłada, że poprzednie już się wykonały.
 */
interface Middleware
{
    /** @param callable(Request): Response $next */
    public function process(Request $request, Route $route, callable $next): Response;
}
