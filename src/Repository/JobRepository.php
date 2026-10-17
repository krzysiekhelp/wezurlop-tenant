<?php

declare(strict_types=1);

namespace Wezurlop\Repository;

use Wezurlop\Db\Connection;
use Wezurlop\Service\JobPriority;

/**
 * Kolejka zadań (docs/szkielet-tenanta.md rozdz. 5). Ładunek bez sekretów i bez tokenów.
 *
 * @phpstan-type JobRow array{id: int, type: string, payload: array<string, mixed>, attempts: int}
 */
final class JobRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** @param array<string, scalar|null> $payload */
    public function enqueue(string $type, array $payload, int $delaySeconds = 0): int
    {
        $stmt = $this->db->pdo()->prepare(
            "INSERT INTO job (type, priority, payload, status, run_after, created_at)
             VALUES (:type, :priority, :payload, 'pending', UTC_TIMESTAMP() + INTERVAL :delay SECOND, UTC_TIMESTAMP())"
        );
        $stmt->execute([
            'type'     => $type,
            'priority' => JobPriority::forType($type),
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'delay'   => $delaySeconds,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * Zadanie cykliczne: jeśli nie czeka żadne zadanie tego typu, dodaje nowe — od razu, gdy ostatnie
     * wykonanie było dawniej niż $everySeconds, inaczej z odpowiednim opóźnieniem.
     */
    public function ensureScheduled(string $type, int $everySeconds): void
    {
        $pending = $this->db->pdo()->prepare("SELECT 1 FROM job WHERE type = :type AND status = 'pending' LIMIT 1");
        $pending->execute(['type' => $type]);
        if ($pending->fetchColumn() !== false) {
            return;
        }
        $last = $this->db->pdo()->prepare(
            "SELECT TIMESTAMPDIFF(SECOND, MAX(finished_at), UTC_TIMESTAMP()) FROM job WHERE type = :type AND status = 'done'"
        );
        $last->execute(['type' => $type]);
        $ago = $last->fetchColumn();
        $delay = is_numeric($ago) ? max(0, $everySeconds - (int) $ago) : 0;
        $this->enqueue($type, [], $delay);
    }

    /**
     * Pobranie porcji zadań z blokadą. Każde zadanie rezerwowane osobnym UPDATE z warunkiem —
     * dwa równoległe przebiegi crona nie wykonają tego samego zadania.
     *
     * Kolejność: priorytet (pilne pierwsze), potem termin — nie kolejność wstawienia.
     *
     * @return list<JobRow>
     */
    public function claim(int $limit, int $lockSeconds, int $maxPriority = 255): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT id FROM job
             WHERE status = 'pending' AND run_after <= UTC_TIMESTAMP() AND priority <= :max_priority
               AND (locked_until IS NULL OR locked_until < UTC_TIMESTAMP())
             ORDER BY priority, run_after, id LIMIT :limit"
        );
        $stmt->bindValue('limit', max(1, min($limit, 100)), \PDO::PARAM_INT);
        $stmt->bindValue('max_priority', max(0, min($maxPriority, 255)), \PDO::PARAM_INT);
        $stmt->execute();
        $ids = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));

        $lock = $this->db->pdo()->prepare(
            "UPDATE job SET locked_until = UTC_TIMESTAMP() + INTERVAL :lock SECOND, attempts = attempts + 1
             WHERE id = :id AND status = 'pending' AND (locked_until IS NULL OR locked_until < UTC_TIMESTAMP())"
        );
        $load = $this->db->pdo()->prepare('SELECT id, type, payload, attempts FROM job WHERE id = :id');
        $claimed = [];
        foreach ($ids as $id) {
            $lock->execute(['lock' => $lockSeconds, 'id' => $id]);
            if ($lock->rowCount() !== 1) {
                continue;
            }
            $load->execute(['id' => $id]);
            $row = $load->fetch();
            if (!is_array($row)) {
                continue;
            }
            $payload = json_decode((string) $row['payload'], true);
            $claimed[] = [
                'id'       => (int) $row['id'],
                'type'     => (string) $row['type'],
                'payload'  => is_array($payload) ? $payload : [],
                'attempts' => (int) $row['attempts'],
            ];
        }

        return $claimed;
    }

    /** Czy czeka zadanie o priorytecie co najmniej tak pilnym — tani test przed domknięciem kolejki. */
    public function hasDue(int $maxPriority): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT 1 FROM job WHERE status = 'pending' AND priority <= :max_priority AND run_after <= UTC_TIMESTAMP()
               AND (locked_until IS NULL OR locked_until < UTC_TIMESTAMP()) LIMIT 1"
        );
        $stmt->execute(['max_priority' => $maxPriority]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Czy w kolejce czeka już zaproszenie dla tego konta.
     *
     * ⚠ Bez tego „Wyślij ponownie" kliknięte pięć razy przed przebiegiem crona dałoby pięć
     * zadań i pięć wiadomości — czyli dokładnie to, przed czym ma chronić odstęp minimalny.
     * Odstęp liczony z wydanych linków tego nie łapie, bo linki powstają dopiero przy wysyłce.
     */
    public function hasPendingFor(string $type, int $accountId): bool
    {
        // ⚠ `JSON_EXTRACT(...) = :account` NIE DZIAŁA i to jest pułapka zmierzona, nie wyczytana:
        // PDO wiąże parametr jako TEKST, a `JSON_EXTRACT` zwraca wartość JSON — porównanie
        // liczby JSON z tekstem daje FAŁSZ, więc warunek po cichu nie łapał niczego.
        // Zmierzone na tych samych danych: `= "4"` → 0 wierszy, `= 4` → 2 wiersze.
        // Dlatego odcytowanie i rzutowanie, a wiązanie parametru jawnie jako liczba całkowita.
        $stmt = $this->db->pdo()->prepare(
            "SELECT 1 FROM job
             WHERE type = :type AND status = 'pending'
               AND CAST(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.account_id')) AS UNSIGNED) = :account
             LIMIT 1"
        );
        $stmt->bindValue('type', $type);
        $stmt->bindValue('account', $accountId, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Ostatnie zadanie tego typu dla konta — **liczby i znaczniki czasu, nigdy treść**.
     *
     * ⚠ Po co, skoro jest `hasPendingFor()`: „czeka" i „wyszło" to dwa różne zdania dla
     * człowieka, a drugiego nie da się napisać uczciwie bez `finished_at`. Zmierzone na
     * zadaniu `#176`: utworzone 09:41:47, zakończone **09:45:02** — czyli 3 min 15 s później.
     * Bez tej daty panel mógłby napisać tylko „wysłane", a pytanie brzmi „to dlaczego
     * nie ma go w skrzynce".
     *
     * ⚠ `finished_at` znaczy **„przekaźnik przyjął"**, nie „doszło" (docs/audyt.md) — i tak
     * ma być nazwane na ekranie.
     *
     * @return array{status: string, utworzone_utc: string, zakonczone_utc: ?string}|null
     */
    public function ostatnieZadanieDla(string $type, int $accountId): ?array
    {
        // ⚠ To samo odcytowanie i rzutowanie co w `hasPendingFor()` — z tego samego,
        // zmierzonego powodu (porównanie liczby JSON z tekstem z PDO daje fałsz).
        $stmt = $this->db->pdo()->prepare(
            "SELECT status, created_at, finished_at FROM job
             WHERE type = :type
               AND CAST(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.account_id')) AS UNSIGNED) = :account
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->bindValue('type', $type);
        $stmt->bindValue('account', $accountId, \PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }

        return [
            'status'         => (string) $row['status'],
            'utworzone_utc'  => (string) $row['created_at'],
            'zakonczone_utc' => $row['finished_at'] === null ? null : (string) $row['finished_at'],
        ];
    }

    /**
     * Podsumowanie kolejki do ekranu — **liczby, nigdy treść** (zlecenie §4).
     *
     * ⚠ Bez treści wiadomości i bez adresatów: ekran ma odpowiadać na pytanie „czy wysyłka
     * ruszyła", a nie pokazywać, do kogo co poszło. Kto i komu — to dziennik zdarzeń,
     * z uprawnieniem `admin.event_log`.
     *
     * ⚠ **„Czeka" i „czeka już teraz" to dwie różne liczby.** Zadania cykliczne
     * (`maintenance.*`) stoją w kolejce ze `run_after` w przyszłości, więc liczone razem
     * z zaległymi dawały na ekranie „najdłużej czekające: 20 godzin" dla zadania, które
     * **nie jest spóźnione ani o sekundę** — zmierzone na zrzucie karty. Zaległość liczymy
     * wyłącznie z zadań, których termin już minął.
     *
     * @return array{czeka: int, czeka_gotowe: int, czeka_poczta: int, nieudane: int,
     *     najstarsze_utc: ?string, ostatni_blad_utc: ?string, typy: list<array{typ: string, ile: int}>}
     */
    public function podsumowanie(): array
    {
        // ⚠ Zaległość liczona od TERMINU zadania (`run_after`), nie od jego utworzenia:
        // zadanie cykliczne powstaje długo przed swoim terminem, więc liczone od `created_at`
        // pokazywało „najdłużej zaległe 54 minuty" dla zadania, które czekało poprawnie.
        $pdo = $this->db->pdo();
        $liczby = $pdo->query(
            "SELECT
                SUM(status = 'pending') AS czeka,
                SUM(status = 'pending' AND run_after <= UTC_TIMESTAMP()) AS czeka_gotowe,
                SUM(status = 'pending' AND type LIKE 'mail.%') AS czeka_poczta,
                SUM(status = 'failed') AS nieudane,
                MIN(CASE WHEN status = 'pending' AND run_after <= UTC_TIMESTAMP() THEN run_after END) AS najstarsze,
                MAX(CASE WHEN status = 'failed' THEN finished_at END) AS ostatni_blad
             FROM job"
        )->fetch();
        $typy = $pdo->query(
            "SELECT type, COUNT(*) AS ile FROM job WHERE status = 'pending'
             GROUP BY type ORDER BY ile DESC, type"
        )->fetchAll();

        return [
            'czeka'        => (int) ($liczby['czeka'] ?? 0),
            'czeka_gotowe' => (int) ($liczby['czeka_gotowe'] ?? 0),
            'czeka_poczta' => (int) ($liczby['czeka_poczta'] ?? 0),
            'nieudane'     => (int) ($liczby['nieudane'] ?? 0),
            'najstarsze_utc'   => $liczby['najstarsze'] === null ? null : (string) $liczby['najstarsze'],
            'ostatni_blad_utc' => $liczby['ostatni_blad'] === null ? null : (string) $liczby['ostatni_blad'],
            'typy' => array_map(
                static fn (array $r): array => ['typ' => (string) $r['type'], 'ile' => (int) $r['ile']],
                $typy,
            ),
        ];
    }

    /** Czy w kolejce czeka zadanie tego typu (bez patrzenia w ładunek). */
    public function hasPendingType(string $type): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT 1 FROM job WHERE type = :type AND status = 'pending' LIMIT 1"
        );
        $stmt->execute(['type' => $type]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Usunięcie zadania z kolejki — **bez śladu**.
     *
     * ⚠ Używane wyłącznie przez sondę crona: zadanie, które istnieje po to, żeby zostać
     * wykonanym, nie ma prawa zaśmiecać historii kolejki, do której zagląda się przy awarii
     * (zlecenie `link-i-cron-przyjete` §2.1).
     */
    public function forget(int $id): void
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM job WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public function markDone(int $id): void
    {
        $stmt = $this->db->pdo()->prepare(
            "UPDATE job SET status = 'done', locked_until = NULL, last_error = NULL, finished_at = UTC_TIMESTAMP()
             WHERE id = :id"
        );
        $stmt->execute(['id' => $id]);
    }

    public function markRetry(int $id, string $error, int $delaySeconds): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE job SET locked_until = NULL, last_error = :error,
                run_after = UTC_TIMESTAMP() + INTERVAL :delay SECOND
             WHERE id = :id'
        );
        $stmt->execute(['error' => mb_substr($error, 0, 500), 'delay' => $delaySeconds, 'id' => $id]);
    }

    public function markFailed(int $id, string $error): void
    {
        $stmt = $this->db->pdo()->prepare(
            "UPDATE job SET status = 'failed', locked_until = NULL, last_error = :error, finished_at = UTC_TIMESTAMP()
             WHERE id = :id"
        );
        $stmt->execute(['error' => mb_substr($error, 0, 500), 'id' => $id]);
    }
}
