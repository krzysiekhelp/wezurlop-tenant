<?php

declare(strict_types=1);

namespace Wezurlop\Http\Middleware;

use Wezurlop\Http\OdnowienieFormularza;
use Wezurlop\Http\Request;
use Wezurlop\Http\Response;
use Wezurlop\Http\Route;
use Wezurlop\Security\CsrfToken;
use Wezurlop\Support\ErrorHandler;
use Wezurlop\Support\UrlGenerator;

/**
 * Weryfikacja tokenu CSRF dla wszystkich metod poza GET/HEAD/OPTIONS (CLAUDE.md §7).
 *
 * Stoi przed Authenticate: sprawdzenie tokenu nie wymaga wiedzy, kim jest użytkownik,
 * a odwrotna kolejność pozwalałaby wymuszać akcje na sesji anonimowej (np. logowanie CSRF).
 * Token z pola `_csrf` albo z nagłówka `X-CSRF-Token` (htmx).
 *
 * ⚠ **Formularze sprzed zalogowania nie dostają strony 419, tylko siebie od nowa**
 * (`OdnowienieFormularza`, zlecenie Z56 §1). Odmowa zostaje odmową — żądanie nie wchodzi
 * do kontrolera, czyli nie loguje i nie zapisuje niczego; zmienia się wyłącznie to, co widzi
 * człowiek, który trzymał kartę otwartą przez godzinę.
 */
final class Csrf implements Middleware
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function __construct(
        private readonly CsrfToken $csrf,
        private readonly ErrorHandler $errors,
        private readonly string $expiredPageUrl,
        private readonly UrlGenerator $urls,
        private readonly OdnowienieFormularza $odnowienie,
    ) {
    }

    public function process(Request $request, Route $route, callable $next): Response
    {
        if (in_array($request->method(), self::SAFE_METHODS, true)) {
            return $next($request);
        }

        $sent = $request->post('_csrf') ?? $request->header('X-CSRF-Token');
        if ($this->csrf->isValid($sent)) {
            return $next($request);
        }

        // ⚠ PRZED gałęzią htmx, ale kolejność i tak jest bez znaczenia: ekrany sprzed
        // zalogowania nie mają ani jednego atrybutu `hx-*` (partials/scripts.twig).
        $odnowiony = $this->odnowienie->odpowiedz($request, $route);
        if ($odnowiony !== null) {
            return $odnowiony;
        }

        if ($request->isHtmx()) {
            // Strona 419 nie jest wstawiana w treść — htmx przechodzi na nią całą stroną.
            return Response::text('', 419)->withHeader('HX-Redirect', $this->expiredPageUrl);
        }

        // „Wróć” prowadzi na ścieżkę dopasowanej trasy (bez parametrów), nigdy na adres z Referer.
        $back = str_contains($route->path, '{') ? '/' : preg_replace('#^/m(?=/|$)#', '', $route->path);
        $back = $back === '' ? '/' : $back;

        return $this->errors->errorPage(419, null, false, [
            'back_url' => $this->urls->to($back),
        ]);
    }
}
