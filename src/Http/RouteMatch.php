<?php

declare(strict_types=1);

namespace Wezurlop\Http;

/**
 * Wynik dopasowania ścieżki do tras.
 */
final class RouteMatch
{
    public const FOUND = 'found';
    public const NOT_FOUND = 'not_found';
    public const METHOD_NOT_ALLOWED = 'method_not_allowed';

    /**
     * @param array<string, string> $params
     * @param list<string> $allowedMethods
     */
    public function __construct(
        public readonly string $status,
        public readonly string $path,
        public readonly ?Route $route = null,
        public readonly array $params = [],
        public readonly array $allowedMethods = [],
    ) {
    }
}
