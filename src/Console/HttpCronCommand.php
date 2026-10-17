<?php

declare(strict_types=1);

namespace Wezurlop\Console;

use Wezurlop\Service\CronHeartbeat;
use Wezurlop\Service\CronStan;
use Wezurlop\Support\Logger;
use Wezurlop\Support\Services;

/**
 * Przebieg kolejki wywołany przez HTTP (cron-05min.php na hostingach klasy home.pl).
 *
 * ⚠ Tego pliku nie da się zablokować po adresie — hosting woła go po HTTP z własnej infrastruktury.
 * Ochroną jest to, co tutaj: lock (jeden przebieg naraz), minimalny odstęp przebiegów, całkowite
 * ignorowanie wejścia (żadnego $_GET/$_POST/nagłówków — nic nie steruje przebiegiem) i odpowiedź
 * BEZ TREŚCI: 204 = przebieg wykonany, 429 + Retry-After = nic nie zrobiono (lock albo za wcześnie).
 * Blokada w konfiguracji serwera zostaje jako warstwa „jeśli się da”.
 */
final class HttpCronCommand
{
    /** Cron co 5 minut z zapasem na nierówny start. */
    public const MIN_INTERVAL_SECONDS = 240;

    public function __invoke(Services $services, Logger $logger): int
    {
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex');

        $var = $services->config->varPath;
        $lock = fopen($var . '/cron-http.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            http_response_code(429);
            header('Retry-After: ' . self::MIN_INTERVAL_SECONDS);

            return 0;
        }
        try {
            $stampFile = $var . '/cron-http.last';
            $last = is_file($stampFile) ? (int) file_get_contents($stampFile) : 0;
            $wait = $last + self::MIN_INTERVAL_SECONDS - time();
            if ($wait > 0) {
                http_response_code(429);
                header('Retry-After: ' . $wait);
                // ⚠ Znacznik odrzucenia, żeby ekran ustawień mógł pokazać WYNIK sprawdzenia,
                // a nie deklarację „odstęp jest wymuszony" (ta sama zasada co przy `.htaccess`:
                // obecność reguły nie dowodzi niczego).
                touch($var . CronStan::PLIK_ODRZUCEN);

                return 0;
            }
            file_put_contents($stampFile, (string) time(), LOCK_EX);
            ignore_user_abort(true);

            $runner = $services->jobRunner($logger);
            $stats = $runner->run(RunJobsCommand::BUDGET_SECONDS);
            (new CronHeartbeat($var))->touch();
            $logger->info('Cron HTTP', $stats);
            http_response_code(204);

            return 0;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
