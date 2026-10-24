<?php

declare(strict_types=1);

// Odmowa odblokowania konta przy pełnej licencji (decyzja Krzysztofa z 24.10.2026, zlecenie Z85).
//
// ⚠ Powód istnienia tego pliku: do 24.10.2026 odblokowanie NIE pytało o limit wcale, więc konto
// wracało z dostępami i użycie przekraczało licencję. Reguła jest teraz ta sama, co przy nadaniu
// dostępu — a jej sedno siedzi w KOLEJNOŚCI: pytanie o wolne miejsce i zapis muszą być jedną
// transakcją. Test sprawdza obie strony: odmowę przy pełnym limicie i przejście, gdy miejsce się
// zwolni.
//
// ⚠ Test pisze do bazy roboczej i podmienia PLIK LICENCJI (bez limitu nie ma czego przekroczyć),
// więc jedno i drugie przywraca w `finally` — razem z osobami próbnymi. Dane fikcyjne (CLAUDE.md §18).
//
// Uruchomienie: php tests/odblokowanie-licencja.php — kod wyjścia 0 = wszystko przeszło.

require dirname(__DIR__) . '/vendor/autoload.php';

use Wezurlop\Db\Connection;
use Wezurlop\Repository\AccountRepository;
use Wezurlop\Repository\PermissionRepository;
use Wezurlop\Service\AccountService;
use Wezurlop\Support\Config;
use Wezurlop\Support\Services;

const NAZWISKO = '__test_odblok';

