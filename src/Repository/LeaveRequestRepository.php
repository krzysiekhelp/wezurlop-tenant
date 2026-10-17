<?php

declare(strict_types=1);

namespace Wezurlop\Repository;

use Wezurlop\Db\Connection;

/**
 * Wnioski urlopowe od strony aplikacji (składanie, decyzje). SQL wyłącznie tutaj (CLAUDE.md §4).
 * Odczyty stanu synchronizacji są w SyncTaskRepository — tamten patrzy od strony connectora, ten od strony
 * pracownika i przełożonego.
 */
final class LeaveRequestRepository
{
    /** Stany, w których wniosek zajmuje termin i dni z limitu, choć nie ma jeszcze potwierdzenia z kadr. */
    public const PENDING_STATUSES = ['submitted', 'approved', 'sync_failed', 'confirmed', 'cancel_requested'];

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Zatrudnienie po identyfikatorze z R2 (r2_employment_ref) — tylko takie, które connector już przysłał.
     *
     * @return array{id: int, company_id: int, person_id: int, is_active: bool, terminated_at: ?string, hired_at: ?string, fte: string, r2_calendar_ref: ?string}|null
     */
    public function employmentByRef(string $ref): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, company_id, person_id, is_active, terminated_at, hired_at, fte, r2_calendar_ref
             FROM employment WHERE r2_employment_ref = :ref ORDER BY id LIMIT 1'
        );
        $stmt->execute(['ref' => $ref]);
        $row = $stmt->fetch();

        return is_array($row) ? [
            'id' => (int) $row['id'], 'company_id' => (int) $row['company_id'], 'person_id' => (int) $row['person_id'],
            'is_active' => (bool) $row['is_active'], 'terminated_at' => $row['terminated_at'] === null ? null : (string) $row['terminated_at'],
            'hired_at' => $row['hired_at'] === null ? null : (string) $row['hired_at'], 'fte' => (string) $row['fte'],
            'r2_calendar_ref' => $row['r2_calendar_ref'] === null ? null : (string) $row['r2_calendar_ref'],
        ] : null;
    }

    /** @return array{id: int, name: string, requestable: bool, read_only: bool, counts_to_limit: bool, limit_kind: ?string, limit_kind_source: ?string}|null */
    public function leaveType(string $source, int $code): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, name, requestable, read_only, counts_to_limit, limit_kind, limit_kind_source
             FROM leave_type WHERE source = :s AND code = :c'
        );
        $stmt->execute(['s' => $source, 'c' => $code]);
        $row = $stmt->fetch();

        return is_array($row) ? [
            'id' => (int) $row['id'], 'name' => (string) $row['name'], 'requestable' => (bool) $row['requestable'],
            'read_only' => (bool) $row['read_only'], 'counts_to_limit' => (bool) $row['counts_to_limit'],
            'limit_kind' => $row['limit_kind'] === null ? null : (string) $row['limit_kind'],
            'limit_kind_source' => $row['limit_kind_source'] === null ? null : (string) $row['limit_kind_source'],
        ] : null;
    }

    /**
     * Saldo roku z blokadą wiersza — czytane w tej samej transakcji, w której wniosek powstaje.
     *
     * @return array{id: int, accrued: bool, remaining_min: ?int, out_of_window: bool}|null
     */
    public function balanceForUpdate(int $employmentId, int $year, string $kind = 'wypoczynkowy'): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, accrued, remaining_min, out_of_window FROM leave_balance
             WHERE employment_id = :e AND year = :y AND kind = :k FOR UPDATE'
        );
        $stmt->execute(['e' => $employmentId, 'y' => $year, 'k' => $kind]);
        $row = $stmt->fetch();

        return is_array($row) ? [
            'id' => (int) $row['id'], 'accrued' => (bool) $row['accrued'],
            'remaining_min' => $row['remaining_min'] === null ? null : (int) $row['remaining_min'],
            'out_of_window' => (bool) $row['out_of_window'],
        ] : null;
    }

    /** Minuty wniosków, które zajmują limit roku, a nie są jeszcze rozliczone w kadrach (dni × minuty dnia). */
    /**
     * ⚠ Dzielnik minut na dni bierzemy z normy kalendarza zatrudnienia (`normaUrlopowaMin`), a stała
     * jest tylko wartością zapasową podawaną przez wołającego — inaczej u firmy z dniówką 12 h rezerwacja
     * liczyłaby się inaczej niż sam wniosek.
     */
    public function reservedMinutes(int $employmentId, int $year, int $defaultDayMinutes): int
    {
        $placeholders = [];
        $params = ['e' => $employmentId, 'from' => $year . '-01-01', 'to' => $year . '-12-31', 'norm' => $defaultDayMinutes];
        foreach (self::PENDING_STATUSES as $i => $status) {
            $placeholders[] = ':s' . $i;
            $params['s' . $i] = $status;
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT COALESCE(SUM(COALESCE(r.minutes_confirmed, d.dni * ROUND(COALESCE(c.leave_norm_min, :norm) * e.fte))), 0)
             FROM leave_request r
             JOIN employment e ON e.id = r.employment_id
             LEFT JOIN company_calendar c ON c.company_id = e.company_id AND c.calendar_ref = e.r2_calendar_ref
                  AND c.year = YEAR(r.date_from)
             JOIN leave_type t ON t.id = r.leave_type_id
             JOIN (SELECT leave_request_id, COUNT(*) AS dni FROM leave_request_day GROUP BY leave_request_id) d
                  ON d.leave_request_id = r.id
             WHERE r.employment_id = :e AND t.counts_to_limit = 1 AND r.status IN (' . implode(', ', $placeholders) . ')
               AND r.date_from >= :from AND r.date_from <= :to'
        );
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Dni tego zatrudnienia zajęte innym wnioskiem (termin zajęty) — po dniach, nie po zakresie dat.
     *
     * @param list<string> $days
     * @return list<string>
     */
    public function busyDays(int $employmentId, array $days): array
    {
        if ($days === []) {
            return [];
        }
        $dayPlaceholders = [];
        $statusPlaceholders = [];
        $params = ['e' => $employmentId];
        foreach ($days as $i => $day) {
            $dayPlaceholders[] = ':d' . $i;
            $params['d' . $i] = $day;
        }
        foreach (self::PENDING_STATUSES as $i => $status) {
            $statusPlaceholders[] = ':s' . $i;
            $params['s' . $i] = $status;
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT DISTINCT dd.day FROM leave_request_day dd JOIN leave_request r ON r.id = dd.leave_request_id
             WHERE dd.employment_id = :e AND dd.day IN (' . implode(', ', $dayPlaceholders) . ')
               AND r.status IN (' . implode(', ', $statusPlaceholders) . ') ORDER BY dd.day'
        );
        $stmt->execute($params);

        return array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** Nieobecność wpisana w kadrach, pokrywająca którykolwiek dzień wniosku (dla osoby, we wszystkich firmach). */
    public function externalAbsenceKolizja(int $personId, string $from, string $to): ?string
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT CONCAT(t.name, \' \', a.date_from, \'–\', a.date_to) FROM external_absence a JOIN leave_type t ON t.id = a.leave_type_id
             WHERE a.person_id = :person AND a.removed_at IS NULL AND a.out_of_window = 0
               AND a.date_from <= :to AND a.date_to >= :from ORDER BY a.date_from LIMIT 1'
        );
        $stmt->execute(['person' => $personId, 'from' => $from, 'to' => $to]);
        $hit = $stmt->fetchColumn();

        return $hit === false ? null : (string) $hit;
    }

    /**
     * Zapis wniosku razem z dniami i wpisem do historii decyzji — wołane w transakcji serwisu.
     *
     * @param list<string> $days
     */
    public function create(int $employmentId, int $leaveTypeId, string $from, string $to, array $days, ?int $accountId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO leave_request (employment_id, leave_type_id, date_from, date_to, days_requested, status,
                created_by_account_id, submitted_at, created_at, updated_at)
             VALUES (:e, :t, :from, :to, :days, \'submitted\', :account, UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        );
        $stmt->execute(['e' => $employmentId, 't' => $leaveTypeId, 'from' => $from, 'to' => $to, 'days' => count($days), 'account' => $accountId]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $day = $this->db->pdo()->prepare('INSERT INTO leave_request_day (leave_request_id, employment_id, day) VALUES (:r, :e, :d)');
        foreach ($days as $value) {
            $day->execute(['r' => $id, 'e' => $employmentId, 'd' => $value]);
        }

        return $id;
    }

    /**
     * Zmiana stanu wniosku z kontrolą stanu wyjściowego (maszyna stanów jest jedynym źródłem prawdy)
     * plus wpis do historii decyzji.
     *
     * @param array<string, string> $timestamps kolumny znaczników z białej listy
     */
    public function changeStatus(int $requestId, string $from, string $to, string $decision, ?int $accountId, ?string $reason, array $timestamps = []): bool
    {
        $allowed = ['decided_at' => 'decided_at', 'confirmed_at' => 'confirmed_at', 'cancel_requested_at' => 'cancel_requested_at',
            'cancel_approved_at' => 'cancel_approved_at', 'cancelled_at' => 'cancelled_at'];
        $sets = ['status = :to', 'updated_at = UTC_TIMESTAMP()'];
        foreach ($timestamps as $column => $value) {
            if (isset($allowed[$column])) {
                $sets[] = $allowed[$column] . ' = ' . ($value === 'now' ? 'UTC_TIMESTAMP()' : 'NULL');
            }
        }
        $stmt = $this->db->pdo()->prepare('UPDATE leave_request SET ' . implode(', ', $sets) . ' WHERE id = :id AND status = :from');
        $stmt->execute(['to' => $to, 'id' => $requestId, 'from' => $from]);
        if ($stmt->rowCount() !== 1) {
            return false;
        }
        $this->db->pdo()->prepare(
            'INSERT INTO leave_decision (leave_request_id, decided_by_account_id, decision, from_status, to_status, reason, decided_at)
             VALUES (:r, :account, :decision, :from, :to, :reason, UTC_TIMESTAMP())'
        )->execute(['r' => $requestId, 'account' => $accountId, 'decision' => $decision, 'from' => $from, 'to' => $to, 'reason' => $reason]);

        return true;
    }

    /** @return array{id: int, employment_id: int, status: string, date_from: string, date_to: string, days_requested: int}|null */
    public function find(int $requestId): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id, employment_id, status, date_from, date_to, days_requested FROM leave_request WHERE id = :id');
        $stmt->execute(['id' => $requestId]);
        $row = $stmt->fetch();

        return is_array($row) ? [
            'id' => (int) $row['id'], 'employment_id' => (int) $row['employment_id'], 'status' => (string) $row['status'],
            'date_from' => (string) $row['date_from'], 'date_to' => (string) $row['date_to'], 'days_requested' => (int) $row['days_requested'],
        ] : null;
    }
}
