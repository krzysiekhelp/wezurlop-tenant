<?php

declare(strict_types=1);

// **Panel osoby: JEDEN zapis i karty w każdym stanie konta** (zlecenia Z58, Z59, Z61).
//
// ⚠ Powód istnienia tego pliku. Panel zapisuje dwie różne rzeczy — dane konta i dostęp do firm —
// JEDNYM przyciskiem i jednym żądaniem (Z61 §1). Trzyma się to na trzech szczegółach HTML-a,
// których nie widać w żadnym pomiarze wizualnym: atrybucie `form="osoba-dane"` na polach wyboru
// firm (dzięki niemu jadą z formularzem panelu **bez JavaScriptu**), ukrytym znaczniku
// `firmy_w_formularzu` (bez niego zapis danych konta na panelu BEZ tej karty odbierałby dostęp,
// bo żądanie bez pól wyboru wygląda jak odznaczenie wszystkich) i braku drugiego przycisku zapisu.
// Każdy z nich da się zepsuć jedną linijką w szablonie, a skutek jest cichy.
//
// ⚠ Test renderuje PRAWDZIWY szablon prawdziwym Twigiem (`strict_variables`, auto-escaping),
// na danych fikcyjnych wspólnych z podglądem (`dev/probka-panelu-osoby.php`).
//
// Uruchomienie: php tests/panel-osoby.php — kod wyjścia 0 = wszystko przeszło.

require dirname(__DIR__) . '/vendor/autoload.php';

use Wezurlop\Dev\Panel\PrzykladyPanelu;
use Wezurlop\Support\Odmiana;
use Wezurlop\Support\TwigFactory;
use Wezurlop\Support\UrlGenerator;
use Wezurlop\Support\WorkTime;

