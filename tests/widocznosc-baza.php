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
use Wezurlop\Repository\PermissionRepository;
use Wezurlop\Repository\PracownikRepository;
use Wezurlop\Repository\SyncDomainRepository;
use Wezurlop\Service\CalendarVisibility;
use Wezurlop\Service\GrupyService;
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
    // ⚠ Te dwie zależności są tu po to, żeby test IDZIE TĄ SAMĄ DROGĄ co produkt (Z80 cz. B):
    // wygaszenie etatu przez repozytorium domenowe synchronizacji i zmiany składu grupy przez
    // serwis grup — a nie własnym `UPDATE`-em, który ominąłby to, co przy okazji robi produkt.
    $domenaSync = new SyncDomainRepository($db);
    $grupySerwis = new GrupyService($grupy, $events, $service);
    $uprawnienia = new PermissionRepository($db);
    $widocznosc = new CalendarVisibility(
        $reguly,
        new ZasiegPodgladu(new HierarchyRepository($db), new PracownikRepository($db)),
        new PracownikRepository($db),
        $uprawnienia,
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
        // ⚠ Sześć osób próbnych: widz, dwie osoby celu w ALFIE, jedna w BECIE oraz **dwie bez
        // żadnego zatrudnienia** — administrator i konto z nadaniem na cały tenant. Te dwie muszą
        // być bez etatu, inaczej trafiłyby na listę kandydatów przez zatrudnienie i test
        // sprawdzałby zupełnie inną gałąź zapytania, niż zamierzał (zmierzone: pierwsza wersja
        // dawała `zrodlo = etat` w firmie BETA).
        foreach (['widz' => 'Jan', 'cel1' => 'Anna', 'cel2' => 'Piotr', 'obcy' => 'Ewa',
                  'admin' => 'Marek', 'tenantowy' => 'Zofia'] as $rola => $imie) {
            $osoby[$rola] = $wstaw(
                'INSERT INTO person (first_name, last_name, outside_hr) VALUES (:i, :n, 0)',
                ['i' => $imie, 'n' => 'PRÓBNY ' . strtoupper($rola)]
            );
        }
        $konta['widz'] = $wstaw(
            "INSERT INTO account (person_id, email, status) VALUES (:p, :e, 'active')",
            ['p' => $osoby['widz'], 'e' => strtolower($znacznik) . '-widz@example.invalid']
        );
        // ⚠ Dwa dodatkowe konta próbne (zlecenie Z55): jedno z uprawnieniem administratora
        // i jedno z dawnym nadaniem obejmującym cały tenant, ale BEZ uprawnień administratora.
        // Role przypisujemy po KODZIE z zapytania, nie po numerze — numer bywa inny w każdej bazie.
        $konta['admin'] = $wstaw(
            "INSERT INTO account (person_id, email, status) VALUES (:p, :e, 'active')",
            ['p' => $osoby['admin'], 'e' => strtolower($znacznik) . '-admin@example.invalid']
        );
        $konta['tenantowy'] = $wstaw(
            "INSERT INTO account (person_id, email, status) VALUES (:p, :e, 'active')",
            ['p' => $osoby['tenantowy'], 'e' => strtolower($znacznik) . '-tenant@example.invalid']
        );
        $rola = static function (string $kod) use ($pdo): int {
            $stmt = $pdo->prepare('SELECT id FROM role WHERE code = :kod');
            $stmt->execute(['kod' => $kod]);

            return (int) $stmt->fetchColumn();
        };
        $wstaw('INSERT INTO role_assignment (account_id, role_id, company_id) VALUES (:a, :r, 0)',
            ['a' => $konta['admin'], 'r' => $rola('tenant_admin')]);
        $wstaw('INSERT INTO role_assignment (account_id, role_id, company_id) VALUES (:a, :r, 0)',
            ['a' => $konta['tenantowy'], 'r' => $rola('calendar_viewer')]);
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
        $check('dane próbne założone (2 firmy, 6 osób, 3 konta, 4 etaty, 2 grupy)',
            $grupaId > 0 && count($etaty) === 4 && count($konta) === 3 && $obcaGrupa > 0);

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
        // ⚠ **Z80 A6: „cała firma" RAZEM ze wskazanymi osobami.** To jedyna kombinacja dwóch
        // rodzajów celu, która dociera do serwisu — przy grupach cel zapisuje się jako `groups`
        // i znacznik całej firmy zostaje w żądaniu (odmawia tam `powodStanuOkna()`).
        // ⚠ Do 23.10.2026 serwis tego NIE odrzucał, tylko wygrywał zbiór szerszy; odmowa jest
        // bezpieczna dlatego, że od Z80 A4 pola osób mają wtedy `disabled` i nie jadą w żądaniu.
        $check('Z80: cel „cała firma" ze wskazanymi osobami → odmowa „cel"',
            $service->zapisz(null, $firmy['ALFA'], Z::KTO_KONTO, $konta['widz'], Z::CZYJE_FIRMA,
                [$etaty['cel1']], Z::ZE_SZCZEGOLAMI, $konta['widz'], 'test')
                === WidocznoscService::POWOD_CEL);
        // ⚠ **Z81: pusty zakres odrzuca SERWIS, nie okno.** Od 24.10.2026 krok 3 startuje pusty
        // i serwer nie podstawia już zakresu domyślnego, więc żądanie bez zakresu jest teraz
        // stanem zwykłym, a nie podmienionym polem. Sprawdzamy tę samą drogę, którą idzie zapis
        // z okna — bo wyszarzony przycisk nie jest bramką: żądanie da się złożyć z ręki.
        $check('Z81: zapis bez zakresu → odmowa „zakres"',
            $service->zapisz(null, $firmy['ALFA'], Z::KTO_KONTO, $konta['widz'], Z::CZYJE_FIRMA, [],
                '', $konta['widz'], 'test') === WidocznoscService::POWOD_ZAKRES);
        // ⚠ „brak" jest zakresem w rozumieniu osi widoczności, ale NIE JEST zakresem, który
        // wolno zapisać w regule (reguła dająca „nic" to reguła bez sensu). Odmowa ma mówić
        // o zakresie, a nie o celu — inaczej ekran odsyła do kroku 2.
        $check('Z81: zapis z zakresem „brak" → odmowa „zakres"',
            $service->zapisz(null, $firmy['ALFA'], Z::KTO_KONTO, $konta['widz'], Z::CZYJE_FIRMA, [],
                Z::BRAK, $konta['widz'], 'test') === WidocznoscService::POWOD_ZAKRES);
        // ⚠ Także w regule STAŁEJ — ma dokładnie jeden krok, więc to jedyna odmowa, jaka
        // jej dotyczy, i musi działać tą samą drogą.
        $check('Z81: zmiana reguły stałej bez zakresu → odmowa „zakres"',
            $service->zapisz(null, $firmy['ALFA'], Z::KTO_PRZELOZENI, 0, Z::CZYJE_PODWLADNI, [],
                '', $konta['widz'], 'test') === WidocznoscService::POWOD_ZAKRES);
        $check('j, s, Z81: po dziesięciu odmowach nie przybyła ani jedna reguła',
            $ileRegul() === $przedOdmowami, (string) ($ileRegul() - $przedOdmowami));
        // ⚠ Druga strona tego samego twierdzenia — i dlatego stoi PO liczniku wyżej: ten zapis
        // się udaje, czyli dokłada regułę. Bez niego odmowa przechodziłaby także wtedy, gdyby
        // serwis odrzucał „całą firmę" w ogóle.
        $check('Z80: sam cel „cała firma" (bez osób) nadal przechodzi',
            $service->zapisz(null, $firmy['ALFA'], Z::KTO_KONTO, $konta['widz'], Z::CZYJE_FIRMA,
                [], Z::ZE_SZCZEGOLAMI, $konta['widz'], 'test') === null);

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

        // ——— t, u, v, w: REGUŁA „ADMINISTRATORZY" ————————————————————————————————
        // ⚠ Reguły nie zakładamy: w firmie próbnej jej nie ma, a mimo to ma OBOWIĄZYWAĆ —
        // brak wiersza znaczy „nierozstrzygnięta", czyli zakres domyślny. To jest sedno
        // tej konstrukcji i dlatego sprawdzamy ją na firmie, której migracja nie dotknęła.
        $check('t: w firmie bez wiersza reguła administratorów i tak obowiązuje (zakres domyślny)',
            $widocznosc->zakresAdministratorow($firmy['ALFA']) === Z::ZE_SZCZEGOLAMI,
            $widocznosc->zakresAdministratorow($firmy['ALFA']));
        $check('t: konto z uprawnieniem jest rozpoznane jako administrator tej firmy',
            $widocznosc->jestAdminem($konta['admin'], $firmy['ALFA']));
        $check('v: konto bez tego uprawnienia administratorem nie jest',
            !$widocznosc->jestAdminem($konta['tenantowy'], $firmy['ALFA']));
        $zakresyAdmina = $widocznosc->zakresy($konta['admin'], $firmy['ALFA'], null, $cele);
        $check('t: administrator widzi pełną nazwę u każdego w firmie, bez żadnej reguły osobistej',
            $zakresyAdmina[$cele[0]] === Z::ZE_SZCZEGOLAMI
            && $zakresyAdmina[$cele[1]] === Z::ZE_SZCZEGOLAMI,
            json_encode($zakresyAdmina));
        $check('v: konto bez uprawnienia nie widzi nikogo (ta sama firma, te same osoby)',
            $widocznosc->zakresy($konta['tenantowy'], $firmy['ALFA'], null, $cele)
                === [$cele[0] => Z::BRAK, $cele[1] => Z::BRAK]);

        $powodAdmini = $service->zapisz(null, $firmy['ALFA'], Z::KTO_ADMINI, 0, Z::CZYJE_FIRMA, [],
            Z::BEZ_SZCZEGOLOW, $konta['widz'], 'test');
        $check('u: zakres reguły administratorów daje się zmienić', $powodAdmini === null,
            (string) $powodAdmini);
        $check('u: po zmianie administrator widzi już tylko fakt nieobecności',
            $widocznosc->zakresy($konta['admin'], $firmy['ALFA'], null, $cele)[$cele[0]]
                === Z::BEZ_SZCZEGOLOW);
        // ⚠ Ograniczenie reguły stałej nie zabiera dostępu nadanego OSOBNO — wygrywa szerszy.
        $service->zapisz(null, $firmy['ALFA'], Z::KTO_KONTO, $konta['admin'], Z::CZYJE_FIRMA, [],
            Z::ZE_SZCZEGOLAMI, $konta['widz'], 'test');
        $check('u: osobna reguła „ze szczegółami" wygrywa z ograniczoną regułą administratorów',
            $widocznosc->zakresy($konta['admin'], $firmy['ALFA'], null, $cele)[$cele[0]]
                === Z::ZE_SZCZEGOLAMI);
        $regulaAdminow = $reguly->regulaStala($firmy['ALFA'], Z::KTO_ADMINI);
        $check('w: reguły administratorów NIE DA SIĘ usunąć — serwer odmawia',
            $service->usun((int) ($regulaAdminow['id'] ?? 0), $firmy['ALFA'], $konta['widz'], 'test')
                === WidocznoscService::POWOD_DOMYSLNA);
        $check('w: po odmowie reguła nadal jest w bazie',
            $reguly->regulaStala($firmy['ALFA'], Z::KTO_ADMINI) !== null);

        // ——— Z78, Z79: REGUŁA STAŁA NIE PRZYJMUJE ZMIANY PODMIOTU ANI CELU ——————————
        // ⚠ **To jest test żądania ZŁOŻONEGO Z RĘKI, a nie klikania w oknie.** Ekran wyłącza
        // w regule stałej kroki „Kto widzi" i „Czyje nieobecności", ale wyłączony kafelek nie jest
        // bramką (CLAUDE.md §10): żądanie z innym podmiotem i innym celem wysyła się jednym
        // wywołaniem `curl`. Sprawdzamy więc to, co zostaje, gdy ekranu nie ma wcale.
        //
        // ⚠ **Każda odmowa ma obok siebie dowód, że żądanie NIE przeszło bokiem**: zmieniony
        // zakres. Bez tego test przechodziłby także wtedy, gdyby serwis odrzucał całe żądanie
        // z innego powodu — a wtedy nikt by nie zauważył, że pilnowana granica przestała istnieć.
        foreach ([Z::KTO_PRZELOZENI => Z::CZYJE_PODWLADNI, Z::KTO_ADMINI => Z::CZYJE_FIRMA] as $staly => $celStaly) {
            $sygn = '„' . ($staly === Z::KTO_ADMINI ? 'Administratorzy' : 'Każdy przełożony') . '"';
            // Pierwszy zapis zakłada wiersz — bez niego reguła obowiązuje, ale numeru nie ma.
            $service->zapisz(null, $firmy['ALFA'], $staly, 0, $celStaly, [], Z::BEZ_SZCZEGOLOW,
                $konta['widz'], 'test');
            $stalaId = (int) ($reguly->regulaStala($firmy['ALFA'], $staly)['id'] ?? 0);
            $ileStalych = $ileRegul();
            $check('Z79: reguła ' . $sygn . ' ma po zapisie swój wiersz', $stalaId > 0, (string) $stalaId);

            // (a) podmiot wskazany z listy przy rodzaju stałym → JAWNA odmowa
            $check('Z78: ' . $sygn . ' + wskazane konto jako podmiot → odmowa „podmiot"',
                $service->zapisz(null, $firmy['ALFA'], $staly, $konta['widz'], $celStaly, [],
                    Z::ZE_SZCZEGOLAMI, $konta['widz'], 'test') === WidocznoscService::POWOD_PODMIOT);

            // (b) inny cel przy rodzaju stałym → cel wymuszony, zakres przyjęty
            $innyCel = $celStaly === Z::CZYJE_FIRMA ? Z::CZYJE_PODWLADNI : Z::CZYJE_FIRMA;
            $powodCel = $service->zapisz(null, $firmy['ALFA'], $staly, 0, $innyCel, [],
                Z::ZE_SZCZEGOLAMI, $konta['widz'], 'test');
            $poCelu = $reguly->regulaStala($firmy['ALFA'], $staly);
            $check('Z78: ' . $sygn . ' — żądanie innego celu nie zmieniło celu',
                (string) ($poCelu['target_kind'] ?? '') === $celStaly, (string) ($poCelu['target_kind'] ?? ''));
            $check('Z78: ' . $sygn . ' — to samo żądanie zmieniło ZAKRES (więc dojechało do zapisu)',
                $powodCel === null && (string) ($poCelu['scope'] ?? '') === Z::ZE_SZCZEGOLAMI,
                (string) $powodCel . '/' . (string) ($poCelu['scope'] ?? ''));

            // (c) numer reguły stałej + zupełnie inny podmiot i cel → zmienia się WYŁĄCZNIE zakres
            $powodZamiany = $service->zapisz($stalaId, $firmy['ALFA'], Z::KTO_KONTO, $konta['widz'],
                Z::CZYJE_FIRMA, [], Z::BEZ_SZCZEGOLOW, $konta['widz'], 'test');
            $poZamianie = $reguly->regulaStala($firmy['ALFA'], $staly);
            $check('Z78: ' . $sygn . ' — podmiotu nie da się podmienić numerem reguły',
                (string) ($poZamianie['subject_kind'] ?? '') === $staly
                && (int) ($poZamianie['subject_account_id'] ?? -1) === 0,
                (string) ($poZamianie['subject_kind'] ?? '') . '/'
                    . (string) ($poZamianie['subject_account_id'] ?? '—'));
            $check('Z78: ' . $sygn . ' — cel po próbie podmiany nietknięty',
                (string) ($poZamianie['target_kind'] ?? '') === $celStaly,
                (string) ($poZamianie['target_kind'] ?? ''));
            $check('Z78: ' . $sygn . ' — i to nadal TA SAMA, jedna reguła',
                (int) ($poZamianie['id'] ?? 0) === $stalaId && $ileRegul() === $ileStalych,
                (int) ($poZamianie['id'] ?? 0) . '/' . ($ileRegul() - $ileStalych));
            $check('Z78: ' . $sygn . ' — próba podmiany też zmieniła sam zakres',
                $powodZamiany === null && (string) ($poZamianie['scope'] ?? '') === Z::BEZ_SZCZEGOLOW,
                (string) $powodZamiany . '/' . (string) ($poZamianie['scope'] ?? ''));
        }

        // ——— Z79: ZAKRES REGUŁY „KAŻDY PRZEŁOŻONY" DOCHODZI DO KALENDARZA ——————————
        // ⚠ **Osobna gałąź próbna, a nie widz z poprzednich przypadków.** Tamto konto ma już
        // reguły osobiste, a między regułami wygrywa SZERSZA — mierzylibyśmy więc tamtą regułę,
        // nie tę, i test świeciłby zielono niezależnie od tego, co robi reguła stała.
        //
        // ⚠ **Przez tę samą funkcję, która karmi kalendarz** (`CalendarVisibility::zakresy()`),
        // a nie przez drugą, podobną: zakres pokazany w oknie reguly i zakres zastosowany
        // w siatce muszą być tą samą wielkością.
        $osoby['szef'] = $wstaw(
            'INSERT INTO person (first_name, last_name, outside_hr) VALUES (:i, :n, 0)',
            ['i' => 'Szef', 'n' => 'PRÓBNY SZEF']
        );
        $osoby['podwl'] = $wstaw(
            'INSERT INTO person (first_name, last_name, outside_hr) VALUES (:i, :n, 0)',
            ['i' => 'Podwładny', 'n' => 'PRÓBNY PODWŁADNY']
        );
        $konta['szef'] = $wstaw(
            "INSERT INTO account (person_id, email, status) VALUES (:p, :e, 'active')",
            ['p' => $osoby['szef'], 'e' => strtolower($znacznik) . '-szef@example.invalid']
        );
        $etaty['szef'] = $wstaw(
            'INSERT INTO employment (person_id, company_id, is_active) VALUES (:p, :c, 1)',
            ['p' => $osoby['szef'], 'c' => $firmy['ALFA']]
        );
        $etaty['podwl'] = $wstaw(
            'INSERT INTO employment (person_id, company_id, supervisor_employment_id, is_active)
             VALUES (:p, :c, :s, 1)',
            ['p' => $osoby['podwl'], 'c' => $firmy['ALFA'], 's' => $etaty['szef']]
        );
        $check('Z79: gałąź próbna założona (przełożony + jeden podwładny)',
            $etaty['szef'] > 0 && $etaty['podwl'] > 0);
        // ⚠ Oba zakresy, nie jeden: pomiar jednego stanu nie odróżnia „zakres zadziałał"
        // od „ten zakres jest tu zawsze".
        foreach ([Z::ZE_SZCZEGOLAMI, Z::BEZ_SZCZEGOLOW] as $zakres) {
            $powodZakresu = $service->zapisz(null, $firmy['ALFA'], Z::KTO_PRZELOZENI, 0,
                Z::CZYJE_PODWLADNI, [], $zakres, $konta['widz'], 'test');
            $widziane = $widocznosc->zakresy($konta['szef'], $firmy['ALFA'], $etaty['szef'],
                [$etaty['podwl']]);
            $check('Z79: po zmianie zakresu reguły stałej przełożony widzi podwładnego jako „'
                . $zakres . '"',
                $powodZakresu === null && ($widziane[$etaty['podwl']] ?? '') === $zakres,
                (string) $powodZakresu . '/' . ($widziane[$etaty['podwl']] ?? '—'));
        }

        // ——— Z80 cz. B: STATUS „AKTYWNA" ——————————————————————————————————————————
        // ⚠ **Reguła nieaktywna nie daje ANI JEDNEGO uprawnienia** (§B5) i to jest w tym
        // zestawie najważniejsze twierdzenie: status bez tego byłby ozdobą na liście.
        // Mierzymy przez `CalendarVisibility::zakresy()`, czyli tę samą funkcję, która karmi
        // kalendarz — nie przez zapytanie napisane w teście.
        $regulaB = $service->zapisz(null, $firmy['ALFA'], Z::KTO_KONTO, $konta['widz'], Z::CZYJE_OSOBY,
            [$etaty['cel2']], Z::ZE_SZCZEGOLAMI, $konta['widz'], 'test');
        $check('B5: reguła na wskazaną osobę zapisana', $regulaB === null, (string) $regulaB);
        $idB = 0;
        foreach ($reguly->reguly($firmy['ALFA']) as $r) {
            if ((string) $r['target_kind'] === Z::CZYJE_OSOBY && in_array($etaty['cel2'], $r['grupy'], true)) {
                $idB = (int) $r['id'];
            }
        }
        $check('B5: reguła ma swój numer i jest aktywna', $idB > 0
            && (int) $reguly->regula($idB, $firmy['ALFA'])['is_active'] === 1);
        $widaC = static fn (): string => $widocznosc->zakresy($konta['widz'], $firmy['ALFA'],
            $etaty['widz'], [$etaty['cel2']])[$etaty['cel2']] ?? '?';
        $check('B5: przed wyłączeniem widz widzi osobę ze szczegółami', $widaC() === Z::ZE_SZCZEGOLAMI, $widaC());
        $check('B2: administrator wyłącza regułę', $service->przestaw($idB, $firmy['ALFA'], false,
            $konta['widz'], 'test') === null);
        $check('B5: po wyłączeniu reguła nie daje NIC', $widaC() === Z::BRAK, $widaC());
        $zrodla = $widocznosc->zrodlaWidza($konta['widz'], $firmy['ALFA'], $etaty['widz']);
        $check('B5: wyłączonej reguły nie ma też w źródłach wglądu',
            array_filter($zrodla, static fn (array $z): bool => $z['rodzaj'] === Z::CZYJE_OSOBY) === []);
        $check('B2: wpis w dzienniku o wyłączeniu',
            $events->countRecent('calendar_access.disabled', 1) >= 1);
        $check('B2: administrator włącza regułę z powrotem', $service->przestaw($idB, $firmy['ALFA'], true,
            $konta['widz'], 'test') === null);
        $check('B5: po włączeniu wgląd wraca', $widaC() === Z::ZE_SZCZEGOLAMI, $widaC());
        // ⚠ Drugie wywołanie z tym samym stanem nie jest błędem, ale też nie dokłada wpisu:
        // dziennik ma mieć tyle zdarzeń, ile było ZMIAN.
        $wpisowPrzed = $events->countRecent('calendar_access.enabled', 1);
        $service->przestaw($idB, $firmy['ALFA'], true, $konta['widz'], 'test');
        $check('B2: powtórne włączenie nie dokłada wpisu',
            $events->countRecent('calendar_access.enabled', 1) === $wpisowPrzed);
        $check('B1: reguły stałej nie da się wyłączyć',
            $service->przestaw((int) ($reguly->regulaStala($firmy['ALFA'], Z::KTO_ADMINI)['id'] ?? 0),
                $firmy['ALFA'], false, $konta['widz'], 'test') === WidocznoscService::POWOD_DOMYSLNA);

        // ——— Z80 B-e, B-f: ODMOWY WOKÓŁ STATUSU ————————————————————————————————
        $service->przestaw($idB, $firmy['ALFA'], false, $konta['widz'], 'test');
        $check('B-e: identyczna reguła przy wyłączonej → odmowa „wylaczona", nie „duplikat"',
            $service->zapisz(null, $firmy['ALFA'], Z::KTO_KONTO, $konta['widz'], Z::CZYJE_OSOBY,
                [$etaty['cel2']], Z::ZE_SZCZEGOLAMI, $konta['widz'], 'test')
                === WidocznoscService::POWOD_WYLACZONA);
        $service->przestaw($idB, $firmy['ALFA'], true, $konta['widz'], 'test');
        $check('B-e: przy regule CZYNNEJ odmowa jest nadal „duplikat"',
            $service->zapisz(null, $firmy['ALFA'], Z::KTO_KONTO, $konta['widz'], Z::CZYJE_OSOBY,
                [$etaty['cel2']], Z::ZE_SZCZEGOLAMI, $konta['widz'], 'test')
                === WidocznoscService::POWOD_DUPLIKAT);
        // ⚠ B-f: reguła bez skutku nie wchodzi do bazy. Przełożony próbny ma podwładnego,
        // więc jego reguła się zapisze; `cel1` nie jest niczyim przełożonym, więc jego — nie.
        $przedBezSkutku = $ileRegul();
        // ⚠ Podmiotem jest KONTO Z ETATEM, ale bez ani jednego podwładnego. To jest przypadek,
        // którego dotąd NIE BYŁO: `sprawdzPodmiot()` przepuszcza takie konto (jest kandydatem),
        // `sprawdzCel()` też (ma etat, więc nie wpada w `POWOD_BEZ_ETATU`) — i do 23.10.2026
        // reguła zapisywała się jako czynna, nie dając nikomu nic.
        // ⚠ Przy KTO = Przełożony ten sam brak odrzuca już `sprawdzPodmiot()` (`POWOD_PODMIOT`),
        // bo lista przełożonych zawiera wyłącznie zatrudnienia z podwładnymi — i tak zostaje.
        $check('B-f: konto z etatem, ale bez podwładnych, przy celu „podwładni" → odmowa',
            $service->zapisz(null, $firmy['ALFA'], Z::KTO_KONTO, $konta['widz'], Z::CZYJE_PODWLADNI,
                [], Z::BEZ_SZCZEGOLOW, $konta['widz'], 'test')
                === WidocznoscService::BEZ_SKUTKU_PODWLADNI);
        $check('B-f: KTO = Przełożony bez podwładnych → odmowa „podmiot" (jak dotąd)',
            $service->zapisz(null, $firmy['ALFA'], Z::KTO_PRZELOZONY, $etaty['cel1'], Z::CZYJE_PODWLADNI,
                [], Z::BEZ_SZCZEGOLOW, $konta['widz'], 'test')
                === WidocznoscService::POWOD_PODMIOT);
        $check('B-f: po dwóch odmowach nie przybyła żadna reguła', $ileRegul() === $przedBezSkutku);

        // ——— Z80 B3 + droga 2: JEDNO WYGASZENIE ETATU, CZTERY WYZWALACZE ——————————
        // ⚠ **Tak wygląda najczęściej spotykana zmiana w kadrach**: ktoś odchodzi z firmy.
        // Jedno wygaszenie etatu odbiera naraz: cel regule „osoby", podwładnego regule
        // „przełożony X › jego podwładni", podmiot regule „osoba" (bo konto bez żadnego
        // czynnego etatu zostaje zablokowane) — i właśnie dlatego test jest jeden, a nie cztery.
        // ⚠ Wygaszenie idzie `SyncDomainRepository::deactivateEmployment()`, czyli DOKŁADNIE tą
        // metodą, którą wywołuje `RecordApplier::deactivate()` w drodze 2.
        $kontoPodwl = $wstaw(
            "INSERT INTO account (person_id, email, status) VALUES (:p, :e, 'active')",
            ['p' => $osoby['podwl'], 'e' => strtolower($znacznik) . '-podwl@example.invalid']
        );
        $konta['podwl'] = $kontoPodwl;
        // ⚠ Numer roli bierzemy osobnym zapytaniem, a NIE domknięciem `$rola` z fixture: pętla
        // zakładająca etaty nadpisuje tę zmienną napisem (`'obcy'`), więc wywołanie kończy się
        // „undefined function obcy()". Zmierzone 23.10.2026 — i to jest dokładnie ten rodzaj
        // pułapki, który daje `$rola` w dwóch znaczeniach w jednym pliku.
        $stmtRola = $pdo->prepare('SELECT id FROM role WHERE code = :kod');
        $stmtRola->execute(['kod' => 'employee']);
        $wstaw('INSERT INTO role_assignment (account_id, role_id, company_id) VALUES (:a, :r, :c)',
            ['a' => $kontoPodwl, 'r' => (int) $stmtRola->fetchColumn(), 'c' => $firmy['ALFA']]);
        $r1 = $service->zapisz(null, $firmy['ALFA'], Z::KTO_PRZELOZONY, $etaty['szef'], Z::CZYJE_PODWLADNI,
            [], Z::BEZ_SZCZEGOLOW, $konta['widz'], 'test');
        $r2 = $service->zapisz(null, $firmy['ALFA'], Z::KTO_KONTO, $konta['widz'], Z::CZYJE_OSOBY,
            [$etaty['podwl']], Z::BEZ_SZCZEGOLOW, $konta['widz'], 'test');
        $r3 = $service->zapisz(null, $firmy['ALFA'], Z::KTO_KONTO, $kontoPodwl, Z::CZYJE_FIRMA,
            [], Z::BEZ_SZCZEGOLOW, $konta['widz'], 'test');
        $check('droga 2: trzy reguły próbne zapisane', $r1 === null && $r2 === null && $r3 === null,
            (string) $r1 . '/' . (string) $r2 . '/' . (string) $r3);
        $aktywnych = static fn (): int => (int) $pdo->query('SELECT COUNT(*) FROM calendar_access_rule
            WHERE company_id = ' . (int) $firmy['ALFA'] . ' AND is_active = 1')->fetchColumn();
        $przedWygaszeniem = $aktywnych();
        // Wygaszenie etatu podwładnego + blokada konta przy zerze czynnych etatów — tak samo
        // jak w `RecordApplier::deactivate()`.
        $osobaPodwl = $domenaSync->deactivateEmployment($etaty['podwl']);
        $check('droga 2: wygaszenie etatu zwróciło osobę', $osobaPodwl === $osoby['podwl'],
            (string) $osobaPodwl);
        if ($osobaPodwl !== null && $domenaSync->activeEmploymentCount($osobaPodwl) === 0) {
            $domenaSync->blockAccountOfPerson($osobaPodwl);
        }
        $check('droga 2: konto podwładnego zablokowane po utracie ostatniego etatu',
            $pdo->query('SELECT blocked_at FROM account WHERE id = ' . $kontoPodwl)->fetchColumn() !== null);
        $wylaczone = $service->przelicz($firmy['ALFA'], true, null, 'sync');
        $powody = array_values($wylaczone);
        $check('droga 2: jedno wygaszenie wyłączyło TRZY reguły', count($wylaczone) === 3,
            json_encode($wylaczone));
        $check('droga 2: powód „brak podwładnych" (reguła przełożonego i cel podwładni)',
            in_array(WidocznoscService::BEZ_SKUTKU_PODWLADNI, $powody, true));
        $check('droga 2: powód „bez czynnych osób" (cel wskazane osoby)',
            in_array(WidocznoscService::BEZ_SKUTKU_OSOBY, $powody, true));
        $check('droga 2: powód „konto nieczynne" (podmiot z zablokowanym kontem)',
            in_array(WidocznoscService::BEZ_SKUTKU_KONTO, $powody, true));
        $check('droga 2: liczba reguł aktywnych spadła dokładnie o trzy',
            $aktywnych() === $przedWygaszeniem - 3, (string) ($przedWygaszeniem - $aktywnych()));
        $check('droga 2: wpisy w dzienniku o wyłączeniu automatycznym',
            $events->countRecent('calendar_access.auto_disabled', 1) >= 3);
        // ⚠ **System NIGDY nie włącza reguły sam** (§B4): drugi przebieg po tych samych danych
        // nie rusza niczego, także gdyby skutek wrócił.
        $check('B4: drugi przebieg przeliczania nie wyłącza ani nie włącza niczego',
            $service->przelicz($firmy['ALFA'], true, null, 'sync') === []
            && $aktywnych() === $przedWygaszeniem - 3);
        // ⚠ B4: włączyć wolno WYŁĄCZNIE regułę, która ma skutek — i odmawia SERWER, a nie
        // zablokowany przełącznik na ekranie.
        $idBezSkutku = (int) array_key_first($wylaczone);
        $check('B4: włączenie reguły bez skutku → odmowa z powodem',
            in_array($service->przestaw($idBezSkutku, $firmy['ALFA'], true, $konta['widz'], 'test'), [
                WidocznoscService::BEZ_SKUTKU_PODWLADNI,
                WidocznoscService::BEZ_SKUTKU_OSOBY,
                WidocznoscService::BEZ_SKUTKU_KONTO,
            ], true));
        $check('B4: po odmowie reguła jest nadal nieaktywna',
            (int) $reguly->regula($idBezSkutku, $firmy['ALFA'])['is_active'] === 0);

        // ——— Z80 B3-d: PUSTA GRUPA NIE WYŁĄCZA REGUŁY ————————————————————————————
        // ⚠ Decyzja Krzysztofa z 23.10.2026, świadomie inna niż przy przełożonym bez podwładnych:
        // skład grupy zmienia się przy zwykłych porządkach w etykietach, a to nie jest decyzja
        // o dostępie. Ten test pilnuje, żeby nikt tego nie „ujednolicił".
        // ⚠ **WŁASNA grupa, nie `$grupaId` z fixture**: przypadek `h` wyżej usuwa tę pierwszą,
        // a reguła na usuniętą grupę nie zapisze się wcale (`POWOD_PODMIOT`). Zmierzone
        // 23.10.2026 — test mówił wtedy „podmiot" i wyglądało to na usterkę produktu.
        $nowaGrupa = $grupySerwis->utworz($firmy['ALFA'], 'Grupa pusta ' . $znacznik, $konta['widz'], 'test');
        $grupaPusta = is_array($nowaGrupa) ? (int) $nowaGrupa['id'] : 0;
        $check('B3-d: druga grupa próbna założona', $grupaPusta > 0);
        $grupySerwis->przypisz($grupaPusta, $firmy['ALFA'], [$etaty['cel1']], $konta['widz'], 'test');
        $rGrupa = $service->zapisz(null, $firmy['ALFA'], Z::KTO_GRUPA, $grupaPusta, Z::CZYJE_FIRMA, [],
            Z::BEZ_SZCZEGOLOW, $konta['widz'], 'test');
        $check('B3-d: reguła na grupę zapisana', $rGrupa === null, (string) $rGrupa);
        $grupySerwis->wypisz($grupaPusta, $firmy['ALFA'], [$etaty['cel1']], $konta['widz'], 'test');
        $check('B3-d: grupa jest teraz pusta',
            $reguly->zatrudnieniaGrup($firmy['ALFA'], [$grupaPusta]) === []);
        $check('B3-d: pusta grupa NIE wyłącza reguły',
            $service->przelicz($firmy['ALFA'], true, null, 'test') === []);

        // ——— KANDYDACI: konto z nadaniem na cały tenant ————————————————————————
        // ⚠ To domknięcie decyzji z Z53 (zlecenie Z55 pkt 2). Bez niego reguła założona takiemu
        // kontu przez migrację miała martwy ołówek: każdy zapis sprawdza kandydaturę podmiotu.
        foreach (['ALFA', 'BETA'] as $nazwaFirmy) {
            $kandydaci = array_column($reguly->kandydaciKonta($firmy[$nazwaFirmy], '', 500, 0), 'zrodlo', 'id');
            $check("kandydaci: konto z nadaniem tenantowym jest na liście w firmie {$nazwaFirmy}",
                ($kandydaci[$konta['tenantowy']] ?? '') === 'tenant',
                (string) ($kandydaci[$konta['tenantowy']] ?? '—'));
        }
        $powodTenant = $service->zapisz(null, $firmy['BETA'], Z::KTO_KONTO, $konta['tenantowy'],
            Z::CZYJE_FIRMA, [], Z::BEZ_SZCZEGOLOW, $konta['widz'], 'test');
        $check('kandydaci: regułę takiego konta da się zapisać', $powodTenant === null,
            (string) $powodTenant);
        $regulaTenant = (int) $pdo->query('SELECT MAX(id) FROM calendar_access_rule WHERE company_id = '
            . $firmy['BETA'] . ' AND subject_account_id = ' . $konta['tenantowy'])->fetchColumn();
        $check('kandydaci: i da się ją ZMIENIĆ ołówkiem (zmiana zakresu przechodzi)',
            $service->zapisz($regulaTenant, $firmy['BETA'], Z::KTO_KONTO, $konta['tenantowy'],
                Z::CZYJE_FIRMA, [], Z::ZE_SZCZEGOLAMI, $konta['widz'], 'test') === null);

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
        // ⚠ **To było ostrzeżenie, a od Z55 jest KRYTERIUM** (20.10.2026). Po Z53 nadanie
        // obejmujące cały tenant u konta bez roli `employee` nie dawało ani jednej reguły, więc
        // takie konto po cichu traciło dostęp — test wypisywał tę liczbę, żeby jej nie przeoczyć.
        // Migracja Z55 domyka obie drogi: konta z uprawnieniem administratora obejmuje reguła
        // „Administratorzy", pozostałym zakłada regułę osobistą w każdej firmie. Dlatego liczba
        // nadań, które nie są objęte ANI regułą osobistą, ANI regułą administratorów, ma być zerem.
        $porzucone = (int) $pdo->query("SELECT COUNT(*) FROM role_assignment ra
               JOIN role r ON r.id = ra.role_id
              WHERE r.code IN ('calendar_viewer', 'calendar_viewer_full')
                AND ra.company_id = 0
                AND NOT EXISTS (SELECT 1 FROM calendar_access_rule car
                                 WHERE car.subject_kind = 'account'
                                   AND car.subject_account_id = ra.account_id)
                AND NOT EXISTS (SELECT 1 FROM role_assignment adm
                                  JOIN role_permission rp ON rp.role_id = adm.role_id
                                  JOIN permission p ON p.id = rp.permission_id
                                       AND p.code = 'calendar.visibility_manage'
                                 WHERE adm.account_id = ra.account_id)")->fetchColumn();
        $check('i: każde nadanie na cały tenant jest objęte regułą — osobistą albo administratorów',
            $porzucone === 0, (string) $porzucone);
        // ⚠ Druga strona tego samego: reguła „Administratorzy" ma istnieć w KAŻDEJ firmie, którą
        // zastała migracja. Firmy założone później obywają się bez wiersza, bo zakres domyślny
        // stosuje jedno miejsce w kodzie — i to właśnie sprawdza przypadek `t` wyżej.
        $bezAdminow = (int) $pdo->query("SELECT COUNT(*) FROM company c
              WHERE c.id < " . min($firmy) . "
                AND NOT EXISTS (SELECT 1 FROM calendar_access_rule car
                                 WHERE car.company_id = c.id AND car.subject_kind = 'admins_all')")->fetchColumn();
        $check('i: każda firma zastana przez migrację ma wiersz reguły „Administratorzy"',
            $bezAdminow === 0, (string) $bezAdminow);
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
