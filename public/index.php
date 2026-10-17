<?php

declare(strict_types=1);

// Front controller — obok niego w public/ może leżeć wyłącznie app-path.php (ścieżka aplikacji,
// pisana przez instalator). Cała logika w src/ (Wezurlop\Http\Kernel).
//
// Położenie aplikacji (CLAUDE.md §0, docs/profile-hostingu.md) — w tej kolejności:
//   1. public/app-path.php — plik pisany przez instalator (wariant 2), działa na każdym serwerze,
//   2. zmienna WEZURLOP_APP_PATH (SetEnv albo RewriteRule [E=…]) — tylko tam, gdzie serwer ją zna,
//   3. katalog nad public/ (wariant 1).
(static function (): void {
    $fromFile = is_file(__DIR__ . '/app-path.php') ? (require __DIR__ . '/app-path.php') : null;
    $fromEnv = getenv('WEZURLOP_APP_PATH') ?: ($_SERVER['WEZURLOP_APP_PATH'] ?? $_SERVER['REDIRECT_WEZURLOP_APP_PATH'] ?? '');
    $appPath = match (true) {
        is_string($fromFile) && $fromFile !== '' => rtrim($fromFile, '/'),
        is_string($fromEnv) && $fromEnv !== ''   => rtrim($fromEnv, '/'),
        default                                  => dirname(__DIR__),
    };

    if (!is_file($appPath . '/vendor/autoload.php')) {
        // Bez autoloadera nie ma Kernela ani szablonów — minimalny komunikat bez szczegółów ścieżki.
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        echo "Nie znaleziono plików aplikacji. Sprawdź app-path.php albo WEZURLOP_APP_PATH.\n";
        return;
    }

    require $appPath . '/vendor/autoload.php';

    $kernel = new Wezurlop\Http\Kernel($appPath);
    $kernel->handle(Wezurlop\Http\Request::fromGlobals())->send();
    // Po wysłaniu odpowiedzi: domknięcie kolejki, gdy włączone QUEUE_ON_REQUEST (cron rzadki).
    $kernel->afterResponse();
})();