(static function (): void {
    $failures = 0;
    $check = static function (string $opis, bool $ok, string $widziane = '') use (&$failures): void {
        echo ($ok ? 'OK    ' : 'BŁĄD  ') . $opis . ($ok || $widziane === '' ? '' : " — widziane: {$widziane}") . "\n";
        $failures += $ok ? 0 : 1;
    };

    $urls = new UrlGenerator('', '/assets', 'x', UrlGenerator::WEB);
    $twig = TwigFactory::create(dirname(__DIR__) . '/templates', null, false, $urls, [
        'csrf_token'  => static fn (): string => 'test',
        'can'         => static fn (string $p): bool => $p === 'admin.event_log',
        'nip_grupami' => static fn (string $nip): string => $nip,
        'czas_pracy'  => WorkTime::format(...),
        'czas_pelny'  => static fn (?string $t): string => '20.10.2026, 09:00',
        'czas_ze_strefa' => static fn (?string $t): string => '20.10.2026, 09:00',
        'odmiana_osob' => Odmiana::osoby(...),
        'odmiana_innych' => Odmiana::inne(...),
    ]);

    $przypadki = PrzykladyPanelu::wszystkie();
    $check('katalog obejmuje sześć przypadków', count($przypadki) === 6, (string) count($przypadki));

    foreach ($przypadki as $nazwa => $dane) {
        $html = $twig->render('fragments/person-panel.twig', $dane);
        $zFirmami = str_contains($html, 'class="pf"');

        // ——— JEDEN zapis (Z61 §1) ———
        // ⚠ **Przepięte 20.10.2026 (Z64).** Stało tu „dokładnie jeden przycisk z `form=
        // "osoba-dane"`" i to już nie jest ta sama reguła: przycisk „Zapisz i wyślij" w wierszu
        // zaproszenia jest DRUGIM przyciskiem tego samego formularza, świadomie — ma zapisać
        // dokładnie to samo, co „Zapisz zmiany", jednym żądaniem, żeby nie istniał stan „adres
        // wpisany, nie zapisany, a zaproszenie poszło na stary". Reguła Z61 brzmi „jeden ZAPIS
        // w panelu", nie „jeden przycisk": zapis dalej jest jeden i dalej idzie jednym żądaniem.
        // Dlatego liczymy osobno przycisk zapisu (`data-zapisz`) i przyciski formularza.
        // ⚠ Rozpoznajemy wiersz zaproszenia po POLU ŻĄDANIA, nie po `data-zapros`: zdanie obok
        // przycisku ma atrybut `data-zapros-opis`, więc `str_contains($html, 'data-zapros')`
        // było prawdziwe także wtedy, gdy przycisk tracił swój znacznik — i łamanie kryterium
        // nie świeciło na czerwono (zmierzone przy pisaniu tego sprawdzenia).
        $zZaproszeniem = str_contains($html, 'name="wyslij_zaproszenie"');
        $check($nazwa . ': dokładnie jeden przycisk „Zapisz zmiany"',
            substr_count($html, 'data-zapisz') === 1);
        $check($nazwa . ': przyciski formularza panelu to zapis plus ewentualne zaproszenie',
            substr_count($html, 'type="submit" form="osoba-dane"') === ($zZaproszeniem ? 2 : 1));
        // Umowa z modułem JS: przycisk zaproszenia ma swój znacznik i oba napisy w atrybutach.
        $check($nazwa . ': przycisk zaproszenia ma znacznik i napisy dla modułu',
            // ⚠ Wzorzec z `(?![\w-])`, nie `'data-zapros '`: atrybut stoi na końcu wiersza,
            // więc po nim jest znak nowej linii, a nie spacja — i sprawdzenie liczyło zero.
            !$zZaproszeniem || (preg_match_all('/data-zapros(?![\w-])/', $html) === 1
                && str_contains($html, 'data-napis-zapisz-i-wyslij="Zapisz i wyślij"')
                && str_contains($html, 'data-opis-bez-adresu="Najpierw wpisz adres e-mail')));
        // ⚠ Zaproszenie NIE MA własnego formularza: osobny `POST /admin/konta/zapros` z panelu
        // był właśnie tą drugą drogą, która wysyłała zaproszenie na adres sprzed zapisu.
        $check($nazwa . ': zaproszenie nie ma w panelu własnego formularza',
            !str_contains($html, 'action="/admin/konta/zapros"'));
        $check($nazwa . ': karta firm nie ma własnego przycisku zapisu',
            !str_contains($html, 'Zapisz dostęp'));
        if ($zFirmami) {
            $pol = substr_count($html, 'name="spolki[]" value');
            // ⚠ KAŻDE pole wyboru firmy musi należeć do formularza panelu — jedno bez atrybutu
            // znaczy firmę, która nie pojedzie w żądaniu, czyli cichą utratę dostępu.
            $check($nazwa . ': każde pole firmy należy do formularza panelu',
                substr_count($html, 'name="spolki[]" value="3" form="osoba-dane"') === 1
                // ⚠ Odjęte: ukryte pole `firmy_w_formularzu`, przycisk zapisu i — gdy wiersz
                // zaproszenia jest na ekranie — przycisk „Zapisz i wyślij".
                && $pol === substr_count($html, 'form="osoba-dane"') - ($zZaproszeniem ? 3 : 2),
                (string) $pol);
            $check($nazwa . ': znacznik „kontrolka była na ekranie"',
                str_contains($html, 'name="firmy_w_formularzu" value="1" form="osoba-dane"'));
            $check($nazwa . ': stopka pola firm bez przycisku', !str_contains($html, 'pf__stopka">' . "\n" . '                <button'));
        } else {
            // ⚠ Panel bez karty firm NIE MOŻE nieść znacznika: inaczej zapis danych konta
            // odebrałby dostęp (żądanie bez pól wyboru = odznaczone wszystkie).
            $check($nazwa . ': bez karty firm nie ma znacznika', !str_contains($html, 'firmy_w_formularzu'));
        }

        // ——— komunikat przy swojej karcie (Z61 §1) ———
        $zBledami = $twig->render('fragments/person-panel.twig', [
            'error_danych' => 'BŁĄD DANYCH',
            'error_dostepu' => 'BŁĄD DOSTĘPU',
        ] + $dane);
        $check($nazwa . ': błąd danych konta stoi w formularzu danych',
            (bool) preg_match('/id="osoba-dane".*BŁĄD DANYCH.*<\/form>/s', $zBledami));
        if ($zFirmami) {
            $check($nazwa . ': odmowa dostępu stoi przy karcie firm',
                (bool) preg_match('/BŁĄD DOSTĘPU.*class="pf"/s', $zBledami));
        }

        // ——— stara kontrolka zniknęła (Z61 §2) ———
        $check($nazwa . ': bez rozwijanej warstwy dostępu',
            !str_contains($html, 'data-dostep=') && !str_contains($html, 'dropdown'));

        // ——— karty: pięć, a przy koncie administracyjnym cztery (Z59 §3) ———
        preg_match_all('/class="op-karta__tytul">([^<]+)</', $html, $tytuly);
        $oczekiwane = $nazwa === 'konto_administracyjne'
            ? ['Dane konta', 'Rola', 'Widoczność kalendarza', 'Konto']
            : ['Dane z kadr', 'Dane konta', $dane['wiersz']['caly_tenant'] ? 'Rola' : 'Dostęp do firm',
                'Widoczność kalendarza', 'Konto'];
        $check($nazwa . ': karty i ich kolejność', $tytuly[1] === $oczekiwane, implode(' / ', $tytuly[1]));

        // ——— akcje: każdy wiersz ma dokładnie jeden przycisk równej szerokości (Z58) ———
        $wierszy = substr_count($html, '<li class="op-akcja">');
        $przyciskow = substr_count($html, 'class="btn btn--primary btn--sm op-akcja__btn"')
            + substr_count($html, 'class="btn btn--ghost btn--sm op-akcja__btn"')
            + substr_count($html, 'class="btn btn--ghost btn--sm op-akcja__btn op-akcja__btn--niebezpieczna"');
        // ⚠ Wiersz akcji ma przycisk ALBO zdanie odmowy (Z59 §3) — nigdy jedno i drugie i nigdy
        // nic. „Każdy wiersz ma przycisk" byłoby tu fałszem: blokada ostatniego administratora
        // świadomie przycisku nie ma.
        $powodow = substr_count($html, 'class="op-akcja__powod"');
        $check($nazwa . ': każda akcja ma przycisk albo zdanie odmowy',
            $wierszy === $przyciskow + $powodow,
            $wierszy . ' wierszy / ' . $przyciskow . ' przycisków / ' . $powodow . ' odmów');

        // ——— odmowa blokady zdaniem, nie wyłączonym przyciskiem (Z59 §3) ———
        if (($dane['odmowa_blokady'] ?? null) !== null) {
            $check($nazwa . ': odmowa blokady jako zdanie', str_contains($html, 'op-akcja__powod'));
            $check($nazwa . ': bez wyłączonego przycisku blokady',
                preg_match('/op-akcja__btn" disabled[^>]*>Zablokuj/', $html) !== 1);
        }
    }

    echo $failures === 0 ? "Wszystkie sprawdzenia przeszły.\n" : "NIE PRZESZŁO: {$failures}\n";
    exit($failures === 0 ? 0 : 1);
})();
