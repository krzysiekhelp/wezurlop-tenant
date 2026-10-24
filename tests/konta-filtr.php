<?php

declare(strict_types=1);

// **Filtr zatrudnienia na `/admin/konta`** (zlecenie Z84 cz. A).
//
// ⚠ Test na BAZIE, nie na czystej funkcji, i to jest sedno: filtr jest warunkiem w zapytaniu,
// a jego treść rozstrzyga o tym, kogo administrator widzi na liście osób. Policzony w PHP
// po pobraniu wszystkich wierszy przeszedłby ten test i wywrócił się na pierwszej firmie
// z dwoma tysiącami zatrudnień — a tu sprawdzamy to, co pojedzie do bazy.
//
// ⚠ **Trzy liczby muszą się do siebie DODAWAĆ** („pracujący + byli = wszyscy"), bo stoją obok
// siebie w jednym pasku. Liczone osobnymi zapytaniami rozjadą się przy pierwszej zmianie,
// która trafi tylko do jednej z nich — dlatego `employmentCounters()` liczy je jednym
// zapytaniem, a ten test pilnuje sumy.
//
// ⚠ Dane próbne są fikcyjne (nazwisko ze znacznikiem `__probka_filtr`) i kasowane w `finally`,
// także po wyjątku w połowie przebiegu. Liczby sprawdzamy jako RÓŻNICĘ wobec stanu zastanego:
// baza robocza żyje, więc wartość bezwzględna niczego by nie dowodziła.
//
// Uruchomienie: php tests/konta-filtr.php — kod wyjścia 0 = wszystko przeszło.

require dirname(__DIR__) . '/vendor/autoload.php';

use Wezurlop\Db\Connection;
use Wezurlop\Repository\PersonRepository as P;

// ⚠ Konfigurację składa ta sama fabryka, co aplikacja — i tą samą drogą co pozostałe testy
// bazowe (`tests/widocznosc-baza.php`). Test dotykający bazy roboczej nie ma prawa uruchomić
// się nigdzie poza `dev`.
$root = dirname(__DIR__);
(Dotenv\Dotenv::createImmutable($root))->safeLoad();
$cfg = (require $root . '/config/config.php')($_ENV, $_SERVER, $root);
if (($cfg['app_env'] ?? '') !== 'dev') {
    fwrite(STDERR, 'Test pisze do bazy — działa wyłącznie na APP_ENV=dev. Widziane: '
        . ($cfg['app_env'] ?? '—') . "\n");
    exit(2);
}

