<?php

declare(strict_types=1);

// Wejście runnera kolejki dla crona uruchamianego PRZEZ HTTP (dołączane przez shim cron-05min.php).
// Osiągalne z internetu, więc: lock i minimalny odstęp przebiegów w PHP (Console\HttpCronCommand),
// niezależnie od blokady w konfiguracji serwera. Pod CLI — zwykły bin/run-jobs.php.
if (PHP_SAPI === 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

// Zero treści w odpowiedzi — także przy błędzie (szczegóły w storage/logs pod identyfikatorem zdarzenia).
if ((new Wezurlop\Console\ConsoleKernel(dirname(__DIR__)))->run(new Wezurlop\Console\HttpCronCommand(), fopen('php://memory', 'wb')) !== 0) {
    http_response_code(500);
}
