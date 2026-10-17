<?php

declare(strict_types=1);

namespace Wezurlop\Support;

use ErrorException;

/**
 * Log aplikacji: jeden wiersz JSON na zdarzenie, plik dzienny w storage/logs (poza webrootem).
 *
 * Do kontekstu nie wolno przekazywać haseł, tokenów, sekretów ani pełnych danych osobowych
 * (CLAUDE.md §9, §14) — logger ich nie filtruje, odpowiedzialność jest po stronie wywołującego.
 */
final class Logger
{
    public function __construct(private string $directory)
    {
    }

    public function setDirectory(string $directory): void
    {
        $this->directory = $directory;
    }

    /** Czy o nieudanym nadaniu praw już powiedzieliśmy — raz na proces, nie przy każdym wpisie. */
    private static bool $prawaZglaszone = false;

    /**
     * Prawa grupy na nowo utworzonym pliku logu.
     *
     * ⚠ Powód jest zmierzony, nie teoretyczny: katalog `storage/logs` jest zapisywalny dla grupy
     * (setgid, więc pliki dziedziczą grupę), ale PHP tworzy plik z `umask` procesu — pod Apache
     * daje to `0644`. Skutek: wpis dzienny tworzy ten proces, który akurat był pierwszy, a drugi
     * (cron z konta powłoki albo odwrotnie) nie może już do tego pliku dopisać i cała jego
     * diagnostyka leci do logu serwera. `0664` naprawia to bez konfiguracji systemu, której
     * u klienta nie kontrolujemy.
     *
     * ⚠ Nieudany `chmod` NIE MOŻE wywrócić zapisu do logu: kod, który przy problemie z logiem
     * rzuca wyjątkiem, zamienia usterkę diagnostyki w awarię aplikacji. Wpis już jest na dysku;
     * brak praw grupy odnotowujemy raz i pracujemy dalej.
     */
    private static function prawaGrupy(string $file): void
    {
        try {
            ErrorGuard::run(static fn (): bool => chmod($file, 0664));
        } catch (ErrorException $e) {
            if (!self::$prawaZglaszone) {
                self::$prawaZglaszone = true;
                error_log('wezurlop: nie udało się nadać praw 0664 plikowi logu (' . $file
                    . ') — zapis działa, ale inny użytkownik systemu może nie móc dopisywać; '
                    . $e->getMessage());
            }
        }
    }

    /** @param array<string, mixed> $context */
    public function error(string $message, array $context = []): void
    {
        $this->write('error', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function warning(string $message, array $context = []): void
    {
        $this->write('warning', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function info(string $message, array $context = []): void
    {
        $this->write('info', $message, $context);
    }

    /** @param array<string, mixed> $context */
    private function write(string $level, string $message, array $context): void
    {
        $line = json_encode(
            ['time' => date('c'), 'level' => $level, 'message' => $message] + $context,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR
        );
        $file = $this->directory . '/app-' . date('Y-m-d') . '.log';
        $nowy = !is_file($file);

        try {
            ErrorGuard::run(static fn (): int|false => file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX));
            if ($nowy) {
                self::prawaGrupy($file);
            }
        } catch (ErrorException) {
            // Katalog logów niedostępny — ostatnia deska ratunku to log serwera, nie ekran.
            error_log('wezurlop: nie można zapisać logu aplikacji; ' . $line);
        }
    }
}
