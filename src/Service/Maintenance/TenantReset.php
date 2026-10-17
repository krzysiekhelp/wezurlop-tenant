<?php

declare(strict_types=1);

namespace Wezurlop\Service\Maintenance;

use InvalidArgumentException;
use PDO;
use Wezurlop\Db\Connection;
use Wezurlop\Repository\EventLogRepository;

/**
 * Reset instalacji do stanu „jak po instalacji”: znikają dane, zostaje schemat, uprawnienia, role,
 * ustawienia w wartościach domyślnych i konta administratorów.
 *
 * ⚠ To jest **zwykły serwis aplikacji**, nie skrypt do wyrzucenia: ta sama logika wejdzie kiedyś do
 * wersji klienckiej jako „reset instalacji”, z innymi zabezpieczeniami (liczby przed potwierdzeniem,
 * przepisanie nazwy firmy, hasło administratora, porcje w kolejce, tryb serwisowy). Dlatego warunek
 * „tylko na dev” siedzi w **wejściach** (CLI i kontroler), a nie tutaj — zabezpieczenia wymieni się
 * bez ruszania tego, co kasuje.
 *
 * ⚠ Czego NIE ruszamy i dlaczego:
 * - `.env`, a w nim klucz HMAC identyfikatorów — po ponownym imporcie te same osoby mają dostać
 *   te same identyfikatory; zmiana klucza założyłaby komplet nowych osób i test byłby bez wartości,
 * - `permission`, `role`, `role_permission` — słownik uprawnień jest częścią schematu, nie danymi,
 * - konta z przypisaniem na całego tenanta (administratorzy) razem z ich osobami, 2FA, kodami
 *   zapasowymi i tożsamościami zewnętrznymi — inaczej nie ma czym się zalogować,
 * - `canary_*` w `tenant_setting` — opisują plik kanarka, który zostaje na dysku.
 *
 * ⚠ Jeden wpis w `event_log` PRZEŻYWA czyszczenie: kto, kiedy i ile rekordów w której tabeli zniknęło.
 * Bez niego za tydzień nikt nie odpowie na pytanie, dlaczego baza jest pusta.
 */
final class TenantReset
{
    /**
     * Kolejność ma znaczenie: dzieci przed rodzicami, bo klucze obce nie są wyłączane
     * (wyłączenie `FOREIGN_KEY_CHECKS` ukryłoby błąd w kolejności zamiast go pokazać).
     */
    private const TABLES = [
        // synchronizacja — najpierw to, co wskazuje na przebiegi i rekordy
        'sync_review_seen',
        'sync_idempotency',
        'sync_task',
        'sync_conflict',
        'sync_record',
        'sync_cursor',
        'sync_stream',
        'sync_run',
        'api_rate_counter',
        'api_key_company',
        'api_key',
        'company_signup',
        // wnioski urlopowe i ich ślady
        'leave_request_r2_record',
        'leave_request_day',
        'leave_decision',
        'leave_comment',
        'leave_request',
        // dane kadrowe
        'external_absence',
        'leave_balance',
        'leave_type',
        'company_calendar',
        'employment',
        // ⚠ Sama firma na końcu danych kadrowych: employment i external_absence trzymają ją przez
        // RESTRICT, więc kolejność jest warunkiem powodzenia, nie kosmetyką.
        'company',
        'person_external_ref',
        // kolejka zadań: po skasowaniu wniosków i firm zadania wskazywałyby na nieistniejące rekordy
        'job',
        // ślady logowań i zaproszeń — po resecie nie mają do czego się odnosić
        'invitation',
        'password_reset_token',
        'login_attempt',
        'remembered_device',
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly EventLogRepository $events,
        private readonly string $appRoot,
    ) {
    }

    /**
     * @param int|null $accountId konto wykonujące reset (CLI: null)
     * @param string|null $keepSessionId sesja, która ma przeżyć — inaczej przeglądarka wylądowałaby
     *                                   na ekranie logowania zamiast na kreatorze podłączenia
     * @return array{tabele: array<string, int>, czasMs: float}
     */
    public function run(?int $accountId, string $actorLabel, ?string $keepSessionId = null): array
    {
        $start = hrtime(true);
        $counts = $this->db->transaction(function () use ($keepSessionId): array {
            $counts = [];
            foreach (self::TABLES as $table) {
                $counts[$table] = $this->deleteAll($table);
            }
            $counts['session'] = $this->deleteSessions($keepSessionId);
            $counts['account'] = $this->deleteNonAdminAccounts();
            $counts['person'] = $this->deleteOrphanPeople();
            $counts['role_assignment'] = $this->deleteCompanyAssignments();
            $counts['tenant_setting'] = $this->restoreSettings();
            $counts['event_log'] = $this->deleteAll('event_log');

            return $counts;
        });
        $ms = (hrtime(true) - $start) / 1e6;
        // Ślad samego resetu — dopisany PO wyczyszczeniu dziennika, więc zostaje jako jedyny.
        $this->events->record('tenant.reset', $accountId, null, $actorLabel, 'tenant', null, null, [
            'tabele' => array_filter($counts, static fn (int $n): bool => $n > 0),
            'czasMs' => round($ms, 1),
        ]);

        return ['tabele' => $counts, 'czasMs' => round($ms, 1)];
    }

