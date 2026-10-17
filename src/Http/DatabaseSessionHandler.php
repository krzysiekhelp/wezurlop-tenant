<?php

declare(strict_types=1);

namespace Wezurlop\Http;

use SessionHandlerInterface;
use SessionUpdateTimestampHandlerInterface;
use Wezurlop\Repository\SessionRepository;

/**
 * Sesje w bazie (CLAUDE.md §8, docs/szkielet-tenanta.md rozdz. 5).
 *
 * - identyfikator w bazie wyłącznie jako hash SHA-256,
 * - lazy_write: bez zmian danych PHP woła updateTimestamp(), który odświeża aktywność najwyżej
 *   raz na minutę,
 * - sesja unieważniona lub wygasła nie jest wczytywana (validateId → false, PHP nadaje nowy
 *   identyfikator) — unieważnienie działa przy następnym żądaniu,
 * - regeneracja identyfikatora usuwa stary wiersz (destroy) i zapisuje nowy — bez sierot,
 * - sprzątanie przez kolejkę zadań, nie przez GC PHP.
 */
final class DatabaseSessionHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
{
    public const REJECTED_REVOKED = 'revoked';
    public const REJECTED_EXPIRED = 'expired';

    private ?string $rejected = null;

    /**
     * Konto odrzuconej sesji — potrzebne, żeby ekran logowania umiał powiedzieć **dlaczego**.
     *
     * ⚠ Nie służy do żadnej decyzji o dostępie: sesja jest już odrzucona i nic jej nie wraca.
     * Wyłącznie do rozróżnienia komunikatu „ta sesja została zakończona" od „konto zablokowano".
     */
    private ?int $rejectedAccountId = null;

    /**
     * @param array{ip_hash: ?string, ip_prefix: ?string, user_agent: ?string} $client
     * @param callable(): array{account_id: ?int, employment_id: ?int, client: string, app_version: ?string} $metadata
     */
    public function __construct(
        private readonly SessionRepository $sessions,
        private readonly array $client,
        private readonly int $idleSeconds,
        private $metadata,
    ) {
    }

    /** Dlaczego sesja z ciasteczka nie została wczytana (dla komunikatu na ekranie logowania). */
    public function rejectedReason(): ?string
    {
        return $this->rejected;
    }

    /** Konto odrzuconej sesji — tylko do doprecyzowania komunikatu, nigdy do dostępu. */
    public function rejectedAccountId(): ?int
    {
        return $this->rejectedAccountId;
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        $row = $this->sessions->find(self::hash($id));

        return $row !== null && $row['is_active'] ? $row['payload'] : '';
    }

    public function write(string $id, string $data): bool
    {
        $meta = ($this->metadata)();
        $this->sessions->save(self::hash($id), $data, $meta, $this->client, $this->idleSeconds);

        return true;
    }

    public function destroy(string $id): bool
    {
        $this->sessions->delete(self::hash($id));

        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        return 0;
    }

    public function validateId(string $id): bool
    {
        $row = $this->sessions->find(self::hash($id));
        if ($row !== null && !$row['is_active']) {
            $this->rejected = $row['revoked_at'] !== null ? self::REJECTED_REVOKED : self::REJECTED_EXPIRED;
            $this->rejectedAccountId = $row['account_id'];
        }

        return $row !== null && $row['is_active'];
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        $this->sessions->touch(self::hash($id), $this->idleSeconds);

        return true;
    }

    public static function hash(string $id): string
    {
        return hash('sha256', $id);
    }
}