(static function (array $cfg): void {
    $failures = 0;
    $check = static function (string $name, bool $ok, string $widziane = '') use (&$failures): void {
        echo ($ok ? 'OK    ' : 'BŁĄD  ') . $name
            . ($ok || $widziane === '' ? '' : " — widziane: {$widziane}") . "\n";
        $failures += $ok ? 0 : 1;
    };

    $db = new Connection($cfg['db']);
    $pdo = $db->pdo();
    $osoby = new P($db);
    $znacznik = '__probka_filtr';
    $wstawione = [];

    try {
        // Firma z aktywnymi zatrudnieniami — ta sama droga wyboru, co w `dev/probka-zespolu.php`.
        $spolka = (int) $pdo->query(
            'SELECT company_id FROM employment WHERE is_active = 1 GROUP BY company_id
              ORDER BY COUNT(*) DESC LIMIT 1'
        )->fetchColumn();
        $check('A: jest firma z aktywnymi zatrudnieniami', $spolka > 0, (string) $spolka);

        $przed = $osoby->employmentCounters(null, '', $spolka);
        $check('A: liczniki zastane dodają się do siebie',
            $przed[P::ZATRUDNIENIE_PRACUJACY] + $przed[P::ZATRUDNIENIE_BYLI]
                === $przed[P::ZATRUDNIENIE_WSZYSCY],
            json_encode($przed));

        $nowaOsoba = static function (string $imie) use ($pdo, $znacznik, &$wstawione): int {
            $st = $pdo->prepare(
                'INSERT INTO person (first_name, last_name, outside_hr, created_at)
                 VALUES (:i, :n, 1, UTC_TIMESTAMP())'
            );
            $st->execute(['i' => $imie, 'n' => $znacznik]);
            $id = (int) $pdo->lastInsertId();
            $wstawione[] = $id;

            return $id;
        };
        $nowyEtat = static function (int $osoba, int $spolka, bool $czynne) use ($pdo): void {
            $st = $pdo->prepare(
                'INSERT INTO employment (person_id, company_id, fte_numerator, fte_denominator,
                                         fte, contract_employment, is_active, created_at)
                 VALUES (:o, :c, 1, 1, 1.0000, 1, :a, UTC_TIMESTAMP())'
            );
            $st->execute(['o' => $osoba, 'c' => $spolka, 'a' => $czynne ? 1 : 0]);
        };

        $pracujacy = $nowaOsoba('Pracuje');
        $nowyEtat($pracujacy, $spolka, true);
        $byly = $nowaOsoba('Odszedl');
        $nowyEtat($byly, $spolka, false);

        $po = $osoby->employmentCounters(null, '', $spolka);
        $check('A: osoba z czynnym etatem dolicza się do „Pracujący"',
            $po[P::ZATRUDNIENIE_PRACUJACY] === $przed[P::ZATRUDNIENIE_PRACUJACY] + 1,
            $przed[P::ZATRUDNIENIE_PRACUJACY] . ' → ' . $po[P::ZATRUDNIENIE_PRACUJACY]);
        $check('A: osoba bez czynnego etatu dolicza się do „Byli pracownicy"',
            $po[P::ZATRUDNIENIE_BYLI] === $przed[P::ZATRUDNIENIE_BYLI] + 1,
            $przed[P::ZATRUDNIENIE_BYLI] . ' → ' . $po[P::ZATRUDNIENIE_BYLI]);
        $check('A: „Wszyscy" rośnie o dwie i dalej jest sumą dwóch pierwszych',
            $po[P::ZATRUDNIENIE_WSZYSCY] === $przed[P::ZATRUDNIENIE_WSZYSCY] + 2
            && $po[P::ZATRUDNIENIE_PRACUJACY] + $po[P::ZATRUDNIENIE_BYLI]
                === $po[P::ZATRUDNIENIE_WSZYSCY],
            json_encode($po));

        // ——— LISTA: segment rozstrzyga, kogo widać ——————————————————————————————
        $naLiscie = static function (string $filtr, int $personId) use ($osoby, $spolka, $znacznik): bool {
            $wiersze = $osoby->listForAdmin(null, $znacznik, 'nazwisko', true, 50, 0, $spolka, $filtr);
            foreach ($wiersze as $w) {
                if ($w['person_id'] === $personId) {
                    return true;
                }
            }

            return false;
        };
        $check('A: „Pracujący" pokazuje osobę z czynnym etatem',
            $naLiscie(P::ZATRUDNIENIE_PRACUJACY, $pracujacy));
        $check('A: „Pracujący" NIE pokazuje byłego pracownika',
            !$naLiscie(P::ZATRUDNIENIE_PRACUJACY, $byly));
        $check('A: „Byli pracownicy" pokazuje byłego', $naLiscie(P::ZATRUDNIENIE_BYLI, $byly));
        $check('A: „Byli pracownicy" NIE pokazuje pracującego',
            !$naLiscie(P::ZATRUDNIENIE_BYLI, $pracujacy));
        $check('A: „Wszyscy" pokazuje oboje',
            $naLiscie(P::ZATRUDNIENIE_WSZYSCY, $pracujacy)
            && $naLiscie(P::ZATRUDNIENIE_WSZYSCY, $byly));
        // ⚠ Wartość spoza białej listy nie jest błędem, tylko brakiem zawężenia — adres wklejony
        // ze starym parametrem ma pokazać listę, nie ekran awarii.
        $check('A: nieznana wartość filtra zachowuje się jak „Wszyscy"',
            $naLiscie('cokolwiek', $pracujacy) && $naLiscie('cokolwiek', $byly));

        // ——— FILTR ŁĄCZY SIĘ Z SZUKANIEM ——————————————————————————————————————
        // ⚠ To jest ten warunek ze zlecenia, który najłatwiej napisać źle: dwa zawężenia naraz.
        // Szukanie po nazwisku próbki daje dwie osoby, ale z segmentem „Pracujący" — jedną.
        $check('A: liczniki liczą TEN SAM zbiór co lista, razem z szukaniem',
            $osoby->employmentCounters(null, $znacznik, $spolka) === [
                P::ZATRUDNIENIE_PRACUJACY => 1,
                P::ZATRUDNIENIE_BYLI => 1,
                P::ZATRUDNIENIE_WSZYSCY => 2,
            ],
            json_encode($osoby->employmentCounters(null, $znacznik, $spolka)));
        $check('A: licznik listy (stronicowanie) też respektuje segment i szukanie',
            $osoby->countForAdmin(null, $znacznik, $spolka, P::ZATRUDNIENIE_PRACUJACY) === 1
            && $osoby->countForAdmin(null, $znacznik, $spolka, P::ZATRUDNIENIE_BYLI) === 1
            && $osoby->countForAdmin(null, $znacznik, $spolka, P::ZATRUDNIENIE_WSZYSCY) === 2);

        // ——— ZAKRES: inna firma nie widzi tych osób ——————————————————————————————
        $innaSpolka = (int) $pdo->query(
            'SELECT id FROM company WHERE id <> ' . $spolka . ' ORDER BY id LIMIT 1'
        )->fetchColumn();
        if ($innaSpolka > 0) {
            // ⚠ Osoba próbna ma etat TYLKO w jednej firmie, więc w drugiej nie ma jej w żadnym
            // segmencie — także w „Wszyscy". Segment zawęża zakres, nie rozszerza go.
            $check('A: w innej firmie osób próbnych nie ma w ŻADNYM segmencie',
                $osoby->countForAdmin(null, $znacznik, $innaSpolka, P::ZATRUDNIENIE_WSZYSCY) === 0);
        }
    } finally {
        // ⚠ Kolejność: zatrudnienia przed osobami (klucz obcy), i każde usunięcie osobno —
        // po przerwanym przebiegu część wierszy może już nie istnieć.
        if ($wstawione !== []) {
            $lista = implode(',', array_map('intval', $wstawione));
            $pdo->exec('DELETE FROM employment WHERE person_id IN (' . $lista . ')');
            $pdo->exec('DELETE FROM person WHERE id IN (' . $lista . ')');
        }
        $zostalo = (int) $pdo->query(
            'SELECT COUNT(*) FROM person WHERE last_name = ' . $pdo->quote($znacznik)
        )->fetchColumn();
        echo ($zostalo === 0 ? 'OK    ' : 'BŁĄD  ')
            . "sprzątanie: po przebiegu nie został ani jeden wiersz próbny\n";
        $failures += $zostalo === 0 ? 0 : 1;
    }

    echo $failures === 0 ? "Wszystkie sprawdzenia przeszły.\n" : "Nieudane: {$failures}\n";
    exit($failures === 0 ? 0 : 1);
})($cfg);
