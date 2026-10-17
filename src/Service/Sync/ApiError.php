<?php

declare(strict_types=1);

namespace Wezurlop\Service\Sync;

use RuntimeException;

/**
 * Błąd protokołu API connectora (docs/api-connector.md §4). Odpowiedź: { "blad": { kod, komunikat,
 * zdarzenieId, …extra } } — BEZ szczegółów technicznych (CLAUDE.md §14); szczegóły tylko w logu.
 * 401 zatrzymuje cały connector, 403 tylko tę firmę.
 */
final class ApiError extends RuntimeException
{
    /**
     * @param array<string, mixed> $extra dodatkowe pola w obiekcie `blad` (pozycje, maks, obslugiwane)
     * @param array<string, string> $headers np. Retry-After
     * @param array<string, scalar|null> $log kontekst WYŁĄCZNIE do logu (np. NIP przy 403) — nigdy do odpowiedzi
     */
    public function __construct(
        public readonly int $status,
        public readonly string $kod,
        public readonly string $komunikat,
        public readonly array $extra = [],
        public readonly array $headers = [],
        public readonly array $log = [],
    ) {
        parent::__construct($kod);
    }
}
