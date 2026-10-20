<?php

declare(strict_types=1);

namespace Wezurlop\Repository;

use Wezurlop\Db\Connection;

/**
 * Stan synchronizacji z connectorem: przebiegi (sync_run), kursory, rejestr rekordów (sync_record —
 * właściciel jednoznaczności nip + zbiór + id), przegląd kontrolny, idempotencja i konflikty.
 *
 * @phpstan-type RunRow array{id: int, run_uuid: string, company_id: int, status: string, started_at: string, stats: array<string, mixed>}
 * @phpstan-type RecordRow array{record_id: string, checksum: ?string, state: string, local_id: ?int, parent_ref: ?string, payload: ?array<string, mixed>, pending_attempts: int}
 */
final class SyncRepository
{
    /** „Alertu jeszcze nie było” — ta sama stała, co przy znaczniku ciszy klucza. */
    public const ALERT_RESET = '1970-01-01 00:00:00';

    public function __construct(private readonly Connection $db)
    {
    }

    // --- przebiegi ---------------------------------------------------------------------------------

    /** @param array<string, mixed> $connector */
    public function openRun(string $uuid, int $companyId, int $apiKeyId, array $connector): int
    {
        // ⚠ Jeden otwarty przebieg na firmę: poprzedni niezamknięty jest unieważniany w tej samej chwili,
        // w której powstaje nowy (docs/api-connector.md 3.1).
        $this->db->pdo()->prepare(
            "UPDATE sync_run SET status = 'abandoned', finished_at = UTC_TIMESTAMP() WHERE company_id = :company AND status = 'open'"
        )->execute(['company' => $companyId]);
        $stmt = $this->db->pdo()->prepare(
            "INSERT INTO sync_run (run_uuid, company_id, api_key_id, status, phase, connector_version, r2_version,
                connector_state, connector_state_note, last_success_cycle, stats, started_at, updated_at)
             VALUES (:uuid, :company, :key, 'open', 'start', :cv, :rv, :state, :note, :last, :stats, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        );
        $stmt->execute([
            'uuid' => $uuid, 'company' => $companyId, 'key' => $apiKeyId,
            'cv' => $connector['wersjaConnectora'], 'rv' => $connector['wersjaR2'], 'state' => $connector['stan'],
            'note' => $connector['stanOpis'], 'last' => $connector['ostatniUdanyCykl'], 'stats' => '{}',
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * Ostatni domknięty przebieg firmy — odpowiedź operacji `stan` (test połączenia w connectorze).
     * Zwraca czas UTC w formacie bazy albo null, gdy firma nie miała jeszcze ani jednego cyklu.
     */
    public function lastClosedRunAt(int $companyId): ?string
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT MAX(finished_at) FROM sync_run WHERE company_id = :company AND status = 'closed'"
        );
        $stmt->execute(['company' => $companyId]);
        $value = $stmt->fetchColumn();

        return $value === false || $value === null ? null : (string) $value;
    }

    /**
     * Podsumowanie dla ekranu podłączenia: ile danych już przyszło i kiedy była ostatnia synchronizacja.
     *
     * @return array{pracownicy: int, nieobecnosci: int, ostatnia_synchronizacja: ?string, przebiegi_dzis: int, bledy_dzis: int}
     */
    public function dataSummary(): array
    {
        $row = $this->db->pdo()->query(
            "SELECT (SELECT COUNT(*) FROM employment) AS pracownicy,
                    (SELECT COUNT(*) FROM external_absence WHERE removed_at IS NULL) AS nieobecnosci,
                    (SELECT MAX(finished_at) FROM sync_run WHERE status = 'closed') AS ostatnia,
                    (SELECT COUNT(*) FROM sync_run WHERE started_at >= UTC_DATE()) AS przebiegi_dzis,
                    (SELECT COUNT(*) FROM sync_run WHERE started_at >= UTC_DATE() AND status IN ('abandoned', 'expired')) AS bledy_dzis"
        )->fetch();

        return [
            'pracownicy' => (int) $row['pracownicy'], 'nieobecnosci' => (int) $row['nieobecnosci'],
            'ostatnia_synchronizacja' => $row['ostatnia'] === null ? null : (string) $row['ostatnia'],
            'przebiegi_dzis' => (int) $row['przebiegi_dzis'], 'bledy_dzis' => (int) $row['bledy_dzis'],
        ];
    }

    /** Licznik 503 w opisie przebiegu (po wycofaniu transakcji paczki — osobne polecenie). */
    /** Powody, dla których przebieg nie żyje — pole `powod` w odpowiedzi 409 (docs/api-connector.md 3.1). */
    public const REASON_EXPIRED = 'wygasl';
    public const REASON_CLOSED = 'zamkniety';
    public const REASON_REPLACED = 'zastapiony';
    public const REASON_UNKNOWN = 'nieznany';
    private const REASONS = ['closed' => self::REASON_CLOSED, 'abandoned' => self::REASON_REPLACED, 'expired' => self::REASON_EXPIRED];

    public function countTransient(string $runUuid): void
    {
        $this->db->pdo()->prepare(
            "UPDATE sync_run SET stats = JSON_SET(COALESCE(stats, '{}'), '$.przerwyTechniczne',
                COALESCE(JSON_EXTRACT(stats, '$.przerwyTechniczne'), 0) + 1), updated_at = UTC_TIMESTAMP()
             WHERE run_uuid = :uuid AND status = 'open'"
        )->execute(['uuid' => $runUuid]);
    }

