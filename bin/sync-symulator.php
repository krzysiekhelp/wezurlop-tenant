<?php

declare(strict_types=1);

// Symulator connectora R2 (tylko APP_ENV=dev — odtwarza bazę z seeda). Opis opcji: dev/Console/SyncSimulatorCommand.php. Nie jedzie do paczki klienta (dev/build-package.sh)
if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

exit((new Wezurlop\Console\ConsoleKernel(dirname(__DIR__)))->run(new Wezurlop\Dev\Console\SyncSimulatorCommand($argv, STDOUT, dirname(__DIR__)), STDERR));
