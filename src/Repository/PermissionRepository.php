<?php

declare(strict_types=1);

namespace Wezurlop\Repository;

use Wezurlop\Db\Connection;
use Wezurlop\Support\NazwaOsoby;

/**
 * Uprawnienia pary (konto, firma). company_id = 0 w role_assignment oznacza całego tenanta.
 * Czytane z bazy przy każdym żądaniu — odebranie uprawnienia działa natychmiast (CLAUDE.md §8).
 */
final class PermissionRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @param int|null $companyId firma aktywnego kontekstu; null = tylko przypisania na całego tenanta
     * @return list<string>
     */
    public function permissionsFor(int $accountId, ?int $companyId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT DISTINCT p.code
             FROM role_assignment ra
             JOIN role_permission rp ON rp.role_id = ra.role_id
             JOIN permission p ON p.id = rp.permission_id
             WHERE ra.account_id = :account AND (ra.company_id = 0 OR (ra.company_id = :company AND p.tenant_only = 0))'
        );
        $stmt->execute(['account' => $accountId, 'company' => $companyId ?? 0]);

        return array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Uprawnienia z dowolnej firmy konta — dla tras niezwiązanych z kontekstem (np. wybór firmy).
     *
     * @return list<string>
     */
    public function permissionsInAnyCompany(int $accountId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT DISTINCT p.code
             FROM role_assignment ra
             JOIN role_permission rp ON rp.role_id = ra.role_id
             JOIN permission p ON p.id = rp.permission_id
             WHERE ra.account_id = :account AND (ra.company_id = 0 OR p.tenant_only = 0)'
        );
        $stmt->execute(['account' => $accountId]);

        return array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** Czy konto ma przypisanie w danej firmie (zakres działań administratora firmy). */
    public function accountInCompany(int $accountId, int $companyId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM role_assignment WHERE account_id = :account AND company_id = :company LIMIT 1'
        );
        $stmt->execute(['account' => $accountId, 'company' => $companyId]);

        return $stmt->fetchColumn() !== false;
    }

    public function hasTenantWideRole(int $accountId): bool
    {
        // Tylko rola administracyjna na cały tenant — pracownik z company_id = 0 nie omija
        // wymogu zatrudnienia (docs/szkielet-tenanta.md rozdz. 9).
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM role_assignment ra JOIN role r ON r.id = ra.role_id
             WHERE ra.account_id = :account AND ra.company_id = 0 AND r.code = :role LIMIT 1'
        );
        $stmt->execute(['account' => $accountId, 'role' => self::ROLA_ADMIN]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * ⚠ **Nazwy obu ról objętych rozdziałem stoją TU, jako stałe.** Dwa powody:
     *  1. reguła z `etap3` §8c mówi o tych dwóch rolach wprost, więc muszą mieć jedno miejsce,
     *  2. bramka `check-rules` zgłasza **porównania do nazwy roli** w warunkach (§10: decyzja
     *     o dostępie pyta o KOD UPRAWNIENIA). ⚠ Porównanie w `assignRole()` jest wyjątkiem
     *     **merytorycznym, nie obejściem**: tam rola nie jest pośrednikiem dla uprawnienia —
     *     rola jest PRZEDMIOTEM reguły („tej roli nie wolno nadać temu koncie"). Stała zamiast
     *     literału jest tu świadoma i zgłoszona w raporcie, a nie sposobem na ciszę w bramce.
     */
    public const ROLA_ADMIN = 'tenant_admin';

    /** Rola dostępu pracowniczego do jednej spółki — druga strona rozdziału z §8c. */
    public const ROLA_PRACOWNIK = 'employee';

    /** Czy któraś z ról konta (w dowolnej firmie) wymaga 2FA. */
    public function requiresTwoFactor(int $accountId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM role_assignment ra JOIN role r ON r.id = ra.role_id
             WHERE ra.account_id = :account AND r.requires_2fa = 1 LIMIT 1'
        );
        $stmt->execute(['account' => $accountId]);

        return $stmt->fetchColumn() !== false;
    }

    /** Czy konto ma już dostęp pracowniczy do tej spółki. */
    public function hasCompanyAccess(int $accountId, int $companyId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT 1 FROM role_assignment ra JOIN role r ON r.id = ra.role_id
             WHERE ra.account_id = :account AND ra.company_id = :company AND r.code = 'employee' LIMIT 1"
        );
        $stmt->execute(['account' => $accountId, 'company' => $companyId]);

        return $stmt->fetchColumn() !== false;
    }

    /** Odebranie dostępu pracowniczego do spółki. Rola administratora zostaje nietknięta. */
    /**
     * Spółki, do których konto ma dostęp pracowniczy.
     *
     * @return list<int>
     */
    public function companyAccessIds(int $accountId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT ra.company_id FROM role_assignment ra JOIN role r ON r.id = ra.role_id
             WHERE ra.account_id = :account AND ra.company_id >= 1 AND r.code = 'employee'
             ORDER BY ra.company_id"
        );
        $stmt->execute(['account' => $accountId]);

        return array_map(static fn (array $r): int => (int) $r['company_id'], $stmt->fetchAll());
    }

    /** @return int liczba usuniętych przypisań — 0 znaczy „nie było czego odbierać" */
    public function revokeCompanyAccess(int $accountId, int $companyId): int
    {
        $stmt = $this->db->pdo()->prepare(
            "DELETE ra FROM role_assignment ra JOIN role r ON r.id = ra.role_id
             WHERE ra.account_id = :account AND ra.company_id = :company AND r.code = 'employee'"
        );
        $stmt->execute(['account' => $accountId, 'company' => $companyId]);

        return $stmt->rowCount();
    }

    /**
     * Wszystkie czynne spółki tenanta — **tylko dla konta z rolą tenantową**.
     *
     * ⚠ To zapytanie **nie sprawdza uprawnień** i dlatego ma jednego wołającego:
     * `KontekstSpolki::spolki()` pyta o nie **wyłącznie** po `hasTenantWideRole()`. Nazwa
     * spółki idzie z `NazwaSpolki::SQL`, bo drugie miejsce z tą regułą rozjechałoby się
     * przy pierwszej zmianie (ta sama zasada co w `HierarchyRepository::spolki()`).
     *
     * @return list<array{id: int, nazwa: string}>
     */
    public function wszystkieSpolki(): array
    {
        $rows = $this->db->pdo()->query(
            'SELECT c.id, ' . NazwaSpolki::SQL . ' AS nazwa
               FROM company c WHERE c.is_active = 1 ORDER BY nazwa'
        )->fetchAll();

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'nazwa' => (string) $r['nazwa'],
        ], $rows);
    }

    /**
     * Spółki, w których konto ma podane uprawnienie — z nadania na spółkę **albo** na całego tenanta.
     *
     * ⚠ Potrzebne dla konta **bez zatrudnienia**: administrator z nadanym poziomem widoczności
     * nie ma kontekstu zatrudnienia, więc spółkę do oglądania trzeba wziąć z uprawnienia.
     * Lista jest **białą listą** dla parametru `?spolka=` — wartość spoza niej nie jest błędem,
     * tylko wraca do pierwszej dozwolonej (CLAUDE.md §11).
     *
     * ⚠ Warunek `ra.company_id = 0 OR p.tenant_only = 0` jest tą samą regułą co w `permissionsFor()`
     * — jedno znaczenie zakresu w obu miejscach, żeby nie dało się dostać spółki na liście,
     * w której uprawnienie potem nie zadziała.
     *
     * @return list<array{id: int, nazwa: string}>
     */
    public function companiesWithPermission(int $accountId, string $code): array
    {
        // ⚠ Wyrażenie musi stać w `SELECT` **i** w `ORDER BY` — przy `DISTINCT` MySQL odrzuca
        // sortowanie po kolumnie, której nie ma na liście wyników („not in SELECT list; this is
        // incompatible with DISTINCT"). Zmierzone 06.10.2026 przy dowodzie przez łamanie: cofnięcie
        // samego `ORDER BY` do `c.name` nie dało złej kolejności, tylko **błąd zapytania** i pustą
        // kontrolkę spółki. Te dwa miejsca zmienia się razem albo wcale.
        $stmt = $this->db->pdo()->prepare(
            'SELECT DISTINCT c.id, ' . NazwaSpolki::SQL . ' AS name
             FROM company c
             JOIN role_assignment ra ON (ra.company_id = c.id OR ra.company_id = 0)
             JOIN role_permission rp ON rp.role_id = ra.role_id
             JOIN permission p ON p.id = rp.permission_id
             WHERE ra.account_id = :account AND p.code = :code AND c.is_active = 1
               AND (ra.company_id = 0 OR p.tenant_only = 0)
             ORDER BY ' . NazwaSpolki::SQL . ', c.id'
        );
        $stmt->execute(['account' => $accountId, 'code' => $code]);

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'nazwa' => (string) $r['name'],
        ], $stmt->fetchAll());
    }

    /**
     * Uprawnienia nadane **dokładnie w tym zakresie** — bez dziedziczenia z nadania
     * na całego tenanta.
     *
     * ⚠ Różnica wobec `permissionsFor()` jest funkcjonalna, nie kosmetyczna. Tamta funkcja
     * odpowiada „co ten człowiek MOŻE" (i słusznie dolicza nadanie tenant-wide), a ta —
     * „co jest nadane TUTAJ". Kontrolka nadawania musi pytać o to drugie: gdyby czytała
     * uprawnienia efektywne, pokazałaby poziom 1 przy spółce ALFA, choć nadanie siedzi
     * na całym tenancie, a próba wybrania „nie widzi" dla jednej spółki nie miałaby czego
     * odebrać i kontrolka wróciłaby do poprzedniego stanu bez wyjaśnienia.
     *
     * @return list<string>
     */
    public function permissionsAssignedAt(int $accountId, int $companyId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT DISTINCT p.code
             FROM role_assignment ra
             JOIN role_permission rp ON rp.role_id = ra.role_id
             JOIN permission p ON p.id = rp.permission_id
             WHERE ra.account_id = :account AND ra.company_id = :company
               AND (:company2 = 0 OR p.tenant_only = 0)'
        );
        $stmt->execute(['account' => $accountId, 'company' => $companyId, 'company2' => $companyId]);

        return array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Odebranie jednej roli w jednym zakresie.
     *
     * @return int liczba usuniętych przypisań — 0 znaczy „nie było czego odbierać"
     */
    public function revokeRole(int $accountId, string $roleCode, int $companyId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'DELETE ra FROM role_assignment ra JOIN role r ON r.id = ra.role_id
             WHERE ra.account_id = :account AND ra.company_id = :company AND r.code = :role'
        );
        $stmt->execute(['account' => $accountId, 'company' => $companyId, 'role' => $roleCode]);

        return $stmt->rowCount();
    }

    /**
     * Nadanie roli w jednym zakresie — **i jedyne miejsce, w którym pilnowany jest rozdział
     * „administrator tenanta ≠ pracownik"** (`docs/etap3-logika-urlopowa.md` §8c).
     *
     * ⚠ **Warunek stoi TU, bo tu rola staje się przypisaniem.** Nadanie roli ma w aplikacji
     * cztery wejścia (`AccessService::saveCompanyAccess`, `InvitationService` przy przyjęciu
     * zaproszenia, `CalendarVisibility::ustaw`, `dev/utworz-administratora.php`), więc warunek
     * postawiony w którymkolwiek z nich zostawiłby trzy obejścia.
     *
     * ⚠ **Reguła brzmi na STANIE KONTA, nie na kodzie roli** — i to jest różnica, która
     * decyduje. „Nie nadawaj `tenant_admin`" byłoby fałszem, bo każde zaproszenie administratora
     * nadaje właśnie tę rolę; regułą jest „nie nadawaj jej koncie, które MA aktywne zatrudnienie".
     * Druga strona jest symetryczna: dostępu pracowniczego nie nadajemy koncie administracyjnemu.
     *
     * ⚠ **Trzecia droga nie przechodzi tędy i to jest świadome.** Zatrudnienie może powstać
     * po stronie kadr: właściciel przechodzi na etat, księgowa z zewnątrz dostaje umowę —
     * i wtedy connector łamie regułę przy synchronizacji, nie nadając niczego. Tego ten warunek
     * nie złapie i nie ma łapać: synchronizacji nie blokujemy i danych z kadr nie prostujemy
     * (§8c reguła 3). Wykrywa to `administratorzyZEtatem()` i pozycja na `/diagnostyka`.
     *
     * @throws RozdzialRolException gdy nadanie złamałoby rozdział — **nigdy cicho**
     */
    public function assignRole(int $accountId, string $roleCode, int $companyId, ?int $grantedBy): void
    {
        if ($roleCode === self::ROLA_ADMIN && $this->maAktywneZatrudnienie($accountId)) {
            throw new RozdzialRolException(
                RozdzialRolException::POWOD_ADMIN_Z_ETATEM,
                'Konto ma aktywne zatrudnienie — nie może dostać roli administratora tenanta.',
            );
        }
        if ($roleCode === self::ROLA_PRACOWNIK && $this->hasTenantWideRole($accountId)) {
            throw new RozdzialRolException(
                RozdzialRolException::POWOD_PRACOWNIK_ADMINA,
                'Konto jest administratorem tenanta — nie może dostać dostępu pracowniczego.',
            );
        }
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO role_assignment (account_id, role_id, company_id, granted_by)
             SELECT :account, r.id, :company, :granted_by FROM role r WHERE r.code = :role'
        );
        $stmt->execute([
            'account'    => $accountId,
            'company'    => $companyId,
            'granted_by' => $grantedBy,
            'role'       => $roleCode,
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new \InvalidArgumentException('Nieznana rola: ' . $roleCode);
        }
    }

    /** Czy osoba, do której należy konto, ma dziś gdziekolwiek aktywne zatrudnienie. */
    public function maAktywneZatrudnienie(int $accountId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM account a
               JOIN employment e ON e.person_id = a.person_id
              WHERE a.id = :account AND e.is_active = 1 LIMIT 1'
        );
        $stmt->execute(['account' => $accountId]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Konta administracyjne, które **mają** aktywne zatrudnienie — czyli stan łamiący rozdział
     * z §8c. Wejście dla pozycji na `/diagnostyka`.
     *
     * ⚠ Pytamy o STAN, nie o zapamiętany znacznik. Naruszenie powstaje trzecią drogą (kadry
     * zatrudniają administratora), więc flaga zapisana w chwili nadania roli nic by nie wiedziała
     * — a stan odpowiada także wtedy, gdy ktoś wpisał wiersz ręcznie w phpMyAdmin.
     * ⚠ Pozycja ma **zniknąć sama**, gdy człowiek rozwiąże sprawę; dlatego zapytanie, nie wpis.
     *
     * @return list<array{account_id: int, email: ?string, osoba: string, spolka: string}>
     */
    public function administratorzyZEtatem(): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT a.id AS account_id, a.email, p.first_name, p.last_name, ' . NazwaSpolki::SQL . ' AS company
               FROM role_assignment ra
               JOIN role r ON r.id = ra.role_id
               JOIN account a ON a.id = ra.account_id
               JOIN person p ON p.id = a.person_id
               JOIN employment e ON e.person_id = a.person_id AND e.is_active = 1
               JOIN company c ON c.id = e.company_id
              WHERE r.code = :role
              ORDER BY p.last_name, p.first_name, ' . NazwaSpolki::SQL
        );
        $stmt->execute(['role' => self::ROLA_ADMIN]);

        return array_map(static fn (array $row): array => [
            'account_id' => (int) $row['account_id'],
            'email' => $row['email'] === null ? null : (string) $row['email'],
            'osoba' => NazwaOsoby::doWyswietlenia((string) $row['last_name']) . ' '
                . NazwaOsoby::doWyswietlenia((string) $row['first_name']),
            'spolka' => (string) $row['company'],
        ], $stmt->fetchAll());
    }
}
