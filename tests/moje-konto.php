<?php

declare(strict_types=1);

// **„Moje konto": telefon, hasło i drugi składnik** (zlecenie Z69, 21.10.2026).
//
// ⚠ Co ten plik sprawdza i dlaczego właśnie to: każda z tych czterech rzeczy jest decyzją
// o DOSTĘPIE DO KONTA, a żadnej nie widać na ekranie w chwili, gdy się psuje.
//   1. zmiana hasła ze złym obecnym hasłem nie może zmienić niczego — ani hasza, ani sesji,
//   2. udana zmiana MUSI zakończyć pozostałe sesje i **zostawić bieżącą** (inaczej człowiek
//      wylatuje z aplikacji po własnej poprawnej operacji),
//   3. kod zapasowy działa **raz** (drugie użycie to już nie kod, a dziura),
//   4. przy wymogu dla administratorów serwer ODMAWIA wyłączenia 2FA, a nie tylko chowa przycisk.
//
// ⚠ **Test PISZE do bazy i dlatego ma bezpiecznik** (ten sam, co `tests/zaproszenie-bez-adresu.php`):
// odmawia uruchomienia, gdy `APP_ENV` nie jest `dev`. Zakłada własną osobę, konto i sesje,
// a `finally` kasuje je także po błędzie.
//
// ⚠ **Zero danych osobowych i zero adresów z bazy roboczej**: osoba nazywa się „Próbny",
// adres jest w domenie `.invalid` z losowym sufiksem (`account.email` jest `UNIQUE`).
//
// ⚠ **Hasła w tym pliku są losowe, nie literalne** (CLAUDE.md §14: żadnych haseł w repozytorium,
// także próbnych) — powstają z `random_bytes` na czas przebiegu.
//
// Uruchomienie: php tests/moje-konto.php — 0 = wszystko przeszło, 2 = odmowa (nie `dev`).

require dirname(__DIR__) . '/vendor/autoload.php';

use Wezurlop\Console\ConsoleKernel;
use Wezurlop\Http\DatabaseSessionHandler;
use Wezurlop\Security\BackupCodes;
use Wezurlop\Service\TwoFactorService;
use Wezurlop\Support\Logger;
use Wezurlop\Support\Services;
use Wezurlop\Support\Telefon;

