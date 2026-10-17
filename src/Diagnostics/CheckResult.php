<?php

declare(strict_types=1);

namespace Wezurlop\Diagnostics;

/**
 * Wynik pojedynczego sprawdzenia środowiska.
 */
final class CheckResult
{
    public const OK = 'ok';
    public const WARN = 'warn';
    public const FAIL = 'fail';

    public function __construct(
        public readonly string $group,
        public readonly string $name,
        public readonly string $status,
        public readonly string $value,
        public readonly string $note = '',
    ) {
    }

    public function isBlocking(): bool
    {
        return $this->status === self::FAIL;
    }
}
