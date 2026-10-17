<?php

declare(strict_types=1);

// Testy jednostkowe synchronizacji (Etap 3, blok C/D): okno dat na przełomie roku, limity treści,
// walidacja rekordów, rozpoznanie błędów przejściowych bazy (503). Scenariusze end-to-end przez HTTP:
// bin/sync-symulator.php. Uruchomienie: php tests/sync.php — kod wyjścia 0 = wszystko przeszło.

require dirname(__DIR__) . '/vendor/autoload.php';

use Wezurlop\Http\ApiKernel;
use Wezurlop\Service\Sync\ApiBody;
use Wezurlop\Service\Sync\ApiError;
use Wezurlop\Service\Sync\SchemaValidator;
use Wezurlop\Service\Sync\TaskService;
use Wezurlop\Service\Sync\SyncWindow;
use Wezurlop\Support\WorkTime;

(static function (): void {
    $failures = 0;
    $check = static function (string $name, bool $ok) use (&$failures): void {
        echo ($ok ? 'OK    ' : 'BŁĄD  ') . $name . "\n";
        $failures += $ok ? 0 : 1;
    };
    $d = static fn (string $s): DateTimeImmutable => new DateTimeImmutable($s);
    $status = static function (callable $f): ?int {
        try {
            $f();
        } catch (ApiError $e) {
            return $e->status;
        }

        return null;
    };

    // --- okno: nieobecność poza oknem, gdy dataDo < 1 stycznia roku poprzedniego; 2 dni zapasu po Nowym Roku
    $check('19.09.2026: najstarszy rok 2025', (new SyncWindow($d('2026-09-19')))->oldestYear() === 2025);
    $check('19.09.2026: 2024-12-31 poza oknem', (new SyncWindow($d('2026-09-19')))->isOutside($d('2024-12-31')));
    $check('19.09.2026: 2025-01-01 w oknie', !(new SyncWindow($d('2026-09-19')))->isOutside($d('2025-01-01')));
    $check('31.12.2026: 2025 nadal w oknie', !(new SyncWindow($d('2026-12-31')))->isOutside($d('2025-06-30')));
    $check('01.01.2027: okno szersze — 2025 w oknie', !(new SyncWindow($d('2027-01-01')))->isOutside($d('2025-06-30')));
    $check('02.01.2027: okno szersze — 2025 w oknie', !(new SyncWindow($d('2027-01-02')))->isOutside($d('2025-06-30')));
    $check('03.01.2027: przesunięcie — 2025 poza oknem', (new SyncWindow($d('2027-01-03')))->isOutside($d('2025-12-31')));
    $check('03.01.2027: 2026-01-01 w oknie', !(new SyncWindow($d('2027-01-03')))->isOutside($d('2026-01-01')));

    // --- treść żądania
    $check('treść > 2 MB → 413', $status(static fn () => ApiBody::parse(str_repeat('a', ApiBody::MAX_BYTES + 1), null)) === 413);
    $check('gzip rozpakowany > 2 MB → 413', $status(static fn () => ApiBody::parse((string) gzencode('{"x":"' . str_repeat('a', 2_200_000) . '"}'), 'gzip')) === 413);
    $check('bomba gzip (9 MB zer) → 413', $status(static fn () => ApiBody::parse((string) gzencode(str_repeat('0', 9_000_000), 9), 'gzip')) === 413);
    $check('uszkodzony gzip → 400', $status(static fn () => ApiBody::parse('nie-gzip', 'gzip')) === 400);
    $check('Content-Encoding: br → 415', $status(static fn () => ApiBody::parse('{}', 'br')) === 415);
    $check('lista zamiast obiektu → 400', $status(static fn () => ApiBody::parse('[1,2]', null)) === 400);
    $check('gzip poprawny → obiekt', ApiBody::parse((string) gzencode('{"a":1}'), 'gzip') === ['a' => 1]);

    // --- walidacja rekordów (config/sync-schema.php)
    $v = new SchemaValidator(require dirname(__DIR__) . '/config/sync-schema.php');
    $sum = 'sha256:' . str_repeat('ab', 32);
    $base = ['id' => 'urlop:1', 'suma' => $sum, 'zatrudnienieId' => '207', 'rodzaj' => ['zrodlo' => 'urlop', 'kod' => 0],
        'dataOd' => '2026-10-05', 'dataDo' => '2026-10-09', 'zrodloWpisu' => 'kadry'];
    $r = $v->validate('nieobecnosc', [$base]);
    $check('brak kluczy opcjonalnych = null (rekord poprawny)', $r['errors'] === [] && $r['records'][0]['dni'] === null);
    $r = $v->validate('nieobecnosc', [['dataDo' => null] + $base]);
    $check('pole wymagane = null → POLE_WYMAGANE', ($r['errors'][0]['kod'] ?? '') === 'POLE_WYMAGANE' && $r['errors'][0]['pole'] === 'dataDo');
    $r = $v->validate('nieobecnosc', [['dni' => '5'] + $base]);
    $check('dni jako tekst → ZLY_TYP', ($r['errors'][0]['kod'] ?? '') === 'ZLY_TYP');
    $r = $v->validate('nieobecnosc', [['utworzono' => '2026-09-19T10:09:56'] + $base]);
    $check('znacznik czasu bez strefy → ZLY_TYP', ($r['errors'][0]['pole'] ?? '') === 'utworzono');
    $r = $v->validate('nieobecnosc', [['nowePole' => 1, 'inne' => 2] + $base]);
    $check('nieznane pola pominięte i policzone', $r['errors'] === [] && $r['skipped'] === 2);
    $r = $v->validate('nieobecnosc', [['id' => 'kadrowe:17', 'usuniety' => true]]);
    $check('rekord usunięty: tylko id', $r['errors'] === []);
    $r = $v->validate('nieobecnosc', [['suma' => 'sha256:3b1f…'] + $base]);
    $check('suma skrócona → ZLY_TYP', ($r['errors'][0]['pole'] ?? '') === 'suma');

    // --- nowe pola z danych klienta (20.09.2026): powiązanie rodzaju z limitem i normy kalendarza
    $slownik = ['id' => 'urlop:0', 'suma' => $sum, 'zrodlo' => 'urlop', 'kod' => 0, 'nazwa' => 'urlop wypoczynkowy',
        'tylkoOdczyt' => false, 'doWniosku' => true, 'nieobecnosc' => true];
    $r = $v->validate('slownik', [$slownik + ['obciazaLimit' => 'wypoczynkowy', 'obciazaLimitZrodlo' => 'dane']]);
    $check('slownik: obciazaLimit i obciazaLimitZrodlo przyjęte',
        $r['errors'] === [] && $r['records'][0]['obciazaLimit'] === 'wypoczynkowy' && $r['records'][0]['obciazaLimitZrodlo'] === 'dane');
    $r = $v->validate('slownik', [$slownik]);
    $check('slownik: starszy connector bez nowych pól → null, nie błąd',
        $r['errors'] === [] && $r['records'][0]['obciazaLimit'] === null && $r['records'][0]['obciazaLimitZrodlo'] === null);
    $r = $v->validate('slownik', [['obciazaLimit' => 7] + $slownik]);
    $check('slownik: obciazaLimit liczbą → ZLY_TYP', ($r['errors'][0]['pole'] ?? '') === 'obciazaLimit');

    $kalendarz = ['id' => 'kal:2', 'suma' => $sum, 'kalendarzId' => '2', 'nazwa' => 'Główny', 'rok' => 2026,
        'indywidualne' => false, 'dniNierobocze' => ['2026-01-01']];
    $r = $v->validate('kalendarz', [$kalendarz + ['normaUrlopowaMin' => 720, 'normaDobowaMin' => 720]]);
    $check('kalendarz: normy przyjęte', $r['errors'] === [] && $r['records'][0]['normaUrlopowaMin'] === 720);
    $r = $v->validate('kalendarz', [$kalendarz]);
    $check('kalendarz: brak norm → null (wartość zapasową wybiera aplikacja, nie walidator)',
        $r['errors'] === [] && $r['records'][0]['normaUrlopowaMin'] === null);
    $r = $v->validate('kalendarz', [['normaUrlopowaMin' => '480'] + $kalendarz]);
    $check('kalendarz: norma tekstem → ZLY_TYP', ($r['errors'][0]['pole'] ?? '') === 'normaUrlopowaMin');

    // --- requestId: twarde ograniczenie programu kadrowego wymuszone w generatorze
    $ids = [];
    for ($i = 0; $i < 500; $i++) {
        $ids[] = TaskService::newRequestId();
    }
    $check('generator requestId: 500 identyfikatorów w limicie 32 znaków i alfabecie [A-Za-z0-9-]',
        array_filter($ids, static fn (string $id): bool => !TaskService::isValidRequestId($id)) === []);
    $check('generator requestId: stała długość 29 znaków', array_unique(array_map('strlen', $ids)) === [29]);
    $check('generator requestId: bez powtórzeń w 500 próbach', count(array_unique($ids)) === 500);
    $check('requestId 33 znaki odrzucony', !TaskService::isValidRequestId(str_repeat('a', 33)));
    $check('requestId ze znakiem spoza alfabetu odrzucony', !TaskService::isValidRequestId('wu_01J9ZC8QK4T7'));
    $check('requestId pusty odrzucony', !TaskService::isValidRequestId(''));
    $check('requestId z dokumentu przyjęty', TaskService::isValidRequestId('wu-01J9ZC8QK4T7'));

    // --- minuty z kadr na „dni i godziny” (ekrany /dane, CLAUDE.md §5: formatowanie w widoku)
    // ⚠ **Dzielnik to 480 i nie ma już parametru** (rozstrzygnięcie z 22.09.2026, zlecenie
    // `kalendarz-zespolu-domyslny-wybor` §3.1). Ten blok sprawdzał wcześniej dzielenie przez
    // `normaUrlopowaMin` kalendarza — z asercją „960 min przy normie 720 → 1 dzień 4 godz.".
    // Reguła się zmieniła, bo dwie różne liczby dni na dwóch naszych ekranach (pulpit pracownika
    // dzielił przez 480, karta kadrowej przez normę) to stan, w którym nie da się rozstrzygnąć,
    // która wersja jest ważna. Pytanie o dniówkę 12 h stoi w `docs/audyt.md` jako pytanie
    // do autora connectora; gdy przyjdzie odpowiedź, wraca tu asercja o normie — świadomie,
    // a nie przez przywrócenie parametru „na wszelki wypadek".
    $check('1920 min → 4 dni', WorkTime::format(1920) === '4 dni');
    $check('1200 min → 2 dni 4 godz.', WorkTime::format(1200) === '2 dni 4 godz.');
    $check('960 min → 2 dni (dzielnik 480 także przy dniówce 12 h)', WorkTime::format(960) === '2 dni');
    $check('90 min → 1 godz. 30 min', WorkTime::format(90) === '1 godz. 30 min');
    $check('wartość ujemna zachowuje znak', WorkTime::format(-480) === '-1 dzień');
    $check('brak wartości → myślnik', WorkTime::format(null) === '—');
    $check('zero czyta się jak dni, nie jak minuty', WorkTime::format(0) === '0 dni');
    $check('stała dzielnika to 480', WorkTime::MINUT_W_DNIU === 480);
    $check('składniki i napis z tej samej arytmetyki',
        WorkTime::parts(1200) === ['ujemne' => false, 'dni' => 2, 'godziny' => 4, 'minuty' => 0]);
    $check('norma dnia w godzinach', WorkTime::hours(480) === '8 godz.' && WorkTime::hours(455) === '7 godz. 35 min');
    $check('title zawsze w minutach', WorkTime::minutes(1920) === '1920 min' && WorkTime::minutes(null) === 'brak wartości');

    // --- błędy przejściowe bazy → 503
    $pdo = static function (string $state, int $code, string $msg): PDOException {
        $e = new PDOException($msg);
        $e->errorInfo = [$state, $code, $msg];

        return $e;
    };
    $check('1205 lock wait → przejściowy', ApiKernel::isTransient($pdo('HY000', 1205, 'Lock wait timeout exceeded')));
    $check('1213 deadlock → przejściowy', ApiKernel::isTransient($pdo('40001', 1213, 'Deadlock found')));
    $check('2002 brak połączenia → przejściowy', ApiKernel::isTransient(new PDOException('SQLSTATE[HY000] [2002] Connection refused')));
    $check('1064 błąd składni → NIE przejściowy', !ApiKernel::isTransient($pdo('42000', 1064, 'syntax')));
    $check('zwykły wyjątek → NIE przejściowy', !ApiKernel::isTransient(new RuntimeException('x')));

    echo $failures === 0 ? "\nWszystko przeszło.\n" : "\nNiezaliczone: {$failures}\n";
    exit($failures === 0 ? 0 : 1);
})();
