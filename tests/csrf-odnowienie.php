<?php

declare(strict_types=1);

// Siatka na wygasły token CSRF w formularzach SPRZED zalogowania (zlecenie Z56).
//
// ⚠ Powód istnienia tego pliku: zgłoszenie Krzysztofa — strona logowania otwarta przez noc,
// po wpisaniu danych komunikat „formularz wygasł". Sesja anonimowa wygasa po czasie
// bezczynności (`Middleware\SessionLifetime`), razem z nią znika token, a POST trafiał
// na stronę 419. Naprawa zmienia WYŁĄCZNIE odpowiedź: ten sam formularz, świeży token,
// wpisany adres. Odmowa zostaje odmową — żądanie nie wchodzi do kontrolera.
//
// ⚠ Dlatego tu są dwa rodzaje kryteriów i oba są potrzebne:
//   • CO wraca (szablon, komunikat, przeniesione pola) — czysta funkcja, bez HTTP,
//   • CZEGO NIE MA w odpowiedzi: hasła. Przeniesione hasło byłoby dokładnie tym, przed czym
//     broni token (żądanie z obcej strony dokończone „po poprawieniu").
// Trzecie kryterium pilnuje, żeby biała lista nie rozjechała się z `config/routes.php`.
//
// Uruchomienie: php tests/csrf-odnowienie.php — kod wyjścia 0 = wszystko przeszło.

require dirname(__DIR__) . '/vendor/autoload.php';

use Wezurlop\Http\OdnowienieFormularza;
use Wezurlop\Http\Request;
use Wezurlop\Security\Password;
use Wezurlop\Security\RateLimiter;
use Wezurlop\Support\TwigFactory;
use Wezurlop\Support\UrlGenerator;

