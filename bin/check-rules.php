<?php

declare(strict_types=1);

// Strażnik reguł z CLAUDE.md. Uruchamiany przed każdym commitem.
// Kod wyjścia 1 przy dowolnym trafieniu. Opcjonalny argument: katalog do sprawdzenia.
if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

exit((new Wezurlop\Support\RuleChecker(rtrim($argv[1] ?? dirname(__DIR__), '/')))->report(STDOUT));
