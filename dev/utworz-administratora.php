<?php

declare(strict_types=1);

// Konto z hasłem ustawionym poleceniem (tylko APP_ENV=dev; u klienta jednorazowy link — CLAUDE.md §15).
// Opis opcji: dev/Console/CreateAdminCommand.php. Nie jedzie do paczki klienta.
if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

exit((new Wezurlop\Console\ConsoleKernel(dirname(__DIR__)))->run(
    new Wezurlop\Dev\Console\CreateAdminCommand($argv, STDOUT, dirname(__DIR__)),
    STDERR,
));
