<?php

declare(strict_types=1);

// Diagnostyka środowiska w CLI. Opcje: bin/diagnose.php --help
if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

exit((new Wezurlop\Diagnostics\DiagnoseCommand(dirname(__DIR__)))->run($argv, STDOUT));
