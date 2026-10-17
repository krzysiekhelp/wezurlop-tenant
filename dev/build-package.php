<?php

declare(strict_types=1);

// Paczka dla klienta (wywoływane z dev/build-package.sh — tylko na maszynie budującej, nie jedzie w paczce).
//   php dev/build-package.php copy <katalog>    — kopiuje pliki śledzone w git (lista ze stdin) zgodne z listą
//   php dev/build-package.php verify <katalog>  — każdy plik paczki musi pasować do listy albo być vendor/**,
//                                                 a autoload nie może znać przestrzeni Wezurlop\Dev
if (PHP_SAPI !== 'cli' || count($argv) !== 3 || !in_array($argv[1], ['copy', 'verify'], true)) {
    fwrite(STDERR, "Użycie: php dev/build-package.php copy|verify <katalog>\n");
    exit(2);
}

$root = dirname(__DIR__);
// Autoload potrzebny w OBU trybach: `copy` liczy manifest, `verify` sprawdza jego zawartość.
require $root . '/vendor/autoload.php';
$patterns = array_values(array_filter(array_map('trim', file($root . '/dev/package-allowlist.txt') ?: []),
    static fn (string $l): bool => $l !== '' && !str_starts_with($l, '#')));
$allowed = static function (string $path) use ($patterns): bool {
    foreach ($patterns as $p) {
        if (str_ends_with($p, '/**') ? str_starts_with($path, substr($p, 0, -2)) : $path === $p) {
            return true;
        }
    }

    return false;
};
$out = rtrim($argv[2], '/');

