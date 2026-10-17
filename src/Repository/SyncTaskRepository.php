<?php

declare(strict_types=1);

namespace Wezurlop\Repository;

use Wezurlop\Db\Connection;

/**
 * Zadania zapisu i usunięcia w programie kadrowym (docs/api-connector.md 3.4–3.5) oraz stany wniosków,
 * które synchronizacja zmienia. Wydane zadanie jest rezerwowane na 15 minut; zadania jednego wniosku
 * wydawane sekwencyjnie.
 *
 * @phpstan-type TaskRow array{id: int, leave_request_id: int, request_id: string, operation: string, status: string, attempts: int,
 *   request_status: string, employment_ref: ?string, source: string, code: int, date_from: string, date_to: string}
 */
final class SyncTaskRepository
{
    private const TASK_COLUMNS = 't.id, t.leave_request_id, t.request_id, t.operation, t.status, t.attempts, r.status AS request_status,
        e.r2_employment_ref, lt.source, lt.code, r.date_from, r.date_to';

    public function __construct(private readonly Connection $db)
    {
    }

    // --- kandydaci do zadań ------------------------------------------------------------------------

    /**
     * Wnioski zaakceptowane bez zadania zapisu oraz anulowania zatwierdzone przez przełożonego bez zadania
     * usunięcia. Wyłącznie zatrudnienia znane w R2 (r2_employment_ref).
     *
     * ⚠ NIEZMIENNIK: `zapis` i `usuniecie` tego samego wniosku nigdy w jednym cyklu (gwarancja dla autora
     * connectora, 20.09.2026). Dlatego `usuniecie` powstaje WYŁĄCZNIE wtedy, gdy zapis został potwierdzony
     * wynikiem (zakończone zadanie `zapis`) albo wniosek ma wpis w R2. Zapis wydany i niepotwierdzony
     * zamyka się przez listę `wycofane` (withdrawnRequestIds) i wynik `wycofano` — bez zadania usunięcia.
     *
     * @return list<array{id: int, operation: string, employment_id: int, person_id: int, date_from: string, date_to: string,
     *   is_active: bool, terminated_at: ?string}>
     */
    public function candidates(int $companyId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT r.id, IF(r.status = 'approved', 'zapis', 'usuniecie') AS operation, e.id AS employment_id, e.person_id,
                    r.date_from, r.date_to, e.is_active, e.terminated_at
             FROM leave_request r JOIN employment e ON e.id = r.employment_id
             WHERE e.company_id = :company AND e.r2_employment_ref IS NOT NULL
               AND (r.status = 'approved' OR (r.status = 'cancel_requested' AND r.cancel_approved_at IS NOT NULL))
               AND NOT EXISTS (SELECT 1 FROM sync_task t WHERE t.leave_request_id = r.id
                               AND t.operation = IF(r.status = 'approved', 'zapis', 'usuniecie')
                               AND t.status IN ('pending', 'issued', 'done'))
               AND (r.status = 'approved' OR EXISTS (SELECT 1 FROM sync_task d WHERE d.leave_request_id = r.id
                                                     AND d.operation = 'zapis' AND d.status = 'done')
                                          OR EXISTS (SELECT 1 FROM leave_request_r2_record x WHERE x.leave_request_id = r.id
                                                     AND x.removed_at IS NULL))
             ORDER BY r.id"
        );
        $stmt->execute(['company' => $companyId]);

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'], 'operation' => (string) $row['operation'], 'employment_id' => (int) $row['employment_id'],
            'person_id' => (int) $row['person_id'], 'date_from' => (string) $row['date_from'], 'date_to' => (string) $row['date_to'],
            'is_active' => (bool) $row['is_active'], 'terminated_at' => $row['terminated_at'] === null ? null : (string) $row['terminated_at'],
        ], $stmt->fetchAll());
    }

    /** Nieobecność zewnętrzna tej OSOBY (we wszystkich firmach) pokrywająca zakres — kolizja przed wysyłką. */
    public function externalKolizja(int $personId, string $from, string $to): ?string
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT CONCAT(lt.name, \' \', a.date_from, \'–\', a.date_to) FROM external_absence a JOIN leave_type lt ON lt.id = a.leave_type_id
             WHERE a.person_id = :person AND a.removed_at IS NULL AND a.out_of_window = 0 AND lt.is_absence = 1
               AND a.date_from <= :to AND a.date_to >= :from LIMIT 1'
        );
        $stmt->execute(['person' => $personId, 'from' => $from, 'to' => $to]);
        $hit = $stmt->fetchColumn();

        return $hit === false ? null : (string) $hit;
    }

    public function createTask(int $companyId, int $leaveRequestId, string $requestId, string $operation): void
    {
        $this->db->pdo()->prepare(
            "INSERT INTO sync_task (company_id, leave_request_id, request_id, operation, status, attempts, created_at)
             VALUES (:company, :request, :rid, :op, 'pending', 0, UTC_TIMESTAMP())"
        )->execute(['company' => $companyId, 'request' => $leaveRequestId, 'rid' => $requestId, 'op' => $operation]);
    }

    // --- wydawanie -----------------------------------------------------------------------------------

    /**
     * Zadania do wydania: oczekujące albo wydane, których rezerwacja minęła. Najwyżej jedno na wniosek.
     *
     * @return list<TaskRow>
     */
    public function issuable(int $companyId, int $limit): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::TASK_COLUMNS . "
             FROM sync_task t JOIN leave_request r ON r.id = t.leave_request_id JOIN employment e ON e.id = r.employment_id
             JOIN leave_type lt ON lt.id = r.leave_type_id
             WHERE t.company_id = :company
               AND (t.status = 'pending' OR (t.status = 'issued' AND t.reserved_until < UTC_TIMESTAMP()))
             ORDER BY t.id LIMIT :limit FOR UPDATE"
        );
        $stmt->bindValue('company', $companyId, \PDO::PARAM_INT);
        $stmt->bindValue('limit', max(1, min($limit, 100)), \PDO::PARAM_INT);
        $stmt->execute();

        return array_map([$this, 'taskRow'], $stmt->fetchAll());
    }

    public function reserve(int $taskId, int $runId, int $minutes): void
    {
        $this->db->pdo()->prepare(
            "UPDATE sync_task SET status = 'issued', reserved_until = UTC_TIMESTAMP() + INTERVAL :min MINUTE, issued_run_id = :run WHERE id = :id"
        )->execute(['min' => $minutes, 'run' => $runId, 'id' => $taskId]);
    }

    /** @return list<array{id: string}> identyfikatory zapisów R2 wniosku (do usunięcia) */
    public function activeR2Records(int $leaveRequestId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT r2_record_id FROM leave_request_r2_record WHERE leave_request_id = :id AND removed_at IS NULL ORDER BY date_from, r2_record_id'
        );
        $stmt->execute(['id' => $leaveRequestId]);

        return array_map(static fn (string $id): array => ['id' => $id], array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN)));
    }

    /**
     * Zadania wydane, których nie należy już wykonywać (wniosek wycofany w międzyczasie) — lista `wycofane`.
     * ⚠ Obejmuje też zatwierdzone anulowanie wniosku, którego zapis jest jeszcze niepotwierdzony: to ta lista,
     * a nie zadanie `usuniecie`, zamyka wtedy sprawę (niezmiennik z candidates()).
     *
     * @return list<string>
     */
    public function withdrawnRequestIds(int $companyId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT t.request_id FROM sync_task t JOIN leave_request r ON r.id = t.leave_request_id
             WHERE t.company_id = :company AND t.status IN ('issued', 'pending') AND t.operation = 'zapis'
               AND (r.status IN ('cancelled', 'rejected', 'cancelled_confirmed')
                    OR (r.status = 'cancel_requested' AND r.cancel_approved_at IS NOT NULL))"
        );
        $stmt->execute(['company' => $companyId]);

        return array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    // --- wyniki --------------------------------------------------------------------------------------

    /** @return TaskRow|null */
    public function byRequestId(int $companyId, string $requestId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::TASK_COLUMNS . '
             FROM sync_task t JOIN leave_request r ON r.id = t.leave_request_id JOIN employment e ON e.id = r.employment_id
             JOIN leave_type lt ON lt.id = r.leave_type_id
             WHERE t.company_id = :company AND t.request_id = :rid FOR UPDATE'
        );
        $stmt->execute(['company' => $companyId, 'rid' => $requestId]);
        $row = $stmt->fetch();

        return is_array($row) ? $this->taskRow($row) : null;
    }

    public function finishTask(int $taskId, string $status, string $resultStatus, ?string $code, ?string $message): void
    {
        $this->db->pdo()->prepare(
            "UPDATE sync_task SET status = :status, result_status = :rs, result_code = :code, result_message = :msg,
                reserved_until = NULL, finished_at = IF(:status2 IN ('done', 'withdrawn', 'failed', 'cancelled'), UTC_TIMESTAMP(), NULL)
             WHERE id = :id"
        )->execute([
            'status' => $status, 'status2' => $status, 'rs' => $resultStatus, 'code' => $code,
            'msg' => $message === null ? null : mb_substr($message, 0, 500), 'id' => $taskId,
        ]);
    }

    /** Błąd przejściowy: zadanie wraca do puli, z licznikiem prób. @return int liczba prób */
    public function retryTask(int $taskId, ?string $code, ?string $message): int
    {
        $this->db->pdo()->prepare(
            "UPDATE sync_task SET status = 'pending', attempts = attempts + 1, reserved_until = NULL, result_status = 'bladPrzejsciowy',
                result_code = :code, result_message = :msg WHERE id = :id"
        )->execute(['code' => $code, 'msg' => $message === null ? null : mb_substr($message, 0, 500), 'id' => $taskId]);
        $stmt = $this->db->pdo()->prepare('SELECT attempts FROM sync_task WHERE id = :id');
        $stmt->execute(['id' => $taskId]);

        return (int) $stmt->fetchColumn();
    }

    // --- wnioski ---------------------------------------------------------------------------------------

    /** Zmiana stanu wniosku przez synchronizację + wpis do historii decyzji (decydent: system). */
    public function setRequestStatus(int $leaveRequestId, string $from, string $to, ?string $reason, array $fields = []): void
    {
        $sets = ['status = :to', 'updated_at = UTC_TIMESTAMP()'];
        $params = ['to' => $to, 'id' => $leaveRequestId];
        // Kolumny z białej listy (CLAUDE.md §4) — nazwy nigdy nie pochodzą z danych wejściowych.
        $allowed = [
            'days_confirmed' => 'days_confirmed', 'minutes_confirmed' => 'minutes_confirmed', 'r2_warning_code' => 'r2_warning_code',
            'r2_warning_text' => 'r2_warning_text', 'r2_error_code' => 'r2_error_code', 'r2_error_text' => 'r2_error_text',
            'changed_in_r2' => 'changed_in_r2',
        ];
        // Znaczniki czasu: wartość 'now' oznacza UTC_TIMESTAMP(); kolumny też z białej listy (CLAUDE.md §4).
        $timestamps = ['cancelled_at' => 'cancelled_at', 'confirmed_at' => 'confirmed_at', 'cancel_approved_at' => 'cancel_approved_at'];
        foreach ($fields as $name => $value) {
            if (isset($timestamps[$name]) && $value === 'now') {
                $sets[] = $timestamps[$name] . ' = UTC_TIMESTAMP()';
                unset($fields[$name]);
            }
        }
        foreach ($fields as $name => $value) {
            if (isset($allowed[$name])) {
                $sets[] = $allowed[$name] . ' = :f_' . $name;
                $params['f_' . $name] = $value;
            }
        }
        if ($to === 'confirmed') {
            $sets[] = 'confirmed_at = COALESCE(confirmed_at, UTC_TIMESTAMP())';
        }
        if ($to === 'cancelled_confirmed') {
            $sets[] = 'cancelled_at = COALESCE(cancelled_at, UTC_TIMESTAMP())';
        }
        $this->db->pdo()->prepare('UPDATE leave_request SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($params);
        if ($from !== $to) {
            $this->db->pdo()->prepare(
                "INSERT INTO leave_decision (leave_request_id, decided_by_account_id, decision, from_status, to_status, reason, decided_at)
                 VALUES (:id, NULL, 'sync', :from, :to, :reason, UTC_TIMESTAMP())"
            )->execute(['id' => $leaveRequestId, 'from' => $from, 'to' => $to, 'reason' => $reason]);
        }
    }

    /** @return array{id: int, status: string, company_id: int}|null */
    public function requestById(int $leaveRequestId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT r.id, r.status, e.company_id FROM leave_request r JOIN employment e ON e.id = r.employment_id WHERE r.id = :id FOR UPDATE'
        );
        $stmt->execute(['id' => $leaveRequestId]);
        $row = $stmt->fetch();

        return is_array($row) ? ['id' => (int) $row['id'], 'status' => (string) $row['status'], 'company_id' => (int) $row['company_id']] : null;
    }

    /**
     * Faktyczny stan zapisów w R2 po zadaniu: lista zastępuje dotychczasową (wpisy spoza listy — usunięte).
     *
     * @param list<array{id: string, dataOd: string, dataDo: string, dni: ?int, minuty: ?int}> $records
     */
    public function replaceR2Records(int $leaveRequestId, array $records): void
    {
        $ids = [];
        foreach ($records as $r) {
            $this->upsertR2Record($leaveRequestId, $r);
            $ids[] = $r['id'];
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT r2_record_id FROM leave_request_r2_record WHERE leave_request_id = :id AND removed_at IS NULL'
        );
        $stmt->execute(['id' => $leaveRequestId]);
        foreach (array_diff(array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN)), $ids) as $gone) {
            $this->markR2RecordRemoved($leaveRequestId, $gone);
        }
    }

    /** @param array{id: string, dataOd: string, dataDo: string, dni: ?int, minuty: ?int} $r */
    public function upsertR2Record(int $leaveRequestId, array $r): bool
    {
        $find = $this->db->pdo()->prepare(
            'SELECT date_from, date_to, days, minutes FROM leave_request_r2_record WHERE leave_request_id = :id AND r2_record_id = :ref'
        );
        $find->execute(['id' => $leaveRequestId, 'ref' => $r['id']]);
        $old = $find->fetch();
        $params = ['id' => $leaveRequestId, 'ref' => $r['id'], 'from' => $r['dataOd'], 'to' => $r['dataDo'], 'days' => $r['dni'], 'min' => $r['minuty']];
        if (is_array($old)) {
            $this->db->pdo()->prepare(
                'UPDATE leave_request_r2_record SET date_from = :from, date_to = :to, days = :days, minutes = :min, removed_at = NULL
                 WHERE leave_request_id = :id AND r2_record_id = :ref'
            )->execute($params);

            return (string) $old['date_from'] !== $r['dataOd'] || (string) $old['date_to'] !== $r['dataDo']
                || ($old['days'] === null ? null : (int) $old['days']) !== $r['dni'];
        }
        $this->db->pdo()->prepare(
            'INSERT INTO leave_request_r2_record (leave_request_id, r2_record_id, date_from, date_to, days, minutes, created_at)
             VALUES (:id, :ref, :from, :to, :days, :min, UTC_TIMESTAMP())'
        )->execute($params);

        return false;
    }

    public function markR2RecordRemoved(int $leaveRequestId, string $ref): void
    {
        $this->db->pdo()->prepare(
            'UPDATE leave_request_r2_record SET removed_at = COALESCE(removed_at, UTC_TIMESTAMP()) WHERE leave_request_id = :id AND r2_record_id = :ref'
        )->execute(['id' => $leaveRequestId, 'ref' => $ref]);
    }

    /** Suma dni i minut ze WSZYSTKICH aktywnych zapisów wniosku — nigdy z pierwszego. @return array{days: ?int, minutes: ?int} */
    public function confirmedTotals(int $leaveRequestId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT SUM(days) AS days, SUM(minutes) AS minutes, SUM(days IS NULL) AS no_days, SUM(minutes IS NULL) AS no_minutes, COUNT(*) AS n
             FROM leave_request_r2_record WHERE leave_request_id = :id AND removed_at IS NULL'
        );
        $stmt->execute(['id' => $leaveRequestId]);
        $row = $stmt->fetch();
        $n = (int) ($row['n'] ?? 0);

        return [
            'days' => $n === 0 || (int) $row['no_days'] > 0 ? null : (int) $row['days'],
            'minutes' => $n === 0 || (int) $row['no_minutes'] > 0 ? null : (int) $row['minutes'],
        ];
    }

    /**
     * Wniosek powiązany z zapisem R2 (przez requestId zadania albo przez znany identyfikator zapisu).
     *
     * @return array{leave_request_id: int, status: string, date_to: string}|null
     */
    public function requestForR2Record(int $companyId, ?string $requestId, string $r2RecordId): ?array
    {
        if ($requestId !== null) {
            $stmt = $this->db->pdo()->prepare(
                'SELECT r.id, r.status, r.date_to, e.is_active FROM sync_task t JOIN leave_request r ON r.id = t.leave_request_id
                 JOIN employment e ON e.id = r.employment_id WHERE t.company_id = :company AND t.request_id = :rid'
            );
            $stmt->execute(['company' => $companyId, 'rid' => $requestId]);
            $row = $stmt->fetch();
            if (is_array($row)) {
                return self::linkedRequest($row);
            }
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT r.id, r.status, x.date_to, e.is_active FROM leave_request_r2_record x JOIN leave_request r ON r.id = x.leave_request_id
             JOIN employment e ON e.id = r.employment_id WHERE e.company_id = :company AND x.r2_record_id = :ref ORDER BY x.id DESC LIMIT 1'
        );
        $stmt->execute(['company' => $companyId, 'ref' => $r2RecordId]);
        $row = $stmt->fetch();

        return is_array($row) ? self::linkedRequest($row) : null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{leave_request_id: int, status: string, date_to: string, employment_active: bool}
     */
    private static function linkedRequest(array $row): array
    {
        return [
            'leave_request_id' => (int) $row['id'], 'status' => (string) $row['status'], 'date_to' => (string) $row['date_to'],
            'employment_active' => (bool) $row['is_active'],
        ];
    }

    /**
     * Czy wniosek jest (albo może być) obecny w programie kadrowym: ma aktywny wpis R2 albo zadanie
     * `zapis` zakończone lub **wydane i niepotwierdzone**. ⚠ Wydane zadanie liczy się jak obecne —
     * connector mógł już zapisać urlop, a wynik jeszcze nie wrócił. Lepiej jedno zbędne zadanie
     * usunięcia niż urlop, który został w kadrach, a u nas jest anulowany (rozstrzygnięcie 20.09.2026).
     * Jedno miejsce dla tej reguły: pyta o nią i serwis wniosków, i obsługa wyników.
     */
    public function existsInR2(int $leaveRequestId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT EXISTS (SELECT 1 FROM leave_request_r2_record x WHERE x.leave_request_id = :r AND x.removed_at IS NULL)
                 OR EXISTS (SELECT 1 FROM sync_task t WHERE t.leave_request_id = :r2 AND t.operation = 'zapis'
                            AND t.status IN ('issued', 'done'))"
        );
        $stmt->execute(['r' => $leaveRequestId, 'r2' => $leaveRequestId]);

        return (bool) $stmt->fetchColumn();
    }

    public function countOpenTasks(int $companyId): int
    {
        $stmt = $this->db->pdo()->prepare("SELECT COUNT(*) FROM sync_task WHERE company_id = :company AND status IN ('pending', 'issued')");
        $stmt->execute(['company' => $companyId]);

        return (int) $stmt->fetchColumn();
    }

    /** @param array<string, mixed> $row */
    private function taskRow(array $row): array
    {
        return [
            'id' => (int) $row['id'], 'leave_request_id' => (int) $row['leave_request_id'], 'request_id' => (string) $row['request_id'],
            'operation' => (string) $row['operation'], 'status' => (string) $row['status'], 'attempts' => (int) $row['attempts'],
            'request_status' => (string) $row['request_status'],
            'employment_ref' => $row['r2_employment_ref'] === null ? null : (string) $row['r2_employment_ref'],
            'source' => (string) $row['source'], 'code' => (int) $row['code'],
            'date_from' => (string) $row['date_from'], 'date_to' => (string) $row['date_to'],
        ];
    }
}
