<?php

declare(strict_types=1);

// **Wygląd i budowa każdej wiadomości** (zlecenie Z60 §5).
//
// ⚠ Powód istnienia tego pliku: do 20.10.2026 osiem z jedenastu rodzajów wiadomości było
// napisami sklejanymi w PHP — bez wersji HTML, bez wspólnej stopki i z tematami w dwóch
// wzorach („wezurlop: …" i „wezurlop…"). Rozjazd w poczcie jest szczególnie drogi: wiadomość
// widzi się raz, w dniu wysyłki, a potem już nigdy — nikt nie zauważy, że alert wyszedł bez
// stopki albo bez wersji tekstowej, dopóki nie wpadnie komuś do spamu.
//
// ⚠ Test renderuje **prawdziwe szablony prawdziwym renderem** (`MailRenderer`, `strict_variables`,
// auto-escaping w HTML), na danych fikcyjnych wspólnych z podglądem (`dev/probka-poczty.php`).
// Brakująca zmienna wywala render, a nie renderuje pustkę.
//
// Uruchomienie: php tests/poczta.php — kod wyjścia 0 = wszystko przeszło.

require dirname(__DIR__) . '/vendor/autoload.php';

use Wezurlop\Dev\Poczta\PrzykladyWiadomosci;
use Wezurlop\Service\AccountMailService;
use Wezurlop\Service\Mail\BarwyPoczty;
use Wezurlop\Service\Mail\MailRenderer;

