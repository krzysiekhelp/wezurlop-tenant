<?php

declare(strict_types=1);

// **Widoczność kalendarza — przypadki zależne od STANU BAZY** (zlecenie Z54, przypadki g, h, i,
// j, r, s z odbioru Z53). Resztę przypadków (a–f, k–q) sprawdza `tests/widocznosc.php` na czystych
// funkcjach, bez bazy — tu zostaje to, czego bez bazy udowodnić się nie da: odmowy zapisu,
// sprzątanie po usuniętej grupie, zablokowane konto, zwolnione zatrudnienie i stan po migracji.
//
// ⚠ **Ten test PISZE do bazy — i dlatego ma bezpiecznik.** Odmawia uruchomienia, gdy
// `APP_ENV` nie jest `dev`. Bezpiecznik nie jest formalnością: plik zakłada firmy, osoby, konta
// i zatrudnienia, blokuje konto i zwalnia zatrudnienia. Puszczony u klienta zrobiłby to na
// prawdziwych danych kadrowych.
//
// ⚠ **Test zakłada WŁASNE dane i nie dotyka ani jednego istniejącego wiersza** (poza odczytem
// w przypadku `i`). Baza robocza zawiera prawdziwe dane osobowe, a test, który „pożycza" z niej
// kogoś do zablokowania albo zwolnienia, prędzej czy później zostawi go w tym stanie — wystarczy
// jeden wyjątek w połowie przebiegu. Stąd dwie firmy próbne o nazwiskach zastępczych
// (`PRÓBNY`, `PRÓBNA`) i sprzątanie w `finally`, czyli także po błędzie.
//
// ⚠ **W pliku nie ma ani jednego identyfikatora z bazy roboczej** — wszystkie powstają w trakcie
// przebiegu. Wpisany na sztywno numer firmy albo etatu znaczyłby test, który na innej kopii bazy
// trafia w kogoś zupełnie innego.
//
// Uruchomienie: php tests/widocznosc-baza.php — kod wyjścia 0 = wszystko przeszło,
// 2 = odmowa uruchomienia (nie `dev`).

require dirname(__DIR__) . '/vendor/autoload.php';

use Wezurlop\Db\Connection;
use Wezurlop\Repository\CalendarAccessRepository;
use Wezurlop\Repository\EventLogRepository;
use Wezurlop\Repository\GroupRepository;
use Wezurlop\Repository\HierarchyRepository;
use Wezurlop\Repository\PracownikRepository;
use Wezurlop\Service\CalendarVisibility;
use Wezurlop\Service\WidocznoscService;
use Wezurlop\Service\ZakresWidocznosci as Z;
use Wezurlop\Service\ZasiegPodgladu;