if ($argv[1] === 'copy') {
    $copied = 0;
    while (($line = fgets(STDIN)) !== false) {
        $path = trim($line);
        if ($path === '' || !$allowed($path) || !is_file($root . '/' . $path)) {
            continue;
        }
        if (!is_dir(dirname($out . '/' . $path)) && !mkdir(dirname($out . '/' . $path), 0755, true)) {
            fwrite(STDERR, "Nie można utworzyć katalogu dla {$path}\n");
            exit(1);
        }
        if (!copy($root . '/' . $path, $out . '/' . $path)) {
            fwrite(STDERR, "Nie można skopiować {$path}\n");
            exit(1);
        }
        $copied++;
    }
    foreach (['var', 'var/cache'] as $dir) {
        if (!is_dir($out . '/' . $dir)) {
            mkdir($out . '/' . $dir, 0755, true);
        }
    }
    // ⚠ Manifest wersji zasobów — SKRÓT TREŚCI per plik, liczony przy budowaniu paczki.
    // W paczce nie ma `filemtime` do niczego: kopiowanie i rozpakowanie ustawiają czasy pliku
    // po swojemu, więc data pliku u klienta nie mówi nic o wersji treści. Skrót mówi.
    // ⚠ Nowy adres dostają TYLKO zmienione pliki, więc aktualizacja nie unieważnia całego
    // cache'u przeglądarki — a plik, który się zmienił, unieważnia się na pewno.
    // ⚠ Numer wydania przychodzi ze skryptu powłoki (`WYDANIE`), nie z `exec` w PHP —
    // uruchamianie poleceń z kodu jest zakazane (CLAUDE.md §14), a `dev/build-package.sh`
    // i tak stoi w repozytorium i zna `git`.
    $wydanie = trim((string) getenv('WYDANIE'));
    if ($wydanie === '') {
        fwrite(STDERR, "Brak numeru wydania (zmienna WYDANIE) — buduj przez dev/build-package.sh\n");
        exit(1);
    }
    $wersje = Wezurlop\Support\AssetVersions::zbuduj($out . '/public/assets', $wydanie);
    if ($wersje === []) {
        fwrite(STDERR, "Manifest wersji zasobów jest pusty — czy public/assets trafiło do paczki?\n");
        exit(1);
    }
    $plik = $out . '/public/assets/' . Wezurlop\Support\AssetVersions::MANIFEST;
    if (file_put_contents($plik, json_encode($wersje, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) {
        fwrite(STDERR, "Nie można zapisać {$plik}\n");
        exit(1);
    }
    echo "skopiowano plików: {$copied}, wersji zasobów w manifeście: " . count($wersje) . "\n";
    exit(0);
}

$errors = [];
$count = 0;
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($out, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    $path = substr($file->getPathname(), strlen($out) + 1);
    $count++;
    if (!str_starts_with($path, 'vendor/') && !$allowed($path)) {
        $errors[] = 'spoza listy: ' . $path;
    }
}
// ⚠ Lista jawna, choć lista dopuszczonych i tak by tego nie przepuściła — narzędzie
// deweloperskie w paczce klienta to powierzchnia ataku, więc wolę dwa sprawdzenia niż jedno.
foreach (['bin/sync-symulator.php', 'bin/check-rules.php', 'bin/inwentarz-klas.php',
          'dev', 'tests', 'docs', 'db/seeds', '.env', 'CLAUDE.md'] as $forbidden) {
    if (file_exists($out . '/' . $forbidden)) {
        $errors[] = 'w paczce jest ' . $forbidden;
    }
}
// ⚠ Manifest musi BYĆ i musi pokrywać to, co ładuje layout. Paczka bez manifestu wyglądałaby
// poprawnie i podawałaby wszystkie zasoby pod jedną wersją z `.env` — czyli wracałaby usterka
// „stary arkusz z nowym HTML-em", tylko ciszej.
$manifestFile = $out . '/public/assets/' . Wezurlop\Support\AssetVersions::MANIFEST;
$manifestRaw = is_file($manifestFile) ? json_decode((string) file_get_contents($manifestFile), true) : null;
$manifest = is_array($manifestRaw) ? $manifestRaw : null;
if (!is_array($manifest) || $manifest === []) {
    $errors[] = 'brak albo pusty manifest wersji zasobów (' . Wezurlop\Support\AssetVersions::MANIFEST . ')';
} else {
    // ⚠ Lista zasobów powstaje z PRZESZUKANIA paczki, nie z ośmiu ścieżek wpisanych tutaj
    // (warunek Krzysztofa z 22.09.2026). Wpisana ręcznie lista pilnuje tego, co ktoś pamiętał
    // w dniu jej pisania: `js/modules/hierarchia.js` doszedł tydzień później i nikt tej listy
    // nie ruszył, więc weryfikacja jego nie sprawdzała.
    //
    // Dwa źródła, bo zasoby wołane są z dwóch miejsc:
    //  1. szablony — `asset('css/…')`, adres NOSI wersję (`?v=…`),
    //  2. arkusze — `url('../fonts/…')`, adres wersji NIE nosi. ⚠ To właśnie dlatego
    //     `immutable` jeszcze nie wchodzi: na adresie bez wersji znaczyłoby „nigdy nie pytaj".
    $wolane = [];
    $szablony = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
        $out . '/templates', FilesystemIterator::SKIP_DOTS));
    foreach ($szablony as $plik) {
        if (!$plik instanceof SplFileInfo || $plik->getExtension() !== 'twig') {
            continue;
        }
        preg_match_all("/asset\(\s*'([^']+)'/", (string) file_get_contents($plik->getPathname()), $m);
        foreach ($m[1] as $zasob) {
            $wolane[$zasob] = 'szablon ' . substr($plik->getPathname(), strlen($out) + 1);
        }
    }
    foreach (glob($out . '/public/assets/css/*.css') ?: [] as $arkusz) {
        preg_match_all("/url\(\s*'?\.\.\/([^')]+)'?\s*\)/", (string) file_get_contents($arkusz), $m);
        foreach ($m[1] as $zasob) {
            $wolane[$zasob] = 'arkusz ' . basename($arkusz);
        }
    }
    if ($wolane === []) {
        $errors[] = 'przeszukanie szablonów i arkuszy nie znalazło ANI JEDNEGO zasobu — to nie jest wynik, to usterka weryfikacji';
    }
    foreach ($wolane as $zasob => $skad) {
        if (!isset($manifest[$zasob])) {
            $errors[] = 'manifest nie zna zasobu ' . $zasob . ' (wołany z: ' . $skad . ')';
        }
        if (!is_file($out . '/public/assets/' . $zasob)) {
            $errors[] = 'w paczce nie ma pliku zasobu ' . $zasob . ' (wołany z: ' . $skad . ')';
        }
    }
    // Numer wydania: bez niego zasób spoza manifestu dostałby stały napis z `.env`, czyli adres,
    // który nigdy się nie zmienia (rozstrzygnięcie 22.09.2026).
    if (!isset($manifestRaw[Wezurlop\Support\AssetVersions::KLUCZ_WYDANIA])) {
        $errors[] = 'manifest bez numeru wydania (' . Wezurlop\Support\AssetVersions::KLUCZ_WYDANIA . ')';
    }
    echo 'zasobów wołanych z szablonów i arkuszy: ' . count($wolane) . "\n";
}
$autoloadFile = $out . '/vendor/composer/autoload_psr4.php';
$autoload = is_file($autoloadFile) ? (string) file_get_contents($autoloadFile) : '';
if ($autoload === '' || str_contains($autoload, 'Wezurlop\\\\Dev\\\\')) {
    $errors[] = 'autoload paczki zawiera przestrzeń Wezurlop\\Dev albo go brak';
}
if ($errors !== []) {
    fwrite(STDERR, "PACZKA NIEZGODNA Z LISTĄ:\n  " . implode("\n  ", $errors) . "\n");
    exit(1);
}
echo "paczka zgodna z listą: {$count} plików (w tym vendor/)\n";
