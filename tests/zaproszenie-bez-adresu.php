<?php

declare(strict_types=1);

// **Zaproszenie wymaga adresu e-mail** (zlecenie Z64, zgłoszenie Krzysztofa z 20.10.2026).
//
// ⚠ Powód istnienia tego pliku: panel osoby bez konta i z pustym polem e-mail miał CZYNNY
// przycisk „Wyślij" przy wierszu „Wyślij zaproszenie". Zgłoszenie dotyczyło wyglądu, ale
// pytanie pod nim jest o serwer: **co robi żądanie zaproszenia bez adresu**. Odpowiedź ma być
// jedna — odmowa, która nie zakłada konta, nie wydaje tokenu i nie zostawia zadania w kolejce.
// Trzy liczby, nie jedno „nie udało się".
//
// ⚠ **Ten test PISZE do bazy i dlatego ma bezpiecznik** (ten sam, co `tests/widocznosc-baza.php`):
// odmawia uruchomienia, gdy `APP_ENV` nie jest `dev`. Zakłada własne osoby i konta — puszczony
// u klienta zrobiłby to na prawdziwych danych kadrowych.
//
// ⚠ **Żadnego identyfikatora z bazy roboczej w pliku**: osoby, etaty i adresy powstają w trakcie
// przebiegu (domena `.invalid`, nazwiska zastępcze), a `finally` kasuje je także po błędzie.
// Adres z losowym sufiksem, bo `account.email` jest `UNIQUE` i dwa przebiegi by się zderzyły.
//
// ⚠ **Zadania z kolejki kasujemy sami, w tym samym przebiegu.** Instancja robocza ma
// skonfigurowany prawdziwy serwer SMTP i crona co pięć minut, więc zadanie zostawione po teście
// znaczyłoby próbę wysyłki poczty — na adres w `.invalid`, ale próbę.
//
// Uruchomienie: php tests/zaproszenie-bez-adresu.php — 0 = wszystko przeszło,
// 2 = odmowa uruchomienia (nie `dev`).

require dirname(__DIR__) . '/vendor/autoload.php';

use Wezurlop\Console\ConsoleKernel;
use Wezurlop\Service\InvitationService;
use Wezurlop\Support\Logger;
use Wezurlop\Support\Services;

