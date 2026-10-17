<?php

declare(strict_types=1);

namespace Wezurlop\Service\Install;

use ErrorException;
use RuntimeException;
use Wezurlop\Support\ErrorGuard;

/**
 * Zapis .env przez instalator (poza webrootem). Plik jest nadpisywany W MIEJSCU — katalog aplikacji
 * nie musi być zapisywalny dla serwera WWW, wystarczy sam plik przygotowany przez wdrażającego.
 */
final class EnvWriter
{
    /** @param array<string, string> $values */
    public static function render(array $values): string
    {
        $lines = ["# Wygenerowane przez instalator wezurlop " . gmdate('Y-m-d H:i') . " UTC. Plik poza repozytorium."];
        foreach ($values as $key => $value) {
            if (preg_match('/^[A-Z][A-Z0-9_]*$/', $key) !== 1) {
                throw new RuntimeException('Nieprawidłowy klucz konfiguracji');
            }
            if (preg_match('/[\r\n]/', $value) === 1) {
                throw new RuntimeException('Wartość konfiguracji nie może zawierać znaku nowej linii: ' . $key);
            }
            // Wartości w cudzysłowach; phpdotenv rozwija ${...} tylko w podwójnych — używamy pojedynczych.
            $lines[] = $key . "='" . str_replace("'", '', $value) . "'";
        }

        return implode("\n", $lines) . "\n";
    }

    /** @param array<string, string> $values */
    public static function write(string $file, array $values): void
    {
        foreach ($values as $key => $value) {
            if (str_contains($value, "'")) {
                throw new RuntimeException('Wartość ' . $key . ' zawiera niedozwolony apostrof');
            }
        }
        $content = self::render($values);
        try {
            ErrorGuard::run(static function () use ($file, $content): void {
                $handle = fopen($file, 'c+');
                try {
                    flock($handle, LOCK_EX);
                    ftruncate($handle, 0);
                    rewind($handle);
                    fwrite($handle, $content);
                    fflush($handle);
                    flock($handle, LOCK_UN);
                } finally {
                    fclose($handle);
                }
            });
        } catch (ErrorException $e) {
            throw new RuntimeException('Nie można zapisać pliku .env — nadaj serwerowi WWW prawo zapisu do tego pliku na czas instalacji', 0, $e);
        }
    }

    /** Prawa po instalacji: odczyt dla właściciela i grupy, bez zapisu grupy i bez dostępu dla innych. */
    public const RESTRICTED_MODE = 0640;

    /** Zaciśnięcie praw po zapisie. Nieudany chmod to błąd, nie ostrzeżenie (rozdz. 12, krok 11). */
    public static function restrict(string $file): void
    {
        try {
            ErrorGuard::run(static fn (): bool => chmod($file, self::RESTRICTED_MODE));
        } catch (ErrorException $e) {
            throw new RuntimeException('Nie można ustawić praw 0640 pliku .env — ustaw je ręcznie i powtórz ten krok', 0, $e);
        }
        clearstatcache(true, $file);
        if (!self::isRestricted($file)) {
            throw new RuntimeException('Plik .env nadal ma zbyt szerokie prawa po chmod');
        }
    }

    /** Czy prawa są 0640 lub ciaśniejsze (brak zapisu dla grupy i jakiegokolwiek dostępu dla innych). */
    public static function isRestricted(string $file): bool
    {
        $perms = fileperms($file);

        return $perms !== false && ($perms & 0o137) === 0;
    }
}
