<?php

declare(strict_types=1);

use Phinx\Seed\AbstractSeed;
use Wezurlop\Security\Password;
use Wezurlop\Service\Calendar\WorkingCalendar;
use Wezurlop\Support\EnvFile;

/**
 * Dane deweloperskie (docs/szkielet-tenanta.md rozdz. 5; Etap 3, E1). Fikcyjne — żadnych prawdziwych
 * danych kadrowych. Identyfikatory R2 (207, 311, urlop:12315 …) jak w docs/przyklady-api.md, żeby
 * przykłady API dało się odtworzyć na tej bazie.
 *
 * - dwie firmy z NIP-ami o poprawnej sumie kontrolnej: A 1234567009, B 9876543210 (NIP B występuje w przykładzie
 *   zgłoszenia firmy — firma istnieje, ale klucz symulatora jej nie obejmuje),
 * - administrator tenanta bez zatrudnienia (rola na całego tenanta, company_id = 0),
 * - osoba z DWOMA aktywnymi zatrudnieniami (po pół etatu w każdej firmie),
 * - hierarchia trzypoziomowa w firmie A: Zenon → Jan → Anna, Piotr; w firmie B: Beata → Anna,
 * - limity na rok bieżący (w minutach — wartość wiążąca), słowniki trzech źródeł R2,
 * - kilka nieobecności zewnętrznych i zaakceptowane wnioski, z których API wyda zadania.
 *
 * Hasła losowe, wypisywane jeden raz. Uruchamia się wyłącznie przy APP_ENV=dev i na pustej bazie.
 */