(static function (): void {
    $failures = 0;
    $check = static function (string $name, bool $ok) use (&$failures): void {
        echo ($ok ? 'OK    ' : 'BŁĄD  ') . $name . "\n";
        $failures += $ok ? 0 : 1;
    };

    $HASLO = 'tajne-haslo-z-pomiaru-123';
    $ADRES = 'ktos@przyklad.invalid';
    $zadanie = static fn (array $body): Request => new Request(['REQUEST_METHOD' => 'POST'], [], $body);

    // ——— a) biała lista: które formularze obejmuje siatka ———————————————————————
    $objete = ['/logowanie', '/haslo/reset', '/haslo/nowe', '/zaproszenie'];
    foreach ($objete as $sciezka) {
        $check('objęty formularz ' . $sciezka, OdnowienieFormularza::formularz('POST', $sciezka) !== null);
        // ⚠ Wariant mobilny to ta sama trasa pod przedrostkiem — i ten sam formularz.
        $check('objęty także /m' . $sciezka, OdnowienieFormularza::formularz('POST', '/m' . $sciezka) !== null);
    }
    // Pozostałe 419 zostają bez zmian — to jest zakres zlecenia, nie „wszędzie".
    foreach (['/kalendarz/osoby', '/wyloguj', '/logowanie/kod', '/admin/widocznosc', '/ustawienia/strefa'] as $poza) {
        $check('poza siatką ' . $poza, OdnowienieFormularza::formularz('POST', $poza) === null);
    }
    $check('GET nie jest formularzem', OdnowienieFormularza::formularz('GET', '/logowanie') === null);

    // ——— b) przedrostek wariantu zdejmowany dokładnie ————————————————————————————
    $check('/m/haslo/nowe to /haslo/nowe', OdnowienieFormularza::bezWariantu('/m/haslo/nowe') === '/haslo/nowe');
    $check('/m to /', OdnowienieFormularza::bezWariantu('/m') === '/');
    // ⚠ Nie „wszystko, co zaczyna się na /m": ścieżka /mapa nie jest wariantem mobilnym.
    $check('/mapa zostaje /mapa', OdnowienieFormularza::bezWariantu('/mapa') === '/mapa');
    $check('/logowanie zostaje bez zmian', OdnowienieFormularza::bezWariantu('/logowanie') === '/logowanie');

    // ——— c) hasło nie jest przenoszone NIGDZIE ——————————————————————————————————
    foreach ($objete as $sciezka) {
        $formularz = OdnowienieFormularza::formularz('POST', $sciezka);
        $przenosi = $formularz['przenosi'];
        $check('biała lista pól bez hasła (' . $sciezka . ')',
            array_intersect($przenosi, ['password', 'password_repeat', '_csrf']) === []);
        $dane = OdnowienieFormularza::dane($formularz, $zadanie([
            'email' => $ADRES, 'token' => str_repeat('a', 64),
            'password' => $HASLO, 'password_repeat' => $HASLO,
        ]));
        $check('w danych szablonu nie ma hasła (' . $sciezka . ')',
            !in_array($HASLO, $dane, true) && !array_key_exists('password', $dane));
        $check('komunikat spokojny, bez słowa „błąd" (' . $sciezka . ')',
            is_string($dane['notice']) && $dane['notice'] !== ''
            && stripos($dane['notice'], 'błąd') === false && !str_contains($dane['notice'], '!')
            && $dane['notice_rodzaj'] === 'info');
        $check('brak stanu błędu (' . $sciezka . ')', $dane['error'] === null);
    }

    // ——— d) przeniesione pola: adres i token, z limitem długości ————————————————
    $logowanie = OdnowienieFormularza::formularz('POST', '/logowanie');
    $daneLog = OdnowienieFormularza::dane($logowanie, $zadanie(['email' => $ADRES, 'password' => $HASLO]));
    $check('adres wraca do pola', $daneLog['email'] === $ADRES);
    $check('komunikat logowania zgodny ze zleceniem',
        $daneLog['notice'] === 'Strona była otwarta długo — zaloguj się jeszcze raz.');
    // ⚠ Za długa wartość wraca PUSTA, nie obcięta — ta sama reguła, co w kontrolerze
    // (`Request::postString`): wejście spoza zakresu odrzucamy, a nie naprawiamy po cichu.
    $daneDlugi = OdnowienieFormularza::dane($logowanie, $zadanie(['email' => str_repeat('x', 500)]));
    $check('adres spoza limitu nie wraca wcale', $daneDlugi['email'] === '');
    $nowe = OdnowienieFormularza::formularz('POST', '/haslo/nowe');
    $daneNowe = OdnowienieFormularza::dane($nowe, $zadanie(['token' => 'abc', 'password' => $HASLO]));
    $check('token linku wraca do formularza', $daneNowe['token'] === 'abc');
    $check('formularz hasła zna minimalną długość', $daneNowe['min_length'] === Password::MIN_LENGTH);

    // ——— e) licznik prób: wygasły token NIE jest drogą obejścia limitu ————————————
    $check('logowanie liczy próbę', $logowanie['liczy_probe'] === true);
    // ⚠ Prośba o reset ma własny, twardy limit, ale na tej drodze nie powstaje ani poczta,
    // ani token — doliczenie zabierałoby jedną z trzech prób resetu za otwartą kartę.
    $check('prośba o reset nie zjada limitu resetu',
        OdnowienieFormularza::formularz('POST', '/haslo/reset')['liczy_probe'] === false);
    $check('rodzaj licznika to ten sam co przy haśle', RateLimiter::KIND_PASSWORD === 'password');

    // ——— f) próg odświeżenia tokenu: z konfiguracji, nie na sztywno ————————————————
    $check('próg to połowa czasu bezczynności', OdnowienieFormularza::progSekund(1800) === 900);
    $check('próg przy dwóch godzinach', OdnowienieFormularza::progSekund(7200) === 3600);
    // Krótka sesja na środowisku testowym nie może zamienić odświeżania w ruch przy każdym kliknięciu.
    $check('próg ma dolne ograniczenie', OdnowienieFormularza::progSekund(30) === 60);

    // ——— g) biała lista zgodna z config/routes.php ————————————————————————————————
    $trasy = (require dirname(__DIR__) . '/config/routes.php')(false);
    $mapa = [];
    foreach ($trasy as $trasa) {
        $mapa[$trasa[0] . ' ' . $trasa[1]] = $trasa;
    }
    foreach ($objete as $sciezka) {
        $check('trasa POST ' . $sciezka . ' istnieje i jest publiczna',
            isset($mapa['POST ' . $sciezka]) && ($mapa['POST ' . $sciezka]['auth'] ?? null) === 'none');
    }
    // ⚠ Endpoint tokenu: TYLKO GET (zlecenie Z56 §2). POST pod tym adresem nie istnieje,
    // więc nie ma czego podsuwać — router odpowie 405.
    $check('/token-formularza istnieje jako GET', isset($mapa['GET /token-formularza']));
    $check('/token-formularza nie ma wariantu POST', !isset($mapa['POST /token-formularza']));
    $check('/token-formularza jest publiczne', ($mapa['GET /token-formularza']['auth'] ?? null) === 'none');

    // ——— h) ten sam szablon, co wchodzi na ekran: render z strict_variables ——————————
    // ⚠ Mierzymy PLIKI z `templates/`, nie makietę: brakująca zmienna wywala render, więc ten
    // blok jest jedynym dowodem, że dane z `dane()` wystarczają szablonowi.
    $urls = new UrlGenerator('', '/assets', 'x', UrlGenerator::WEB);
    $twig = TwigFactory::create(dirname(__DIR__) . '/templates', null, false, $urls, [
        'csrf_token'  => static fn (): string => 'swiezy-token-pomiaru',
        'prog_tokenu' => static fn (): int => OdnowienieFormularza::progSekund(1800),
    ]);
    foreach ($objete as $sciezka) {
        $formularz = OdnowienieFormularza::formularz('POST', $sciezka);
        $dane = OdnowienieFormularza::dane($formularz, $zadanie([
            'email' => $ADRES, 'token' => 'token-linku', 'password' => $HASLO, 'password_repeat' => $HASLO,
        ]));
        $html = $twig->render($formularz['szablon'], $dane);
        $check('render ' . $sciezka . ': komunikat na ekranie', str_contains($html, $dane['notice']));
        $check('render ' . $sciezka . ': świeży token w formularzu',
            str_contains($html, 'name="_csrf" value="swiezy-token-pomiaru"'));
        $check('render ' . $sciezka . ': HASŁA NIE MA w HTML-u', !str_contains($html, $HASLO));
        $check('render ' . $sciezka . ': komunikat nie jest czerwony', !str_contains($html, 'notice--danger'));
        $check('render ' . $sciezka . ': znacznik odświeżania tokenu stoi',
            str_contains($html, 'data-token-url="/token-formularza"')
            && str_contains($html, 'data-token-prog="900"'));
    }
    $htmlLog = $twig->render('page/auth/login.twig', OdnowienieFormularza::dane($logowanie, $zadanie(['email' => $ADRES])));
    $check('render logowania: adres wpisany w pole', str_contains($htmlLog, 'value="' . $ADRES . '"'));

    echo $failures === 0 ? "\nWSZYSTKO PRZESZŁO\n" : "\nBŁĘDÓW: {$failures}\n";
    exit($failures === 0 ? 0 : 1);
})();
