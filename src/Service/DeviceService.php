<?php

declare(strict_types=1);

namespace Wezurlop\Service;

use Wezurlop\Http\ClientKind;
use Wezurlop\Http\DatabaseSessionHandler;
use Wezurlop\Repository\EventLogRepository;
use Wezurlop\Repository\SessionRepository;
use Wezurlop\Security\ClientInfo;

/**
 * „Twoje urządzenia” (docs/szkielet-tenanta.md rozdz. 6): lista aktywnych sesji konta,
 * zakończenie jednej, wszystkich poza bieżącą i — przez administratora — wszystkich cudzego konta.
 * Właściciel zawsze sprawdzany w zapytaniu.
 */
final class DeviceService
{
    public function __construct(
        private readonly SessionRepository $sessions,
        private readonly EventLogRepository $events,
    ) {
    }

    /**
     * @return list<array{handle: string, device: string, network: ?string, created_at: string, last_seen_at: string, current: bool}>
     */
    public function listForAccount(int $accountId, string $currentSessionId): array
    {
        $current = DatabaseSessionHandler::hash($currentSessionId);
        $items = [];
        foreach ($this->sessions->activeForAccount($accountId) as $row) {
            $items[] = [
                // Uchwyt = hash identyfikatora; nie jest ciasteczkiem i nie pozwala się podszyć.
                'handle'       => (string) $row['id'],
                // Opis z sesji (zapisany przy logowaniu), a dla przeglądarki — z User-Agent.
                'device'       => ClientKind::describe((string) $row['client'], $row['app_version'])
                    ?? ClientInfo::describeUserAgent($row['user_agent']),
                'network'      => $row['ip_prefix'],
                // ⚠ Rodzaj do IKONY, nie do decyzji: aplikacja mobilna poznaje się po rejestrze
                // sesji (`client`), telefon w przeglądarce po `User-Agent` — a to dana od klienta,
                // więc wpływa wyłącznie na wygląd wiersza (Z69, CLAUDE.md §11).
                'kind'         => (string) $row['client'] === 'app'
                    ? 'app'
                    : (ClientInfo::mobilny($row['user_agent']) ? 'telefon' : 'komputer'),
                'created_at'   => (string) $row['created_at'],
                'last_seen_at' => (string) $row['last_seen_at'],
                'current'      => hash_equals($current, (string) $row['id']),
            ];
        }

        return $items;
    }

    public function revoke(int $accountId, string $handle, string $currentSessionId, ?string $ipHash): bool
    {
        if (preg_match('/^[0-9a-f]{64}$/', $handle) !== 1
            || hash_equals(DatabaseSessionHandler::hash($currentSessionId), $handle)) {
            // Bieżącej sesji nie kończymy z listy — do tego służy „Wyloguj”.
            return false;
        }
        if (!$this->sessions->revokeForAccount($handle, $accountId)) {
            return false;
        }
        $this->events->record('session.revoked', $accountId, $ipHash, null, 'session', null, null, ['scope' => 'one']);

        return true;
    }

    public function revokeOthers(int $accountId, string $currentSessionId, ?string $ipHash): int
    {
        $count = $this->sessions->revokeAllForAccount($accountId, DatabaseSessionHandler::hash($currentSessionId));
        $this->events->record('session.revoked', $accountId, $ipHash, null, 'session', null, null, ['scope' => 'others', 'count' => $count]);

        return $count;
    }

    /** Zakończenie wszystkich sesji cudzego konta przez administratora — z wpisem kto i komu. */
    public function revokeAllByAdmin(int $targetAccountId, int $adminAccountId, string $adminLabel, ?string $ipHash): int
    {
        $count = $this->sessions->revokeAllForAccount($targetAccountId);
        $this->events->record('session.revoked_by_admin', $adminAccountId, $ipHash, $adminLabel, 'account', $targetAccountId, null, ['count' => $count]);

        return $count;
    }
}
