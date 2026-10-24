<?php

declare(strict_types=1);

// Trzy liczby panelu „Zablokować konto?" (zlecenie Z85 pkt 4).
// ⚠ Powód istnienia tego pliku: te trzy liczby stoją na ekranie OBOK SIEBIE, a czwarta rzecz
// (zdanie pod nimi) mówi o ich różnicy. Policzone w szablonie byłyby czterema wyrażeniami,
// z których każde może się rozjechać osobno — a administrator podejmuje na ich podstawie
// decyzję o cudzym dostępie do danych kadrowych. Dlatego liczy je jedna czysta funkcja
// i dlatego ma własny test, bez bazy i bez pliku licencji.
// Uruchomienie: php tests/blokada-licencje.php — kod wyjścia 0 = wszystko przeszło.

require dirname(__DIR__) . '/vendor/autoload.php';

use Wezurlop\Service\AccessService;

(static function (): void {
    $failures = 0;
    $check = static function (string $name, mixed $got, mixed $want) use (&$failures): void {
        $ok = $got === $want;
        echo ($ok ? 'OK    ' : 'BŁĄD  ') . $name
            . ' -> ' . json_encode($got, JSON_UNESCAPED_UNICODE)
            . ($ok ? '' : ' (oczekiwano ' . json_encode($want, JSON_UNESCAPED_UNICODE) . ')') . "\n";
        $failures += $ok ? 0 : 1;
    };

    // Blokada przy limicie 25 i 20 zajętych: zwalnia 3, więc wolne rosną z 5 do 8.
    $blokada = AccessService::skutekNaLicencje(3, 25, 20, false);
    $check('blokada: limit', $blokada['limit'], 25);
    $check('blokada: wolne teraz', $blokada['wolne_teraz'], 5);
    $check('blokada: wolne potem', $blokada['wolne_potem'], 8);
    $check('blokada: zmiana', $blokada['zmiana'], 3);

    // Odblokowanie tej samej osoby działa w drugą stronę: wolne spadają z 5 do 2.
    $odblok = AccessService::skutekNaLicencje(3, 25, 20, true);
    $check('odblokowanie: wolne potem', $odblok['wolne_potem'], 2);

    // ⚠ Osoba, która nie zajmuje licencji (dostęp bez aktywnego zatrudnienia albo brak dostępu):
    // wszystkie trzy liczby muszą być zgodne, bo ekran pokaże je obok siebie, a zdanie powie
    // „nie zmieni liczby wolnych licencji". Zero w `zmiana` jest warunkiem tego zdania.
    $zero = AccessService::skutekNaLicencje(0, 25, 20, false);
    $check('bez licencji: wolne teraz', $zero['wolne_teraz'], 5);
    $check('bez licencji: wolne potem', $zero['wolne_potem'], 5);
    $check('bez licencji: zmiana', $zero['zmiana'], 0);

    // ⚠ Licencja BEZ limitu: „wolne" nie istnieje jako liczba, więc obie pierwsze kolumny
    // mówią słowem (`null`), a zostaje liczba dostępów zmieniających stan. Zlepienie tego
    // z „zero wolnych" dałoby ostrzeżenie o limicie na instalacji, która limitu nie ma.
    $bezLimitu = AccessService::skutekNaLicencje(4, null, 99, false);
    $check('bez limitu: limit', $bezLimitu['limit'], null);
    $check('bez limitu: wolne teraz', $bezLimitu['wolne_teraz'], null);
    $check('bez limitu: wolne potem', $bezLimitu['wolne_potem'], null);
    $check('bez limitu: zmiana', $bezLimitu['zmiana'], 4);

    // ⚠ Użycie POWYŻEJ limitu jest stanem osiągalnym: odblokowanie limitu nie pilnuje.
    // „Wolnych −2" nie jest liczbą wolnych miejsc, więc podłogą jest zero.
    $ponad = AccessService::skutekNaLicencje(3, 25, 24, true);
    $check('ponad limit: wolne teraz', $ponad['wolne_teraz'], 1);
    $check('ponad limit: wolne potem', $ponad['wolne_potem'], 0);
    $przekroczone = AccessService::skutekNaLicencje(1, 25, 30, false);
    $check('juz przekroczony: wolne teraz', $przekroczone['wolne_teraz'], 0);
    $check('juz przekroczony: wolne potem', $przekroczone['wolne_potem'], 0);

    if ($failures > 0) {
        echo "\nNIEPOWODZENIA: {$failures}\n";
        exit(1);
    }
    echo "\nWszystkie sprawdzenia przeszły.\n";
})();
