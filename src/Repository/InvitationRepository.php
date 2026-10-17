<?php

declare(strict_types=1);

namespace Wezurlop\Repository;

use Wezurlop\Db\Connection;

/**
 * Zaproszenia — jednorazowe linki ustawiające pierwsze hasło (docs/szkielet-tenanta.md rozdz. 6).
 * W bazie wyłącznie hash SHA-256 tokenu; jawny token istnieje tylko w wysłanej wiadomości.
 */
final class InvitationRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function create(int $accountId, string $tokenHash, int $validMinutes, ?int $createdBy): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO invitation (account_id, token_hash, expires_at, created_by)
             VALUES (:account, :hash, UTC_TIMESTAMP() + INTERVAL :minutes MINUTE, :created_by)'
        );
        $stmt->execute([
            'account' => $accountId,
            'hash'    => $tokenHash,
            'minutes' => $validMinutes,
            'created_by' => $createdBy,
        ]);
    }

    /** @return int|null konto, do którego należy ważny (nieużyty, niewygasły) token */
    public function validAccountId(string $tokenHash): ?int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT account_id FROM invitation
             WHERE token_hash = :hash AND used_at IS NULL AND expires_at > UTC_TIMESTAMP()'
        );
        $stmt->execute(['hash' => $tokenHash]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : (int) $value;
    }

    /**
     * Zużycie tokenu. Warunek w UPDATE rozstrzyga wyścig: z dwóch równoległych żądań wygrywa jedno.
     *
     * @return int|null konto, jeśli token był ważny i został właśnie zużyty
     */
    public function consume(string $tokenHash): ?int
    {
        $accountId = $this->validAccountId($tokenHash);
        if ($accountId === null) {
            return null;
        }
        $stmt = $this->db->pdo()->prepare(
            'UPDATE invitation SET used_at = UTC_TIMESTAMP()
             WHERE token_hash = :hash AND used_at IS NULL AND expires_at > UTC_TIMESTAMP()'
        );
        $stmt->execute(['hash' => $tokenHash]);

        return $stmt->rowCount() === 1 ? $accountId : null;
    }

    /** Unieważnienie wszystkich niezużytych tokenów konta (np. po zmianie hasła). */
    /**
     * Kiedy ostatnio wydano zaproszenie temu kontu (także już unieważnione).
     *
     * ⚠ Liczy się **wydanie linku**, nie jego stan: po to, żeby odstęp między ponowieniami
     * obowiązywał również wtedy, gdy poprzedni link został właśnie unieważniony.
     */
    public function lastIssuedAt(int $accountId): ?string
    {
        return $this->ostatniLink($accountId)['wydany_utc'] ?? null;
    }

    /**
     * Ostatni wydany link **wraz ze stanem ważności**.
     *
     * ⚠ Sam czas wydania nie wystarcza i to jest **zmierzone na żywym ekranie**: panel osoby
     * pisał „Link wydany 22.09.2026, 12:50:59", a ten link był w tej chwili **unieważniony**
     * (`used_at` ustawione 45 minut później). Krzysztof przeczytał to zdanie tak, jak każdy
     * je przeczyta — że link żyje — i zgłosił sprzeczność z liczbą czynnych zaproszeń.
     * Zdanie mówiło o **wydaniu**, milcząc o **ważności**: dokładnie ta klasa usterki,
     * co „1 min temu" na karcie otwartej przed godziną.
     *
     * Trzy stany, bo trzy różne odpowiedzi dla człowieka: link **czynny**, link **wygaszony**
     * (unieważniony albo przeterminowany) i **żadnego linku nigdy**.
     *
     * @return array{wydany_utc: ?string, stan: string} stan: `czynny` | `wygaszony` | `brak`
     */
    public function ostatniLink(int $accountId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT created_at, used_at, expires_at FROM invitation
              WHERE account_id = :account ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute(['account' => $accountId]);
        $row = $stmt->fetch();
        if ($row === false) {
            return ['wydany_utc' => null, 'stan' => 'brak'];
        }
        $czynny = $row['used_at'] === null
            && (string) $row['expires_at'] > gmdate('Y-m-d H:i:s');

        return [
            'wydany_utc' => (string) $row['created_at'],
            'stan'       => $czynny ? 'czynny' : 'wygaszony',
        ];
    }

    public function revokeAllForAccount(int $accountId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE invitation SET used_at = UTC_TIMESTAMP() WHERE account_id = :account AND used_at IS NULL'
        );
        $stmt->execute(['account' => $accountId]);
    }

    public function deleteByHash(string $tokenHash): void
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM invitation WHERE token_hash = :hash');
        $stmt->execute(['hash' => $tokenHash]);
    }
}
