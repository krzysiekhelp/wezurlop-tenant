<?php

declare(strict_types=1);

// Hierarchia przełożonych: kontrola cyklu (znalezisko L2), bezpiecznik głębokości,
// najgłębszy poziom i brzmienie zdania na pasku zmian.
// ⚠ Sedno: cykl `A → B → A` zawiesiłby eskalację w nieskończonej pętli, więc jest BŁĘDEM
// WALIDACJI, nie ostrzeżeniem w interfejsie. Wygaszona karta w przeglądarce jest wygodą;
// zabezpieczeniem jest ta funkcja.
// Uruchomienie: php tests/hierarchia.php — kod wyjścia 0 = wszystko przeszło.

require dirname(__DIR__) . '/vendor/autoload.php';

use Wezurlop\Service\Hierarchia;

(static function (): void {
    $failures = 0;
    $check = static function (string $name, bool $ok) use (&$failures): void {
        echo ($ok ? 'OK    ' : 'BŁĄD  ') . $name . "\n";
        $failures += $ok ? 0 : 1;
    };
    $cykl = Hierarchia::powodCyklu(...);

    // Struktura: 10 → 20 → 30 (30 na szczycie); 40 luzem.
    $k = [10 => 20, 20 => 30];

    // --- droga wolna
    $check('nowy przełożony spoza łańcucha przechodzi', $cykl($k, 40, 10) === null);
    $check('podwładny może dostać przełożonego wyżej w innej gałęzi', $cykl($k, 10, 30) === null);
    $check('szczyt może dostać przełożonego z zewnątrz', $cykl($k, 30, 40) === null);

    // --- SEDNO: pętle odrzucone
    $check('sam siebie odrzucony', $cykl($k, 10, 10) === Hierarchia::POWOD_SAM_SIEBIE);
    $check('pętla bezpośrednia A→B→A odrzucona', $cykl($k, 20, 10) === Hierarchia::POWOD_CYKL);
    $check('pętla przez dwa szczeble odrzucona', $cykl($k, 30, 10) === Hierarchia::POWOD_CYKL);
    // ⚠ Ten przypadek jest tym, którego interfejs sam nie łapie: wnuk wskazany dziadkowi.
    $check('wnuk nie może zostać przełożonym dziadka', $cykl([5 => 6, 6 => 7], 7, 5) === Hierarchia::POWOD_CYKL);

    // --- bezpiecznik głębokości: gdyby w bazie już siedział cykl, wchodzenie w górę
    //     kręciłoby się bez końca i zjadło limit 30 s zamiast zgłosić błąd
    $zepsute = [];
    for ($i = 1; $i <= 200; $i++) {
        $zepsute[$i] = $i === 200 ? 1 : $i + 1;   // pętla 1 → 2 → … → 200 → 1
    }
    $check('cykl w danych nie zawiesza sprawdzenia',
        $cykl($zepsute, 999, 1) === Hierarchia::POWOD_ZA_GLEBOKO);
    $dlugi = [];
    for ($i = 1; $i <= 100; $i++) {
        $dlugi[$i] = $i + 1;                      // łańcuch bez pętli, ale dłuższy niż limit
    }
    $check('łańcuch dłuższy niż limit odrzucony, nie policzony',
        $cykl($dlugi, 999, 1) === Hierarchia::POWOD_ZA_GLEBOKO);

    // --- najgłębszy poziom (pasek liczb)
    $check('poziom pojedynczej osoby to 1', Hierarchia::najglebszyPoziom([], [40]) === 1);
    $check('trzy szczeble to poziom 3', Hierarchia::najglebszyPoziom($k, [10, 20, 30]) === 3);
    $check('liczenie poziomów nie zawiesza się na cyklu',
        Hierarchia::najglebszyPoziom($zepsute, [1]) === Hierarchia::GLEBOKOSC_MAX);

    // --- zdanie: jedno brzmienie dla paska zmian i dla dziennika
    // ⚠ Myślnik, nie strzałka: hostowany podzbiór IBM Plex Sans nie ma U+2192 i w dzienniku
    // wychodził pusty prostokąt (zmierzone 17.10.2026, zlecenie Z19).
    $check('zdanie o nowym przełożonym',
        Hierarchia::zdanie('Nowak Anna', 'Kowalski Jan', false, null, false)
        === 'Nowak Anna — przełożony: Kowalski Jan (było: nie ustawiono)');
    $check('zdanie o szczycie',
        Hierarchia::zdanie('Kowalski Jan', null, true, 'Nowak Anna', false)
        === 'Kowalski Jan — nie podlega nikomu (szczyt hierarchii) (było: Nowak Anna)');
    $check('zdanie o zdjęciu ze struktury',
        Hierarchia::zdanie('Mazur Paweł', null, false, null, true)
        === 'Mazur Paweł — zdjęty ze struktury (nie ustawiono) (było: szczyt hierarchii)');

    echo $failures === 0 ? "\nWSZYSTKO PRZESZŁO\n" : "\nBŁĘDÓW: {$failures}\n";
    exit($failures === 0 ? 0 : 1);
})();