(static function (): void {
    $failures = 0;
    $check = static function (string $opis, bool $ok, string $widziane = '') use (&$failures): void {
        echo ($ok ? 'OK    ' : 'BŁĄD  ') . $opis . ($ok || $widziane === '' ? '' : " — widziane: {$widziane}") . "\n";
        $failures += $ok ? 0 : 1;
    };

    $renderer = new MailRenderer(dirname(__DIR__) . '/templates', null, 'Europe/Warsaw');
    $rodzaje = PrzykladyWiadomosci::wszystkie();

    // ⚠ Spis rodzajów jest częścią kryterium: test ma objąć WSZYSTKIE wiadomości, a nie te,
    // o których ktoś pamiętał. Nowy rodzaj bez wpisu w katalogu przykładów czerwieni ten warunek.
    $check('katalog obejmuje co najmniej osiem rodzajów', count($rodzaje) >= 8, (string) count($rodzaje));

    foreach ($rodzaje as $nazwa => [$szablon, $temat, $dane]) {
        $w = $renderer->wiadomosc($szablon, 'odbiorca@przyklad.invalid', $temat, $dane);
        $html = (string) $w->html;
        $tekst = $w->body;

        // ——— temat w jednym wzorze, z jednym jawnym wyjątkiem (Z62) ———
        if (in_array($szablon, MailRenderer::PELNY_TEMAT, true)) {
            // ⚠ Szablon z `PELNY_TEMAT` dostaje temat gotowy — render nie ma prawa nic dołożyć.
            // Kryterium jest **równością**, nie samym „zaczyna się od": prefiks dołożony w środku
            // albo na końcu też byłby rozjazdem.
            $check($nazwa . ': render nie rusza pełnego tematu', $w->subject === $temat, $w->subject);
        } else {
            $check($nazwa . ': temat z prefiksem „wezurlop · "',
                str_starts_with($w->subject, MailRenderer::PREFIKS), $w->subject);
        }
        $check($nazwa . ': temat bez dawnego „wezurlop: "', !str_contains($w->subject, 'wezurlop: '), $w->subject);

        // ——— obie wersje istnieją i nie są puste ———
        $check($nazwa . ': ma wersję tekstową', trim($tekst) !== '');
        $check($nazwa . ': ma wersję HTML', str_contains($html, '<!doctype html>'));

        // ——— wspólny nagłówek i stopka ———
        $check($nazwa . ': nagłówek z nazwą produktu w barwach marki',
            str_contains($html, 'urlop</span>.pl') && str_contains($html, BarwyPoczty::MAPA['akcja']));
        foreach (['html' => $html, 'tekst' => $tekst] as $gdzie => $tresc) {
            $check($nazwa . ': stopka „nie odpowiadaj" (' . $gdzie . ')',
                str_contains($tresc, 'Wiadomość wysłana automatycznie z aplikacji wezurlop — nie odpowiadaj na nią.'));
            // ⚠ Data i godzina ze strefą: alert bez znacznika czasu nie mówi, czy opisuje stan
            // z dziś, czy z zeszłego tygodnia.
            $check($nazwa . ': znacznik czasu ze strefą (' . $gdzie . ')',
                preg_match('/\d{2}\.\d{2}\.\d{4}, \d{2}:\d{2} \(Europe\/Warsaw\)/', $tresc) === 1);
        }

        // ——— szerokość treści i brak rzeczy zakazanych w poczcie ———
        $check($nazwa . ': szerokość treści 600 px', str_contains($html, 'width:600px; max-width:100%'));
        $check($nazwa . ': zero obrazków', !str_contains($html, '<img'));
        $check($nazwa . ': zero arkusza w nagłówku', !str_contains($html, '<style'));
        $check($nazwa . ': zero klas CSS', !str_contains($html, 'class="'));

        // ——— „firma", nie „spółka" ———
        $check($nazwa . ': bez słowa „spółk"',
            stripos($html, 'spółk') === false && stripos($tekst, 'spółk') === false);

        // ——— jedno działanie: przycisk i adres jako tekst ———
        if (($dane['akcja'] ?? null) !== null) {
            $link = $dane['akcja']['link'];
            $check($nazwa . ': przycisk akcji w HTML', str_contains($html, 'href="' . $link . '"'));
            // ⚠ Adres MUSI stać też jako tekst: przycisk bez adresu jest bezużyteczny
            // w czytniku tekstowym i przy zablokowanych stylach.
            $check($nazwa . ': adres także jako tekst w HTML',
                substr_count($html, $link) >= 2, (string) substr_count($html, $link));
            $check($nazwa . ': adres w wersji tekstowej', str_contains($tekst, $link));
            $check($nazwa . ': jeden przycisk, nie dwa', substr_count($html, 'bgcolor="' . BarwyPoczty::MAPA['akcja'] . '"') === 1);
        }

        // ——— sekrety nie wychodzą ———
        // ⚠ Wiadomość próbna niesie host i port (konfiguracja instalacji), ale NIGDY loginu
        // i hasła SMTP (CLAUDE.md §14). Kryterium pilnuje słów, nie wartości: wartości w danych
        // przykładowych nie ma, więc tylko słowo może je tu wprowadzić.
        foreach (['hasło SMTP', 'password', 'Hasło:'] as $zakazane) {
            $check($nazwa . ': bez „' . $zakazane . '"',
                !str_contains($html, $zakazane) && !str_contains($tekst, $zakazane));
        }
    }

    // ——— temat zaproszenia: nazwa pracodawcy na pierwszym miejscu (zlecenie Z62) ———
    // ⚠ Pracownik czyta tę wiadomość PRZED pierwszym logowaniem i nazwy naszego produktu
    // jeszcze nie zna. Dlatego kryterium jest dwustronne: temat MA się zaczynać od nazwy firmy
    // i NIE MA się zaczynać od „wezurlop" — pierwszy warunek sam przeszedłby też wtedy, gdyby
    // prefiks wrócił w środku napisu.
    $tematZaproszenia = $renderer->wiadomosc(
        $rodzaje['zaproszenie'][0],
        'odbiorca@przyklad.invalid',
        $rodzaje['zaproszenie'][1],
        $rodzaje['zaproszenie'][2],
    )->subject;
    $check('zaproszenie: temat zaczyna się od nazwy firmy z przykładu',
        str_starts_with($tematZaproszenia, 'ALFA · '), $tematZaproszenia);
    $check('zaproszenie: temat NIE zaczyna się od „wezurlop"',
        !str_starts_with($tematZaproszenia, 'wezurlop'), $tematZaproszenia);
    $check('zaproszenie: temat w pełnym brzmieniu',
        $tematZaproszenia === 'ALFA · Zaproszenie do aplikacji urlopowej — ustaw hasło', $tematZaproszenia);

    // ⚠ Konto administracyjne nie ma pracodawcy w R2 — i wtedy, TYLKO wtedy, w temacie stoi
    // nazwa produktu. Bez tego kryterium „nazwa firmy w temacie" przeszłoby pustą nazwą firmy.
    $tematAdmin = $renderer->wiadomosc(
        $rodzaje['zaproszenie-administracyjne'][0],
        'admin@przyklad.invalid',
        $rodzaje['zaproszenie-administracyjne'][1],
        $rodzaje['zaproszenie-administracyjne'][2],
    )->subject;
    $check('zaproszenie konta administracyjnego: temat z prefiksem „wezurlop · "',
        str_starts_with($tematAdmin, MailRenderer::PREFIKS), $tematAdmin);
    $check('zaproszenie konta administracyjnego: temat w pełnym brzmieniu',
        $tematAdmin === 'wezurlop · Zaproszenie do aplikacji — ustaw hasło', $tematAdmin);

    // ⚠ Wzór tematu sprawdzany też wprost, bo to jedna funkcja decyduje o obu wariantach.
    // Pusta i biała nazwa firmy MUSI wpaść w wariant produktu: nazwa „ · Zaproszenie…" byłaby
    // wiadomością bez nadawcy, a nie wiadomością od pracodawcy.
    foreach ([null, '', '   '] as $brak) {
        $check('bez nazwy firmy (' . var_export($brak, true) . ') temat ma prefiks produktu',
            AccountMailService::tematZaproszenia($brak) === 'wezurlop · Zaproszenie do aplikacji — ustaw hasło',
            AccountMailService::tematZaproszenia($brak));
    }
    $check('nazwa firmy z marginesami przychodzi obcięta',
        AccountMailService::tematZaproszenia('  BETA  ') === 'BETA · Zaproszenie do aplikacji urlopowej — ustaw hasło',
        AccountMailService::tematZaproszenia('  BETA  '));

    // ——— barwy: jedno miejsce, wartości z tokenów ———
    // ⚠ Wartości są przepisane z `00-tokens.css` i tamten plik zostaje źródłem palety; test
    // pilnuje, że nie rozjechały się po cichu.
    $tokeny = file_get_contents(dirname(__DIR__) . '/public/assets/css/00-tokens.css');
    $pary = [
        'akcja' => '--c-accent:',
        'tytul' => '--c-text:',
        'tekst' => '--c-text-2:',
        'podpis' => '--c-text-3:',
        'stopka' => '--c-text-4:',
        'tlo' => '--c-bg:',
        'karta' => '--c-surface:',
        'ramka' => '--c-line:',
        'uwaga' => '--c-danger-text:',
    ];
    foreach ($pary as $klucz => $token) {
        $wartosc = BarwyPoczty::MAPA[$klucz];
        $check('barwa „' . $klucz . '" zgodna z ' . $token,
            (bool) preg_match('/' . preg_quote($token, '/') . '\s*' . preg_quote($wartosc, '/') . '/i', (string) $tokeny),
            $wartosc);
    }

    echo $failures === 0 ? "Wszystkie sprawdzenia przeszły.\n" : "NIE PRZESZŁO: {$failures}\n";
    exit($failures === 0 ? 0 : 1);
})();
