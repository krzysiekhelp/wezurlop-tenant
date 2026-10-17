<?php

declare(strict_types=1);

namespace Wezurlop\Console;

use ErrorException;
use Throwable;
use Wezurlop\Support\Config;
use Wezurlop\Support\EnvFile;
use Wezurlop\Support\Logger;
use Wezurlop\Support\Services;

/**
 * Wspólne uruchamianie skryptów z bin/: konfiguracja z .env, log aplikacji, ostrzeżenia jako wyjątki.
 * Szczegóły błędów trafiają do storage/logs; na wyjście tylko identyfikator zdarzenia.
 */
final class ConsoleKernel
{
    public function __construct(private readonly string $appRoot)
    {
    }

    /**
     * @param callable(Services, Logger): int $command
     * @param resource $err
     */
    public function run(callable $command, $err): int
    {
        // Ta sama zasada co w Kernelu HTTP: proces pracuje w UTC, jawnie (docs §10 punkt 1).
        // Cron i narzędzia zapisują te same znaczniki co aplikacja — inaczej godziny by się rozjechały.
        date_default_timezone_set('UTC');

        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if ((error_reporting() & $severity) === 0 || $severity === E_DEPRECATED || $severity === E_USER_DEPRECATED) {
                return false;
            }
            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        $logger = new Logger($this->appRoot . '/storage/logs');
        try {
            $env = EnvFile::load(EnvFile::path($this->appRoot, []));
            $factory = require $this->appRoot . '/config/config.php';
            $config = Config::fromArray($factory($env, [], $this->appRoot));
            $logger->setDirectory($config->logPath());

            return $command(new Services($config), $logger);
        } catch (Throwable $e) {
            $eventId = bin2hex(random_bytes(8));
            $logger->error('Błąd skryptu CLI', [
                'event_id'  => $eventId,
                'exception' => $e::class,
                'error'     => $e->getMessage(),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
                'trace'     => $e->getTraceAsString(),
            ]);
            fwrite($err, 'Błąd. Identyfikator zdarzenia: ' . $eventId . " (szczegóły w storage/logs)\n");

            return 1;
        } finally {
            restore_error_handler();
        }
    }
}
