<?php

declare(strict_types=1);

// Zdania dziennika zdarzeń (zlecenie Z36, 18.10.2026).
//
// ⚠ Sedno tych sprawdzeń jest takie samo, jak w `tests/kody-zdarzen.php`: ekran audytowy nie może
// ukryć zdarzenia dlatego, że ktoś zapomniał dopisać zdanie. Dlatego pilnujemy nie tylko tego,
// że znane kody mają zdania, ale przede wszystkim tego, że NIEZNANY kod dalej się pokazuje.
//
// ⚠ Drugi powód: zdanie składa się z trzech rzeczy trzymanych w trzech miejscach — orzeczenia
// (PHP), ikony (biała lista w `partials/ikona-zdarzenia.twig`) i barwy (reguła `.dz-znak--*`
// w CSS). Rozjazd między nimi nie wywraca ekranu, tylko po cichu zabiera ikonę albo kolor,
// więc widać go dopiero na zrzucie. Tu widać go od razu.
//
// Uruchomienie: php tests/zdania-dziennika.php — kod wyjścia 0 = wszystko przeszło.

require dirname(__DIR__) . '/vendor/autoload.php';

use Wezurlop\Service\ZakresWidocznosci as Z;
use Wezurlop\Support\KodyZdarzen;
use Wezurlop\Support\ZdanieDostepu;
use Wezurlop\Support\ZdanieZdarzenia;

