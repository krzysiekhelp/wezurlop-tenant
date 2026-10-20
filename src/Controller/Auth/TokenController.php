<?php

declare(strict_types=1);

namespace Wezurlop\Controller\Auth;

use Wezurlop\Http\Request;
use Wezurlop\Http\Response;
use Wezurlop\Security\ClientFingerprint;
use Wezurlop\Security\CsrfToken;
use Wezurlop\Security\RateLimiter;

/**
 * Świeży token formularza dla karty, która stała otwarta (zlecenie Z56 §2).
 *
 * ⚠ **Tylko GET i tylko własny token sesji.** Odpowiedź nie zawiera ani jednej danej osobowej
 * i nie mówi, czy ktoś jest zalogowany — to ta sama wartość, którą ta przeglądarka dostaje
 * w każdym formularzu na stronie.
 *
 * ⚠ **Obca strona tego nie przeczyta**: odpowiedź nie ma nagłówków CORS, a `{"token": …}`
 * nie jest poprawnym programem JavaScript, więc nie da się jej wciągnąć przez `<script src>`.
 * Dlatego token w treści nie jest wyciekiem — wyciekiem byłoby wysłanie go pod obcy adres.
 *
 * ⚠ **Sesji nie mnoży.** Żądanie z ciasteczkiem używa istniejącej sesji; żądanie bez
 * ciasteczka zakłada jedną — dokładnie tak samo jak zwykłe wczytanie strony logowania.
 * Pętla bez ciasteczek zatrzymuje się na limicie adresu (`RateLimiter::KIND_TOKEN`).
 *
 * ⚠ **To wygoda, nie warunek działania.** Gdy endpoint odmówi albo nie będzie sieci,
 * zostaje siatka po stronie serwera (`Http\OdnowienieFormularza`) — człowiek i tak się zaloguje.
 */
final class TokenController
{
    public function __construct(
        private readonly CsrfToken $csrf,
        private readonly RateLimiter $limiter,
        private readonly ClientFingerprint $fingerprint,
    ) {
    }

    /** @param array<string, string> $params */
    public function token(Request $request, array $params): Response
    {
        $ipHash = $this->fingerprint->ipHash($request->serverParams());
        if ($this->limiter->isTokenLimited($ipHash)) {
            // Bez szczegółów i bez tokenu: odmowa ma nie zdradzać nawet tego, jak liczymy.
            return Response::json(['error' => 'limit'], 429)->withHeader('Cache-Control', 'no-store');
        }
        $this->limiter->recordTokenRequest($ipHash);

        return Response::json(['token' => $this->csrf->token()])->withHeader('Cache-Control', 'no-store');
    }
}
