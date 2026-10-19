<?php

declare(strict_types=1);

// Regresja: **wywołanie własnej metody z inną liczbą argumentów, niż ma ona parametrów**
// (naprawa z 19.10.2026, zlecenie Z52 pkt 2).
//
// ⚠ Powód istnienia tego pliku. `GroupController::odpowiedz()` ma sześć parametrów, a dwa
// wywołania podawały siedem argumentów — bo przy zmianie sygnatury dopisano parametr w środku
// i nie poprawiono wszystkich wywołań. PHP **nie zgłasza błędu** przy nadmiarowych argumentach
// metody niewariadycznej: po cichu je pomija, a wcześniejsze przesuwa. Skutki były dwa i oba
// ciche:
//   • `$this->odpowiedz(…, $szukaj, null, null, $wGrupie)` → do `string $wGrupie` szedł `null`,
//     czyli **TypeError i 500** przy zapisie z pustą listą osób,
//   • `$this->odpowiedz(…, $szukaj, null, 'pominieci', $wGrupie)` → `$uwaga` wychodziło `null`
//     zamiast `'pominieci'` (komunikat o pominiętym zapisie **nigdy się nie pokazywał**),
//     a `$wGrupie` dostawało napis `'pominieci'` zamiast treści wyszukiwania.
//
// ⚠ Test jest **statyczny i obejmuje cały `src/`**, a nie te dwa wywołania. Powód: usterka nie
// była pomyłką w jednym miejscu, tylko skutkiem zmiany sygnatury — a tę robi się raz na jakiś
// czas w dowolnej klasie. Bramka wyłapująca wyłącznie znany przypadek nie wyłapałaby następnego.
// Sprawdzamy oba kierunki: za dużo argumentów (cisza, czyli nasz przypadek) i za mało (fatal
// w czasie działania, czyli ekran, który pada u klienta zamiast na buildzie).
//
// ⚠ Sprawdzamy tylko `$this->metoda(…)` i tylko gdy metoda jest zadeklarowana w TEJ SAMEJ klasie:
// wywołanie przez zmienną, przez rodzica albo na cudzym obiekcie wymagałoby rozwiązywania typów,
// czyli analizatora, a nie testu. Pomijamy metody wariadyczne i wywołania z rozpakowaniem (`...`),
// bo tam liczba argumentów z definicji nie jest stała.
//
// Uruchomienie: php tests/argumenty-wywolan.php — kod wyjścia 0 = wszystko przeszło.

require dirname(__DIR__) . '/vendor/autoload.php';

