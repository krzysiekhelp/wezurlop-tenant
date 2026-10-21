<?php

declare(strict_types=1);

/*
 * **Kształt komórek osi czasu: tło dzień po dniu pod paskiem wielodniowym** (zlecenie Z68).
 *
 * ⚠ Powód powstania jest świeży i konkretny: pisząc Z68 nazwałem nową zmienną `$dni`, a tak
 * nazywa się w tej samej pętli LICZBA DNI MIESIĄCA. Warunek pętli zaczął porównywać liczbę
 * z tablicą, pętla wyszła za koniec miesiąca i kalendarz zespołu padał na `Undefined array
 * key 31`. Wyszło to dopiero z bramki ekranowej — po kilku minutach i po tym, jak wszystkie
 * pomiary wróciły puste. Ten test odpowiada na to samo pytanie w ułamku sekundy i bez bazy.
 *
 * ⚠ Dlaczego przez refleksję: `wiersz()` jest prywatna i statyczna, a jedyne publiczne wejście
 * (`siatka()`) wymaga bazy, zatrudnień i reguł dostępu. Mierzymy tu NIEZMIENNIK KSZTAŁTU, nie
 * zachowanie bazy, więc test ma być czysto obliczeniowy. ⚠ `KalendarzPracownika` leży w strefie
 * JAWNEJ (`src/Service`), nie w kodowanej — ograniczenie refleksji z CLAUDE.md §15 tu nie sięga.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use Wezurlop\Service\KalendarzPracownika;
use Wezurlop\Service\ZakresWidocznosci;

(static function (): void {
    $bledy = 0;
    $sprawdz = static function (string $nazwa, bool $ok) use (&$bledy): void {
        echo ($ok ? 'OK    ' : 'BŁĄD  ') . $nazwa . "\n";
        $bledy += $ok ? 0 : 1;
    };

    $pierwszy = new DateTimeImmutable('2026-02-01');
    $dniMiesiaca = 28;
    // Nagłówki jak z serwisu: dzień wolny to sobota i niedziela lutego 2026.
    $naglowki = [];
    for ($n = 1; $n <= $dniMiesiaca; $n++) {
        $data = $pierwszy->modify('+' . ($n - 1) . ' days');
        $naglowki[] = [
            'numer' => $n,
            'skrot' => 'x',
            'wolny' => in_array((int) $data->format('N'), [6, 7], true),
            'dzis' => false,
        ];
    }

    // ⚠ Zakres 16–23.02 to DOKŁADNIE przypadek ze zgłoszenia: zaczyna się w poniedziałek,
    // a obejmuje sobotę 21 i niedzielę 22 — czyli dni, pod którymi kolumna weekendu urywała się.
    // Drugi wpis jest jednodniowy i stoi w sobotę, żeby sprawdzić gałąź bez pod-kratek.
    $wpisy = [
        ['id' => 1, 'od' => '2026-02-16', 'do' => '2026-02-23', 'rodzaj' => 'urlop wypoczynkowy', 'zrodlo' => 'urlop'],
        ['id' => 2, 'od' => '2026-02-07', 'do' => '2026-02-07', 'rodzaj' => 'urlop wypoczynkowy', 'zrodlo' => 'urlop'],
    ];

    $metoda = new ReflectionMethod(KalendarzPracownika::class, 'wiersz');
    $metoda->setAccessible(true);
    /** @var list<array<string, mixed>> $komorki */
    $komorki = $metoda->invoke(null, $wpisy, $pierwszy, $naglowki, ZakresWidocznosci::ZE_SZCZEGOLAMI);

    // 1. Komórki pokrywają miesiąc dokładnie raz — to ten warunek pękł przy nadpisanej zmiennej.
    $suma = array_sum(array_map(static fn (array $k): int => (int) $k['rozpietosc'], $komorki));
    $sprawdz("komórki pokrywają {$dniMiesiaca} dni (jest {$suma})", $suma === $dniMiesiaca);

    // 2. ⚠ Najpierw sam KLUCZ, osobnym zdaniem: bez tego test na kodzie bez pola `dni`
    //    wywracał się wyjątkiem `count(): null given` zamiast powiedzieć, czego brakuje —
    //    a test ma nazywać usterkę, nie umierać na niej (zmierzone na kopii sprzed Z68).
    $bezKlucza = 0;
    foreach ($komorki as $k) {
        if (!array_key_exists('dni', $k) || !is_array($k['dni'])) {
            $bezKlucza++;
        }
    }
    $sprawdz('każda komórka niesie listę dni (choćby pustą)', $bezKlucza === 0);
    if ($bezKlucza > 0) {
        echo "Nieudane: brak pola z dniami w " . $bezKlucza . " komórkach\n";
        exit(1);
    }

    // Każda komórka z rozpiętością > 1 ma listę dni tej samej długości, a komórka
    // jednodniowa nie ma jej wcale (tam tło maluje klasa samej komórki).
    $zleDlugosci = $zleJednodniowe = 0;
    foreach ($komorki as $k) {
        $r = (int) $k['rozpietosc'];
        if ($r > 1 && count($k['dni']) !== $r) {
            $zleDlugosci++;
        }
        if ($r === 1 && $k['dni'] !== []) {
            $zleJednodniowe++;
        }
    }
    $sprawdz('lista dni ma długość rozpiętości', $zleDlugosci === 0);
    $sprawdz('komórka jednodniowa nie dostaje listy dni', $zleJednodniowe === 0);

    // 3. Flagi dni zgadzają się z nagłówkami kolumn, które komórka zajmuje.
    $kolumna = 1;
    $zleFlagi = 0;
    foreach ($komorki as $k) {
        $r = (int) $k['rozpietosc'];
        foreach ($k['dni'] as $i => $dzien) {
            if ($dzien['wolny'] !== $naglowki[$kolumna - 1 + $i]['wolny']) {
                $zleFlagi++;
            }
        }
        $kolumna += $r;
    }
    $sprawdz('dni wolne w liście zgadzają się z nagłówkami', $zleFlagi === 0);

    // 4. Przypadek ze zgłoszenia: komórka 16–23.02 zaczyna się w dzień ROBOCZY, a niesie
    //    dwa dni wolne. Gdyby tło brało się z pierwszego dnia, weekend zniknąłby pod paskiem.
    $dlugi = null;
    foreach ($komorki as $k) {
        if ((int) $k['rozpietosc'] === 8) {
            $dlugi = $k;
        }
    }
    $sprawdz('zakres 16–23.02 to jedna komórka na 8 kolumn', $dlugi !== null);
    if ($dlugi !== null) {
        $wolnych = count(array_filter($dlugi['dni'], static fn (array $d): bool => $d['wolny']));
        $sprawdz("w komórce 16–23.02 są 2 dni wolne (jest {$wolnych})", $wolnych === 2);
        $sprawdz('pierwszy dzień komórki 16–23.02 jest roboczy', $dlugi['dni'][0]['wolny'] === false);
        // ⚠ `wolny` na komórce z paskiem MUSI być fałszem: inaczej klasa `--free` pomalowałaby
        // całą komórkę barwą pierwszego dnia, czyli wróciłaby naprawiana usterka.
        $sprawdz('komórka z paskiem nie niesie własnej flagi dnia wolnego', $dlugi['wolny'] === false);
    }

    // 5. Pasek jednodniowy w sobotę: brak listy dni, ale flaga dnia wolnego na komórce zostaje.
    $sobota = null;
    foreach ($komorki as $k) {
        if ($k['pasek'] !== null && (int) $k['rozpietosc'] === 1) {
            $sobota = $k;
        }
    }
    $sprawdz('pasek jednodniowy istnieje', $sobota !== null);
    if ($sobota !== null) {
        $sprawdz('pasek jednodniowy w sobotę zachowuje flagę dnia wolnego', $sobota['wolny'] === true);
    }

    echo $bledy === 0 ? "Wszystko OK\n" : "Nieudane: {$bledy}\n";
    exit($bledy === 0 ? 0 : 1);
})();