final class DevSeeder extends AbstractSeed
{
    public function run(): void
    {
        $env = EnvFile::load(EnvFile::path(dirname(__DIR__, 2), []));
        if (($env['APP_ENV'] ?? '') !== 'dev') {
            throw new RuntimeException('DevSeeder działa wyłącznie przy APP_ENV=dev');
        }
        if ((int) $this->fetchRow('SELECT COUNT(*) AS n FROM company')['n'] > 0) {
            throw new RuntimeException('Baza nie jest pusta — DevSeeder działa tylko na czystej bazie (phinx rollback -t 0 && phinx migrate)');
        }

        // Hasła przez tę samą klasę co aplikacja (SHA-256 + base64 przed bcryptem, CLAUDE.md §9).
        $hasher = new Password(dirname(__DIR__, 2) . '/var/cache/password-dummy.hash');
        $year = (int) gmdate('Y');

        $roles = [];
        foreach ($this->fetchAll('SELECT id, code FROM role') as $row) {
            $roles[$row['code']] = (int) $row['id'];
        }
        $now = gmdate('Y-m-d H:i:s');
        $nowMicro = DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', microtime(true)))->format('Y-m-d H:i:s.u');

        $companyA = $this->insertRow('company', ['name' => 'Przykładowa Spółka A sp. z o.o.', 'nip' => '1234567009']);
        $companyB = $this->insertRow('company', ['name' => 'Przykładowa Spółka B sp. z o.o.', 'nip' => '9876543210']);

        // --- osoby -------------------------------------------------------------------------------
        // osobaId: skrót liczony u klienta (tu fikcyjny); Anna ma ten sam w obu firmach.
        $people = [
            'admin' => ['first_name' => 'Adam',   'last_name' => 'Administrator', 'external' => null],
            'anna'  => ['first_name' => 'Anna',   'last_name' => 'Dwuetatowa',    'external' => 'hmac:' . str_repeat('a1', 32)],
            'jan'   => ['first_name' => 'Jan',    'last_name' => 'Jednoetatowy',  'external' => 'hmac:' . str_repeat('b2', 32)],
            'zenon' => ['first_name' => 'Zenon',  'last_name' => 'Zarządca',      'external' => 'hmac:' . str_repeat('c3', 32)],
            'piotr' => ['first_name' => 'Piotr',  'last_name' => 'Próbny',        'external' => 'hmac:' . str_repeat('d4', 32)],
            'beata' => ['first_name' => 'Beata',  'last_name' => 'Bierska',       'external' => 'hmac:' . str_repeat('e5', 32)],
        ];
        foreach ($people as $key => $p) {
            $people[$key]['id'] = $this->insertRow('person', [
                'first_name'         => $p['first_name'],
                'last_name'          => $p['last_name'],
                'phone'              => null,
                'external_person_id' => $p['external'],
            ]);
            if ($p['external'] !== null) {
                $this->table('person_external_ref')->insert(['external_person_id' => $p['external'], 'person_id' => $people[$key]['id']])->saveData();
            }
        }

        // --- konta (tylko trzy osoby się logują) ------------------------------------------------
        $accounts = [];
        $passwords = [];
        foreach (['admin' => 'admin@wezurlop.test', 'anna' => 'anna@wezurlop.test', 'jan' => 'jan@wezurlop.test'] as $key => $email) {
            $password = bin2hex(random_bytes(9));
            $passwords[$email] = $password;
            $accounts[$key] = $this->insertRow('account', [
                'person_id'           => $people[$key]['id'],
                'email'               => $email,
                'password_hash'       => $hasher->hash($password),
                'status'              => 'active',
                'password_changed_at' => $nowMicro,
            ]);
        }

        // --- zatrudnienia z hierarchią -----------------------------------------------------------
        $employment = [];
        $employments = [
            // klucz, osoba, firma, r2_employment_ref, numer, etat, przełożony (klucz)
            ['zenon_a', 'zenon', $companyA, '101',  'A/0001', '1.0000', null],
            ['jan_a',   'jan',   $companyA, '311',  'A/0002', '1.0000', 'zenon_a'],
            ['anna_a',  'anna',  $companyA, '207',  'A/0003', '0.5000', 'jan_a'],
            ['piotr_a', 'piotr', $companyA, '312',  'A/0004', '0.7500', 'jan_a'],
            ['beata_b', 'beata', $companyB, '5001', 'B/0001', '1.0000', null],
            ['anna_b',  'anna',  $companyB, '5021', 'B/0002', '0.5000', 'beata_b'],
        ];
        foreach ($employments as [$key, $person, $company, $ref, $number, $fte, $supervisor]) {
            [$num, $den] = $fte === '0.5000' ? [1, 2] : ($fte === '0.7500' ? [3, 4] : [1, 1]);
            $employment[$key] = $this->insertRow('employment', [
                'person_id'                => $people[$person]['id'],
                'company_id'               => $company,
                'supervisor_employment_id' => $supervisor === null ? null : $employment[$supervisor],
                'r2_employment_ref'        => $ref,
                'employee_number'          => $number,
                'hired_at'                 => '2024-01-02',
                'fte_numerator'            => $num,
                'fte_denominator'          => $den,
                'fte'                      => $fte,
                'r2_calendar_ref'          => '2',
                'is_active'                => 1,
            ]);
        }

        $assignments = [
            ['account' => 'admin', 'role' => 'tenant_admin', 'company' => 0],
            ['account' => 'anna',  'role' => 'employee',     'company' => $companyA],
            ['account' => 'anna',  'role' => 'employee',     'company' => $companyB],
            ['account' => 'jan',   'role' => 'employee',     'company' => $companyA],
        ];
        foreach ($assignments as $a) {
            $this->insertRow('role_assignment', [
                'account_id' => $accounts[$a['account']],
                'role_id'    => $roles[$a['role']],
                'company_id' => $a['company'],
                'granted_at' => $now,
            ]);
        }

        // --- słowniki trzech źródeł R2 (⚠ kody nakładają się między źródłami) --------------------
        $types = [];
        $dictionary = [
            // źródło, kod, nazwa, doWniosku, tylkoOdczyt, nieobecnosc, do limitu, próg eskalacji (h)
            ['urlop', 0, 'urlop wypoczynkowy', true, false, true, true, 48],
            ['urlop', 1, 'urlop okolicznościowy', false, false, true, false, null],
            ['urlop', 2, 'opieka nad dzieckiem (art. 188)', false, false, true, false, null],
            ['urlop', 3, 'urlop dodatkowy', false, false, true, false, null],
            ['urlop', 4, 'nieobecność płatna', false, false, true, false, null],
            ['urlop', 5, 'urlop wyp. na żądanie', true, false, true, true, 4],
            ['urlop', 6, 'ekwiwalent za urlop', false, false, false, false, null],
            ['urlop', 7, 'urlop szkoleniowy', false, false, true, false, null],
            ['urlop', 8, 'ekwiwalent za urlop zaległy', false, false, false, false, null],
            ['kadrowe', 0, 'urlop bezpłatny', false, true, true, false, null],
            ['kadrowe', 1, 'urlop wychowawczy', false, true, true, false, null],
            ['kadrowe', 2, 'służba wojskowa', false, true, true, false, null],
            ['kadrowe', 3, 'nieobecność usprawiedliwiona', false, true, true, false, null],
            ['kadrowe', 4, 'nieobecność nieusprawiedliwiona', false, true, true, false, null],
            ['zasilek', 0, 'zasiłek chorobowy', false, true, true, false, null],
            ['zasilek', 1, 'zasiłek opiekuńczy', false, true, true, false, null],
            ['zasilek', 2, 'zasiłek macierzyński', false, true, true, false, null],
            ['zasilek', 3, 'zasiłek ojcowski', false, true, true, false, null],
            ['zasilek', 4, 'zasiłek rodzicielski', false, true, true, false, null],
            ['zasilek', 5, 'świadczenie rehabilitacyjne', false, true, true, false, null],
        ];
        foreach ($dictionary as [$source, $code, $name, $requestable, $readOnly, $absence, $limit, $hours]) {
            $types["$source:$code"] = $this->insertRow('leave_type', [
                'source' => $source, 'code' => $code, 'name' => $name,
                'requestable' => $requestable ? 1 : 0, 'read_only' => $readOnly ? 1 : 0,
                'is_absence' => $absence ? 1 : 0, 'counts_to_limit' => $limit ? 1 : 0, 'escalation_hours' => $hours,
            ]);
        }

        // --- limity na rok bieżący (minuty; dni = minuty ÷ 480 wyłącznie do wyświetlenia) -----------
        // pozostalo = dostepneNaStart − wykorzystane + korekty; może być ujemne.
        $balances = [
            // zatrudnienie, wymiar, zaległy, wykorzystane, korekty, na żądanie (dni)
            ['anna_a',  6240,  1440, 2400, 0,    0],   // pół etatu: 26 dni × 240 min
            ['anna_b',  6240,  0,    960,  0,    1],
            ['jan_a',   12480, 6120, 7200, 0,    2],
            ['piotr_a', 9360,  0,    9840, -480, 0],   // przekroczony limit i korekta ujemna
            ['zenon_a', 12480, 2400, 4800, 480,  0],
            ['beata_b', 12480, 960,  3840, 0,    0],
        ];
        foreach ($balances as [$key, $entitlement, $carried, $used, $adjust, $onDemand]) {
            $start = $entitlement + $carried;
            $this->insertRow('leave_balance', [
                'employment_id' => $employment[$key], 'year' => $year, 'kind' => 'wypoczynkowy', 'accrued' => 1,
                'entitlement_min' => $entitlement, 'carried_over_min' => $carried, 'available_start_min' => $start,
                'used_min' => $used, 'adjustments_min' => $adjust, 'remaining_min' => $start - $used + $adjust,
                'on_demand_used_days' => $onDemand,
            ]);
        }
        // limit na przyszły rok jeszcze nienaliczony (styczniowy stan normalny)
        $this->insertRow('leave_balance', [
            'employment_id' => $employment['jan_a'], 'year' => $year + 1, 'kind' => 'wypoczynkowy', 'accrued' => 0,
            'entitlement_min' => null, 'carried_over_min' => null, 'available_start_min' => null, 'used_min' => null,
            'adjustments_min' => null, 'remaining_min' => null, 'on_demand_used_days' => null,
        ]);

        // --- nieobecności zewnętrzne (tylko do odczytu, bez danych medycznych) ------------------------
        $external = [
            // zatrudnienie, osoba, typ, id R2, od, do, dni, minuty, kontynuacja, źródło wpisu
            // zasilek:512 na zatrudnieniu 207 — dokładnie jak w przykładzie 1.2 (paczka nie zmienia stanu)
            ['anna_a',  'anna',  'zasilek:0', 'zasilek:512', "$year-09-14", "$year-09-18", 5, null, false, 'kadry'],
            ['jan_a',   'jan',   'zasilek:0', 'zasilek:513', "$year-09-23", "$year-09-25", 3, null, false, 'kadry'],
            ['piotr_a', 'piotr', 'kadrowe:0', 'kadrowe:17',  "$year-08-03", "$year-08-07", 5, 3600, null, 'kadry'],
            ['anna_b',  'anna',  'urlop:0',   'urlop:900',   "$year-07-13", "$year-07-14", 2, 480,  null, 'kadry'],
            ['zenon_a', 'zenon', 'urlop:6',   'urlop:901',   "$year-06-30", "$year-06-30", 1, 480,  null, 'kadry'],
        ];
        foreach ($external as [$emp, $person, $type, $ref, $from, $to, $days, $minutes, $continuation, $source]) {
            $this->insertRow('external_absence', [
                'company_id' => str_ends_with($emp, '_b') ? $companyB : $companyA,
                'employment_id' => $employment[$emp], 'person_id' => $people[$person]['id'], 'leave_type_id' => $types[$type],
                'r2_record_id' => $ref, 'date_from' => $from, 'date_to' => $to, 'days' => $days, 'minutes' => $minutes,
                'continuation' => $continuation === null ? null : ($continuation ? 1 : 0), 'entry_source' => $source,
            ]);
        }

        // --- wnioski (tylko do obsługi API w tej turze; składanie przez UI to tura B) -----------------
        $calendar = new WorkingCalendar();
        $request = function (string $emp, string $type, string $from, string $to, string $status, array $extra = []) use ($employment, $types, $calendar, $now): int {
            $days = $calendar->workingDays(new DateTimeImmutable($from), new DateTimeImmutable($to));
            $id = $this->insertRow('leave_request', [
                'employment_id' => $employment[$emp], 'leave_type_id' => $types[$type],
                'date_from' => $from, 'date_to' => $to, 'days_requested' => count($days), 'status' => $status,
                'submitted_at' => $now, 'decided_at' => $status === 'submitted' ? null : $now,
            ] + $extra);
            foreach ($days as $day) {
                $this->table('leave_request_day')->insert(['leave_request_id' => $id, 'employment_id' => $employment[$emp], 'day' => $day])->saveData();
            }

            return $id;
        };
        // zaakceptowany, czeka na zapis w kadrach → zadanie „zapis” (przełom miesiąca w R2)
        $request('anna_a', 'urlop:0', "$year-10-29", "$year-11-03", 'approved');
        // potwierdzony w R2 (urlop:12315 z przykładu 1.2)
        $confirmed = $request('anna_a', 'urlop:0', "$year-10-05", "$year-10-09", 'confirmed', [
            'days_confirmed' => 5, 'minutes_confirmed' => 1200, 'confirmed_at' => $now,
        ]);
        // zadanie zapisu już wykonane — requestId z przykładu 1.2 wiąże wpis R2 z wnioskiem
        $this->insertRow('sync_task', [
            'company_id' => $companyA, 'leave_request_id' => $confirmed, 'request_id' => 'wu-01J9ZC8QK4T7', 'operation' => 'zapis',
            'status' => 'done', 'attempts' => 1, 'result_status' => 'zapisano', 'created_at' => $now, 'finished_at' => $now,
        ]);
        $this->insertRow('leave_request_r2_record', [
            'leave_request_id' => $confirmed, 'r2_record_id' => 'urlop:12315',
            'date_from' => "$year-10-05", 'date_to' => "$year-10-09", 'days' => 5, 'minutes' => 1200,
        ]);
        // na żądanie, potwierdzony, anulowanie zatwierdzone przez przełożonego → zadanie „usuniecie”
        $cancel = $request('jan_a', 'urlop:5', "$year-09-22", "$year-09-22", 'cancel_requested', [
            'days_confirmed' => 1, 'minutes_confirmed' => 480, 'confirmed_at' => $now,
            'cancel_requested_at' => $now, 'cancel_approved_at' => $now,
        ]);
        $this->insertRow('leave_request_r2_record', [
            'leave_request_id' => $cancel, 'r2_record_id' => 'urlop:12280',
            'date_from' => "$year-09-22", 'date_to' => "$year-09-22", 'days' => 1, 'minutes' => 480,
        ]);
        // złożony, jeszcze bez decyzji — NIE jedzie do kadr. ⚠ Każdy wniosek w seedzie mieści się w limicie:
        // sprawdzenie limitu dochodzi w turze B, a program kadrowy limitu wypoczynkowego nie blokuje
        // (Piotr ma limit przekroczony, więc wniosek jest Zenona).
        $request('zenon_a', 'urlop:0', "$year-12-28", "$year-12-30", 'submitted');

        // --- ustawienia ------------------------------------------------------------------------------
        $this->execute('UPDATE tenant_setting SET value = ? WHERE `key` = ?', ['alerty@wezurlop.test', 'alert_email']);
        // Na dev wariant mobilny otwiera się także w przeglądarce (docs/szkielet-tenanta.md rozdz. 8).
        $this->execute('UPDATE tenant_setting SET value = ? WHERE `key` = ?', ['open', 'mobile_access']);
        // Wyjątek firmowy (przykład): dzień wolny za święto przypadające w sobotę, tylko firma A.
        $this->execute('UPDATE tenant_setting SET value = ? WHERE `key` = ?', [
            json_encode([(string) $companyA => [sprintf('%04d-11-10', $year) => false]], JSON_THROW_ON_ERROR), 'calendar_exceptions',
        ]);

        $this->getOutput()->writeln('');
        $this->getOutput()->writeln('Konta deweloperskie (hasła pokazane jeden raz):');
        foreach ($passwords as $email => $password) {
            $this->getOutput()->writeln(sprintf('  %-22s %s', $email, $password));
        }
    }

    /** @param array<string, mixed> $row */
    private function insertRow(string $table, array $row): int
    {
        $this->table($table)->insert($row)->saveData();

        return (int) $this->getAdapter()->getConnection()->lastInsertId();
    }
}
