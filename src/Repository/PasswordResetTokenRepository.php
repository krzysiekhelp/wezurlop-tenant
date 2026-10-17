<?php

declare(strict_types=1);

namespace Wezurlop\Repository;

use Wezurlop\Db\Connection;

/**
 * Tokeny resetu hasła (docs/szkielet-tenanta.md rozdz. 6).
 * W bazie wyłącznie hash SHA-256 tokenu; jawny token istnieje tylko w wysłanej wiadomości.
 */
final class PasswordResetTokenRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function create(int $accountId, string $tokenHash, int $validMinutes, ?string $ipHash): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO password_reset_token (account_id, token_hash, expires_at, requested_ip_hash)
             VALUES (:account, :hash, UTC_TIMESTAMP() + INTERVAL :minutes MINUTE, :ip_hash)'
        );
        $stmt->execute([
            'account' => $accountId,
            'hash'    => $tokenHash,
            'minutes' => $validMinutes,
            'ip_hash' => $ipHash,
        ]);
    }

    /** @return int|null konto, do którego należy ważny (nieużyty, niewygasły) token */
    public function validAccountId(string $tokenHash): ?int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT account_id FROM password_reset_token
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
            'UPDATE password_reset_token SET used_at = UTC_TIMESTAMP()
             WHERE token_hash = :hash AND used_at IS NULL AND expires_at > UTC_TIMESTAMP()'
        );
        $stmt->execute(['hash' => $tokenHash]);

        return $stmt->rowCount() === 1 ? $accountId : null;
    }

    /** Unieważnienie wszystkich niezużytych tokenów konta (np. po zmianie hasła). */
    public function revokeAllForAccount(int $accountId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE password_reset_token SET used_at = UTC_TIMESTAMP() WHERE account_id = :account AND used_at IS NULL'
        );
        $stmt->execute(['account' => $accountId]);
    }

    public function deleteByHash(string $tokenHash): void
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM password_reset_token WHERE token_hash = :hash');
        $stmt->execute(['hash' => $tokenHash]);
    }
}
