<?php

declare(strict_types=1);

namespace Wezurlop\Support;

use Dotenv\Dotenv;
use Dotenv\Exception\ExceptionInterface as DotenvException;
use RuntimeException;

/**
 * Plik .env: położenie i odczyt.
 *
 * Położenie z WEZURLOP_ENV_PATH (zmienna serwera/środowiska), domyślnie korzeń aplikacji —
 * w wariancie „aplikacja powyżej katalogu domeny” .env leży gdzie indziej (CLAUDE.md §0).
 * Wartości czytane do tablicy, bez zapisu do $_ENV/$_SERVER/putenv.
 */
final class EnvFile
{
    /** @param array<string, mixed> $server */
    public static function path(string $appRoot, array $server): string
    {
        $configured = $server['WEZURLOP_ENV_PATH'] ?? getenv('WEZURLOP_ENV_PATH');

        return is_string($configured) && $configured !== '' ? $configured : $appRoot . '/.env';
    }

    /**
     * Czy plik istnieje. Istniejący, ale nieczytelny plik to błąd konfiguracji serwera,
     * a nie brak instalacji — nie wolno wtedy kierować do instalatora.
     *
     * @throws RuntimeException gdy plik istnieje, ale nie da się go odczytać
     */
    public static function exists(string $file): bool
    {
        if (!is_file($file)) {
            return false;
        }
        if (!is_readable($file)) {
            throw new RuntimeException('Plik .env istnieje, ale serwer nie ma prawa odczytu');
        }

        return true;
    }

    /**
     * @return array<string, string>
     * @throws RuntimeException gdy pliku nie da się odczytać albo ma błędną składnię
     */
    public static function load(string $file): array
    {
        if (!self::exists($file)) {
            throw new RuntimeException('Brak pliku konfiguracji .env');
        }
        try {
            $values = Dotenv::createArrayBacked(dirname($file), basename($file))->load();
        } catch (DotenvException $e) {
            throw new RuntimeException('Błędna składnia pliku .env', 0, $e);
        }

        return array_map(static fn (?string $v): string => (string) $v, $values);
    }
}
