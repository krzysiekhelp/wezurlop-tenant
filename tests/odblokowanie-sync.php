<?php

declare(strict_types=1);

// Powrót do pracy przy pełnej licencji: automat NIE odblokowuje konta (zlecenie Z87,
// decyzja Krzysztofa z 24.10.2026).
//
// ⚠ Powód istnienia tego pliku: do 24.10.2026 synchronizacja zdejmowała blokadę bez pytania
// o limit, więc powrót pracownika mógł wprowadzić instalację ponad licencję. Reguła jest teraz
// ta sama, co przy odblokowaniu ręcznym — a test sprawdza OBIE strony (odmowa i przejście)
// oraz to, że dwie osoby w jednym przebiegu dają JEDEN alert z liczbą 2, nie dwa alerty.
//
// ⚠ Test idzie drogą produktu: wywołuje `RecordApplier::apply()` rekordem `zatrudnienie`
// z `aktualne: true`, czyli dokładnie tym, co przysyła connector po powrocie pracownika.
// Symulator connectora (`bin/sync-symulator.php`) tu nie wchodzi, bo **odtwarza bazę z seeda** —
// na bazie roboczej byłby narzędziem do skasowania danych kadrowych.
//
// ⚠ Pisze do bazy i podmienia plik licencji; jedno i drugie przywraca w `finally`.
// Dane fikcyjne (CLAUDE.md §18).
//
// Uruchomienie: php tests/odblokowanie-sync.php — kod wyjścia 0 = wszystko przeszło.

require dirname(__DIR__) . '/vendor/autoload.php';

use Wezurlop\Db\Connection;
use Wezurlop\Repository\AccountRepository;
use Wezurlop\Repository\PermissionRepository;
use Wezurlop\Repository\CompanyCalendarRepository;
use Wezurlop\Repository\SyncTaskRepository;
use Wezurlop\Service\Sync\RecordApplier;
use Wezurlop\Service\Sync\SyncService;
use Wezurlop\Service\Sync\SyncWindow;
use Wezurlop\Support\Config;
use Wezurlop\Support\Services;

const NAZWISKO = '__test_sync_odblok';

