<?php

declare(strict_types=1);

namespace Wezurlop\Controller\App;

use Wezurlop\Http\Request;
use Wezurlop\Http\Response;
use Wezurlop\Support\ErrorHandler;

/**
 * Strony systemowe dostępne bez logowania.
 */
final class SystemPageController
{
    public function __construct(private readonly ErrorHandler $errors)
    {
    }

    /**
     * Strona 419 — cel nagłówka HX-Redirect, gdy token CSRF w żądaniu htmx wygasł.
     *
     * @param array<string, string> $params
     */
    public function formExpired(Request $request, array $params): Response
    {
        return $this->errors->errorPage(419);
    }
}