(static function (): void {
    $root = dirname(__DIR__);
    (Dotenv\Dotenv::createImmutable($root))->safeLoad();
    $cfg = (require $root . '/config/config.php')($_ENV, $_SERVER, $root);

    if (($cfg['app_env'] ?? '') !== 'dev') {
        fwrite(STDERR, "ODMOWA: ten test pisze do bazy (zakłada firmy próbne, blokuje konto,\n"
            . "zwalnia zatrudnienia) i uruchamia się wyłącznie przy APP_ENV=dev.\n"
            . 'Widziane: APP_ENV=' . ($cfg['app_env'] ?? '—') . "\n");
        exit(2);
    }

    $failures = 0;
    $check = static function (string $opis, bool $ok, string $widziane = '') use (&$failures): void {
        echo ($ok ? 'OK    ' : 'BŁĄD  ') . $opis
            . ($ok || $widziane === '' ? '' : " — widziane: {$widziane}") . "\n";
        $failures += $ok ? 0 : 1;
    };

    $db = new Connection($cfg['db']);
    $pdo = $db->pdo();
    $reguly = new CalendarAccessRepository($db);
    $grupy = new GroupRepository($db);
    $events = new EventLogRepository($db);
    $service = new WidocznoscService($reguly, $grupy, $events);
    $widocznosc = new CalendarVisibility(
        $reguly,
        new ZasiegPodgladu(new HierarchyRepository($db), new PracownikRepository($db)),
        new PracownikRepository($db),
    );

    // Znacznik przebiegu w nazwach danych próbnych — żeby dwa równoległe przebiegi sobie nie weszły
    // w drogę i żeby po awarii dało się poznać, co zostało.
    $znacznik = 'Z54-' . bin2hex(random_bytes(4));
    $firmy = [];
    $osoby = [];
    $konta = [];
    $etaty = [];
    $grupaId = 0;
    $odZdarzenia = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM event_log')->fetchColumn();

    $wstaw = static function (string $sql, array $dane) use ($pdo): int {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($dane);

        return (int) $pdo->lastInsertId();
    };

    try {
        // ——— DANE PRÓBNE ———————————————————————————————————————————————————————
        // ⚠ NIP zaczyna się od zera, czyli od cyfry, której prawdziwy NIP mieć nie może —
        // kolizja z danymi roboczymi jest wtedy niemożliwa, a nie tylko mało prawdopodobna.
        foreach (['ALFA', 'BETA'] as $i => $nazwa) {
            $firmy[$nazwa] = $wstaw(
                'INSERT INTO company (name, nip, is_active) VALUES (:n, :nip, 1)',
                ['n' => "PRÓBNA {$nazwa} ({$znacznik})", 'nip' => '0' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT) . $i]
            );
        }
        // Cztery osoby próbne: widz, dwie osoby celu w ALFIE i jedna osoba w BECIE.
        foreach (['widz' => 'Jan', 'cel1' => 'Anna', 'cel2' => 'Piotr', 'obcy' => 'Ewa'] as $rola => $imie) {
            $osoby[$rola] = $wstaw(
                'INSERT INTO person (first_name, last_name, outside_hr) VALUES (:i, :n, 0)',
                ['i' => $imie, 'n' => 'PRÓBNY ' . strtoupper($rola)]
            );
        }
        $konta['widz'] = $wstaw(
            "INSERT INTO account (person_id, email, status) VALUES (:p, :e, 'active')",
            ['p' => $osoby['widz'], 'e' => strtolower($znacznik) . '-widz@example.invalid']
        );
        foreach (['widz' => 'ALFA', 'cel1' => 'ALFA', 'cel2' => 'ALFA', 'obcy' => 'BETA'] as $rola => $firma) {
            $etaty[$rola] = $wstaw(
                'INSERT INTO employment (person_id, company_id, is_active) VALUES (:p, :c, 1)',
                ['p' => $osoby[$rola], 'c' => $firmy[$firma]]
            );
        }
        $nowa = $grupy->utworz($firmy['ALFA'], 'Grupa próbna ' . $znacznik, $konta['widz']);
        $grupaId = is_array($nowa) ? (int) $nowa['id'] : 0;
        $obcaGrupa = $wstaw(
            'INSERT INTO company_group (company_id, name) VALUES (:c, :n)',
            ['c' => $firmy['BETA'], 'n' => 'Grupa próbna obca ' . $znacznik]
        );
        $check('dane próbne założone (2 firmy, 4 osoby, 1 konto, 4 etaty, 2 grupy)',
            $grupaId > 0 && count($etaty) === 4 && $obcaGrupa > 0);

        $ileRegul = static fn (): int => (int) $pdo->query('SELECT COUNT(*) FROM calendar_access_rule')->fetchColumn();

        // ——— j, s: ODMOWY ZAPISU ————————————————————————————————————————————————
        // ⚠ Liczymy reguły przed i po: odmowa, która zostawia wiersz w bazie, jest gorsza
        // niż brak odmowy, bo nikt jej nie szuka.
        $przedOdmowami = $ileRegul();
        $check('j: konto spoza firmy jako podmiot → odmowa „podmiot"',
            $service->zapisz(null, $firmy['BETA'], Z::KTO_KONTO, $konta['widz'], Z::CZYJE_FIRMA, [],
                Z::BEZ_SZCZEGOLOW, $konta['widz'], 'test') === WidocznoscService::POWOD_PODMIOT);
        $check('j: grupa z innej firmy jako podmiot → odmowa „podmiot"',
            $service->zapisz(null, $firmy['ALFA'], Z::KTO_GRUPA, $obcaGrupa, Z::CZYJE_FIRMA, [],
                Z::BEZ_SZCZEGOLOW, $konta['widz'], 'test') === WidocznoscService::POWOD_PODMIOT);
        $check('j: grupa z innej firmy jako cel → odmowa „cel"',
            $service->zapisz(null, $firmy['ALFA'], Z::KTO_KONTO, $konta['widz'], Z::CZYJE_GRUPY, [$obcaGrupa],
                Z::BEZ_SZCZEGOLOW, $konta['widz'], 'test') === WidocznoscService::POWOD_CEL);
        $check('s: zatrudnienie z innej firmy w celu „osoby" → odmowa „cel"',
            $service->zapisz(null, $firmy['ALFA'], Z::KTO_KONTO, $konta['widz'], Z::CZYJE_OSOBY, [$etaty['obcy']],
                Z::ZE_SZCZEGOLAMI, $konta['widz'], 'test') === WidocznoscService::POWOD_CEL);
        $check('s: własne zatrudnienie widza w celu „osoby" → odmowa „sam siebie"',
            $service->zapisz(null, $firmy['ALFA'], Z::KTO_KONTO, $konta['widz'], Z::CZYJE_OSOBY, [$etaty['widz']],
                Z::ZE_SZCZEGOLAMI, $konta['widz'], 'test') === WidocznoscService::POWOD_SAM_SIEBIE);
        $check('s: pusta lista osób → odmowa „cel"',
            $service->zapisz(null, $firmy['ALFA'], Z::KTO_KONTO, $konta['widz'], Z::CZYJE_OSOBY, [],
                Z::ZE_SZCZEGOLAMI, $konta['widz'], 'test') === WidocznoscService::POWOD_CEL);
        $check('j, s: po sześciu odmowach nie przybyła ani jedna reguła',
            $ileRegul() === $przedOdmowami, (string) ($ileRegul() - $przedOdmowami));

        // ——— g: KONTO ZABLOKOWANE ————————————————————————————————————————————————
        $kandydatem = static fn (): bool => in_array(
            $konta['widz'],
            array_column($reguly->kandydaciKonta($firmy['ALFA'], '', 500, 0), 'id'),
            true
        );
        $check('g: przed blokadą konto jest kandydatem na podmiot', $kandydatem());
        $pdo->prepare('UPDATE account SET blocked_at = NOW() WHERE id = :id')->execute(['id' => $konta['widz']]);
        $check('g: zablokowane konto znika z listy kandydatów', !$kandydatem());
        $check('g: zapis reguły dla zablokowanego konta → odmowa „podmiot"',
            $service->zapisz(null, $firmy['ALFA'], Z::KTO_KONTO, $konta['widz'], Z::CZYJE_FIRMA, [],
                Z::BEZ_SZCZEGOLOW, null, 'test') === WidocznoscService::POWOD_PODMIOT);
        $pdo->prepare('UPDATE account SET blocked_at = NULL WHERE id = :id')->execute(['id' => $konta['widz']]);
        $check('g: po zdjęciu blokady konto wraca na listę', $kandydatem());

        // ——— r: ZWOLNIENIE WSKAZANEJ OSOBY ——————————————————————————————————————
        $cele = [$etaty['cel1'], $etaty['cel2']];
        $powod = $service->zapisz(null, $firmy['ALFA'], Z::KTO_KONTO, $konta['widz'], Z::CZYJE_OSOBY,
            $cele, Z::ZE_SZCZEGOLAMI, $konta['widz'], 'test');
        $check('r: reguła na wskazane osoby zapisana', $powod === null, (string) $powod);
        $regulaId = (int) $pdo->query('SELECT MAX(id) FROM calendar_access_rule WHERE company_id = '
            . $firmy['ALFA'])->fetchColumn();
        $zakresy = $widocznosc->zakresy($konta['widz'], $firmy['ALFA'], $etaty['widz'], $cele);
        $check('r: obie wskazane osoby są widoczne ze szczegółami',
            $zakresy[$cele[0]] === Z::ZE_SZCZEGOLAMI && $zakresy[$cele[1]] === Z::ZE_SZCZEGOLAMI,
            json_encode($zakresy));

        $pdo->prepare('UPDATE employment SET is_active = 0 WHERE id = :id')->execute(['id' => $cele[0]]);
        $poZwolnieniu = $widocznosc->zakresy($konta['widz'], $firmy['ALFA'], $etaty['widz'], $cele);
        // ⚠ Sedno przypadku r: dostęp ma zniknąć w PUNKCIE EGZEKWOWANIA, a nie dlatego, że
        // wywołujący nie zapytał o zwolnionego. Zmierzone 20.10.2026 przed poprawką: siatka
        // zespołu nie pokazywała zwolnionych, więc wyglądało to na działające, a ta sama funkcja
        // zapytana wprost oddawała `ze_szczegolami`.
        $check('r: zwolniona osoba przestaje być widoczna natychmiast',
            $poZwolnieniu[$cele[0]] === Z::BRAK, $poZwolnieniu[$cele[0]]);
        $check('r: druga, czynna osoba jest widoczna dalej',
            $poZwolnieniu[$cele[1]] === Z::ZE_SZCZEGOLAMI, $poZwolnieniu[$cele[1]]);
        $check('r: reguła zostaje, z obiema osobami',
            count($reguly->regula($regulaId, $firmy['ALFA'])['grupy'] ?? []) === 2);
        $opis = $reguly->osobyRegul($firmy['ALFA'], $cele);
        $check('r: wiersz reguły oznacza zwolnioną osobę jako nieczynną',
            ($opis[$cele[0]]['czynne'] ?? true) === false && ($opis[$cele[1]]['czynne'] ?? false) === true);
        $check('r: okno zmiany reguły nie pokazuje zwolnionej osoby',
            !in_array($cele[0], array_map(
                static fn (array $o): int => $o['id'],
                $reguly->osobyFirmy($firmy['ALFA'], '', 500, 0, [$etaty['widz']])
            ), true));

        $pdo->prepare('UPDATE employment SET is_active = 0 WHERE id = :id')->execute(['id' => $cele[1]]);
        $opisOboje = $reguly->osobyRegul($firmy['ALFA'], $cele);
        $czynnych = count(array_filter($opisOboje, static fn (array $o): bool => $o['czynne']));
        $check('r: po zwolnieniu obu w regule nie ma czynnych osób (plakietka „nieaktywna")',
            $czynnych === 0, (string) $czynnych);
        $check('r: reguła nadal istnieje — kasuje ją administrator, nie zwolnienie',
            $reguly->regula($regulaId, $firmy['ALFA']) !== null);
        $pdo->exec('UPDATE employment SET is_active = 1 WHERE id IN (' . implode(',', $cele) . ')');

        // ——— h: USUNIĘTA GRUPA PO OBU STRONACH REGUŁY ———————————————————————————
        $pdo->exec('DELETE FROM calendar_access_rule WHERE company_id = ' . $firmy['ALFA']);
        $r1 = $service->zapisz(null, $firmy['ALFA'], Z::KTO_GRUPA, $grupaId, Z::CZYJE_FIRMA, [],
            Z::BEZ_SZCZEGOLOW, $konta['widz'], 'test');
        $r2 = $service->zapisz(null, $firmy['ALFA'], Z::KTO_KONTO, $konta['widz'], Z::CZYJE_GRUPY, [$grupaId],
            Z::BEZ_SZCZEGOLOW, $konta['widz'], 'test');
        $check('h: obie reguły z grupą próbną zapisane', $r1 === null && $r2 === null,
            (string) $r1 . '/' . (string) $r2);
        $zdarzenPrzed = $events->countRecent('calendar_access.auto_changed', 1);
        $service->poUsunieciuGrupy($firmy['ALFA'], $grupaId, $konta['widz'], 'test');
        $grupy->usun($grupaId, $firmy['ALFA']);
        $check('h: reguła z grupą jako PODMIOTEM zniknęła',
            (int) $pdo->query('SELECT COUNT(*) FROM calendar_access_rule WHERE subject_group_id = '
                . $grupaId)->fetchColumn() === 0);
        $check('h: grupa jako CEL zniknęła, a reguła bez ani jednej grupy razem z nią',
            (int) $pdo->query('SELECT COUNT(*) FROM calendar_access_rule_group WHERE group_id = '
                . $grupaId)->fetchColumn() === 0);
        $check('h: usunięcie grupy zapisało się w dzienniku',
            $events->countRecent('calendar_access.auto_changed', 1) === $zdarzenPrzed + 1);
        $grupaId = (int) $pdo->query('SELECT COUNT(*) FROM company_group WHERE id = ' . $grupaId)->fetchColumn() === 0
            ? 0
            : $grupaId;
        $check('h: grupa próbna usunięta', $grupaId === 0);

        // ——— i: STAN PO MIGRACJI STARYCH POZIOMÓW ————————————————————————————————
        // ⚠ Jedyny przypadek czytający dane robocze — i **wyłącznie czytający**. Migracja jest
        // zdarzeniem jednorazowym, więc nie da się jej powtórzyć na danych próbnych; sprawdzamy
        // to, co po niej ma być prawdą. Na czystej instalacji (zero starych nadań) wszystkie trzy
        // sprawdzenia przechodzą z definicji i to jest poprawne: nie było czego migrować.
        $role = $pdo->query("SELECT code, requires_2fa FROM role
             WHERE code IN ('calendar_viewer', 'calendar_viewer_full')")->fetchAll();
        $check('i: obie stare role zostają w tabeli (migracja ma się dać cofnąć)', count($role) === 2,
            (string) count($role));
        $pelna = array_values(array_filter($role, static fn (array $r): bool => $r['code'] === 'calendar_viewer_full'));
        $check('i: `calendar_viewer_full` nie wymaga już 2FA — nie jest uprawnieniem administracyjnym',
            $pelna !== [] && (int) $pelna[0]['requires_2fa'] === 0);
        // ⚠ Nadanie **na konkretną firmę** migracja przenosiła zawsze, jeden do jednego.
        // To jest niezmiennik: taka reguła ma istnieć, inaczej ktoś stracił dostęp po cichu.
        $bezReguly = (int) $pdo->query("SELECT COUNT(*) FROM role_assignment ra
               JOIN role r ON r.id = ra.role_id
              WHERE r.code IN ('calendar_viewer', 'calendar_viewer_full')
                AND ra.company_id >= 1
                AND NOT EXISTS (SELECT 1 FROM calendar_access_rule car
                                 WHERE car.subject_kind = 'account'
                                   AND car.subject_account_id = ra.account_id
                                   AND car.company_id = ra.company_id)")->fetchColumn();
        $check('i: nadanie starej roli na konkretną firmę ma swoją regułę', $bezReguly === 0,
            (string) $bezReguly);
        // ⚠ **Ta liczba jest ZNALEZISKIEM, nie kryterium** (20.10.2026). Nadanie obejmujące cały
        // tenant (`company_id = 0`) rozkładało się tylko na firmy, w których konto ma rolę
        // pracownika — więc konto, które jej nigdzie nie ma (typowo: administrator tenanta),
        // **nie dostało ani jednej reguły i po migracji nie widzi już nic**. Kierunek jest
        // bezpieczny (mniej dostępu, nie więcej), dlatego nie jest to błąd testu; ale jest to
        // utrata dostępu, której nikt nie rozstrzygnął — czy administrator tenanta ma widzieć
        // kalendarze wszystkich firm, jest decyzją o danych o zdrowiu, a nie o migracji.
        // Test tę liczbę WYPISUJE, żeby nie zniknęła z pola widzenia na nowej kopii bazy.
        $porzucone = (int) $pdo->query("SELECT COUNT(*) FROM role_assignment ra
               JOIN role r ON r.id = ra.role_id
              WHERE r.code IN ('calendar_viewer', 'calendar_viewer_full')
                AND ra.company_id = 0
                AND NOT EXISTS (SELECT 1 FROM calendar_access_rule car
                                 WHERE car.subject_kind = 'account'
                                   AND car.subject_account_id = ra.account_id)")->fetchColumn();
        echo "      (znalezisko) nadań na cały tenant, które nie dały ani jednej reguły: {$porzucone}\n";
    } finally {
        // ——— SPRZĄTANIE ————————————————————————————————————————————————————————
        // ⚠ W `finally`, więc także po wyjątku w połowie przebiegu. Kolejność od najgłębszej
        // zależności: wiersze celu, reguły, przypisania, zatrudnienia, konta, osoby, grupy, firmy.
        // ⚠ Kasujemy **po identyfikatorach z tego przebiegu**, nigdy po nazwie ani po wzorcu:
        // wzorzec „PRÓBNY%" trafiłby kiedyś w prawdziwe nazwisko.
        $idsFirm = array_values($firmy);
        if ($idsFirm !== []) {
            $lista = implode(',', array_map('intval', $idsFirm));
            foreach ($pdo->query('SELECT id FROM calendar_access_rule WHERE company_id IN (' . $lista . ')') as $r) {
                $pdo->exec('DELETE FROM calendar_access_rule_person WHERE rule_id = ' . (int) $r['id']);
                $pdo->exec('DELETE FROM calendar_access_rule_group WHERE rule_id = ' . (int) $r['id']);
            }
            $pdo->exec('DELETE FROM calendar_access_rule WHERE company_id IN (' . $lista . ')');
            $pdo->exec('DELETE FROM employment_group WHERE employment_id IN (SELECT id FROM employment
                WHERE company_id IN (' . $lista . '))');
            $pdo->exec('DELETE FROM company_group WHERE company_id IN (' . $lista . ')');
            $pdo->exec('DELETE FROM role_assignment WHERE company_id IN (' . $lista . ')');
            $pdo->exec('DELETE FROM employment WHERE company_id IN (' . $lista . ')');
        }
        if ($konta !== []) {
            $pdo->exec('DELETE FROM role_assignment WHERE account_id IN (' . implode(',', array_map('intval', $konta)) . ')');
            $pdo->exec('DELETE FROM account WHERE id IN (' . implode(',', array_map('intval', $konta)) . ')');
        }
        if ($osoby !== []) {
            $pdo->exec('DELETE FROM person WHERE id IN (' . implode(',', array_map('intval', $osoby)) . ')');
        }
        if ($idsFirm !== []) {
            $pdo->exec('DELETE FROM company WHERE id IN (' . implode(',', array_map('intval', $idsFirm)) . ')');
            // Dziennik: wyłącznie wpisy TEGO przebiegu i tylko o firmach próbnych.
            $pdo->exec('DELETE FROM event_log WHERE id > ' . $odZdarzenia
                . ' AND company_id IN (' . implode(',', array_map('intval', $idsFirm)) . ')');
        }
        $zostalo = $idsFirm === [] ? 0 : (int) $pdo->query('SELECT
              (SELECT COUNT(*) FROM company WHERE id IN (' . implode(',', array_map('intval', $idsFirm)) . '))
            + (SELECT COUNT(*) FROM employment WHERE company_id IN (' . implode(',', array_map('intval', $idsFirm)) . '))
            + (SELECT COUNT(*) FROM person WHERE id IN (' . implode(',', array_map('intval', $osoby ?: [0])) . '))
            + (SELECT COUNT(*) FROM account WHERE id IN (' . implode(',', array_map('intval', $konta ?: [0])) . '))')->fetchColumn();
        $check('sprzątanie: po przebiegu nie został ani jeden wiersz próbny', $zostalo === 0, (string) $zostalo);
    }

    echo $failures === 0 ? "Wszystkie sprawdzenia przeszły.\n" : "Nieudane: {$failures}\n";
    exit($failures === 0 ? 0 : 1);
})();
