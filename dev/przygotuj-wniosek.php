<?php

declare(strict_types=1);

// Wniosek do przebiegu integracyjnego (tylko APP_ENV=dev). Opis opcji: dev/Console/PrepareRequestCommand.php
// Nie jedzie do paczki klienta (dev/package-allowlist.txt).
if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

exit((new Wezurlop\Console\ConsoleKernel(dirname(__DIR__)))->run(
    new Wezurlop\Dev\Console\PrepareRequestCommand($argv, STDOUT),
    STDERR,
));