(static function (): void {
    $failures = 0;
    $check = static function (string $opis, bool $ok, string $widziane = '') use (&$failures): void {
        echo ($ok ? 'OK    ' : 'BŁĄD  ') . $opis . ($ok || $widziane === '' ? '' : " — widziane: {$widziane}") . "\n";
        $failures += $ok ? 0 : 1;
    };

    /** @param array<string, mixed> $detale */
    $zbuduj = static function (string $kod, array $detale = [], array $nadpisz = []): array {
        return ZdanieZdarzenia::zbuduj($nadpisz + [
            'kod' => $kod,
            'aktor' => 'Kowalski Jan',
            'aktor_etykieta' => null,
            'konto_id' => 7,
            'podmiot' => null,
            'podmiot_typ' => null,
            'podmiot_id' => null,
            'detale' => $detale,
        ]);
    };
    $tekst = static fn (array $z): string => implode('', array_column($z['czesci'], 't'));
    $mocne = static fn (array $z): array => array_column(
        array_values(array_filter($z['czesci'], static fn (array $c): bool => $c['mocno'])),
        't',
    );

    // ——— kod nieznany ———
    $nieznany = $zbuduj('cokolwiek.nowego');
    $check('nieznany kod ma zdanie zastępcze', $tekst($nieznany) === 'wykonał(a) operację', $tekst($nieznany));
    $check('nieznany kod jest oznaczony jako nieznany', $nieznany['znane'] === false);
    $check('nieznany kod z rodziny dostaje ikonę rodziny', $zbuduj('auth.cos_nowego')['ikona'] === 'klodka');
    $check('nieznany kod spoza rodzin dostaje ikonę zastępczą', $nieznany['ikona'] === 'wnioski', $nieznany['ikona']);

    // ——— części opcjonalne ———
    // ⚠ Bez wartości cała część `[...]` znika: inaczej zdanie kończyło się „na poziomie"
    // albo „— dodani:" i wyglądało na uciętą treść.
    $bezKlienta = $zbuduj('auth.login_success');
    $check('brak szczegółu usuwa część opcjonalną', $tekst($bezKlienta) === 'zalogował(a) się', '„' . $tekst($bezKlienta) . '”');
    $zKlientem = $zbuduj('auth.login_success', ['client' => 'web']);
    $check('klient wchodzi do zdania słowem', $tekst($zKlientem) === 'zalogował(a) się w przeglądarce', $tekst($zKlientem));
    // ⚠ „w przeglądarce" to tłumaczenie, nie treść wpisu — pogrubienie zostaje dla danych.
    $check('okolicznik klienta NIE jest pogrubiony', $mocne($zKlientem) === [], implode('|', $mocne($zKlientem)));
    $check('klient nieznany nie trafia do zdania', $tekst($zbuduj('auth.login_success', ['client' => 'xyz'])) === 'zalogował(a) się');

    // ——— wartości z danych są pogrubione ———
    $grupa = $zbuduj('group.created', ['nazwa' => 'Magazyn']);
    $check('nazwa grupy w cudzysłowie i pogrubiona', $mocne($grupa) === ['„Magazyn”'], implode('|', $mocne($grupa)));
    $check('zdanie o grupie czyta się po polsku', $tekst($grupa) === 'utworzył(a) grupę „Magazyn”', $tekst($grupa));

    // ——— żadnego surowego JSON-a w zdaniu (warunek odbioru Z36) ———
    $sklad = $zbuduj('group.members_changed', ['dodani' => ['A A', 'B B', 'C C', 'D D', 'E E'], 'usunieci' => []]);
    $check('tablica staje się wyliczeniem, nie JSON-em',
        !str_contains($tekst($sklad), '[') && !str_contains($tekst($sklad), '{'), $tekst($sklad));
    $check('długie wyliczenie jest ucięte do trzech pozycji',
        str_contains($tekst($sklad), 'A A, B B, C C i jeszcze 2'), $tekst($sklad));
    $check('pusta tablica usuwa swoją część zdania',
        !str_contains($tekst($sklad), 'usunięci'), $tekst($sklad));
    $zagniezdzone = $zbuduj('group.created', ['nazwa' => ['a' => 1]]);
    $check('wartość nieskalarna nie wycieka do zdania',
        !str_contains($tekst($zagniezdzone), '{') && !str_contains($tekst($zagniezdzone), 'Array'), $tekst($zagniezdzone));

    // ——— kto i kogo ———
    $konto = $zbuduj('account.blocked_manually', [], ['podmiot' => null, 'podmiot_typ' => 'account', 'podmiot_id' => 73]);
    $check('konto bez kartoteki opisane numerem, nie pustką',
        str_contains($tekst($konto), 'konto #73 (nieznane)'), $tekst($konto));
    $sync = $zbuduj('employment.deactivated_by_sync', [], ['aktor' => null, 'aktor_etykieta' => 'sync', 'konto_id' => null]);
    $check('connector jako sprawca ma nazwę po polsku', $sync['kto'] === 'Connector', $sync['kto']);
    $brakAktora = $zbuduj('maintenance.cron_probe', [], ['aktor' => null, 'aktor_etykieta' => null, 'konto_id' => null]);
    $check('brak sprawcy to „System”, nie pusty napis', $brakAktora['kto'] === 'System', $brakAktora['kto']);
    $nieznaneKonto = $zbuduj('auth.login_success', [], ['aktor' => null, 'aktor_etykieta' => null, 'konto_id' => 15]);
    $check('skasowane konto sprawcy opisane numerem', $nieznaneKonto['kto'] === 'konto #15 (nieznane)', $nieznaneKonto['kto']);

    // ——— spójność trzech miejsc: zdanie ↔ ikona ↔ barwa ———
    $ikony = (string) file_get_contents(dirname(__DIR__) . '/templates/partials/ikona-zdarzenia.twig');
    $css = (string) file_get_contents(dirname(__DIR__) . '/public/assets/css/30-components.css');
    $barwy = [
        ZdanieZdarzenia::BARWA_LOGOWANIE, ZdanieZdarzenia::BARWA_DODANIE,
        ZdanieZdarzenia::BARWA_USUNIECIE, ZdanieZdarzenia::BARWA_ZWYKLA,
    ];
    $zleIkony = [];
    $zleBarwy = [];
    foreach (ZdanieZdarzenia::kody() as $kod) {
        $z = $zbuduj($kod);
        if (!str_contains($ikony, "'" . $z['ikona'] . "'")) {
            $zleIkony[] = $kod . ' → ' . $z['ikona'];
        }
        if (!in_array($z['barwa'], $barwy, true)) {
            $zleBarwy[] = $kod . ' → ' . $z['barwa'];
        }
    }
    $check('każda ikona z katalogu jest na białej liście szablonu', $zleIkony === [], implode(', ', array_slice($zleIkony, 0, 5)));
    $check('każda barwa z katalogu jest znana', $zleBarwy === [], implode(', ', array_slice($zleBarwy, 0, 5)));
    foreach ($barwy as $barwa) {
        if ($barwa === ZdanieZdarzenia::BARWA_ZWYKLA) {
            continue;  // stan domyślny nie ma własnego modyfikatora — bierze tło `.dz-znak`
        }
        $check("barwa „{$barwa}” ma regułę w CSS", str_contains($css, '.dz-znak--' . $barwa));
    }

    // ⚠ Kod z etykietą, ale bez zdania, to najcichszy możliwy rozjazd: lista pokaże „wykonał(a)
    // operację" przy zdarzeniu, które aplikacja zna na tyle dobrze, że ma dla niego nazwę.
    $etykiety = new ReflectionClassConstant(KodyZdarzen::class, 'ETYKIETY');
    /** @var array<string, string> $znane */
    $znane = $etykiety->getValue();
    $bezZdania = array_values(array_filter(
        array_keys($znane),
        static fn (string $kod): bool => !ZdanieZdarzenia::maZdanie($kod),
    ));
    $check('każdy kod z etykietą ma też zdanie', $bezZdania === [], implode(', ', $bezZdania));

    // ——— poczta: wpis o FAKCIE wysyłki (Z43 §C) ———
    // ⚠ Odbiorca jest tu daną wpisu i musi być pogrubiony, a komunikat serwera SMTP — nie:
    // pogrubiony powód przeciągał wzrok na trzy linijki techniczne i zasłaniał adres.
    $wyslane = $zbuduj('mail.sent', ['rodzaj' => 'zaproszenie', 'odbiorca' => 'ktos@przyklad.pl', 'zadanie' => '412']);
    $check('wysłana wiadomość czyta się po polsku',
        $tekst($wyslane) === 'wysłał(a) zaproszenie do ktos@przyklad.pl', $tekst($wyslane));
    $check('pogrubiony jest rodzaj i odbiorca, nie numer zadania',
        $mocne($wyslane) === ['zaproszenie', 'ktos@przyklad.pl'], implode('|', $mocne($wyslane)));
    $plik = $zbuduj('mail.sent', ['rodzaj' => 'zaproszenie', 'odbiorca' => 'ktos@przyklad.pl', 'droga' => 'zrzut do pliku']);
    $check('zrzut do pliku jest w zdaniu, bo wiadomość NIE wyszła w świat',
        str_contains($tekst($plik), '— zrzut do pliku'), $tekst($plik));
    // ⚠ Rodzaj przychodzi tu w DOPEŁNIACZU (`ZadaniaKolejki::wiadomoscDopelniacz`), bo polskie
    // zaprzeczenie go wymaga: jedna forma dawała „nie wysłał link do ustawienia hasła".
    $nieudane = $zbuduj('mail.failed', ['rodzaj' => 'linku do ustawienia hasła', 'odbiorca' => 'ktos@przyklad.pl',
        'powod' => 'SMTP: Could not authenticate.']);
    $check('niewysłana wiadomość niesie powód',
        $tekst($nieudane) === 'nie wysłał(a) linku do ustawienia hasła do ktos@przyklad.pl — SMTP: Could not authenticate.',
        $tekst($nieudane));
    $check('powód błędu NIE jest pogrubiony',
        !in_array('SMTP: Could not authenticate.', $mocne($nieudane), true), implode('|', $mocne($nieudane)));
    $bezPowodu = $zbuduj('mail.failed', ['rodzaj' => 'alertu bezpieczeństwa', 'odbiorca' => 'ktos@przyklad.pl']);
    $check('brak powodu usuwa część opcjonalną, nie zostawia myślnika',
        !str_contains($tekst($bezPowodu), '—'), $tekst($bezPowodu));

    // ——— skrzynka na alerty (Z49) ———
    // ⚠ Jeden kod, dwa skutki: ustawienie adresu i WYŁĄCZENIE alertów. Zdanie musi powiedzieć,
    // co się stało — „zmienił skrzynkę na alerty" bez dopowiedzenia przy pustym adresie
    // zostawiałoby czytelnika z wrażeniem, że alerty dalej gdzieś idą.
    $alertUstawiony = $zbuduj('tenant.alert_email_changed', ['z' => '', 'na' => 'alerty@przyklad.pl']);
    $check('ustawienie skrzynki alertowej czyta się po polsku',
        $tekst($alertUstawiony) === 'zmienił(a) skrzynkę na alerty na „alerty@przyklad.pl”', $tekst($alertUstawiony));
    $alertPusty = $zbuduj('tenant.alert_email_changed',
        ['z' => 'alerty@przyklad.pl', 'na' => '', 'pusty' => 'bez adresu, alerty nie będą wysyłane']);
    $check('wyłączenie alertów mówi, co to znaczy',
        $tekst($alertPusty) === 'zmienił(a) skrzynkę na alerty — bez adresu, alerty nie będą wysyłane',
        $tekst($alertPusty));
    $check('dopowiedzenie o pustym adresie NIE jest pogrubione',
        $mocne($alertPusty) === [], implode('|', $mocne($alertPusty)));

    // ——— usunięcie zadania z kolejki (Z43 §B) ———
    $jedno = $zbuduj('maintenance.job_deleted', ['zadanie' => 'Zaproszenie do systemu', 'typ' => 'mail.invitation']);
    $check('usunięcie jednego zadania bez „sztuk: 1”',
        $tekst($jedno) === 'usunął(ęła) z kolejki zadanie Zaproszenie do systemu', $tekst($jedno));
    $trzy = $zbuduj('maintenance.job_deleted', ['zadanie' => 'Zaproszenie do systemu', 'typ' => 'mail.invitation', 'ile' => '3']);
    $check('usunięcie kilku zadań podaje liczbę', str_contains($tekst($trzy), 'sztuk: 3'), $tekst($trzy));

    // ——— filtry rodzaju ———
    // ⚠ Siedem od Z43: poczta dostała własny rodzaj, bo rodzina `mail.*` to od 19.10.2026 wpisy
    // o każdej wysłanej i niewysłanej wiadomości — w zbiorczej „integracji i systemie" ginęły
    // między przebiegami connectora.
    $check('filtrów rodzaju jest 7 (plus „Wszystkie” w szablonie)', count(KodyZdarzen::rodzaje()) === 7,
        (string) count(KodyZdarzen::rodzaje()));
    $check('poczta ma własny rodzaj i tylko rodzinę `mail.`',
        KodyZdarzen::prefiksyRodzaju('poczta') === ['mail.'], implode('|', KodyZdarzen::prefiksyRodzaju('poczta')));
    $check('rodzaj zbiorczy NIE łapie już poczty',
        !in_array('mail.', KodyZdarzen::prefiksyRodzaju('integracja'), true));
    $check('klucz spoza białej listy nie filtruje niczego', KodyZdarzen::prefiksyRodzaju('; DROP TABLE event_log') === []);
    $check('rodzaj „logowania” obejmuje trzy rodziny', KodyZdarzen::prefiksyRodzaju('logowania') === ['auth.', '2fa.', 'session.']);
    // ⚠ Każda rodzina kodów musi wpadać do DOKŁADNIE jednego filtra — inaczej suma filtrów nie
    // zgadza się z całością i część historii jest osiągalna tylko przez „Wszystkie".
    $prefiksy = [];
    foreach (KodyZdarzen::rodzaje() as $r) {
        foreach (KodyZdarzen::prefiksyRodzaju($r['klucz']) as $p) {
            $prefiksy[] = $p;
        }
    }
    $check('żadna rodzina nie jest w dwóch filtrach naraz', count($prefiksy) === count(array_unique($prefiksy)));
    $poza = array_values(array_filter(
        array_keys($znane),
        static function (string $kod) use ($prefiksy): bool {
            foreach ($prefiksy as $p) {
                if (str_starts_with($kod, $p)) {
                    return false;
                }
            }

            return true;
        },
    ));
    $check('każdy znany kod wpada do któregoś filtra', $poza === [], implode(', ', $poza));

    // ——— Z57 U2: reguły widoczności zdaniem, nie kodami ————————————————————————————
    // ⚠ Sedno: w treści wpisu zostają IDENTYFIKATORY (Z53 §5), a nazwy dokłada ekran przy
    // odczycie. Te sprawdzenia pilnują obu końców tej umowy — i tego, że brak nazwy nie daje
    // pustego miejsca w zdaniu audytowym.
    $nazwy = [
        'konto' => [4 => 'Anna Przykładowa'],
        'etat'  => [12 => 'Jan Testowy', 15 => 'Ewa Wzorcowa', 18 => 'Adam Próbny', 21 => 'Zofia Wzorcowa'],
        'grupa' => [1 => 'Magazyn', 2 => 'Produkcja'],
    ];
    /** Wpis z treścią taką, jaką zapisuje `WidocznoscService`. */
    $dostep = static function (string $kod, array $detale) use ($zbuduj, $nazwy): array {
        return $zbuduj($kod, ZdanieDostepu::wartosci($detale, $nazwy) + $detale);
    };

    $osoby = $dostep('calendar_access.granted',
        ['kto' => 'account:4', 'czyje' => 'persons:12,15', 'zakres' => Z::ZE_SZCZEGOLAMI]);
    $check('zdanie o regule nie zawiera kodów',
        !str_contains($tekst($osoby), 'account:') && !str_contains($tekst($osoby), 'persons')
        && !str_contains($tekst($osoby), '_szczegolami'), $tekst($osoby));
    $check('zdanie o regule podaje nazwy z bazy',
        str_contains($tekst($osoby), 'Anna Przykładowa') && str_contains($tekst($osoby), 'Jan Testowy')
        && str_contains($tekst($osoby), 'Ewa Wzorcowa'), $tekst($osoby));
    $check('zakres słowami', str_contains($tekst($osoby), 'ze szczegółami'), $tekst($osoby));
    // ⚠ Strzałki „→" nie ma w kroju pisma (zmierzone zrzutem) — w zdaniu stoi „›".
    $check('rozdziela „›", nie „→"',
        str_contains($tekst($osoby), ' › ') && !str_contains($tekst($osoby), '→'), $tekst($osoby));

    $wiele = $dostep('calendar_access.granted',
        ['kto' => 'account:4', 'czyje' => 'persons:12,15,18,21', 'zakres' => Z::BEZ_SZCZEGOLOW]);
    $check('przy czterech osobach wylicza dwie i „i 2 inne"',
        str_contains($tekst($wiele), 'Jan Testowy, Ewa Wzorcowa i 2 inne'), $tekst($wiele));

    // ⚠ Wpis sprzed Z57 nie niósł numerów osób — zdanie ma powiedzieć tyle, ile wie,
    // a nie wyliczyć dzisiejszy skład reguły (to byłby inny stan niż opisywany).
    $stary = $dostep('calendar_access.granted',
        ['kto' => 'account:4', 'czyje' => 'persons', 'zakres' => Z::ZE_SZCZEGOLAMI]);
    $check('stary wpis bez numerów mówi „wskazane osoby"',
        str_contains($tekst($stary), 'wskazane osoby'), $tekst($stary));

    $brak = $dostep('calendar_access.revoked',
        ['kto' => 'account:99', 'czyje' => 'groups:1,7', 'zakres' => Z::BEZ_SZCZEGOLOW]);
    $check('usunięte konto zostaje numerem, nie pustką',
        str_contains($tekst($brak), 'konto #99 — usunięte'), $tekst($brak));
    $check('skasowana grupa zostaje numerem',
        str_contains($tekst($brak), 'grupa #7 — usunięta'), $tekst($brak));
    $check('istniejąca grupa nazwą', str_contains($tekst($brak), 'Magazyn'), $tekst($brak));

    // ——— reguły stałe firmy: zmienia się w nich WYŁĄCZNIE zakres ———
    $admini = $dostep('calendar_access.changed',
        ['kto' => 'admins_all', 'czyje' => 'company', 'zakres' => Z::BEZ_SZCZEGOLOW, 'bylo' => Z::ZE_SZCZEGOLAMI]);
    $check('reguła stała: zdanie o ZAKRESIE, nie o nadaniu dostępu',
        $tekst($admini) === 'zmienił(a) zakres reguły Administratorzy: ze szczegółami › bez szczegółów',
        $tekst($admini));
    $przelozeni = $dostep('calendar_access.granted',
        ['kto' => 'supervisor_all', 'czyje' => 'subordinates', 'zakres' => Z::BEZ_SZCZEGOLOW]);
    $check('pierwszy zapis reguły stałej też mówi o zakresie',
        $tekst($przelozeni) === 'ustawił(a) zakres reguły Każdy przełożony: bez szczegółów', $tekst($przelozeni));
    // ⚠ Wariant NIE jest kodem zdarzenia: po kodach filtruje się dziennik.
    $check('wariant nie wchodzi do spisu kodów',
        !in_array('calendar_access.changed#stala', ZdanieZdarzenia::kody(), true));
    $check('kod z wariantem jest w spisie raz',
        count(array_filter(ZdanieZdarzenia::kody(), static fn (string $k): bool => $k === 'calendar_access.changed')) === 1);

    // ——— stan poprzedni zwykłej reguły: podpis tymi samymi słowami ———
    $zmiana = $dostep('calendar_access.changed', [
        'kto' => 'account:4', 'czyje' => 'company', 'zakres' => Z::ZE_SZCZEGOLAMI,
        'bylo' => 'account:4|groups:1,2|bez_szczegolow',
    ]);
    $check('„było" też bez kodów',
        str_contains($tekst($zmiana), 'było: Anna Przykładowa › grupy: Magazyn, Produkcja · bez szczegółów'),
        $tekst($zmiana));

    // ——— numery do rozwiązania zbierane z całej strony ———
    $ids = ZdanieDostepu::identyfikatory([
        ['kod' => 'calendar_access.granted', 'detale' => ['kto' => 'account:4', 'czyje' => 'persons:12,15']],
        ['kod' => 'calendar_access.changed', 'detale' => ['kto' => 'group:1', 'czyje' => 'groups:2',
            'bylo' => 'supervisor:21|company|brak']],
        ['kod' => 'auth.login_success', 'detale' => ['client' => 'web']],
    ]);
    $check('numery kont zebrane', $ids['konta'] === [4], implode(',', $ids['konta']));
    $check('numery zatrudnień zebrane z celu i z „było"',
        $ids['etaty'] === [12, 15, 21], implode(',', $ids['etaty']));
    $check('numery grup zebrane z podmiotu i z celu', $ids['grupy'] === [1, 2], implode(',', $ids['grupy']));

    // ——— sprawca „migracja" i rodzaj gramatyczny orzeczenia ———
    $migracja = $zbuduj('calendar_access.migrated', ['regul' => 2],
        ['aktor' => null, 'aktor_etykieta' => 'migracja', 'konto_id' => null]);
    $check('sprawcą jest „Migracja", nie „migracja"', $migracja['kto'] === 'Migracja', (string) $migracja['kto']);
    $check('orzeczenie zgodne z rodzajem sprawcy',
        str_starts_with($tekst($migracja), 'przeniosła'), $tekst($migracja));
    // ⚠ Wpis po usunięciu grupy ma sprawcę (administratora), więc musi mieć ORZECZENIE.
    $poGrupie = $zbuduj('calendar_access.auto_changed', ['skasowanych' => 1]);
    $check('wpis po usunięciu grupy jest zdaniem, nie nagłówkiem',
        str_starts_with($tekst($poGrupie), 'zmienił(a)'), $tekst($poGrupie));

    echo $failures === 0 ? "Wszystkie sprawdzenia przeszły.\n" : "NIE PRZESZŁO: {$failures}\n";
    exit($failures === 0 ? 0 : 1);
})();
