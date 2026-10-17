<?php

declare(strict_types=1);

namespace Wezurlop\Dev\Repository;

use Wezurlop\Db\Connection;

/**
 * Stan wyjściowy i odczyty kontrolne dla bin/sync-symulator.php — WYŁĄCZNIE środowisko deweloperskie
 * (polecenie odmawia pracy poza APP_ENV=dev). Zapisy omijają reguły domeny celowo: ustawiają świat
 * „jak po wcześniejszych cyklach”, którego w tej turze nie da się zbudować z interfejsu (wnioski — tura B).
 */
final class SyncFixtureRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** Kursory i licznik strumienia jak po wcześniejszych cyklach; przegląd kontrolny „przed chwilą”. */
    public function setStream(int $companyId, int $seq, array $cursors): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO sync_stream (company_id, cursor_seq, review_required, last_review_at) VALUES (:c, :seq, 0, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE cursor_seq = :seq2, review_required = 0, last_review_at = UTC_TIMESTAMP()'
        )->execute(['c' => $companyId, 'seq' => $seq, 'seq2' => $seq]);
        $pdo->prepare('DELETE FROM sync_cursor WHERE company_id = :c')->execute(['c' => $companyId]);
        $stmt = $pdo->prepare('INSERT INTO sync_cursor (company_id, zbior, cursor_token, updated_at) VALUES (:c, :z, :k, UTC_TIMESTAMP())');
        foreach ($cursors as $zbior => $cursor) {
            $stmt->execute(['c' => $companyId, 'z' => $zbior, 'k' => $cursor]);
        }
    }

    /**
     * Firmy w bazie — blokada symulatora sprawdza, czy nie ma tam NIP-u spoza seeda.
     *
     * @return list<array{id: int, nip: string, name: string}>
     */
    public function companies(): array
    {
        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'], 'nip' => (string) $r['nip'], 'name' => (string) $r['name'],
        ], $this->db->pdo()->query('SELECT id, nip, name FROM company ORDER BY id')->fetchAll());
    }

    public function employmentId(int $companyId, string $ref): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM employment WHERE company_id = :c AND r2_employment_ref = :r');
        $stmt->execute(['c' => $companyId, 'r' => $ref]);

        return (int) $stmt->fetchColumn();
    }

    public function leaveTypeId(string $source, int $code): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM leave_type WHERE source = :s AND code = :c');
        $stmt->execute(['s' => $source, 'c' => $code]);

        return (int) $stmt->fetchColumn();
    }

    /** Numer kadrowy osoby (`identyfikator` z rekordu `pracownik`). */
    public function personIdentifier(int $personId): ?string
    {
        $stmt = $this->db->pdo()->prepare('SELECT r2_identifier FROM person WHERE id = :id');
        $stmt->execute(['id' => $personId]);
        $value = $stmt->fetchColumn();

        return $value === false || $value === null ? null : (string) $value;
    }

    /** @return list<string> klucze ustawień tenanta — sprawdzenie listy domyślnych dla resetu instalacji */
    public function settingKeys(): array
    {
        return array_map('strval', $this->db->pdo()->query('SELECT `key` FROM tenant_setting ORDER BY `key`')->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Odciski tożsamości firmy: zaakceptowany i oczekujący na decyzję administratora.
     *
     * @return array{identity: ?string, pending: ?string}
     */
    public function identity(int $companyId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT identity_fingerprint, identity_fingerprint_pending FROM company WHERE id = :id'
        );
        $stmt->execute(['id' => $companyId]);
        $row = $stmt->fetch();

        return [
            'identity' => is_array($row) && $row['identity_fingerprint'] !== null ? (string) $row['identity_fingerprint'] : null,
            'pending' => is_array($row) && $row['identity_fingerprint_pending'] !== null ? (string) $row['identity_fingerprint_pending'] : null,
        ];
    }

    /**
     * Rodzaj nieobecności ze słownika — powiązanie z limitem (`obciazaLimit`) i jego pochodzenie.
     *
     * @return array{id: int, counts_to_limit: bool, limit_kind: ?string, limit_kind_source: ?string}|null
     */
    public function leaveType(string $source, int $code): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, counts_to_limit, limit_kind, limit_kind_source FROM leave_type WHERE source = :s AND code = :c'
        );
        $stmt->execute(['s' => $source, 'c' => $code]);
        $row = $stmt->fetch();

        return is_array($row) ? [
            'id' => (int) $row['id'], 'counts_to_limit' => (bool) $row['counts_to_limit'],
            'limit_kind' => $row['limit_kind'] === null ? null : (string) $row['limit_kind'],
            'limit_kind_source' => $row['limit_kind_source'] === null ? null : (string) $row['limit_kind_source'],
        ] : null;
    }

    /**
     * Kalendarz firmy z normami (`normaUrlopowaMin`, `normaDobowaMin`).
     *
     * @return array{leave_norm_min: ?int, daily_norm_min: ?int}|null
     */
    public function calendar(int $companyId, string $ref, int $year): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT leave_norm_min, daily_norm_min FROM company_calendar
             WHERE company_id = :company AND calendar_ref = :ref AND year = :year'
        );
        $stmt->execute(['company' => $companyId, 'ref' => $ref, 'year' => $year]);
        $row = $stmt->fetch();

        return is_array($row) ? [
            'leave_norm_min' => $row['leave_norm_min'] === null ? null : (int) $row['leave_norm_min'],
            'daily_norm_min' => $row['daily_norm_min'] === null ? null : (int) $row['daily_norm_min'],
        ] : null;
    }

    /**
     * Wniosek w zadanym stanie (składanie i decyzje przez interfejs to tura B).
     *
     * @param list<string> $days dni robocze wniosku
     * @param array<string, mixed> $extra kolumny z białej listy
     */
    public function createRequest(int $employmentId, int $typeId, string $from, string $to, string $status, array $days, array $extra = []): int
    {
        $allowed = ['days_confirmed', 'minutes_confirmed', 'confirmed_at', 'cancel_requested_at', 'cancel_approved_at', 'cancelled_at'];
        $cols = ['employment_id', 'leave_type_id', 'date_from', 'date_to', 'days_requested', 'status', 'submitted_at', 'decided_at', 'created_at', 'updated_at'];
        $params = [
            'employment_id' => $employmentId, 'leave_type_id' => $typeId, 'date_from' => $from, 'date_to' => $to,
            'days_requested' => count($days), 'status' => $status, 'submitted_at' => gmdate('Y-m-d H:i:s'),
            'decided_at' => gmdate('Y-m-d H:i:s'), 'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s'),
        ];
        foreach ($extra as $col => $value) {
            if (in_array($col, $allowed, true)) {
                $cols[] = $col;
                $params[$col] = $value;
            }
        }
        $this->db->pdo()->prepare(
            'INSERT INTO leave_request (' . implode(', ', $cols) . ') VALUES (:' . implode(', :', $cols) . ')'
        )->execute($params);
        $id = (int) $this->db->pdo()->lastInsertId();
        $stmt = $this->db->pdo()->prepare('INSERT INTO leave_request_day (leave_request_id, employment_id, day) VALUES (:r, :e, :d)');
        foreach ($days as $day) {
            $stmt->execute(['r' => $id, 'e' => $employmentId, 'd' => $day]);
        }
        $this->db->pdo()->prepare(
            "INSERT INTO leave_decision (leave_request_id, decided_by_account_id, decision, from_status, to_status, reason, decided_at)
             VALUES (:r, NULL, 'fixture', 'submitted', :s, 'symulator', UTC_TIMESTAMP())"
        )->execute(['r' => $id, 's' => $status]);

        return $id;
    }

    public function addR2Record(int $requestId, string $r2Id, string $from, string $to, int $days, int $minutes): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO leave_request_r2_record (leave_request_id, r2_record_id, date_from, date_to, days, minutes)
             VALUES (:r, :id, :f, :t, :d, :m)'
        )->execute(['r' => $requestId, 'id' => $r2Id, 'f' => $from, 't' => $to, 'd' => $days, 'm' => $minutes]);
    }

    public function setRequestStatus(int $requestId, string $status): void
    {
        $this->db->pdo()->prepare('UPDATE leave_request SET status = :s, updated_at = UTC_TIMESTAMP() WHERE id = :id')
            ->execute(['s' => $status, 'id' => $requestId]);
    }

    /** Blokada wiersza strumienia firmy w otwartej transakcji wywołującego (test 503 przy zakleszczeniu/blokadzie). */
    public function lockStream(int $companyId): void
    {
        $stmt = $this->db->pdo()->prepare('SELECT cursor_seq FROM sync_stream WHERE company_id = :c FOR UPDATE');
        $stmt->execute(['c' => $companyId]);
        $stmt->fetchAll();
    }

    /** Cofa znacznik ostatniego żądania przebiegu — test wygaśnięcia bez czekania 30 minut. */
    public function ageRun(string $runUuid, int $minutes): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE sync_run SET updated_at = updated_at - INTERVAL :minutes MINUTE WHERE run_uuid = :uuid'
        );
        $stmt->bindValue('minutes', max(1, $minutes), \PDO::PARAM_INT);
        $stmt->bindValue('uuid', $runUuid);
        $stmt->execute();
    }

    /**
     * Zadania wniosku — do sprawdzenia niezmiennika „zapis i usuniecie nigdy w jednym cyklu”.
     *
     * @return list<array{request_id: string, operation: string, status: string}>
     */
    public function tasksFor(int $leaveRequestId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT request_id, operation, status FROM sync_task WHERE leave_request_id = :r ORDER BY id'
        );
        $stmt->execute(['r' => $leaveRequestId]);

        return $stmt->fetchAll();
    }

    /** Rezerwacja zadania minęła — zadanie wraca do wydania (test wymiany klucza w locie). */
    public function expireReservation(string $requestId): void
    {
        $this->db->pdo()->prepare(
            "UPDATE sync_task SET reserved_until = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE request_id = :r AND status = 'issued'"
        )->execute(['r' => $requestId]);
    }

    // --- odczyty kontrolne ---------------------------------------------------------------------------

    /** @return array<string, mixed>|null treść ostatniego konfliktu wniosku */
    public function conflictDetail(int $leaveRequestId): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT detail FROM sync_conflict WHERE leave_request_id = :r ORDER BY id DESC LIMIT 1');
        $stmt->execute(['r' => $leaveRequestId]);
        $detail = $stmt->fetchColumn();

        return $detail === false || $detail === null ? null : json_decode((string) $detail, true);
    }

    /** @return array<string, mixed>|null */
    public function request(int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, status, days_confirmed, minutes_confirmed, r2_warning_code, r2_error_code, changed_in_r2, updated_at
             FROM leave_request WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /** @return list<array<string, mixed>> */
    public function r2Records(int $requestId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT r2_record_id, date_from, date_to, days, minutes, removed_at IS NOT NULL AS removed
             FROM leave_request_r2_record WHERE leave_request_id = :id ORDER BY r2_record_id'
        );
        $stmt->execute(['id' => $requestId]);

        return $stmt->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function task(string $requestId): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT leave_request_id, operation, status, attempts, result_status FROM sync_task WHERE request_id = :r');
        $stmt->execute(['r' => $requestId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    public function count(string $what, int $id = 0, string $text = ''): int
    {
        // Nazwy zapytań z białej listy; parametry przez placeholdery.
        $sql = [
            'conflicts'          => 'SELECT COUNT(*) FROM sync_conflict WHERE company_id = :id',
            'decisions'          => 'SELECT COUNT(*) FROM leave_decision WHERE leave_request_id = :id',
            'decisions_all'      => 'SELECT COUNT(*) FROM leave_decision',
            'requests_changed'   => 'SELECT COUNT(*) FROM leave_request WHERE updated_at > :t',
            'signups'            => 'SELECT COUNT(*) FROM company_signup WHERE nip = :t',
            'jobs'               => 'SELECT COUNT(*) FROM job WHERE type = :t',
            'records'            => 'SELECT COUNT(*) FROM sync_record WHERE company_id = :id',
            'external_active'    => 'SELECT COUNT(*) FROM external_absence WHERE company_id = :id AND removed_at IS NULL AND out_of_window = 0',
            'external_all'       => 'SELECT COUNT(*) FROM external_absence WHERE company_id = :id',
            'employments_active' => 'SELECT COUNT(*) FROM employment WHERE company_id = :id AND is_active = 1',
            'r2_records_all'     => 'SELECT COUNT(*) FROM leave_request_r2_record',
            'runs'               => 'SELECT COUNT(*) FROM sync_run WHERE company_id = :id',
            'events'             => 'SELECT COUNT(*) FROM event_log WHERE event_code = :t',
        ][$what];
        $params = [];
        if (str_contains($sql, ':id')) {
            $params['id'] = $id;
        }
        if (str_contains($sql, ':t')) {
            $params['t'] = $text;
        }
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /** @return list<array{kind: string, leave_request_id: ?int}> */
    public function conflicts(int $companyId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT kind, leave_request_id FROM sync_conflict WHERE company_id = :c ORDER BY id');
        $stmt->execute(['c' => $companyId]);

        return $stmt->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function run(string $uuid): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT status, phase, stats, connector_summary FROM sync_run WHERE run_uuid = :u');
        $stmt->execute(['u' => $uuid]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }
        $row['stats'] = json_decode((string) $row['stats'], true);

        return $row;
    }

    public function recordState(int $companyId, string $zbior, string $id): ?string
    {
        $stmt = $this->db->pdo()->prepare('SELECT state FROM sync_record WHERE company_id = :c AND zbior = :z AND record_id = :r');
        $stmt->execute(['c' => $companyId, 'z' => $zbior, 'r' => $id]);
        $state = $stmt->fetchColumn();

        return $state === false ? null : (string) $state;
    }

    /** @return array{removed: bool, out_of_window: bool}|null */
    public function externalAbsence(int $companyId, string $r2Id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT removed_at IS NOT NULL AS removed, out_of_window, days, minutes
             FROM external_absence WHERE company_id = :c AND r2_record_id = :r'
        );
        $stmt->execute(['c' => $companyId, 'r' => $r2Id]);
        $row = $stmt->fetch();

        return is_array($row) ? [
            'removed' => (bool) $row['removed'], 'out_of_window' => (bool) $row['out_of_window'],
            'days' => $row['days'] === null ? null : (int) $row['days'],
            'minutes' => $row['minutes'] === null ? null : (int) $row['minutes'],
        ] : null;
    }

    /** @return array{is_active: bool, account_status: ?string}|null */
    public function employment(int $companyId, string $ref): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT e.is_active, (SELECT a.status FROM account a WHERE a.person_id = e.person_id LIMIT 1) AS account_status
             FROM employment e WHERE e.company_id = :c AND e.r2_employment_ref = :r'
        );
        $stmt->execute(['c' => $companyId, 'r' => $ref]);
        $row = $stmt->fetch();

        return is_array($row) ? ['is_active' => (bool) $row['is_active'], 'account_status' => $row['account_status'] === null ? null : (string) $row['account_status']] : null;
    }

    /** @return array{osobaId: ?string, imie: string, nazwisko: string} */
    public function personOf(int $companyId, string $ref): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT p.id, p.external_person_id, p.first_name, p.last_name FROM employment e JOIN person p ON p.id = e.person_id
             WHERE e.company_id = :c AND e.r2_employment_ref = :r'
        );
        $stmt->execute(['c' => $companyId, 'r' => $ref]);
        $row = $stmt->fetch();

        return [
            'id' => (int) $row['id'],
            'osobaId' => $row['external_person_id'] === null ? null : (string) $row['external_person_id'],
            'imie' => (string) $row['first_name'], 'nazwisko' => (string) $row['last_name'],
        ];
    }

    /** @return array{out_of_window: bool}|null */
    public function balance(int $employmentId, int $year): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT out_of_window FROM leave_balance WHERE employment_id = :e AND year = :y');
        $stmt->execute(['e' => $employmentId, 'y' => $year]);
        $v = $stmt->fetchColumn();

        return $v === false ? null : ['out_of_window' => (bool) $v];
    }

    public function dbTime(): string
    {
        return (string) $this->db->pdo()->query('SELECT UTC_TIMESTAMP()')->fetchColumn();
    }
}