(static function (): void {
    $failures = 0;
    $check = static function (string $name, mixed $got, mixed $want) use (&$failures): void {
        $ok = $got === $want;
        echo ($ok ? 'OK    ' : 'BŁĄD  ') . $name . ' -> ' . json_encode($got, JSON_UNESCAPED_UNICODE)
            . ($ok ? '' : ' (oczekiwano ' . json_encode($want, JSON_UNESCAPED_UNICODE) . ')') . "\n";
        $failures += $ok ? 0 : 1;
    };

    // ── strona czysta: agregacja alertu (bez bazy) ──
    $check('alert: dwie osoby = jeden alert z liczbą 2',
        SyncService::alertOsob(['zdarzenia' => ['kontaBezLicencji' => 2]]), 2);
    $check('alert: zero odmów = brak alertu',
        SyncService::alertOsob(['zdarzenia' => ['kontaOdblokowane' => 5]]), 0);
    $check('alert: puste statystyki = brak alertu', SyncService::alertOsob([]), 0);
    // ⚠ `stats` to kolumna JSON, czyli DANE — kształt sprawdzamy typem, nie zakładamy liczby.
    $check('alert: śmieć w statystykach = brak alertu',
        SyncService::alertOsob(['zdarzenia' => ['kontaBezLicencji' => 'dużo']]), 0);

    Dotenv\Dotenv::createImmutable(dirname(__DIR__))->load();
    $env = $_ENV;
    if (($env['APP_ENV'] ?? '') !== 'dev') {
        fwrite(STDERR, "Test pisze do bazy — działa wyłącznie na APP_ENV=dev.\n");
        exit(2);
    }
    $korzen = dirname(__DIR__);
    $probkaLicencji = static function (string ...$args) use ($korzen): void {
        $out = null;
        $kod = 0;
        exec(escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg($korzen . '/dev/probka-licencji.php')
            . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1', $out, $kod);
        if ($kod !== 0) {
            fwrite(STDERR, "Próbka licencji nie wykonała się: " . implode("\n", (array) $out) . "\n");
            exit(2);
        }
    };

    $db = new Connection([
        'host' => (string) $env['DB_HOST'], 'port' => (int) $env['DB_PORT'],
        'name' => (string) $env['DB_NAME'], 'user' => (string) $env['DB_USER'],
        'password' => (string) $env['DB_PASS'],
    ]);
    $pdo = $db->pdo();
    $firma = $pdo->query(
        'SELECT c.id, c.nip FROM company c JOIN employment e ON e.company_id = c.id
          WHERE e.is_active = 1 GROUP BY c.id, c.nip ORDER BY COUNT(*) DESC LIMIT 1'
    )->fetch();
    if ($firma === false) {
        fwrite(STDERR, "Brak firmy z aktywnymi zatrudnieniami — testu nie da się ustawić.\n");
        exit(2);
    }
    $spolka = (int) $firma['id'];

    $sprzataj = static function () use ($pdo, $spolka): void {
        $osoby = $pdo->prepare('SELECT id FROM person WHERE last_name = :n');
        $osoby->execute(['n' => NAZWISKO]);
        foreach (array_map('intval', $osoby->fetchAll(PDO::FETCH_COLUMN)) as $id) {
            $konta = $pdo->prepare('SELECT id FROM account WHERE person_id = :p');
            $konta->execute(['p' => $id]);
            foreach (array_map('intval', $konta->fetchAll(PDO::FETCH_COLUMN)) as $konto) {
                foreach (['role_assignment', 'session', 'event_log', 'invitation'] as $tabela) {
                    $pdo->prepare(sprintf('DELETE FROM `%s` WHERE account_id = :k', $tabela))->execute(['k' => $konto]);
                }
                $pdo->prepare('DELETE FROM account WHERE id = :k')->execute(['k' => $konto]);
            }
            $pdo->prepare('DELETE FROM employment WHERE person_id = :p')->execute(['p' => $id]);
            $pdo->prepare('DELETE FROM person WHERE id = :p')->execute(['p' => $id]);
        }
        $pdo->prepare("DELETE FROM sync_record WHERE company_id = :c AND record_id LIKE 'TEST-ODBLOK-%'")
            ->execute(['c' => $spolka]);
        $pdo->prepare("DELETE FROM sync_run WHERE company_id = :c AND run_uuid LIKE 'test-odblok-%'")
            ->execute(['c' => $spolka]);
    };

    $sprzataj();
    $probkaLicencji('ustaw', 'aktywna');   // licencja z limitem 25
    try {
        $services = new Services(Config::fromArray(
            (require $korzen . '/config/config.php')($_ENV, $_SERVER, $korzen)
        ));
        // ⚠ Aplikator składamy tak samo, jak `Services::syncService()` — razem z `AccessService`,
        // bo właśnie to wstrzyknięcie jest nową częścią reguły (Z87).
        $applier = new RecordApplier(
            $services->syncRepository(),
            $services->syncDomain(),
            new SyncTaskRepository($db),
            new CompanyCalendarRepository($db),
            $services->events,
            $services->jobs,
            new SyncWindow(),
            $services->access,
        );
        $accounts = new AccountRepository($db);
        $rola = (int) $pdo->query("SELECT id FROM role WHERE code = 'employee'")->fetchColumn();

        // Przebieg synchronizacji — rekordy muszą się do czegoś odnosić.
        $pdo->prepare(
            "INSERT INTO sync_run (run_uuid, company_id, status, phase, stats, started_at, updated_at)
             VALUES (:u, :c, 'open', 'rekordy', '{}', UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        )->execute(['u' => 'test-odblok-' . bin2hex(random_bytes(4)), 'c' => $spolka]);
        $runId = (int) $pdo->lastInsertId();

        /** Osoba próbna: konto ZABLOKOWANE PRZEZ SYNCHRONIZACJĘ, z jednym dostępem do firmy. */
        $osobaProbna = static function (string $imie, string $ref) use ($pdo, $spolka, $rola, $runId): array {
            $pdo->prepare(
                'INSERT INTO person (first_name, last_name, outside_hr, created_at)
                 VALUES (:i, :n, 1, UTC_TIMESTAMP())'
            )->execute(['i' => $imie, 'n' => NAZWISKO]);
            $osoba = (int) $pdo->lastInsertId();
            // ⚠ Zatrudnienie NIEAKTYWNE: to jego powrót (`aktualne: true`) ma odblokować konto.
            // ⚠ `r2_employment_ref` ten sam, co `id` rekordu: bez niego `upsertEmployment()`
            // nie rozpozna wiersza i założy DRUGI etat, a sprawdzenie „czy wrócił" mierzyłoby
            // pierwszy z dwóch (zmierzone: wychodziło 0 przy poprawnym działaniu kodu).
            $pdo->prepare(
                'INSERT INTO employment (person_id, company_id, r2_employment_ref, fte_numerator,
                                         fte_denominator, fte, contract_employment, is_active, created_at)
                 VALUES (:o, :c, :ref, 1, 1, 1.0000, 1, 0, UTC_TIMESTAMP())'
            )->execute(['o' => $osoba, 'c' => $spolka, 'ref' => 'TEST-ODBLOK-E-' . $ref]);
            $pdo->prepare(
                "INSERT INTO account (person_id, email, status, status_before_block, blocked_reason,
                                      blocked_at, created_at)
                 VALUES (:o, :m, 'blocked', 'active', 'sync', UTC_TIMESTAMP(), UTC_TIMESTAMP())"
            )->execute(['o' => $osoba, 'm' => 'sync-odblok-' . $osoba . '@example.invalid']);
            $konto = (int) $pdo->lastInsertId();
            $pdo->prepare(
                'INSERT INTO role_assignment (account_id, role_id, company_id, granted_at)
                 VALUES (:k, :r, :c, UTC_TIMESTAMP())'
            )->execute(['k' => $konto, 'r' => $rola, 'c' => $spolka]);
            // Mapowanie pracownika z R2 na osobę — bez niego rekord zatrudnienia trafia w „oczekujące".
            $pdo->prepare(
                "INSERT INTO sync_record (company_id, zbior, record_id, checksum, state, local_id,
                                          payload, created_run_id, updated_run_id, updated_at)
                 VALUES (:c, 'pracownik', :rid, 'sha256:0', 'active', :lid, '{}', :run1, :run2, UTC_TIMESTAMP())"
            // ⚠ Dwa osobne placeholdery na tę samą wartość: PDO bez emulacji nie pozwala użyć
            // tej samej nazwy dwa razy (HY093) — zmierzone w tym pliku.
            )->execute(['c' => $spolka, 'rid' => $ref, 'lid' => $osoba, 'run1' => $runId, 'run2' => $runId]);

            return ['osoba' => $osoba, 'konto' => $konto, 'ref' => $ref];
        };

        $a = $osobaProbna('Wraca', 'TEST-ODBLOK-A');
        $b = $osobaProbna('WracaDruga', 'TEST-ODBLOK-B');

        // ⚠ Dopełniamy użycie DOKŁADNIE do limitu — oba konta próbne są zablokowane, więc się
        // nie liczą; wolne miejsca zajmują osoby z aktywnym zatrudnieniem i kontem czynnym.
        $wypelniacze = [];
        while ($accounts->countLicensedAccesses() < 25) {
            $pdo->prepare(
                'INSERT INTO person (first_name, last_name, outside_hr, created_at)
                 VALUES (:i, :n, 1, UTC_TIMESTAMP())'
            )->execute(['i' => 'Wypelniacz' . count($wypelniacze), 'n' => NAZWISKO]);
            $osoba = (int) $pdo->lastInsertId();
            $pdo->prepare(
                'INSERT INTO employment (person_id, company_id, fte_numerator, fte_denominator, fte,
                                         contract_employment, is_active, created_at)
                 VALUES (:o, :c, 1, 1, 1.0000, 1, 1, UTC_TIMESTAMP())'
            )->execute(['o' => $osoba, 'c' => $spolka]);
            $pdo->prepare(
                "INSERT INTO account (person_id, email, status, created_at)
                 VALUES (:o, :m, 'active', UTC_TIMESTAMP())"
            )->execute(['o' => $osoba, 'm' => 'sync-wypeln-' . $osoba . '@example.invalid']);
            $konto = (int) $pdo->lastInsertId();
            $pdo->prepare(
                'INSERT INTO role_assignment (account_id, role_id, company_id, granted_at)
                 VALUES (:k, :r, :c, UTC_TIMESTAMP())'
            )->execute(['k' => $konto, 'r' => $rola, 'c' => $spolka]);
            $wypelniacze[] = ['osoba' => $osoba, 'konto' => $konto];
        }
        $check('użycie dobite do limitu', $accounts->countLicensedAccesses(), 25);

        /** Rekord zatrudnienia „wróciłem do pracy", czyli `aktualne: true`. */
        $powrot = static fn (array $os, string $suma): array => [
            'id' => 'TEST-ODBLOK-E-' . $os['ref'], 'suma' => 'sha256:' . str_repeat($suma, 64),
            'pracownikId' => $os['ref'], 'etat' => 1.0, 'dataRozpoczecia' => '2024-01-01',
            'dataZakonczenia' => null, 'kalendarzId' => null, 'aktualne' => true,
        ];
        $stan = $pdo->prepare('SELECT status, blocked_reason FROM account WHERE id = :k');

        // ── strona ujemna: pełna licencja, dwie osoby wracają ──
        $applier->apply($spolka, (string) $firma['nip'], 'zatrudnienie', $powrot($a, 'a'), $runId);
        $applier->apply($spolka, (string) $firma['nip'], 'zatrudnienie', $powrot($b, 'b'), $runId);
        $stan->execute(['k' => $a['konto']]);
        $check('pierwsze konto zostało zablokowane', $stan->fetch(PDO::FETCH_NUM), ['blocked', 'sync']);
        $stan->execute(['k' => $b['konto']]);
        $check('drugie konto zostało zablokowane', $stan->fetch(PDO::FETCH_NUM), ['blocked', 'sync']);
        // ⚠ Zatrudnienie MUSI wrócić do stanu aktywnego — odmowa dotyczy konta, nie danych kadrowych.
        $etat = $pdo->prepare('SELECT is_active FROM employment WHERE r2_employment_ref = :ref');
        $etat->execute(['ref' => 'TEST-ODBLOK-E-' . $a['ref']]);
        $check('zatrudnienie wróciło jako aktywne', (int) $etat->fetchColumn(), 1);
        $wpisy = $pdo->prepare(
            "SELECT COUNT(*) FROM event_log WHERE event_code = 'account.auto_unblock_refused'
              AND subject_type = 'account' AND subject_id IN (:a, :b)"
        );
        $wpisy->execute(['a' => $a['konto'], 'b' => $b['konto']]);
        $check('dziennik ma wpis o odmowie dla obu kont', (int) $wpisy->fetchColumn(), 2);
        $liczniki = $applier->takeEvents();
        // ⚠ JEDEN licznik o wartości 2, nie dwa alerty: alert powstaje raz na przebieg.
        $check('licznik przebiegu zsumował obie odmowy', $liczniki['kontaBezLicencji'] ?? 0, 2);
        $check('alert z tego przebiegu dotyczy dwóch osób',
            SyncService::alertOsob(['zdarzenia' => $liczniki]), 2);

        // ── strona dodatnia: zwalniamy jedno miejsce i ta sama droga przechodzi ──
        $pdo->prepare('DELETE FROM role_assignment WHERE account_id = :k')
            ->execute(['k' => $wypelniacze[0]['konto']]);
        $check('użycie po zwolnieniu miejsca', $accounts->countLicensedAccesses(), 24);
        $applier->apply($spolka, (string) $firma['nip'], 'zatrudnienie', $powrot($a, 'c'), $runId);
        $stan->execute(['k' => $a['konto']]);
        $check('konto odblokowane po zwolnieniu licencji', $stan->fetch(PDO::FETCH_NUM), ['active', null]);
        $liczniki = $applier->takeEvents();
        $check('licznik odblokowanych wzrósł', $liczniki['kontaOdblokowane'] ?? 0, 1);
        $check('bez nowej odmowy w tym przebiegu', $liczniki['kontaBezLicencji'] ?? 0, 0);

        // ⚠ Drugie konto dalej czeka: automat NIE ponawia, a wolne miejsce zajęło pierwsze.
        $stan->execute(['k' => $b['konto']]);
        $check('drugie konto nadal czeka na człowieka', $stan->fetch(PDO::FETCH_NUM), ['blocked', 'sync']);
    } finally {
        $sprzataj();
        $probkaLicencji('przywroc');
    }

    if ($failures > 0) {
        echo "\nNIEPOWODZENIA: {$failures}\n";
        exit(1);
    }
    echo "\nWszystkie sprawdzenia przeszły.\n";
})();
