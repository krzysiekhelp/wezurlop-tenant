<?php

declare(strict_types=1);

// Zaproszenie nowej osoby. Opis opcji: src/Console/InviteCommand.php
if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

exit((new Wezurlop\Console\ConsoleKernel(dirname(__DIR__)))->run(new Wezurlop\Console\InviteCommand($argv, STDOUT), STDERR));
