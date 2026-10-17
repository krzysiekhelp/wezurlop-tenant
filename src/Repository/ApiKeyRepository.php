<?php

declare(strict_types=1);

namespace Wezurlop\Repository;

use Wezurlop\Db\Connection;

/**
 * Klucze instalacji connectora, ich zakres firm (NIP), zgłoszenia firm i limity częstotliwości.
 * Klucz w bazie wyłącznie jako SHA-256 — jawna postać pokazywana raz, przy wydaniu.
 *
 * @phpstan-type KeyRow array{id: int, name: string, key_hash: string, status: string, replaced_by_id: ?int, revoked_at: ?string}
 */
final class ApiKeyRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** @return KeyRow|null */
    public function findByHash(string $hash): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, name, key_hash, status, replaced_by_id, revoked_at FROM api_key WHERE key_hash = :hash'
        );
        $stmt->execute(['hash' => $hash]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }

        return [
            'id' => (int) $row['id'], 'name' => (string) $row['name'], 'key_hash' => (string) $row['key_hash'],
            'status' => (string) $row['status'],
            'replaced_by_id' => $row['replaced_by_id'] === null ? null : (int) $row['replaced_by_id'],
            'revoked_at' => $row['revoked_at'] === null ? null : (string) $row['revoked_at'],
        ];
    }

    /** @return array{id: int, name: string, key_prefix: string, status: string, replaced_by_id: ?int, last_used_at: ?string}|null */
    public function find(int $keyId): ?array
    {
        // ⚠ `key_prefix` jest tu po to, żeby wpis w dzienniku o skasowaniu klucza mógł podać,
        // KTÓRY to był klucz. Prefiks nie jest sekretem (osiem znaków, pokazujemy go w wykazie),
        // a sam identyfikator po skasowaniu wiersza nie znaczy już nic.
        $stmt = $this->db->pdo()->prepare('SELECT id, name, key_prefix, status, replaced_by_id, last_used_at FROM api_key WHERE id = :id');
        $stmt->execute(['id' => $keyId]);
        $row = $stmt->fetch();

        return is_array($row) ? [
            'id' => (int) $row['id'], 'name' => (string) $row['name'],
            'key_prefix' => (string) $row['key_prefix'], 'status' => (string) $row['status'],
            'replaced_by_id' => $row['replaced_by_id'] === null ? null : (int) $row['replaced_by_id'],
            'last_used_at' => $row['last_used_at'] === null ? null : (string) $row['last_used_at'],
        ] : null;
    }

    public function touch(int $keyId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE api_key SET last_used_at = UTC_TIMESTAMP()
             WHERE id = :id AND (last_used_at IS NULL OR last_used_at < UTC_TIMESTAMP() - INTERVAL 60 SECOND)'
        );
        $stmt->execute(['id' => $keyId]);
    }

    /** @param list<string> $nips */
    /** @param list<string> $nips — wywołujący obejmuje całość transakcją */
    public function create(string $name, string $hash, string $prefix, ?int $createdBy, array $nips): int
    {
        $stmt = $this->db->pdo()->prepare(
            "INSERT INTO api_key (name, key_hash, key_prefix, status, created_by_account_id, created_at)
             VALUES (:name, :hash, :prefix, 'active', :by, UTC_TIMESTAMP())"
        );
        $stmt->execute(['name' => $name, 'hash' => $hash, 'prefix' => $prefix, 'by' => $createdBy]);
        $id = (int) $this->db->pdo()->lastInsertId();
        foreach (array_unique($nips) as $nip) {
            $this->addNip($id, $nip);
        }

        return $id;
    }

    /** Wskazanie następcy — bez terminu: stary klucz działa, dopóki nowy nie domknie przebiegu. */
    public function markReplaced(int $oldId, int $newId): void
    {
        $stmt = $this->db->pdo()->prepare(
            "UPDATE api_key SET replaced_by_id = :new WHERE id = :old AND status = 'active'"
        );
        $stmt->execute(['new' => $newId, 'old' => $oldId]);
    }

    /**
     * Klucz, w którego miejsce wydano podany — czyli poprzednik w trwającej nakładce.
     *
     * @return array{id: int, name: string}|null
     */
    /** Klucz, który ten klucz zastępuje — czyli ten do unieważnienia przy przejęciu. */
    public function poprzednikKlucza(int $newId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, name FROM api_key WHERE replaced_by_id = :new AND status = 'active' LIMIT 1"
        );
        $stmt->execute(['new' => $newId]);
        $row = $stmt->fetch();

        return is_array($row) ? ['id' => (int) $row['id'], 'name' => (string) $row['name']] : null;
    }

    public function revoke(int $keyId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "UPDATE api_key SET status = 'revoked', revoked_at = UTC_TIMESTAMP() WHERE id = :id AND status = 'active'"
        );
        $stmt->execute(['id' => $keyId]);

        return $stmt->rowCount() === 1;
    }

    /**
     * Ile przebiegów synchronizacji uwierzytelnił ten klucz.
     *
     * ⚠ Liczba potrzebna **przed** skasowaniem, nie po: `sync_run.api_key_id` ma `ON DELETE SET
     * NULL`, więc usunięcie klucza nie usuwa przebiegów, ale **zdejmuje z nich informację, który
     * klucz je uwierzytelnił**. Ekran potwierdzenia ma tę liczbę pokazać, żeby decyzja była
     * świadoma, a nie cicha.
     */
    public function przebiegowKlucza(int $keyId): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM sync_run WHERE api_key_id = :id');
        $stmt->execute(['id' => $keyId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Skasowanie klucza — **wyłącznie unieważnionego**, i ten warunek stoi W ZAPYTANIU.
     *
     * ⚠ `AND status <> 'active'` nie jest ozdobą obok ukrytego przycisku: żądanie da się wysłać
     * wprost, a skasowanie czynnego klucza zatrzymałoby synchronizację u klienta bez żadnego
     * śladu w interfejsie (CLAUDE.md §10 — o dostępie decyduje serwer, nie obecność przycisku).
     * Czynny klucz trzeba najpierw unieważnić; to osobna, odwracalna decyzja z własnym ekranem.
     *
     * ⚠ Co ginie razem z wierszem: przypisania firm (`api_key_company`), licznik limitu
     * i wpisy idempotencji — wszystkie z `ON DELETE CASCADE`, wszystkie bez wartości historycznej.
     * Zostają: przebiegi (bez wskazania klucza), zgłoszenia firm (`SET NULL`) i **dziennik
     * zdarzeń**, w którym zapisujemy nazwę i prefiks, żeby ślad przeżył wiersz.
     *
     * @return bool `false` znaczy „nie było czego skasować albo klucz jest czynny"
     */
    public function usun(int $keyId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "DELETE FROM api_key WHERE id = :id AND status <> 'active'"
        );
        $stmt->execute(['id' => $keyId]);

        return $stmt->rowCount() === 1;
    }

    /** @return list<array{id: int, name: string, key_prefix: string, status: string, created_at: string, replaced_by_id: ?int, last_used_at: ?string, nips: list<string>}> */
    public function all(): array
    {
        $keys = [];
        foreach ($this->db->pdo()->query(
            'SELECT id, name, key_prefix, status, created_at, replaced_by_id, last_used_at FROM api_key ORDER BY id DESC'
        )->fetchAll() as $row) {
            $keys[(int) $row['id']] = [
                'id' => (int) $row['id'], 'name' => (string) $row['name'], 'key_prefix' => (string) $row['key_prefix'],
                'status' => (string) $row['status'], 'created_at' => (string) $row['created_at'],
                'replaced_by_id' => $row['replaced_by_id'] === null ? null : (int) $row['replaced_by_id'],
                'last_used_at' => $row['last_used_at'] === null ? null : (string) $row['last_used_at'], 'nips' => [],
            ];
        }
        foreach ($this->db->pdo()->query('SELECT api_key_id, nip FROM api_key_company ORDER BY nip')->fetchAll() as $row) {
            if (isset($keys[(int) $row['api_key_id']])) {
                $keys[(int) $row['api_key_id']]['nips'][] = (string) $row['nip'];
            }
        }

        return array_values($keys);
    }

    /** @return list<string> */
    public function nips(int $keyId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT nip FROM api_key_company WHERE api_key_id = :id ORDER BY nip');
        $stmt->execute(['id' => $keyId]);

        return array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function hasNip(int $keyId, string $nip): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM api_key_company WHERE api_key_id = :id AND nip = :nip');
        $stmt->execute(['id' => $keyId, 'nip' => $nip]);

        return $stmt->fetchColumn() !== false;
    }

    public function addNip(int $keyId, string $nip): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT IGNORE INTO api_key_company (api_key_id, nip, added_at) VALUES (:id, :nip, UTC_TIMESTAMP())'
        );
        $stmt->execute(['id' => $keyId, 'nip' => $nip]);
    }

    /**
     * Inny AKTYWNY klucz z tą firmą w zakresie — ⚠ dwa connectory na jednym strumieniu to skaczące kursory
     * i zadania pobrane dwa razy. Klucz wydany w ramach wymiany nie liczy się jako „inny”: to ta sama
     * instalacja w trakcie przesiadki, a nie druga.
     *
     * @return array{id: int, name: string, created_at: string}|null
     */
    public function activeKeyForNip(string $nip, ?int $exceptKeyId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT k.id, k.name, k.created_at FROM api_key_company c JOIN api_key k ON k.id = c.api_key_id
             WHERE c.nip = :nip AND k.status = 'active'
               AND (:except IS NULL OR (k.id <> :except2 AND (k.replaced_by_id IS NULL OR k.replaced_by_id <> :except3)))
             ORDER BY k.id LIMIT 1"
        );
        $stmt->execute(['nip' => $nip, 'except' => $exceptKeyId, 'except2' => $exceptKeyId ?? 0, 'except3' => $exceptKeyId ?? 0]);
        $row = $stmt->fetch();

        return is_array($row) ? ['id' => (int) $row['id'], 'name' => (string) $row['name'], 'created_at' => (string) $row['created_at']] : null;
    }

    public function removeNip(int $keyId, string $nip): void
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM api_key_company WHERE api_key_id = :id AND nip = :nip');
        $stmt->execute(['id' => $keyId, 'nip' => $nip]);
    }

    // --- limity częstotliwości (okno stałe) --------------------------------------------------------

    /** Liczba trafień w bieżącym oknie po doliczeniu tego żądania. */
    public function hit(int $keyId, string $bucket, int $windowSeconds): int
    {
        $windowStart = gmdate('Y-m-d H:i:s', intdiv(time(), $windowSeconds) * $windowSeconds);
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO api_rate_counter (api_key_id, bucket, window_start, hits) VALUES (:id, :bucket, :start, 1)
             ON DUPLICATE KEY UPDATE hits = hits + 1'
        );
        $stmt->execute(['id' => $keyId, 'bucket' => $bucket, 'start' => $windowStart]);
        $read = $this->db->pdo()->prepare(
            'SELECT hits FROM api_rate_counter WHERE api_key_id = :id AND bucket = :bucket AND window_start = :start'
        );
        $read->execute(['id' => $keyId, 'bucket' => $bucket, 'start' => $windowStart]);
        $hits = (int) $read->fetchColumn();
        if ($hits === 1) {
            // Nowe okno — stare liczniki tego klucza nie są już potrzebne.
            $clean = $this->db->pdo()->prepare(
                'DELETE FROM api_rate_counter WHERE api_key_id = :id AND bucket = :bucket AND window_start < :start'
            );
            $clean->execute(['id' => $keyId, 'bucket' => $bucket, 'start' => $windowStart]);
        }

        return $hits;
    }

    // --- zgłoszenia firm ------------------------------------------------------------------------------

    /** Zgłoszenie bez duplikatu: powtórne zgłoszenie tego samego NIP-u niczego nie tworzy. */
    /**
     * Zgłoszenie (jedno na NIP). Nowe → pending; zatwierdzone dla innego klucza → z powrotem pending
     * z tym kluczem; pending i rejected bez zmian (odrzucenie cofa tylko administrator).
     */
    public function signup(string $nip, string $name, int $keyId): void
    {
        $stmt = $this->db->pdo()->prepare(
            "INSERT INTO company_signup (nip, name, api_key_id, status, created_at)
             VALUES (:nip, :name, :key, 'pending', UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
                api_key_id = IF(status = 'approved', :key2, api_key_id),
                decided_at = IF(status = 'approved', NULL, decided_at),
                decided_by_account_id = IF(status = 'approved', NULL, decided_by_account_id),
                status = IF(status = 'approved', 'pending', status)"
        );
        $stmt->execute(['nip' => $nip, 'name' => $name, 'key' => $keyId, 'key2' => $keyId]);
    }

    /** @return array{id: int, status: string}|null */
    public function signupByNip(string $nip): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id, status FROM company_signup WHERE nip = :nip');
        $stmt->execute(['nip' => $nip]);
        $r = $stmt->fetch();

        return is_array($r) ? ['id' => (int) $r['id'], 'status' => (string) $r['status']] : null;
    }

    /** Cofnięcie odrzucenia — zgłoszenie wraca do decyzji. */
    public function reopenSignup(int $id): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "UPDATE company_signup SET status = 'pending', decided_at = NULL, decided_by_account_id = NULL WHERE id = :id AND status = 'rejected'"
        );
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() === 1;
    }

    /** @return list<array{id: int, nip: string, name: string, status: string, created_at: string, api_key_id: ?int}> */
    public function signups(): array
    {
        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'], 'nip' => (string) $r['nip'], 'name' => (string) $r['name'], 'status' => (string) $r['status'],
            'created_at' => (string) $r['created_at'], 'api_key_id' => $r['api_key_id'] === null ? null : (int) $r['api_key_id'],
        ], $this->db->pdo()->query(
            "SELECT id, nip, name, status, created_at, api_key_id FROM company_signup ORDER BY status = 'pending' DESC, created_at DESC"
        )->fetchAll());
    }

    /** @return array{id: int, nip: string, name: string, status: string, api_key_id: ?int}|null */
    public function findSignup(int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id, nip, name, status, api_key_id FROM company_signup WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $r = $stmt->fetch();

        return is_array($r) ? [
            'id' => (int) $r['id'], 'nip' => (string) $r['nip'], 'name' => (string) $r['name'], 'status' => (string) $r['status'],
            'api_key_id' => $r['api_key_id'] === null ? null : (int) $r['api_key_id'],
        ] : null;
    }

    public function decideSignup(int $id, string $status, ?int $accountId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "UPDATE company_signup SET status = :status, decided_at = UTC_TIMESTAMP(), decided_by_account_id = :by
             WHERE id = :id AND status = 'pending'"
        );
        $stmt->execute(['status' => $status, 'by' => $accountId, 'id' => $id]);

        return $stmt->rowCount() === 1;
    }
}
