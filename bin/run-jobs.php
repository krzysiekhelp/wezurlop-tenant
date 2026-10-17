<?php

declare(strict_types=1);

// Kolejka zadań (wysyłka poczty). Cron co 5 minut, jako ten sam użytkownik co PHP serwera WWW.
if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

exit((new Wezurlop\Console\ConsoleKernel(dirname(__DIR__)))->run(new Wezurlop\Console\RunJobsCommand(STDOUT), STDERR));
