<?php

declare(strict_types=1);

// Reset instalacji do stanu „jak po instalacji” — WYŁĄCZNIE przy APP_ENV=dev.
// Opis i warunki: dev/Console/ResetTenantCommand.php. Nie jedzie do paczki klienta.
if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

exit((new Wezurlop\Console\ConsoleKernel(dirname(__DIR__)))->run(
    new Wezurlop\Dev\Console\ResetTenantCommand($argv, STDOUT, dirname(__DIR__)),
    STDERR,
));
