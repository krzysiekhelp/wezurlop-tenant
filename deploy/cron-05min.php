<?php

declare(strict_types=1);

// SHIM crona uruchamianego przez HTTP (docs/profile-hostingu.md, profil ideawebserver).
// Nazwa narzucona przez hosting; instalator kopiuje ten plik do katalogu domeny WYŁĄCZNIE w profilu,
// który wymaga crona przez HTTP. Zero logiki i zero konfiguracji: jeden require runnera aplikacji.
// Położenie aplikacji — ten sam plik app-path.php co dla public/index.php (wariant 2), inaczej katalog wyżej.
require (is_file(__DIR__ . '/app-path.php') ? (require __DIR__ . '/app-path.php') : dirname(__DIR__)) . '/bin/cron-http.php';