exit((new ConsoleKernel(dirname(__DIR__)))->run(
    static function (Services $s, Logger $l): int {
        if (!$s->config->isDev()) {
            fwrite(STDERR, "ODMOWA: ten test pisze do bazy (zakłada osoby i konta próbne)\n"
                . "i uruchamia się wyłącznie przy APP_ENV=dev.\n");

            return 2;
        }
        $failures = 0;
        $check = static function (string $opis, bool $ok, string $widziane = '') use (&$failures): void {
            echo ($ok ? 'OK    ' : 'BŁĄD  ') . $opis
                . ($ok || $widziane === '' ? '' : " — widziane: {$widziane}") . "\n";
            $failures += $ok ? 0 : 1;
        };

        $pdo = $s->db->pdo();
        $sufiks = bin2hex(random_bytes(3));
        $osoby = [];
        /** Osoba próbna z czynnym etatem; adres `null` znaczy „bez adresu". */
        $nowaOsoba = static function (string $nazwisko, ?string $adres) use ($pdo, &$osoby): int {
            $spolka = (int) $pdo->query('SELECT id FROM company WHERE is_active = 1 ORDER BY id LIMIT 1')
                ->fetchColumn();
            $st = $pdo->prepare(
                "INSERT INTO person (first_name, last_name, email_private, outside_hr, created_at)
                 VALUES ('Próbny', :n, :mail, 1, UTC_TIMESTAMP())"
            );
            $st->execute(['n' => $nazwisko, 'mail' => $adres]);
            $osoba = (int) $pdo->lastInsertId();
            $osoby[] = $osoba;
            $st = $pdo->prepare(
                'INSERT INTO employment (person_id, company_id, fte_numerator, fte_denominator, fte,
                                         contract_employment, is_active, created_at)
                 VALUES (:osoba, :spolka, 1, 1, 1.0000, 1, 1, UTC_TIMESTAMP())'
            );
            $st->execute(['osoba' => $osoba, 'spolka' => $spolka]);

            return $osoba;
        };
        /** Trzy liczby, których dotyczy odmowa: konto, token, zadanie w kolejce. */
        $slady = static function (int $osoba) use ($pdo): array {
            $st = $pdo->prepare('SELECT id, email, status FROM account WHERE person_id = :p');
            $st->execute(['p' => $osoba]);
            $konto = $st->fetch();
            if ($konto === false) {
                return ['konto' => null, 'adres' => null, 'stan' => null, 'tokenow' => 0, 'zadan' => 0];
            }
            $st = $pdo->prepare('SELECT COUNT(*) FROM invitation WHERE account_id = :k');
            $st->execute(['k' => (int) $konto['id']]);
            $tokenow = (int) $st->fetchColumn();
            // ⚠ Wzorzec ze SPACJĄ po dwukropku — tak zapisuje treść zadania kolejka.
            $st = $pdo->prepare('SELECT COUNT(*) FROM job WHERE type = :typ AND payload LIKE :igla');
            $st->execute(['typ' => 'mail.invitation', 'igla' => '%"account_id": ' . (int) $konto['id'] . '%']);

            return [
                'konto' => (int) $konto['id'],
                'adres' => $konto['email'],
                'stan' => $konto['status'],
                'tokenow' => $tokenow,
                'zadan' => (int) $st->fetchColumn(),
            ];
        };

        try {
            $bez = $nowaOsoba('__test_zapro_bez_' . $sufiks, null);
            $zly = $nowaOsoba('__test_zapro_zly_' . $sufiks, null);
            $zAdresem = $nowaOsoba('__test_zapro_z_' . $sufiks, 'test-zapro-' . $sufiks . '@pomiar.invalid');

            // ——— 1. Odmowa bez adresu: trzy liczby, nie jedno „nie udało się" ———
            $wynik = $s->invitations->inviteExisting($bez, '', null);
            $check('bez adresu: odmowa z powodem „brak adresu e-mail"',
                $wynik['powod'] === InvitationService::POWOD_BRAK_ADRESU, (string) $wynik['powod']);
            $check('bez adresu: konto nie powstało', $wynik['account'] === null);
            $slad = $slady($bez);
            $check('bez adresu: zero kont w bazie', $slad['konto'] === null, json_encode($slad));
            $check('bez adresu: zero tokenów', $slad['tokenow'] === 0, (string) $slad['tokenow']);
            $check('bez adresu: zero zadań w kolejce', $slad['zadan'] === 0, (string) $slad['zadan']);

            // ——— 2. Adres niepoprawny to ta sama odmowa (format sprawdza serwer od nowa) ———
            $wynik = $s->invitations->inviteExisting($zly, 'to-nie-adres', null);
            $check('adres bez małpy: ta sama odmowa',
                $wynik['powod'] === InvitationService::POWOD_BRAK_ADRESU, (string) $wynik['powod']);
            $slad = $slady($zly);
            $check('adres bez małpy: zero kont, tokenów i zadań',
                $slad['konto'] === null && $slad['tokenow'] === 0 && $slad['zadan'] === 0, json_encode($slad));

            // ——— 3. Kontrprób: z adresem zaproszenie PRZECHODZI i zostawia jedno zadanie ———
            // ⚠ Bez tego punktu dwa poprzednie nie dowodzą niczego: metoda, która odmawia
            // zawsze, przeszłaby je obie.
            $wynik = $s->invitations->inviteExisting($zAdresem, 'test-zapro-' . $sufiks . '@pomiar.invalid', null);
            $check('z adresem: brak odmowy', $wynik['powod'] === null, (string) $wynik['powod']);
            $slad = $slady($zAdresem);
            $check('z adresem: konto powstało w stanie „invited"', $slad['stan'] === 'invited', (string) $slad['stan']);
            $check('z adresem: adres zapisany w koncie',
                $slad['adres'] === 'test-zapro-' . $sufiks . '@pomiar.invalid', (string) $slad['adres']);
            $check('z adresem: DOKŁADNIE jedno zadanie w kolejce', $slad['zadan'] === 1, (string) $slad['zadan']);
            // ⚠ Token wydaje WYSYŁKA, nie zaproszenie: zadanie w kolejce dopiero go utworzy.
            // Zapisane wprost, bo „zero tokenów" po udanym zaproszeniu wygląda jak usterka.
            $check('z adresem: token jeszcze nie istnieje (wydaje go wysyłka)',
                $slad['tokenow'] === 0, (string) $slad['tokenow']);

            // ——— 4. Kolejność w kontrolerze: odmowa PRZED zapisem ———
            // ⚠ Sprawdzenie na kodzie, nie na bazie, i to jest świadome: „nic nie zapisane"
            // zależy od KOLEJNOŚCI trzech kroków w jednej metodzie, a nie od wyniku jednego
            // wywołania. Pomiar przez HTTP pokazuje skutek dla jednego przypadku; to pokazuje,
            // że przypadek nie może być inny.
            $kod = (string) file_get_contents(dirname(__DIR__) . '/src/Controller/App/AdminAccountController.php');
            $odmowa = strpos($kod, 'Zaproszenie wymaga adresu e-mail');
            $dostep = strpos($kod, 'zapiszDostepZPanelu($request');
            $zapis = strpos($kod, 'updateContact($personId');
            $kolejka = strpos($kod, 'inviteExisting(');
            $check('kontroler: odmowa stoi przed zapisem dostępu',
                $odmowa !== false && $dostep !== false && $odmowa < $dostep);
            $check('kontroler: odmowa stoi przed zapisem danych konta',
                $odmowa !== false && $zapis !== false && $odmowa < $zapis);
            $check('kontroler: kolejka maila stoi PO zapisie danych konta',
                $kolejka !== false && $zapis !== false && $zapis < $kolejka);
        } finally {
            // ⚠ Sprzątanie także po błędzie — i w kolejności od zadań do osoby, bo wiersze
            // kolejki i tokeny wskazują konto numerem.
            foreach ($osoby as $osoba) {
                $st = $pdo->prepare('SELECT id FROM account WHERE person_id = :p');
                $st->execute(['p' => $osoba]);
                foreach (array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)) as $konto) {
                    $z = $pdo->prepare('DELETE FROM job WHERE type LIKE :typ AND payload LIKE :igla');
                    $z->execute(['typ' => 'mail.%', 'igla' => '%"account_id": ' . $konto . '%']);
                    $pdo->prepare('DELETE FROM invitation WHERE account_id = :k')->execute(['k' => $konto]);
                    $pdo->prepare('DELETE FROM role_assignment WHERE account_id = :k')->execute(['k' => $konto]);
                    $pdo->prepare('DELETE FROM account WHERE id = :k')->execute(['k' => $konto]);
                }
                $pdo->prepare('DELETE FROM employment WHERE person_id = :p')->execute(['p' => $osoba]);
                $pdo->prepare('DELETE FROM person WHERE id = :p')->execute(['p' => $osoba]);
            }
            $zostalo = $pdo->prepare('SELECT COUNT(*) FROM person WHERE last_name LIKE :n');
            $zostalo->execute(['n' => '\_\_test\_zapro\_%']);
            echo 'zostało osób próbnych: ' . (int) $zostalo->fetchColumn() . "\n";
        }

        echo $failures === 0 ? "\nWszystko OK\n" : "\nBŁĘDÓW: {$failures}\n";

        return $failures === 0 ? 0 : 1;
    },
    STDERR,
));