    /**
     * Przebieg otwarty tej firmy — albo null, gdy nie istnieje, został zamknięty, unieważniony nowym
     * `start` albo WYGASŁ (docs/api-connector.md 3.1). Wygaśnięcie liczymy od ostatniego żądania w tym
     * przebiegu; przebieg wygasły dostaje status `expired`, żeby było widać, czym się skończył.
     * ⚠ Kursory nie giną razem z przebiegiem — leżą w sync_cursor i obowiązuje ostatni potwierdzony.
     *
     * @return array{run: RunRow|null, powod: string|null} `powod`: wygasl | zamkniety | zastapiony | nieznany
     */
    public function runState(string $uuid, int $companyId, int $ttlMinutes = 30): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, run_uuid, company_id, status, started_at, stats,
                    TIMESTAMPDIFF(MINUTE, updated_at, UTC_TIMESTAMP()) AS idle_minutes FROM sync_run
             WHERE run_uuid = :uuid AND company_id = :company"
        );
        $stmt->execute(['uuid' => $uuid, 'company' => $companyId]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            // Także przebieg innej firmy: nie mówimy, że istnieje (§10 — bez ujawniania istnienia zasobu).
            return ['run' => null, 'powod' => self::REASON_UNKNOWN];
        }
        if ((string) $row['status'] !== 'open') {
            return ['run' => null, 'powod' => self::REASONS[(string) $row['status']] ?? self::REASON_UNKNOWN];
        }
        if ((int) $row['idle_minutes'] >= max(1, $ttlMinutes)) {
            $this->db->pdo()->prepare(
                "UPDATE sync_run SET status = 'expired', finished_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = :id"
            )->execute(['id' => (int) $row['id']]);

            return ['run' => null, 'powod' => self::REASON_EXPIRED];
        }
        // Każde żądanie odsuwa wygaśnięcie — liczy się bezczynność, nie długość przebiegu.
        $this->db->pdo()->prepare('UPDATE sync_run SET updated_at = UTC_TIMESTAMP() WHERE id = :id')->execute(['id' => (int) $row['id']]);
        $stats = json_decode((string) $row['stats'], true);

        return ['run' => [
            'id' => (int) $row['id'], 'run_uuid' => (string) $row['run_uuid'], 'company_id' => (int) $row['company_id'],
            'status' => (string) $row['status'], 'started_at' => (string) $row['started_at'], 'stats' => is_array($stats) ? $stats : [],
        ], 'powod' => null];
    }

    /** @param array<string, mixed> $stats */
    public function saveRunStats(int $runId, string $phase, array $stats): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE sync_run SET phase = :phase, stats = :stats, updated_at = UTC_TIMESTAMP() WHERE id = :id'
        );
        $stmt->execute(['phase' => $phase, 'stats' => json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'id' => $runId]);
    }

    /** @param array<string, mixed> $summary */
    public function closeRun(int $runId, array $summary): void
    {
        $stmt = $this->db->pdo()->prepare(
            "UPDATE sync_run SET status = 'closed', phase = 'koniec', connector_summary = :summary,
                updated_at = UTC_TIMESTAMP(), finished_at = UTC_TIMESTAMP() WHERE id = :id"
        );
        $stmt->execute(['summary' => json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'id' => $runId]);
    }

    /** @return list<array<string, mixed>> ostatnie przebiegi — ekran administratora */
    public function recentRuns(int $limit): array
    {
        $stmt = $this->db->pdo()->prepare(
            // ⚠ **Nazwa WYŚWIETLANA firmy, nie prawna z R2** (zlecenie Z57 U6). Tabela przebiegów
            // mówi, czyja synchronizacja się wykonała — a firmę człowiek zna pod nazwą, którą
            // sam ustawił na `/ustawienia` (Z29). Pełna nazwa prawna jest tu tylko dłuższa:
            // tożsamość firmy w tym ekranie niesie NIP, nie nazwa.
            'SELECT r.id, r.run_uuid, c.nip, ' . NazwaSpolki::SQL . ' AS company, r.status, r.phase, r.connector_version, r.r2_version,
                    r.connector_state, r.stats, r.started_at, r.finished_at,
                    TIMESTAMPDIFF(SECOND, r.started_at, COALESCE(r.finished_at, r.updated_at)) AS seconds,
                    (SELECT COUNT(*) FROM sync_conflict f WHERE f.sync_run_id = r.id) AS conflicts
             FROM sync_run r JOIN company c ON c.id = r.company_id ORDER BY r.id DESC LIMIT :limit'
        );
        $stmt->bindValue('limit', max(1, min($limit, 200)), \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Firmy z NIP-em i stanem ich strumienia — ekran administratora.
     *
     * @return list<array{id: int, name: string, nip: string, review_required: bool, last_review_at: ?string, connector: ?array<string, mixed>, last_run_at: ?string}>
     */
    public function streams(): array
    {
        $rows = $this->db->pdo()->query(
            'SELECT c.id, c.name, c.nip, c.identity_fingerprint, c.identity_fingerprint_pending,
                    s.review_required, s.last_review_at, s.connector,
                    (SELECT MAX(r.started_at) FROM sync_run r WHERE r.company_id = c.id) AS last_run_at
             FROM company c LEFT JOIN sync_stream s ON s.company_id = c.id ORDER BY c.name'
        )->fetchAll();

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'], 'name' => (string) $r['name'], 'nip' => (string) $r['nip'],
            'review_required' => (bool) $r['review_required'],
            // ⚠ Odcisk oczekujący = strumień tej firmy zatrzymany do jawnej decyzji administratora.
            'identity' => $r['identity_fingerprint'] === null ? null : (string) $r['identity_fingerprint'],
            'identity_pending' => $r['identity_fingerprint_pending'] === null ? null : (string) $r['identity_fingerprint_pending'],
            'last_review_at' => $r['last_review_at'] === null ? null : (string) $r['last_review_at'],
            'connector' => is_string($r['connector']) ? json_decode($r['connector'], true) : null,
            'last_run_at' => $r['last_run_at'] === null ? null : (string) $r['last_run_at'],
        ], $rows);
    }

    // --- strumień firmy i kursory --------------------------------------------------------------------

    /** @param array<string, mixed>|null $connector */
    public function ensureStream(int $companyId, ?array $connector = null): void
    {
        $this->db->pdo()->prepare(
            'INSERT IGNORE INTO sync_stream (company_id, cursor_seq, review_required) VALUES (:company, 0, 0)'
        )->execute(['company' => $companyId]);
        if ($connector !== null) {
            $this->db->pdo()->prepare('UPDATE sync_stream SET connector = :c WHERE company_id = :company')
                ->execute(['c' => json_encode($connector, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'company' => $companyId]);
        }
    }

    /** @return array{cursor_seq: int, review_required: bool, review_age_hours: ?float} */
    public function stream(int $companyId, bool $forUpdate = false): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT cursor_seq, review_required, TIMESTAMPDIFF(SECOND, last_review_at, UTC_TIMESTAMP()) AS age
             FROM sync_stream WHERE company_id = :company' . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $stmt->execute(['company' => $companyId]);
        $row = $stmt->fetch();

        return [
            'cursor_seq' => is_array($row) ? (int) $row['cursor_seq'] : 0,
            'review_required' => is_array($row) && (bool) $row['review_required'],
            'review_age_hours' => is_array($row) && $row['age'] !== null ? ((int) $row['age']) / 3600 : null,
        ];
    }

    /** Kolejny token potwierdzenia dla zbioru (w transakcji paczki). */
    public function advanceCursor(int $companyId, string $zbior): string
    {
        $seq = $this->stream($companyId, true)['cursor_seq'] + 1;
        $this->db->pdo()->prepare('UPDATE sync_stream SET cursor_seq = :seq WHERE company_id = :company')
            ->execute(['seq' => $seq, 'company' => $companyId]);
        $cursor = 'c-' . $seq;
        $this->db->pdo()->prepare(
            'INSERT INTO sync_cursor (company_id, zbior, cursor_token, updated_at) VALUES (:company, :zbior, :cursor, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE cursor_token = :cursor2, updated_at = UTC_TIMESTAMP()'
        )->execute(['company' => $companyId, 'zbior' => $zbior, 'cursor' => $cursor, 'cursor2' => $cursor]);

        return $cursor;
    }

    /** @return array<string, string> zbiór => kursor */
    public function cursors(int $companyId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT zbior, cursor_token FROM sync_cursor WHERE company_id = :company');
        $stmt->execute(['company' => $companyId]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(string) $row['zbior']] = (string) $row['cursor_token'];
        }

        return $out;
    }

    /** Kursor zbioru skasowany — następny `start` zażąda dla niego pełnego ładowania. */
    public function resetCursor(int $companyId, string $zbior): void
    {
        $this->db->pdo()->prepare('DELETE FROM sync_cursor WHERE company_id = :company AND zbior = :zbior')
            ->execute(['company' => $companyId, 'zbior' => $zbior]);
    }

    public function setReviewRequired(int $companyId, bool $required): void
    {
        $this->db->pdo()->prepare('UPDATE sync_stream SET review_required = :r WHERE company_id = :company')
            ->execute(['r' => $required ? 1 : 0, 'company' => $companyId]);
    }

    public function markReviewDone(int $companyId): void
    {
        $this->db->pdo()->prepare('UPDATE sync_stream SET review_required = 0, last_review_at = UTC_TIMESTAMP() WHERE company_id = :company')
            ->execute(['company' => $companyId]);
    }

    /**
     * Zajęcie slotu alertu o ciszy JEDNEJ firmy — najwyżej jeden alert na podany okres.
     *
     * ⚠ Ten sam wzorzec co `TenantSettingRepository::claimAlertSlot()`: warunek siedzi w UPDATE,
     * więc dwa równoległe przebiegi crona nie wyślą dwóch wiadomości o tej samej firmie. Różni się
     * tylko miejscem znacznika — wiersz firmy w `sync_stream` zamiast wiersza ustawienia, bo
     * znaczników per firma nie da się założyć migracją (firmy powstają po instalacji).
     */
    public function claimIdleAlertSlot(int $companyId, int $periodSeconds): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "UPDATE sync_stream SET idle_alert_at = UTC_TIMESTAMP()
             WHERE company_id = :company AND idle_alert_at < UTC_TIMESTAMP() - INTERVAL :period SECOND"
        );
        $stmt->execute(['company' => $companyId, 'period' => $periodSeconds]);

        return $stmt->rowCount() === 1;
    }

    /**
     * Firma znów się synchronizuje — kasujemy znacznik, żeby NASTĘPNA cisza znów wysłała alert.
     *
     * ⚠ Warunek `idle_alert_at > :reset` nie jest ozdobą: bez niego każdy przebieg crona pisałby
     * do wiersza strumienia każdej zdrowej firmy, co godzinę i bez powodu.
     */
    public function clearIdleAlert(int $companyId): void
    {
        $this->db->pdo()->prepare(
            'UPDATE sync_stream SET idle_alert_at = :reset WHERE company_id = :company AND idle_alert_at > :reset2'
        )->execute(['reset' => self::ALERT_RESET, 'company' => $companyId, 'reset2' => self::ALERT_RESET]);
    }

    // --- rejestr rekordów ------------------------------------------------------------------------------

    /**
     * @param list<string> $ids
     * @return array<string, RecordRow>
     */
    public function records(int $companyId, string $zbior, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $out = [];
        // Porcjami po 500 — tyle ma paczka; placeholdery nazwane z indeksu, bez wartości w treści SQL.
        foreach (array_chunk(array_values(array_unique($ids)), 500) as $chunk) {
            $params = ['company' => $companyId, 'zbior' => $zbior];
            $marks = [];
            foreach ($chunk as $i => $id) {
                $marks[] = ':id' . $i;
                $params['id' . $i] = $id;
            }
            $stmt = $this->db->pdo()->prepare(
                'SELECT record_id, checksum, state, local_id, parent_ref, payload, pending_attempts FROM sync_record
                 WHERE company_id = :company AND zbior = :zbior AND record_id IN (' . implode(', ', $marks) . ')'
            );
            $stmt->execute($params);
            foreach ($stmt->fetchAll() as $row) {
                $out[(string) $row['record_id']] = $this->recordRow($row);
            }
        }

        return $out;
    }

    /** @return RecordRow|null */
    public function record(int $companyId, string $zbior, string $id): ?array
    {
        return $this->records($companyId, $zbior, [$id])[$id] ?? null;
    }

    /** @param array<string, mixed>|null $payload */
    public function saveRecord(
        int $companyId,
        string $zbior,
        string $id,
        ?string $checksum,
        string $state,
        ?int $localId,
        ?string $parentRef,
        int $runId,
        ?array $payload = null,
        ?string $pendingReason = null,
    ): void {
        $params = [
            'company' => $companyId, 'zbior' => $zbior, 'id' => $id, 'checksum' => $checksum, 'state' => $state,
            'local' => $localId, 'parent' => $parentRef, 'run' => $runId,
            'payload' => $payload === null ? null : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'reason' => $pendingReason, 'pending' => $state === 'pending' ? 1 : 0,
        ];
        $update = $this->db->pdo()->prepare(
            "UPDATE sync_record SET checksum = :checksum, state = :state, local_id = COALESCE(:local, local_id),
                parent_ref = COALESCE(:parent, parent_ref), payload = :payload, pending_reason = :reason,
                pending_attempts = IF(:pending = 1, pending_attempts + 1, 0),
                pending_since = IF(:pending2 = 1, COALESCE(pending_since, UTC_TIMESTAMP()), NULL),
                updated_run_id = :run, updated_at = UTC_TIMESTAMP()
             WHERE company_id = :company AND zbior = :zbior AND record_id = :id"
        );
        $update->execute($params + ['pending2' => $params['pending']]);
        if ($update->rowCount() > 0 || $this->record($companyId, $zbior, $id) !== null) {
            return;
        }
        // ⚠ `created_run_id` ustawiamy wyłącznie tutaj i nigdy nie aktualizujemy — to on pozwala odróżnić
        // rekord DODANY w przebiegu od ZMIENIONEGO, czego wymaga ocena cyklu na ekranie synchronizacji.
        $insert = $this->db->pdo()->prepare(
            "INSERT INTO sync_record (company_id, zbior, record_id, checksum, state, local_id, parent_ref, payload,
                pending_reason, pending_attempts, pending_since, created_run_id, updated_run_id, updated_at)
             VALUES (:company, :zbior, :id, :checksum, :state, :local, :parent, :payload, :reason, :pending,
                IF(:pending2 = 1, UTC_TIMESTAMP(), NULL), :run, :run2, UTC_TIMESTAMP())"
        );
        $insert->execute($params + ['pending2' => $params['pending'], 'run2' => $runId]);
    }

    public function setRecordState(int $companyId, string $zbior, string $id, string $state, int $runId): void
    {
        $stmt = $this->db->pdo()->prepare(
            "UPDATE sync_record SET state = :state, payload = IF(:state2 = 'pending', payload, NULL), updated_run_id = :run,
                updated_at = UTC_TIMESTAMP() WHERE company_id = :company AND zbior = :zbior AND record_id = :id"
        );
        $stmt->execute(['state' => $state, 'state2' => $state, 'run' => $runId, 'company' => $companyId, 'zbior' => $zbior, 'id' => $id]);
    }

    /** @return list<array{zbior: string, record: RecordRow}> rekordy oczekujące firmy */
    public function pendingRecords(int $companyId, int $limit): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT zbior, record_id, checksum, state, local_id, parent_ref, payload, pending_attempts FROM sync_record
             WHERE company_id = :company AND state = 'pending' ORDER BY FIELD(zbior, 'pracownik', 'zatrudnienie', 'limit', 'nieobecnosc'), record_id
             LIMIT :limit"
        );
        $stmt->bindValue('company', $companyId, \PDO::PARAM_INT);
        $stmt->bindValue('limit', max(1, min($limit, 5000)), \PDO::PARAM_INT);
        $stmt->execute();

        return array_map(fn (array $row): array => ['zbior' => (string) $row['zbior'], 'record' => $this->recordRow($row)], $stmt->fetchAll());
    }

    public function countPending(int $companyId): int
    {
        $stmt = $this->db->pdo()->prepare("SELECT COUNT(*) FROM sync_record WHERE company_id = :company AND state = 'pending'");
        $stmt->execute(['company' => $companyId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Wygaszenie: rekordy oczekujące od przed początkiem przebiegu, który właśnie domknął przegląd —
     * po pełnym przeglądzie nadal nie mają na czym wisieć.
     *
     * @return list<array{zbior: string, record_id: string, pending_reason: ?string, pending_attempts: int}>
     */
    public function expirePending(int $companyId, string $runStartedAt, int $runId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT zbior, record_id, pending_reason, pending_attempts FROM sync_record
             WHERE company_id = :company AND state = 'pending' AND pending_since < :started"
        );
        $stmt->execute(['company' => $companyId, 'started' => $runStartedAt]);
        $rows = $stmt->fetchAll();
        $update = $this->db->pdo()->prepare(
            "UPDATE sync_record SET state = 'orphaned', updated_run_id = :run, updated_at = UTC_TIMESTAMP()
             WHERE company_id = :company AND zbior = :zbior AND record_id = :id AND state = 'pending'"
        );
        foreach ($rows as $row) {
            $update->execute(['run' => $runId, 'company' => $companyId, 'zbior' => $row['zbior'], 'id' => $row['record_id']]);
        }

        return array_map(static fn (array $r): array => [
            'zbior' => (string) $r['zbior'], 'record_id' => (string) $r['record_id'],
            'pending_reason' => $r['pending_reason'] === null ? null : (string) $r['pending_reason'],
            'pending_attempts' => (int) $r['pending_attempts'],
        ], $rows);
    }

    /** @return list<string> identyfikatory rekordów zbioru w danym stanie */
    public function recordIdsByParent(int $companyId, string $zbior, string $parentRef): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT record_id FROM sync_record WHERE company_id = :company AND zbior = :zbior AND parent_ref = :parent'
        );
        $stmt->execute(['company' => $companyId, 'zbior' => $zbior, 'parent' => $parentRef]);

        return array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    // --- przegląd kontrolny --------------------------------------------------------------------------

    /** @param list<string> $ids */
    public function markSeen(int $runId, string $zbior, array $ids): void
    {
        $stmt = $this->db->pdo()->prepare('INSERT IGNORE INTO sync_review_seen (sync_run_id, zbior, record_id) VALUES (:run, :zbior, :id)');
        foreach ($ids as $id) {
            $stmt->execute(['run' => $runId, 'zbior' => $zbior, 'id' => $id]);
        }
    }

    /** Czy przegląd tego zbioru został już domknięty w tym przebiegu (nigdy dwa w jednym przebiegu). */
    public function reviewCompleted(int $runId, string $zbior): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT 1 FROM sync_review_seen WHERE sync_run_id = :run AND zbior = :zbior AND record_id = '#koniec'"
        );
        $stmt->execute(['run' => $runId, 'zbior' => $zbior]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Rekordy aktywne u nas, których przegląd tego przebiegu nie wymienił — kandydaci do usunięcia.
     *
     * @return list<string>
     */
    public function missingInReview(int $companyId, int $runId, string $zbior): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT r.record_id FROM sync_record r
             LEFT JOIN sync_review_seen s ON s.sync_run_id = :run AND s.zbior = r.zbior AND s.record_id = r.record_id
             WHERE r.company_id = :company AND r.zbior = :zbior AND r.state = 'active' AND s.record_id IS NULL"
        );
        $stmt->execute(['run' => $runId, 'company' => $companyId, 'zbior' => $zbior]);

        return array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** Domknięcie przeglądu: znacznik końca zostaje (blokada drugiego przeglądu), reszta porządkowana. */
    public function finishReview(int $runId, string $zbior): void
    {
        $this->db->pdo()->prepare("DELETE FROM sync_review_seen WHERE sync_run_id = :run AND zbior = :zbior AND record_id <> '#koniec'")
            ->execute(['run' => $runId, 'zbior' => $zbior]);
        $this->db->pdo()->prepare("INSERT IGNORE INTO sync_review_seen (sync_run_id, zbior, record_id) VALUES (:run, :zbior, '#koniec')")
            ->execute(['run' => $runId, 'zbior' => $zbior]);
    }

    /**
     * Ślady przeglądu z przebiegów już nieotwartych (przerwanych albo zamkniętych). Zakończony przegląd
     * sprząta po sobie sam (finishReview); tu zostaje to, co porzucił przerwany.
     */
    public function deleteStaleReviewRows(int $limit): int
    {
        $limit = max(1, min($limit, 5000));
        $runs = $this->db->pdo()->query(
            "SELECT DISTINCT s.sync_run_id FROM sync_review_seen s JOIN sync_run r ON r.id = s.sync_run_id
             WHERE r.status <> 'open' AND r.updated_at < UTC_TIMESTAMP() - INTERVAL 1 DAY LIMIT 20"
        )->fetchAll(\PDO::FETCH_COLUMN);
        $deleted = 0;
        $stmt = $this->db->pdo()->prepare('DELETE FROM sync_review_seen WHERE sync_run_id = :run LIMIT :limit');
        foreach ($runs as $run) {
            if ($deleted >= $limit) {
                break;
            }
            $stmt->bindValue('run', (int) $run, \PDO::PARAM_INT);
            $stmt->bindValue('limit', $limit - $deleted, \PDO::PARAM_INT);
            $stmt->execute();
            $deleted += $stmt->rowCount();
        }

        return $deleted;
    }

    // --- idempotencja ----------------------------------------------------------------------------------

    /** @return array{status: int, body: string}|null */
    public function storedResponse(int $apiKeyId, string $key): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT response_status, response_body FROM sync_idempotency WHERE api_key_id = :key AND idem_key = :idem');
        $stmt->execute(['key' => $apiKeyId, 'idem' => $key]);
        $row = $stmt->fetch();

        return is_array($row) ? ['status' => (int) $row['response_status'], 'body' => (string) $row['response_body']] : null;
    }

    public function storeResponse(int $apiKeyId, string $key, string $operation, int $status, string $body): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT IGNORE INTO sync_idempotency (api_key_id, idem_key, operation, response_status, response_body, created_at)
             VALUES (:key, :idem, :op, :status, :body, UTC_TIMESTAMP())'
        );
        $stmt->execute(['key' => $apiKeyId, 'idem' => $key, 'op' => $operation, 'status' => $status, 'body' => $body]);
    }

    /** Sprzątanie porcjami: odpowiedzi starsze niż tydzień. */
    public function deleteOldResponses(int $limit): int
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM sync_idempotency WHERE created_at < UTC_TIMESTAMP() - INTERVAL 7 DAY LIMIT :limit');
        $stmt->bindValue('limit', max(1, min($limit, 5000)), \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount();
    }

    // --- konflikty -------------------------------------------------------------------------------------

    /** @param array<string, mixed> $detail */
    public function conflict(int $companyId, ?int $runId, ?int $leaveRequestId, string $kind, string $reason, array $detail = []): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO sync_conflict (company_id, sync_run_id, leave_request_id, kind, reason, detail, created_at)
             VALUES (:company, :run, :request, :kind, :reason, :detail, UTC_TIMESTAMP())'
        );
        $stmt->execute([
            'company' => $companyId, 'run' => $runId, 'request' => $leaveRequestId, 'kind' => $kind,
            'reason' => mb_substr($reason, 0, 500), 'detail' => $detail === [] ? null : json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]);
    }

    /** @param array<string, mixed> $row */
    private function recordRow(array $row): array
    {
        $payload = $row['payload'] === null ? null : json_decode((string) $row['payload'], true);

        return [
            'record_id' => (string) $row['record_id'], 'checksum' => $row['checksum'] === null ? null : (string) $row['checksum'],
            'state' => (string) $row['state'], 'local_id' => $row['local_id'] === null ? null : (int) $row['local_id'],
            'parent_ref' => $row['parent_ref'] === null ? null : (string) $row['parent_ref'],
            'payload' => is_array($payload) ? $payload : null, 'pending_attempts' => (int) $row['pending_attempts'],
        ];
    }
}
