<?php

declare(strict_types=1);

namespace Wezurlop\Support;

use ErrorException;

/**
 * Jawna obsługa ostrzeżeń PHP z funkcji plikowych i sieciowych.
 *
 * Zamiast tłumienia operatorem `@` (zakazane, CLAUDE.md §14) ostrzeżenie zamieniane jest
 * na wyjątek, który wywołujący musi obsłużyć.
 */
final class ErrorGuard
{
    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     * @throws ErrorException gdy operacja zgłosi ostrzeżenie lub notatkę PHP
     */
    public static function run(callable $operation): mixed
    {
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        try {
            return $operation();
        } finally {
            restore_error_handler();
        }
    }
}
