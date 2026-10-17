<?php

declare(strict_types=1);

namespace Wezurlop\Controller\Api;

use Wezurlop\Http\Response;
use Wezurlop\Service\Sync\ApiError;

/**
 * Format błędu API: { "blad": { "kod", "komunikat", "zdarzenieId", …extra } } — bez szczegółów
 * technicznych (CLAUDE.md §14). zdarzenieId łączy odpowiedź z wpisem w logu tenanta.
 */
final class ApiErrorResponse
{
    public static function from(ApiError $e, ?string $eventId = null): Response
    {
        $response = Response::json(['blad' => [
            'kod'         => $e->kod,
            'komunikat'   => $e->komunikat,
            'zdarzenieId' => $eventId ?? bin2hex(random_bytes(4)),
        ] + $e->extra], $e->status);
        foreach ($e->headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }
}
