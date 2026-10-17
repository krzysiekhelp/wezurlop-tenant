<?php

declare(strict_types=1);

namespace Wezurlop\Repository;

use Wezurlop\Db\Connection;

/**
 * Zatrudnienia konta. Warunek właściciela zawsze w zapytaniu, nie w `if` po pobraniu (CLAUDE.md §10).
 *
 * @phpstan-type EmploymentRow array{id: int, company_id: int, company_name: string,
 *     fte_numerator: int, fte_denominator: int}
 */
final class EmploymentRepository
{
    private const SELECT = 'SELECT e.id, e.company_id, ' . NazwaSpolki::SQL . ' AS company_name, e.fte_numerator, e.fte_denominator
        FROM employment e
        JOIN account a ON a.person_id = e.person_id
        JOIN company c ON c.id = e.company_id';

    public function __construct(private readonly Connection $db)
    {
    }

    /** @return list<EmploymentRow> */
    public function activeForAccount(int $accountId): array
    {
        $stmt = $this->db->pdo()->prepare(
            self::SELECT . ' WHERE a.id = :account AND e.is_active = 1 AND c.is_active = 1 ORDER BY ' . NazwaSpolki::SQL . ', e.id'
        );
        $stmt->execute(['account' => $accountId]);

        return array_map($this->row(...), $stmt->fetchAll());
    }

    /**
     * Zatrudnienia, do których konto ma DOSTĘP — czyli takie, których spółka ma przypisanie
     * `employee` na tym koncie.
     *
     * ⚠ To jest sedno rozdzielenia konta od dostępu (zlecenie `konta-budowa-2` §0.1): dane osoby
     * mogą być w dwóch spółkach w R2, a dostęp może obejmować jedną albo żadną. Bez tego warunku
     * pracownik przełączyłby się na spółkę, do której dostępu mu nie nadano — samo zatrudnienie
     * wystarczyłoby za uprawnienie, a uprawnienie nie może brać się z danych kadrowych.
     *
     * @return list<EmploymentRow>
     */
    public function accessibleForAccount(int $accountId): array
    {
        $stmt = $this->db->pdo()->prepare(
            self::SELECT . " WHERE a.id = :account AND e.is_active = 1 AND c.is_active = 1
                 AND EXISTS (
                     SELECT 1 FROM role_assignment ra JOIN role r ON r.id = ra.role_id
                     WHERE ra.account_id = a.id AND ra.company_id = e.company_id AND r.code = 'employee'
                 )
             ORDER BY " . NazwaSpolki::SQL . ", e.id"
        );
        $stmt->execute(['account' => $accountId]);

        return array_map($this->row(...), $stmt->fetchAll());
    }

    /**
     * Czy konto ma aktywne zatrudnienie w **konkretnej** spółce.
     *
     * ⚠ To jest warunek nadania dostępu (zlecenie `konta-lista-dostep` §3), ostrzejszy niż
     * `hasAnyActive()`. Wcześniejsza reguła pytała „czy ma jakikolwiek etat", więc pozwalała
     * nadać pracownikowi Piaskarza dostęp do Betaa — a taki dostęp daje konto, które się
     * loguje i widzi odmowę, bo kontekst pracownika idzie przez zatrudnienie.
     */
    public function hasActiveInCompany(int $accountId, int $companyId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM employment e
               JOIN account a ON a.person_id = e.person_id
               JOIN company c ON c.id = e.company_id
             WHERE a.id = :account AND e.company_id = :company
               AND e.is_active = 1 AND c.is_active = 1 LIMIT 1'
        );
        $stmt->execute(['account' => $accountId, 'company' => $companyId]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Czy OSOBA ma aktywne zatrudnienie w konkretnej spółce.
     *
     * ⚠ Wariant po osobie, nie po koncie, jest konieczny: dostęp nadaje się teraz także osobie,
     * która konta jeszcze nie ma (§4 zlecenia — konto powstaje przy pierwszym zaznaczeniu).
     * Gdyby warunek dało się sprawdzić tylko po koncie, trzeba by je najpierw założyć, a potem
     * cofać — czyli zakładać konto, żeby dowiedzieć się, że nie wolno go zakładać.
     */
    public function personHasActiveInCompany(int $personId, int $companyId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM employment e JOIN company c ON c.id = e.company_id
             WHERE e.person_id = :person AND e.company_id = :company
               AND e.is_active = 1 AND c.is_active = 1 LIMIT 1'
        );
        $stmt->execute(['person' => $personId, 'company' => $companyId]);

        return $stmt->fetchColumn() !== false;
    }

    /** Czy konto ma w ogóle jakieś aktywne zatrudnienie — niezależnie od dostępu. */
    public function hasAnyActive(int $accountId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM employment e JOIN account a ON a.person_id = e.person_id
             WHERE a.id = :account AND e.is_active = 1 LIMIT 1'
        );
        $stmt->execute(['account' => $accountId]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * @return EmploymentRow|null zatrudnienie tylko wtedy, gdy należy do konta, jest aktywne
     *     I konto ma dostęp do jego spółki
     *
     * ⚠ Warunek dostępu jest tu obowiązkowy, nie ozdobny: to POST przełączający kontekst.
     * Bez niego wystarczyłoby podać identyfikator zatrudnienia w spółce, do której dostępu
     * nie nadano, żeby się na nią przełączyć — samo zatrudnienie działałoby jak uprawnienie.
     */
    public function findActiveForAccount(int $employmentId, int $accountId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            self::SELECT . " WHERE e.id = :id AND a.id = :account AND e.is_active = 1 AND c.is_active = 1
                 AND EXISTS (
                     SELECT 1 FROM role_assignment ra JOIN role r ON r.id = ra.role_id
                     WHERE ra.account_id = a.id AND ra.company_id = e.company_id AND r.code = 'employee'
                 )"
        );
        $stmt->execute(['id' => $employmentId, 'account' => $accountId]);
        $row = $stmt->fetch();

        return is_array($row) ? $this->row($row) : null;
    }

    /**
     * @param array<string, mixed> $row
     * @return EmploymentRow
     */
    private function row(array $row): array
    {
        return [
            'id'              => (int) $row['id'],
            'company_id'      => (int) $row['company_id'],
            'company_name'    => (string) $row['company_name'],
            'fte_numerator'   => (int) $row['fte_numerator'],
            'fte_denominator' => (int) $row['fte_denominator'],
        ];
    }
}
