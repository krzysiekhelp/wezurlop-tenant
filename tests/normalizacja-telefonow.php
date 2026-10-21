<?php

declare(strict_types=1);

// **Ujednolicenie zapisu numerów telefonów** (zlecenie Z74, migracja
// `20261021180001_normalize_person_phones`).
//
// ⚠ Co tu jest sprawdzane i dlaczego właśnie to: migracja ZMIENIA DANE OSOBOWE, więc musi mieć
// dowód na trzy rozłączne przypadki — przepisać, zostawić w spokoju, pominąć bez zgadywania:
//   1. numer w innym zapisie, ale poprawny po oczyszczeniu → przepisany na `+48XXXXXXXXX`,
//   2. numer już w docelowej postaci → NIE wchodzi do zmian (na tym stoi powtarzalność:
//      drugi przebieg migracji rusza zero wierszy),
//   3. numer, którego nie da się znormalizować (wewnętrzny, zagraniczny) → pominięty i policzony,
//      nigdy „poprawiony na siłę". Zmiana danych osobowych na podstawie domysłu jest gorsza
//      niż niejednolity zapis.
//
// ⚠ **Bez bazy i bez prawdziwych numerów**: wszystkie wartości w tym pliku są wymyślone na
// potrzeby sprawdzenia (`600 100 200` i warianty), a reguła jest czystą funkcją
// (`Telefon::doPrzepisania()`), tą samą, której używa migracja. Dzięki temu test mówi o REGULE,
// nie o stanie bazy roboczej — który zmienia się po pierwszym uruchomieniu migracji.
//
// Uruchomienie: php tests/normalizacja-telefonow.php

require dirname(__DIR__) . '/vendor/autoload.php';

use Wezurlop\Support\Telefon;

(static function (): void {
    $bledy = 0;
    $sprawdz = static function (string $opis, bool $ok, string $widziane = '') use (&$bledy): void {
        echo ($ok ? 'OK    ' : 'BŁĄD  ') . $opis . ($ok || $widziane === '' ? '' : " — widziane: {$widziane}") . "\n";
        $bledy += $ok ? 0 : 1;
    };

    // Trzy przypadki naraz, w jednym wywołaniu — tak samo, jak robi to migracja.
    $plan = Telefon::doPrzepisania([
        11 => '600 100 200',          // 1. inny zapis, poprawny po oczyszczeniu
        12 => '+48600100200',         // 2. już docelowy
        13 => '22 123 45 67 w. 12',   // 3. nienormalizowalny (numer wewnętrzny)
    ]);

    $sprawdz('przypadek 1: inny zapis trafia do zmian z jedną postacią',
        ($plan['zmiany'][11] ?? null) === '+48600100200', json_encode($plan['zmiany']));
    $sprawdz('przypadek 2: numer już docelowy NIE wchodzi do zmian',
        !array_key_exists(12, $plan['zmiany']) && in_array(12, $plan['bez_zmian'], true));
    $sprawdz('przypadek 3: nienormalizowalny pominięty, nie poprawiony',
        !array_key_exists(13, $plan['zmiany']) && in_array(13, $plan['pominiete'], true));
    $sprawdz('każdy wiersz w dokładnie jednym koszyku',
        count($plan['zmiany']) + count($plan['bez_zmian']) + count($plan['pominiete']) === 3);

    // ⚠ Powtarzalność: to, co migracja zapisała, przy drugim przebiegu nie może być zmianą.
    $poPierwszym = [];
    foreach ([11 => '600 100 200', 12 => '+48600100200', 13 => '22 123 45 67 w. 12'] as $id => $numer) {
        $poPierwszym[$id] = $plan['zmiany'][$id] ?? $numer;
    }
    $drugi = Telefon::doPrzepisania($poPierwszym);
    $sprawdz('drugi przebieg zmienia ZERO wierszy', $drugi['zmiany'] === [], json_encode($drugi['zmiany']));
    $sprawdz('drugi przebieg nadal pomija ten sam wiersz', $drugi['pominiete'] === [13]);

    // Puste i brakujące wartości nie są ani zmianą, ani pominięciem — nie ma czego normalizować.
    $puste = Telefon::doPrzepisania([21 => null, 22 => '', 23 => '   ']);
    $sprawdz('puste numery w ogóle nie wchodzą do planu',
        $puste['zmiany'] === [] && $puste['pominiete'] === [] && $puste['bez_zmian'] === []);

    // ⚠ Wariant, który łatwo przeoczyć: numer z zerem W ŚRODKU. Normalizacja usuwa wiodące zera,
    // więc gdyby zabrała też środkowe, zmieniłaby NUMER, nie jego zapis.
    $srodek = Telefon::doPrzepisania([31 => '600-048-100']);
    $sprawdz('zero w środku numeru zostaje', ($srodek['zmiany'][31] ?? null) === '+48600048100',
        json_encode($srodek['zmiany']));

    echo $bledy === 0 ? "Wszystko OK\n" : "Nieudane: {$bledy}\n";
    exit($bledy === 0 ? 0 : 1);
})();