(static function (): void {
    $failures = 0;
    $check = static function (string $name, bool $ok) use (&$failures): void {
        echo ($ok ? 'OK    ' : 'BŁĄD  ') . $name . "\n";
        $failures += $ok ? 0 : 1;
    };

    /** Tokeny pliku bez białych znaków i komentarzy — te nic nie wnoszą, a psują sąsiedztwa. */
    $tokeny = static function (string $kod): array {
        $out = [];
        foreach (token_get_all($kod) as $t) {
            if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out[] = $t;
        }

        return $out;
    };

    /**
     * Deklaracje metod: nazwa → [min wymaganych, max przyjmowanych]. `null` dla metody
     * wariadycznej, czyli takiej, której ta reguła nie dotyczy.
     *
     * @return array<string, ?array{int, int}>
     */
    $deklaracje = static function (array $tok): array {
        $wynik = [];
        foreach ($tok as $i => $t) {
            if (!is_array($t) || $t[0] !== T_FUNCTION) {
                continue;
            }
            $nazwa = $tok[$i + 1] ?? null;
            if (!is_array($nazwa) || $nazwa[0] !== T_STRING || ($tok[$i + 2] ?? null) !== '(') {
                continue;                       // domknięcie albo funkcja anonimowa
            }
            $min = 0;
            $max = 0;
            $wariadyczna = false;
            $glebokosc = 0;
            $wParametrze = false;
            $zWartoscia = false;
            for ($j = $i + 2, $n = count($tok); $j < $n; $j++) {
                $x = $tok[$j];
                // ⚠ `#[\SensitiveParameter]` przed parametrem to T_ATTRIBUTE, czyli otwarty
                // nawias kwadratowy bez tokenu `[`. Nieuwzględniony zbijał głębokość na swoim `]`
                // i pętla kończyła się w połowie listy (zmierzone na `Password::verifyDummy()`).
                if ($x === '(' || $x === '[' || (is_array($x) && $x[0] === T_ATTRIBUTE)) {
                    $glebokosc++;
                    continue;
                }
                if ($x === ')' || $x === ']') {
                    $glebokosc--;
                    if ($glebokosc === 0) {
                        break;
                    }
                    continue;
                }
                if ($glebokosc !== 1) {
                    continue;                   // wartość domyślna w nawiasach, np. `= [1, 2]`
                }
                if ($x === ',') {
                    $wParametrze = false;
                    $zWartoscia = false;
                    continue;
                }
                if (is_array($x) && $x[0] === T_ELLIPSIS) {
                    $wariadyczna = true;
                    continue;
                }
                if ($x === '=') {
                    $zWartoscia = true;
                    if ($min > 0) {
                        $min--;                 // ten parametr jednak nie jest wymagany
                    }
                    continue;
                }
                if (is_array($x) && $x[0] === T_VARIABLE && !$wParametrze) {
                    $wParametrze = true;
                    $max++;
                    if (!$zWartoscia) {
                        $min++;
                    }
                }
            }
            $wynik[$nazwa[1]] = $wariadyczna ? null : [$min, $max];
        }

        return $wynik;
    };

    /**
     * Wywołania `$this->metoda(…)`: nazwa → lista liczb argumentów (`null` = rozpakowanie
     * albo składnia pierwszej klasy `(...)`, czyli przypadek spoza tej reguły).
     *
     * @return array<string, list<?int>>
     */
    $wywolania = static function (array $tok): array {
        $wynik = [];
        for ($i = 0, $n = count($tok); $i < $n - 3; $i++) {
            $t = $tok[$i];
            if (!is_array($t) || $t[0] !== T_VARIABLE || $t[1] !== '$this') {
                continue;
            }
            $strzalka = $tok[$i + 1];
            $nazwa = $tok[$i + 2];
            if (!is_array($strzalka) || $strzalka[0] !== T_OBJECT_OPERATOR
                || !is_array($nazwa) || $nazwa[0] !== T_STRING || ($tok[$i + 3] ?? null) !== '(') {
                continue;
            }
            $argumentow = 0;
            $pusty = true;
            $rozpakowanie = false;
            $przecinekNaKoncu = false;
            $glebokosc = 0;
            for ($j = $i + 3; $j < $n; $j++) {
                $x = $tok[$j];
                $otwiera = $x === '(' || $x === '[' || $x === '{'
                    || (is_array($x) && $x[0] === T_ATTRIBUTE);
                $zamyka = $x === ')' || $x === ']' || $x === '}';
                // ⚠ O tym, czy lista argumentów jest pusta, rozstrzyga głębokość PRZED tokenem,
                // nie po nim: `$this->f([1, 2])` zaczyna się od nawiasu kwadratowego, a liczony
                // po inkrementacji wyglądał jak wywołanie bez argumentów (zmierzone na
                // `runSections([…])` i `setDisplayNames([…])` — oba zgłoszone jako „0 argumentów").
                if ($glebokosc === 1 && !$zamyka) {
                    $pusty = false;
                    if ($x === ',') {
                        $argumentow++;
                        $przecinekNaKoncu = true;
                    } else {
                        $przecinekNaKoncu = false;
                        if (is_array($x) && $x[0] === T_ELLIPSIS) {
                            $rozpakowanie = true;
                        }
                    }
                }
                if ($otwiera) {
                    $glebokosc++;
                    continue;
                }
                if ($zamyka) {
                    $glebokosc--;
                    if ($glebokosc === 0) {
                        break;
                    }
                }
            }
            // ⚠ Przecinek PO ostatnim argumencie jest w PHP dozwolony i w tym repozytorium
            // powszechny przy wywołaniach łamanych na kilka wierszy — policzony dawał argument
            // więcej (zmierzone na `RateLimiter::remaining()`, `InstallController::withCookie()`
            // i `GroupController::listaZBledem()`, wszystkie trzy zgłoszone fałszywie).
            if ($przecinekNaKoncu) {
                $argumentow--;
            }
            $wynik[$nazwa[1]][] = $rozpakowanie ? null : ($pusty ? 0 : $argumentow + 1);
        }

        return $wynik;
    };

    // ——— Przebieg po całym `src/` ——————————————————————————————————————————
    $katalog = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__) . '/src'));
    $zle = [];
    $sprawdzonych = 0;
    $plikow = 0;
    foreach ($katalog as $plik) {
        if (!$plik->isFile() || $plik->getExtension() !== 'php') {
            continue;
        }
        $plikow++;
        $tok = $tokeny((string) file_get_contents($plik->getPathname()));
        $metody = $deklaracje($tok);
        foreach ($wywolania($tok) as $nazwa => $liczby) {
            if (!array_key_exists($nazwa, $metody) || $metody[$nazwa] === null) {
                continue;                       // metoda z innej klasy albo wariadyczna
            }
            [$min, $max] = $metody[$nazwa];
            foreach ($liczby as $ile) {
                if ($ile === null) {
                    continue;
                }
                $sprawdzonych++;
                if ($ile > $max || $ile < $min) {
                    $zle[] = sprintf(
                        '%s::%s() przyjmuje %d–%d, a wywołano z %d',
                        $plik->getBasename('.php'),
                        $nazwa,
                        $min,
                        $max,
                        $ile,
                    );
                }
            }
        }
    }

    foreach ($zle as $opis) {
        echo "      → " . $opis . "\n";
    }
    $check(sprintf('src/: %d plików, %d wywołań własnych metod — zgodne z sygnaturami',
        $plikow, $sprawdzonych), $zle === []);
    // ⚠ Gdyby parser przestał cokolwiek znajdować, test świeciłby na zielono nie dlatego,
    // że kod jest poprawny, tylko dlatego, że nic nie sprawdził — ta sama rodzina co „render
    // zliczony ≠ render poprawny".
    $check('parser w ogóle coś znalazł (> 200 wywołań)', $sprawdzonych > 200);

    // ——— Oba przypadki ze zlecenia, wprost ————————————————————————————————
    // ⚠ Nazwane osobno, bo to one były usterką; przebieg wyżej jest bramką na przyszłość.
    $kod = (string) file_get_contents(dirname(__DIR__) . '/src/Controller/App/GroupController.php');
    $tok = $tokeny($kod);
    $sygnatura = $deklaracje($tok)['odpowiedz'] ?? null;
    $check('GroupController::odpowiedz() ma 4–6 parametrów', $sygnatura === [4, 6]);
    $wywolaniaOdpowiedzi = $wywolania($tok)['odpowiedz'] ?? [];
    $check('wywołań odpowiedz() jest trzy', count($wywolaniaOdpowiedzi) === 3);
    $check('pusta lista osób: wywołanie mieści się w sygnaturze (było 7 argumentów → TypeError)',
        ($wywolaniaOdpowiedzi[0] ?? null) === 6);
    $check('zapis pominięty w całości: wywołanie mieści się w sygnaturze (było 7 argumentów)',
        ($wywolaniaOdpowiedzi[1] ?? null) === 6);
    // ⚠ Liczba argumentów to za mało: przy siedmiu argumentach `'pominieci'` trafiało do
    // `$wGrupie`, czyli do filtru wyszukiwania. Sprawdzamy więc, że stoi na miejscu `$uwaga`.
    $check('„pominieci" stoi na pozycji $uwaga, nie $wGrupie',
        str_contains($kod, "\$szukaj, 'pominieci', \$wGrupie)")
        && !str_contains($kod, "\$szukaj, null, 'pominieci'"));
    $check('nie został ani jeden nadmiarowy `null,` w wywołaniach odpowiedz()',
        !str_contains($kod, '$szukaj, null, null,'));

    echo $failures === 0 ? "\nWszystko OK\n" : "\nBŁĘDÓW: {$failures}\n";
    exit($failures === 0 ? 0 : 1);
})();