    /** @return list<string> tabele czyszczone przez reset — do pokazania przed potwierdzeniem */
    public static function tables(): array
    {
        return [...self::TABLES, 'session', 'account', 'person', 'role_assignment', 'tenant_setting', 'event_log'];
    }

    private function deleteAll(string $table): int
    {
        return $this->db->pdo()->exec('DELETE FROM ' . self::tableName($table)) ?: 0;
    }

    /**
     * ⚠ Nazwy tabeli nie da się sparametryzować, więc bierze się WYŁĄCZNIE z białej listy w kodzie —
     * nigdy z wejścia (CLAUDE.md §4). Poza listą: wyjątek, nie zapytanie.
     */
    private static function tableName(string $table): string
    {
        if (!in_array($table, [...self::TABLES, 'event_log'], true)) {
            throw new InvalidArgumentException('Tabela spoza listy resetu: ' . $table);
        }

        return '`' . $table . '`';
    }

    private function deleteSessions(?string $keepSessionId): int
    {
        if ($keepSessionId === null) {
            return $this->db->pdo()->exec('DELETE FROM session') ?: 0;
        }
        $stmt = $this->db->pdo()->prepare('DELETE FROM session WHERE id <> :id');
        $stmt->execute(['id' => hash('sha256', $keepSessionId)]);

        return $stmt->rowCount();
    }

    /**
     * Konta bez przypisania na całego tenanta (konta pracowników z testów) znikają razem z danymi —
     * ich osoby i tak przestaną istnieć. Administratorzy zostają.
     */
    private function deleteNonAdminAccounts(): int
    {
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM account WHERE id NOT IN (SELECT account_id FROM role_assignment WHERE company_id = 0)'
        );
        $stmt->execute();

        return $stmt->rowCount();
    }

    /** Osoby bez konta — po skasowaniu zatrudnień nie ma ich z czym powiązać. */
    private function deleteOrphanPeople(): int
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM person WHERE id NOT IN (SELECT person_id FROM account WHERE person_id IS NOT NULL)');
        $stmt->execute();

        return $stmt->rowCount();
    }

    /** Przypisania ról do konkretnych firm — firmy właśnie zniknęły. */
    private function deleteCompanyAssignments(): int
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM role_assignment WHERE company_id <> 0');
        $stmt->execute();

        return $stmt->rowCount();
    }

    /**
     * Ustawienia do wartości domyślnych z `config/tenant-defaults.php`: wartości nadpisane, klucze
     * spoza listy (i spoza zachowanych przedrostków) skasowane jako pozostałość.
     */
    private function restoreSettings(): int
    {
        /** @var array{defaults: array<string, string>, preserved_prefixes: list<string>} $config */
        $config = require $this->appRoot . '/config/tenant-defaults.php';
        $changed = 0;
        $update = $this->db->pdo()->prepare('UPDATE tenant_setting SET value = :v, updated_at = UTC_TIMESTAMP() WHERE `key` = :k AND value <> :v2');
        $insert = $this->db->pdo()->prepare('INSERT IGNORE INTO tenant_setting (`key`, value, updated_at) VALUES (:k, :v, UTC_TIMESTAMP())');
        foreach ($config['defaults'] as $key => $value) {
            $update->execute(['k' => $key, 'v' => $value, 'v2' => $value]);
            $changed += $update->rowCount();
            $insert->execute(['k' => $key, 'v' => $value]);
            $changed += $insert->rowCount();
        }
        $keep = array_keys($config['defaults']);
        $rows = $this->db->pdo()->query('SELECT `key` FROM tenant_setting')->fetchAll(PDO::FETCH_COLUMN);
        $delete = $this->db->pdo()->prepare('DELETE FROM tenant_setting WHERE `key` = :k');
        foreach ($rows as $key) {
            $key = (string) $key;
            if (in_array($key, $keep, true) || self::preserved($key, $config['preserved_prefixes'])) {
                continue;
            }
            $delete->execute(['k' => $key]);
            $changed += $delete->rowCount();
        }

        return $changed;
    }

    /** @param list<string> $prefixes */
    private static function preserved(string $key, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