(static function (): void {
    $failures = 0;
    $check = static function (string $name, mixed $got, mixed $want) use (&$failures): void {
        $ok = $got === $want;
        echo ($ok ? 'OK    ' : 'BŁĄD  ') . $name . ' -> ' . json_encode($got, JSON_UNESCAPED_UNICODE)
            . ($ok ? '' : ' (oczekiwano ' . json_encode($want, JSON_UNESCAPED_UNICODE) . ')') . "\n";
        $failures += $ok ? 0 : 1;
    };

    Dotenv\Dotenv::createImmutable(dirname(__DIR__))->load();
    $env = $_ENV;
    if (($env['APP_ENV'] ?? '') !== 'dev') {
        fwrite(STDERR, "Test pisze do bazy — działa wyłącznie na APP_ENV=dev.\n");
        exit(2);
    }
    $korzen = dirname(__DIR__);
    $probka = static function (string ...$args) use ($korzen): void {
        $wynik = null;
        $kod = 0;
        exec(escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg($korzen . '/dev/probka-licencji.php')
            . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1', $wynik, $kod);
        if ($kod !== 0) {
            fwrite(STDERR, "Próbka licencji nie wykonała się: " . implode("\n", (array) $wynik) . "\n");
            exit(2);
        }
    };

    $db = new Connection([
        'host' => (string) $env['DB_HOST'], 'port' => (int) $env['DB_PORT'],
        'name' => (string) $env['DB_NAME'], 'user' => (string) $env['DB_USER'],
        'password' => (string) $env['DB_PASS'],
    ]);
    $pdo = $db->pdo();
    $spolka = (int) $pdo->query(
        'SELECT company_id FROM employment WHERE is_active = 1 GROUP BY company_id
          ORDER BY COUNT(*) DESC LIMIT 1'
    )->fetchColumn();
    if ($spolka === 0) {
        fwrite(STDERR, "Brak firmy z aktywnymi zatrudnieniami — testu nie da się ustawić.\n");
        exit(2);
    }

    $sprzataj = static function () use ($pdo): void {
        $osoby = $pdo->prepare('SELECT id FROM person WHERE last_name = :n');
        $osoby->execute(['n' => NAZWISKO]);
        foreach (array_map('intval', $osoby->fetchAll(PDO::FETCH_COLUMN)) as $id) {
            $konta = $pdo->prepare('SELECT id FROM account WHERE person_id = :p');
            $konta->execute(['p' => $id]);
            foreach (array_map('intval', $konta->fetchAll(PDO::FETCH_COLUMN)) as $konto) {
                foreach (['role_assignment', 'session', 'event_log', 'invitation'] as $tabela) {
                    $pdo->prepare(sprintf('DELETE FROM `%s` WHERE account_id = :k', $tabela))
                        ->execute(['k' => $konto]);
                }
                $pdo->prepare('DELETE FROM account WHERE id = :k')->execute(['k' => $konto]);
            }
            $pdo->prepare('DELETE FROM employment WHERE person_id = :p')->execute(['p' => $id]);
            $pdo->prepare('DELETE FROM person WHERE id = :p')->execute(['p' => $id]);
        }
    };

    $sprzataj();
    $probka('ustaw', 'aktywna');   // licencja z limitem 25
    try {
        // ⚠ Pełny zestaw usług, nie ręcznie zlepiony `AccountService`: testujemy ścieżkę
        // produkcyjną razem z wstrzyknięciem `AccessService` — bo właśnie to wstrzyknięcie
        // jest nową częścią reguły.
        $services = new Services(Config::fromArray(
            (require $korzen . '/config/config.php')($_ENV, $_SERVER, $korzen)
        ));
        $konta = $services->konta;
        $accounts = new AccountRepository($db);
        $admin = (int) $pdo->query("SELECT id FROM account WHERE status = 'active' ORDER BY id LIMIT 1")
            ->fetchColumn();

        // Osoba z kontem i JEDNYM dostępem do firmy z aktywnym zatrudnieniem.
        $nowa = static function (string $imie, string $stan) use ($pdo, $spolka): array {
            $pdo->prepare(
                'INSERT INTO person (first_name, last_name, outside_hr, created_at)
                 VALUES (:i, :n, 1, UTC_TIMESTAMP())'
            )->execute(['i' => $imie, 'n' => NAZWISKO]);
            $osoba = (int) $pdo->lastInsertId();
            $pdo->prepare(
                'INSERT INTO employment (person_id, company_id, fte_numerator, fte_denominator, fte,
                                         contract_employment, is_active, created_at)
                 VALUES (:o, :c, 1, 1, 1.0000, 1, 1, UTC_TIMESTAMP())'
            )->execute(['o' => $osoba, 'c' => $spolka]);
            $pdo->prepare(
                'INSERT INTO account (person_id, email, status, created_at)
                 VALUES (:o, :m, :s, UTC_TIMESTAMP())'
            )->execute(['o' => $osoba, 'm' => 'odblok-' . $osoba . '@example.invalid', 's' => $stan]);
            $konto = (int) $pdo->lastInsertId();
            // ⚠ Rola przez `role_id` z tabeli `role`, nie napisem: `role_assignment` trzyma
            // identyfikator, więc wpisany tu napis wywalił test na nieznanej kolumnie.
            $pdo->prepare(
                'INSERT INTO role_assignment (account_id, role_id, company_id, granted_at)
                 VALUES (:k, (SELECT id FROM role WHERE code = :r), :c, UTC_TIMESTAMP())'
            )->execute(['k' => $konto, 'r' => PermissionRepository::ROLA_PRACOWNIK, 'c' => $spolka]);

            return ['osoba' => $osoba, 'konto' => $konto];
        };

        // Konto do odblokowania: zablokowane ręcznie, z jednym dostępem (czyli jedną licencją).
        $cel = $nowa('Cel', AccountRepository::STAN_ZABLOKOWANE);
        $pdo->prepare(
            "UPDATE account SET status_before_block = 'active', blocked_reason = 'manual',
                blocked_at = UTC_TIMESTAMP() WHERE id = :k"
        )->execute(['k' => $cel['konto']]);

        $check('cel zajmuje jedna licencje', $accounts->licensedAccessesOfAccount($cel['konto']), 1);

        // ⚠ Dopełniamy użycie DOKŁADNIE do limitu: po odblokowaniu celu byłoby 26 z 25.
        $wypelniacze = [];
        $limit = 25;
        while ($accounts->countLicensedAccesses() < $limit) {
            $wypelniacze[] = $nowa('Wypelniacz' . count($wypelniacze), AccountRepository::STAN_AKTYWNE);
        }
        $check('uzycie dobite do limitu', $accounts->countLicensedAccesses(), $limit);

        // ── strona ujemna: odmowa ──
        $powod = $konta->unblock($cel['konto'], $admin, null, 'test');
        $check('odmowa z powodu licencji', $powod, AccountService::POWOD_BRAK_LICENCJI);
        $stan = $pdo->prepare('SELECT status FROM account WHERE id = :k');
        $stan->execute(['k' => $cel['konto']]);
        // ⚠ Odmowa MUSI zostawić konto zablokowane: sprawdzenie i zapis są w jednej transakcji,
        // więc nie ma stanu „odmówiono, ale odblokowano".
        $check('konto zostalo zablokowane', (string) $stan->fetchColumn(), AccountRepository::STAN_ZABLOKOWANE);

        // ── strona dodatnia: po zwolnieniu miejsca przechodzi ──
        $pdo->prepare('DELETE FROM role_assignment WHERE account_id = :k')
            ->execute(['k' => $wypelniacze[0]['konto']]);
        $check('uzycie po zwolnieniu miejsca', $accounts->countLicensedAccesses(), $limit - 1);
        $powod = $konta->unblock($cel['konto'], $admin, null, 'test');
        $check('odblokowanie przeszlo', $powod, null);
        $stan->execute(['k' => $cel['konto']]);
        $check('konto wrocilo do stanu sprzed blokady', (string) $stan->fetchColumn(),
            AccountRepository::STAN_AKTYWNE);

        // ⚠ Konto BEZ dostępów odblokowuje się nawet przy pełnym limicie: nie ma czego zajmować.
        $bezDostepu = $nowa('BezDostepu', AccountRepository::STAN_ZABLOKOWANE);
        $pdo->prepare('DELETE FROM role_assignment WHERE account_id = :k')
            ->execute(['k' => $bezDostepu['konto']]);
        $pdo->prepare(
            "UPDATE account SET status_before_block = 'active', blocked_reason = 'manual',
                blocked_at = UTC_TIMESTAMP() WHERE id = :k"
        )->execute(['k' => $bezDostepu['konto']]);
        $check('konto bez dostepow nie zajmuje licencji',
            $accounts->licensedAccessesOfAccount($bezDostepu['konto']), 0);
        $check('odblokowanie konta bez dostepow przechodzi',
            $konta->unblock($bezDostepu['konto'], $admin, null, 'test'), null);
    } finally {
        $sprzataj();
        $probka('przywroc');
    }

    if ($failures > 0) {
        echo "\nNIEPOWODZENIA: {$failures}\n";
        exit(1);
    }
    echo "\nWszystkie sprawdzenia przeszły.\n";
})();