exit((new ConsoleKernel(dirname(__DIR__)))->run(
    static function (Services $s, Logger $l): int {
        if (!$s->config->isDev()) {
            fwrite(STDERR, "ODMOWA: ten test pisze do bazy (zakłada osobę, konto i sesje próbne)\n"
                . "i uruchamia się wyłącznie przy APP_ENV=dev.\n");

            return 2;
        }
        $bledy = 0;
        $sprawdz = static function (string $opis, bool $ok, string $widziane = '') use (&$bledy): void {
            echo ($ok ? 'OK    ' : 'BŁĄD  ') . $opis
                . ($ok || $widziane === '' ? '' : " — widziane: {$widziane}") . "\n";
            $bledy += $ok ? 0 : 1;
        };

        // ── 1. Numer telefonu: reguła serwera ────────────────────────────────────────────────
        $sprawdz('telefon: 9 cyfr przechodzi', Telefon::poprawny('600100200'));
        $sprawdz('telefon: ze spacjami przechodzi', Telefon::poprawny('600 100 200'));
        $sprawdz('telefon: z prefiksem +48 przechodzi', Telefon::poprawny('+48 600 100 200'));
        $sprawdz('telefon: 8 cyfr odrzucone', !Telefon::poprawny('60010020'));
        $sprawdz('telefon: 10 cyfr odrzucone', !Telefon::poprawny('6001002000'));
        $sprawdz('telefon: same zera odrzucone', !Telefon::poprawny('000000000'));
        $sprawdz('telefon: pusty odrzucony', !Telefon::poprawny(''));
        $sprawdz('telefon: jedna postać w bazie', Telefon::doZapisu('600 100 200') === '+48600100200',
            (string) Telefon::doZapisu('600 100 200'));
        // ⚠ Numer z zerem W ŚRODKU nie może stracić środka — pułapka normalizacji „usuń zera".
        $sprawdz('telefon: zero w środku zostaje', Telefon::doZapisu('600 048 100') === '+48600048100',
            (string) Telefon::doZapisu('600 048 100'));
        $sprawdz('telefon: obcy numer NIE udaje polskiego',
            Telefon::doWyswietlenia('+44 20 7946 0958') === '+44 20 7946 0958');

        // ── 1b. Jedna decyzja dla trzech ekranów (Z73) ──────────────────────────────────────
        // ⚠ Od Z73 panel osoby i formularz nowego konta administracyjnego używają TEJ SAMEJ
        // funkcji co „Moje konto". Do Z73 panel miał własną, luźniejszą białą listę znaków
        // i zapisywał numer w dowolnym kształcie — w jednej kolumnie bazy stały dwa formaty.
        // Te cztery sprawdzenia są umową między trzema ekranami, nie detalem jednego z nich.
        [$numer, $odmowa] = Telefon::dlaZapisu('600 100 200');
        $sprawdz('decyzja: poprawny numer → jedna postać i brak odmowy',
            $numer === '+48600100200' && $odmowa === null, (string) $numer . '|' . (string) $odmowa);
        [$numer, $odmowa] = Telefon::dlaZapisu('');
        $sprawdz('decyzja: pusty numer → USUNIĘCIE, nie błąd', $numer === null && $odmowa === null);
        [$numer, $odmowa] = Telefon::dlaZapisu('   ');
        $sprawdz('decyzja: same spacje → usunięcie', $numer === null && $odmowa === null);
        [$numer, $odmowa] = Telefon::dlaZapisu('+44 20 7946 0958');
        $sprawdz('decyzja: numer zagraniczny → ODMOWA i nic do zapisu',
            $numer === null && $odmowa === Telefon::ODMOWA, (string) $odmowa);
        [$numer, $odmowa] = Telefon::dlaZapisu('60010020');
        $sprawdz('decyzja: osiem cyfr → odmowa', $numer === null && $odmowa !== null);
        // ⚠ Wartość do POLA: numer zastany oddajemy BEZ ZMIAN, bo pole ma pokazać to, co jest
        // w bazie; poprawki wymaga dopiero zapis (rozstrzygnięcie Z73).
        $sprawdz('pole: numer w docelowym kształcie bez prefiksu',
            Telefon::doPola('+48600100200') === '600 100 200', Telefon::doPola('+48600100200'));
        $sprawdz('pole: numer zastany bez zmian i bez obcinania',
            Telefon::doPola('22 123 45 67 w. 12') === '22 123 45 67 w. 12');
        $sprawdz('pole: brak numeru → pusto', Telefon::doPola(null) === '');

        $pdo = $s->db->pdo();
        $sufiks = bin2hex(random_bytes(3));
        $haslo = 'Pr' . bin2hex(random_bytes(12)) . '!x';
        $noweHaslo = 'Nw' . bin2hex(random_bytes(12)) . '!y';
        $osobaId = 0;
        $kontoId = 0;

        try {
            // ── Osoba i konto próbne ────────────────────────────────────────────────────────
            $st = $pdo->prepare(
                "INSERT INTO person (first_name, last_name, phone, outside_hr, created_at)
                 VALUES ('Próbny', :n, NULL, 1, UTC_TIMESTAMP())"
            );
            $st->execute(['n' => 'KONTO-' . strtoupper($sufiks)]);
            $osobaId = (int) $pdo->lastInsertId();

            $st = $pdo->prepare(
                "INSERT INTO account (person_id, email, password_hash, status, created_at)
                 VALUES (:osoba, :mail, :hash, 'active', UTC_TIMESTAMP())"
            );
            $st->execute([
                'osoba' => $osobaId,
                'mail'  => 'konto-' . $sufiks . '@wezurlop.invalid',
                'hash'  => $s->password->hash($haslo),
            ]);
            $kontoId = (int) $pdo->lastInsertId();

            // ── 2. Telefon: zapis idzie drogą repozytorium, dziennik BEZ numeru ─────────────
            $s->people->updatePhone($osobaId, Telefon::doZapisu('600 100 200'));
            $sprawdz('telefon: zapisany jako jedna postać',
                $s->people->phone($osobaId) === '+48600100200', (string) $s->people->phone($osobaId));
            $s->events->record('person.contact_changed', $kontoId, null, null, 'person', $osobaId,
                null, ['telefon' => true, 'bylo' => false, 'wlasny' => true]);
            $st = $pdo->prepare(
                "SELECT detail FROM event_log WHERE account_id = :k AND event_code = 'person.contact_changed'
                 ORDER BY id DESC LIMIT 1"
            );
            $st->execute(['k' => $kontoId]);
            $payload = (string) $st->fetchColumn();
            // ⚠ **W dzienniku nie ma numeru i to jest sprawdzane, nie zakładane**: telefon jest
            // daną osobową (CLAUDE.md §14), a dziennik widzą administratorzy tenanta.
            $sprawdz('dziennik: wpis o telefonie BEZ numeru',
                !str_contains($payload, '600') && str_contains($payload, 'telefon'), $payload);
            $s->people->updatePhone($osobaId, null);
            $sprawdz('telefon: usunięcie zostawia pusto', $s->people->phone($osobaId) === null);

            // ── 3. Zmiana hasła ────────────────────────────────────────────────────────────
            $hashPrzed = (string) $pdo->query("SELECT password_hash FROM account WHERE id = {$kontoId}")->fetchColumn();
            // Dwie sesje: „ta przeglądarka" i druga, która ma zniknąć po zmianie hasła.
            $sesje = [];
            foreach (['ta', 'obca'] as $nazwa) {
                $id = DatabaseSessionHandler::hash('sesja-' . $nazwa . '-' . $sufiks);
                $sesje[$nazwa] = $id;
                $st = $pdo->prepare(
                    "INSERT INTO session (id, account_id, client, payload, ip_hash, ip_prefix, user_agent,
                                          created_at, last_seen_at, expires_at)
                     VALUES (:id, :konto, 'web', '', :ip, '203.0.113.0/24', 'Test', UTC_TIMESTAMP(),
                             UTC_TIMESTAMP(), UTC_TIMESTAMP() + INTERVAL 3600 SECOND)"
                );
                $st->execute(['id' => $id, 'konto' => $kontoId, 'ip' => str_repeat('a', 64)]);
            }

            $odmowa = $s->passwordReset->changeOwn($kontoId, $haslo . 'ZLE', $noweHaslo, $noweHaslo,
                str_repeat('b', 64), $sesje['ta']);
            $sprawdz('hasło: złe obecne → odmowa', $odmowa !== null, (string) $odmowa);
            $sprawdz('hasło: złe obecne → hasz NIEZMIENIONY',
                (string) $pdo->query("SELECT password_hash FROM account WHERE id = {$kontoId}")->fetchColumn() === $hashPrzed);
            $sprawdz('hasło: złe obecne → sesje zostają',
                (int) $pdo->query("SELECT COUNT(*) FROM session WHERE account_id = {$kontoId} AND revoked_at IS NULL")->fetchColumn() === 2);

            $odmowa = $s->passwordReset->changeOwn($kontoId, $haslo, $noweHaslo, $noweHaslo . 'INNE',
                str_repeat('b', 64), $sesje['ta']);
            $sprawdz('hasło: powtórzenie się nie zgadza → odmowa', $odmowa !== null, (string) $odmowa);

            $odmowa = $s->passwordReset->changeOwn($kontoId, $haslo, 'krótkie', 'krótkie',
                str_repeat('b', 64), $sesje['ta']);
            $sprawdz('hasło: za krótkie → odmowa', $odmowa !== null, (string) $odmowa);

            $odmowa = $s->passwordReset->changeOwn($kontoId, $haslo, $haslo, $haslo,
                str_repeat('b', 64), $sesje['ta']);
            $sprawdz('hasło: takie samo jak obecne → odmowa', $odmowa !== null, (string) $odmowa);

            $odmowa = $s->passwordReset->changeOwn($kontoId, $haslo, $noweHaslo, $noweHaslo,
                str_repeat('b', 64), $sesje['ta']);
            $sprawdz('hasło: poprawna zmiana przechodzi', $odmowa === null, (string) $odmowa);
            $konto = $s->accounts->findById($kontoId);
            $sprawdz('hasło: nowe hasło weryfikuje się',
                $konto !== null && $s->password->verify($noweHaslo, $konto['password_hash']));
            $sprawdz('hasło: data zmiany zapisana', $konto !== null && $konto['password_changed_at'] !== null);
            // ⚠ To jest ten warunek, przez który „ta przeglądarka zostaje" jest wykonalne.
            $st = $pdo->prepare('SELECT revoked_at IS NULL AS zyje FROM session WHERE id = :id');
            $st->execute(['id' => $sesje['ta']]);
            $sprawdz('hasło: BIEŻĄCA sesja zostaje', (int) $st->fetchColumn() === 1);
            $st->execute(['id' => $sesje['obca']]);
            $sprawdz('hasło: pozostałe sesje zakończone', (int) $st->fetchColumn() === 0);

            // ── 4. Urządzenia: nie da się zakończyć cudzej sesji ───────────────────────────
            // Sesja INNEGO konta — z identyfikatorem podstawionym wprost, czyli dokładnie tak,
            // jak wyglądałby atak przez podmianę pola `session` w formularzu (IDOR).
            $inneKonto = (int) $pdo->query(
                "SELECT id FROM account WHERE id <> {$kontoId} AND status = 'active' ORDER BY id LIMIT 1"
            )->fetchColumn();
            $obcaSesja = DatabaseSessionHandler::hash('sesja-cudza-' . $sufiks);
            $st = $pdo->prepare(
                "INSERT INTO session (id, account_id, client, payload, ip_hash, ip_prefix, user_agent,
                                      created_at, last_seen_at, expires_at)
                 VALUES (:id, :konto, 'web', '', :ip, '203.0.113.0/24', 'Test', UTC_TIMESTAMP(),
                         UTC_TIMESTAMP(), UTC_TIMESTAMP() + INTERVAL 3600 SECOND)"
            );
            $st->execute(['id' => $obcaSesja, 'konto' => $inneKonto, 'ip' => str_repeat('c', 64)]);
            $udalo = $s->devices->revoke($kontoId, $obcaSesja, 'sesja-ta-' . $sufiks, str_repeat('b', 64));
            $sprawdz('urządzenia: cudzej sesji NIE da się zakończyć', $udalo === false);
            $st = $pdo->prepare('SELECT revoked_at IS NULL AS zyje FROM session WHERE id = :id');
            $st->execute(['id' => $obcaSesja]);
            $sprawdz('urządzenia: cudza sesja dalej żyje', (int) $st->fetchColumn() === 1);
            $pdo->prepare('DELETE FROM session WHERE id = :id')->execute(['id' => $obcaSesja]);

            // ── 5. Drugi składnik ──────────────────────────────────────────────────────────
            $twoFactor = $s->twoFactor(new \Wezurlop\Http\Session());
            $sprawdz('2FA: kodów zapasowych jest ' . BackupCodes::COUNT . ' (reguła z CLAUDE.md §9)',
                BackupCodes::COUNT === 8, (string) BackupCodes::COUNT);

            // Konto z włączonym 2FA i jednym kodem zapasowym — bez parowania aplikacji,
            // bo sprawdzamy drogę kodu zapasowego, nie generowanie sekretu.
            $kod = BackupCodes::generate()[0];
            $pdo->prepare("UPDATE account SET totp_enabled_at = UTC_TIMESTAMP() WHERE id = {$kontoId}")->execute();
            $st = $pdo->prepare('INSERT INTO backup_code (account_id, code_hash) VALUES (:k, :h)');
            $st->execute(['k' => $kontoId, 'h' => $s->password->hash(BackupCodes::normalize($kod))]);

            $sprawdz('2FA: kod zapasowy działa raz — pierwsze użycie',
                $twoFactor->verifyBackupCode($kontoId, $kod, str_repeat('d', 64)));
            $sprawdz('2FA: ten sam kod drugi raz NIE działa',
                !$twoFactor->verifyBackupCode($kontoId, $kod, str_repeat('d', 64)));

            // Wyłączenie: najpierw przy wymogu (odmowa), potem bez kodu (odmowa).
            $odmowa = $twoFactor->disable($kontoId, $noweHaslo, '000000', str_repeat('d', 64), false, true);
            $sprawdz('2FA: wymóg administratora blokuje wyłączenie',
                $odmowa === TwoFactorService::ODMOWA_WYMOGU, (string) $odmowa);
            $sprawdz('2FA: po odmowie wymogu nadal włączone',
                $twoFactor->isEnabled($kontoId));
            $odmowa = $twoFactor->disable($kontoId, $noweHaslo, '000000', str_repeat('e', 64));
            $sprawdz('2FA: wyłączenie bez poprawnego kodu → odmowa', $odmowa !== null, (string) $odmowa);
            $sprawdz('2FA: po nieudanej próbie nadal włączone', $twoFactor->isEnabled($kontoId));

            // Nowe kody: bez hasła nie ma zestawu.
            $odmowa = $twoFactor->regenerateCodes($kontoId, $noweHaslo . 'ZLE', str_repeat('f', 64));
            $sprawdz('2FA: nowe kody ze złym hasłem → odmowa', $odmowa !== null, (string) $odmowa);
            $sprawdz('2FA: nieudane żądanie nie ruszyło kodów',
                $twoFactor->unusedBackupCodes($kontoId) === 0, (string) $twoFactor->unusedBackupCodes($kontoId));
        } finally {
            // ⚠ Sprzątanie w `finally`, więc dzieje się także po wyjątku: instancja robocza jest
            // używana, a osoba próbna zostawiona w bazie wchodzi na listy i do pomiarów bramki.
            //
            // ⚠ **Każde usunięcie OSOBNO i odporne na błąd poprzedniego** — nauczka z tego samego
            // przebiegu, 21.10.2026: pierwszy `DELETE` trafił w nieistniejącą kolumnę, wywrócił
            // `finally` i zabrał ze sobą pozostałe. Po takim przebiegu w bazie roboczej została
            // osoba i konto próbne, które trzeba było dokasować ręcznie. Sprzątanie nie ma prawa
            // przerwać się w połowie z powodu literówki w jednym zapytaniu.
            $usun = static function (string $sql, array $par) use ($pdo, $l): void {
                try {
                    $pdo->prepare($sql)->execute($par);
                } catch (\Throwable $e) {
                    $l->error('sprzątanie testu nie wykonało kroku', ['sql' => $sql, 'blad' => $e->getMessage()]);
                }
            };
            if ($kontoId > 0) {
                $usun('DELETE FROM backup_code WHERE account_id = :k', ['k' => $kontoId]);
                $usun('DELETE FROM session WHERE account_id = :k', ['k' => $kontoId]);
                $usun('DELETE FROM event_log WHERE account_id = :k', ['k' => $kontoId]);
                $usun('DELETE FROM login_attempt WHERE account_id = :k', ['k' => $kontoId]);
                $usun('DELETE FROM account WHERE id = :k', ['k' => $kontoId]);
            }
            if ($osobaId > 0) {
                $usun('DELETE FROM employment WHERE person_id = :p', ['p' => $osobaId]);
                $usun('DELETE FROM person WHERE id = :p', ['p' => $osobaId]);
            }
        }

        echo $bledy === 0 ? "Wszystko OK\n" : "Nieudane: {$bledy}\n";

        return $bledy === 0 ? 0 : 1;
    },
    STDERR,
));
