<?php

declare(strict_types=1);

namespace Wezurlop\Repository;

use Wezurlop\Db\Connection;

/**
 * Zapisy danych kadrowych przychodzących z R2 (docs/etap3-logika-urlopowa.md rozdz. 3b, 5b).
 * Zasada: co przyszło z R2, wygrywa — ale NIC nie jest kasowane z historii (osoby, wnioski, decyzje).
 */
final class SyncDomainRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    // --- firmy -------------------------------------------------------------------------------------

    /** @return array{id: int, name: string}|null */
    public function companyByNip(string $nip): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id, name FROM company WHERE nip = :nip');
        $stmt->execute(['nip' => $nip]);
        $row = $stmt->fetch();

        return is_array($row) ? ['id' => (int) $row['id'], 'name' => (string) $row['name']] : null;
    }

    /**
     * Odcisk sekretu tożsamości firmy: zaakceptowany i ten, który czeka na decyzję administratora.
     *
     * @return array{fingerprint: ?string, pending: ?string}
     */
    public function identityFingerprint(int $companyId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT identity_fingerprint, identity_fingerprint_pending FROM company WHERE id = :id'
        );
        $stmt->execute(['id' => $companyId]);
        $row = $stmt->fetch();

        return [
            'fingerprint' => is_array($row) && $row['identity_fingerprint'] !== null ? (string) $row['identity_fingerprint'] : null,
            'pending' => is_array($row) && $row['identity_fingerprint_pending'] !== null ? (string) $row['identity_fingerprint_pending'] : null,
        ];
    }

    /** Pierwszy odcisk dla firmy — zapamiętywany cicho, bez pytania (nie ma z czym porównywać). */
    public function setIdentityFingerprint(int $companyId, string $fingerprint): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE company SET identity_fingerprint = :f, identity_fingerprint_pending = NULL,
                    identity_fingerprint_at = UTC_TIMESTAMP()
             WHERE id = :id'
        );
        $stmt->execute(['f' => $fingerprint, 'id' => $companyId]);
    }

    /** Odcisk inny niż zapamiętany — zapisujemy jako oczekujący, żeby ekran miał co pokazać. */
    public function setPendingIdentityFingerprint(int $companyId, string $fingerprint): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE company SET identity_fingerprint_pending = :f WHERE id = :id AND
                    (identity_fingerprint_pending IS NULL OR identity_fingerprint_pending <> :f2)'
        );
        $stmt->execute(['f' => $fingerprint, 'f2' => $fingerprint, 'id' => $companyId]);
    }

    /**
     * NIP-y firm o podanych identyfikatorach — zakres klucza ustawia się WYBOREM firm, nie wpisaniem NIP-u
     * (rozstrzygnięcie 20.09.2026: dwa źródła prawdy o obsługiwanych firmach dawały pętlę przy podłączaniu).
     *
     * @param list<int> $ids
     * @return list<string>
     */
    public function nipsByCompanyIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $placeholders = [];
        $params = [];
        foreach (array_values(array_unique($ids)) as $i => $id) {
            $placeholders[] = ':id' . $i;
            $params['id' . $i] = $id;
        }
        $stmt = $this->db->pdo()->prepare('SELECT nip FROM company WHERE id IN (' . implode(', ', $placeholders) . ')');
        $stmt->execute($params);

        return array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function createCompany(string $nip, string $name): int
    {
        $stmt = $this->db->pdo()->prepare('INSERT INTO company (name, nip, is_active, created_at) VALUES (:name, :nip, 1, UTC_TIMESTAMP())');
        $stmt->execute(['name' => mb_substr($name, 0, 200), 'nip' => $nip]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    public function renameCompany(int $companyId, string $name): void
    {
        $this->db->pdo()->prepare('UPDATE company SET name = :name WHERE id = :id')->execute(['name' => mb_substr($name, 0, 200), 'id' => $companyId]);
    }

    // --- osoby ---------------------------------------------------------------------------------------

    /** @return array{id: int, first_name: string, last_name: string}|null osoba przypięta do osobaId */
    public function personByExternalRef(string $externalId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT p.id, p.first_name, p.last_name FROM person_external_ref r JOIN person p ON p.id = r.person_id
             WHERE r.external_person_id = :ext'
        );
        $stmt->execute(['ext' => $externalId]);
        $row = $stmt->fetch();

        return is_array($row) ? ['id' => (int) $row['id'], 'first_name' => (string) $row['first_name'], 'last_name' => (string) $row['last_name']] : null;
    }

    public function createPerson(string $firstName, string $lastName, ?string $externalId, bool $claimRef, ?string $identifier = null): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO person (first_name, last_name, external_person_id, r2_identifier, created_at)
             VALUES (:first, :last, :ext, :ident, UTC_TIMESTAMP())'
        );
        $stmt->execute(['first' => mb_substr($firstName, 0, 100), 'last' => mb_substr($lastName, 0, 100), 'ext' => $externalId,
            'ident' => $identifier === null ? null : mb_substr($identifier, 0, 32)]);
        $id = (int) $this->db->pdo()->lastInsertId();
        if ($claimRef && $externalId !== null) {
            $this->db->pdo()->prepare('INSERT INTO person_external_ref (external_person_id, person_id, created_at) VALUES (:ext, :person, UTC_TIMESTAMP())')
                ->execute(['ext' => $externalId, 'person' => $id]);
        }

        return $id;
    }

    public function updatePersonNames(int $personId, string $firstName, string $lastName, ?string $identifier = null): void
    {
        // ⚠ Numer kadrowy nadpisujemy tylko wtedy, gdy przyszedł — starszy connector go nie wysyła
        // i nie ma powodu, żeby brak w jednym cyklu kasował wartość zapisaną w poprzednim.
        $this->db->pdo()->prepare(
            'UPDATE person SET first_name = :first, last_name = :last,
                    r2_identifier = COALESCE(:ident, r2_identifier) WHERE id = :id'
        )->execute([
            'first' => mb_substr($firstName, 0, 100), 'last' => mb_substr($lastName, 0, 100),
            'ident' => $identifier === null ? null : mb_substr($identifier, 0, 32), 'id' => $personId,
        ]);
    }

    // --- zatrudnienia ------------------------------------------------------------------------------

    /** @return array{id: int, person_id: int, is_active: bool}|null */
    public function employmentByRef(int $companyId, string $ref): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, person_id, is_active FROM employment WHERE company_id = :company AND r2_employment_ref = :ref ORDER BY id LIMIT 1'
        );
        $stmt->execute(['company' => $companyId, 'ref' => $ref]);
        $row = $stmt->fetch();

        return is_array($row) ? ['id' => (int) $row['id'], 'person_id' => (int) $row['person_id'], 'is_active' => (bool) $row['is_active']] : null;
    }

    /** @param array{person_id: int, ref: string, fte: ?float, hired_at: ?string, ended_at: ?string, calendar_ref: ?string, active: bool} $e */
    public function upsertEmployment(int $companyId, ?int $employmentId, array $e): int
    {
        $num = $e['fte'] === null ? 1 : (int) round($e['fte'] * 10000);
        $den = $e['fte'] === null ? 1 : 10000;
        $g = self::gcd($num, $den);
        $params = [
            'person' => $e['person_id'], 'ref' => $e['ref'], 'fte' => $e['fte'] === null ? null : number_format($e['fte'], 4, '.', ''),
            'hired' => $e['hired_at'], 'ended' => $e['ended_at'], 'cal' => $e['calendar_ref'], 'active' => $e['active'] ? 1 : 0,
            'num' => intdiv($num, $g), 'den' => intdiv($den, $g),
        ];
        if ($employmentId !== null) {
            $stmt = $this->db->pdo()->prepare(
                'UPDATE employment SET person_id = :person, r2_employment_ref = :ref, fte = :fte, hired_at = :hired, terminated_at = :ended,
                    r2_calendar_ref = :cal, fte_numerator = :num, fte_denominator = :den, is_active = :active,
                    deactivated_at = IF(:active2 = 1, NULL, COALESCE(deactivated_at, UTC_TIMESTAMP()))
                 WHERE id = :id AND company_id = :company'
            );
            $stmt->execute($params + ['active2' => $params['active'], 'id' => $employmentId, 'company' => $companyId]);

            return $employmentId;
        }
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO employment (person_id, company_id, r2_employment_ref, hired_at, terminated_at, fte_numerator, fte_denominator,
                fte, r2_calendar_ref, is_active, created_at)
             VALUES (:person, :company, :ref, :hired, :ended, :num, :den, :fte, :cal, :active, UTC_TIMESTAMP())'
        );
        $stmt->execute($params + ['company' => $companyId]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /** Wypowiedzenie: ostatni dzień pracy na zatrudnieniach tej kartoteki (null = brak wypowiedzenia). */
    public function setTermination(int $employmentId, ?string $lastDay): void
    {
        $this->db->pdo()->prepare('UPDATE employment SET terminated_at = :d WHERE id = :id')->execute(['d' => $lastDay, 'id' => $employmentId]);
    }

    /**
     * Firmy w zakresie klucza razem z odciskiem tożsamości.
     *
     * ⚠ Sekret, z którego connector liczy `osobaId`, jest JEDEN na instalację connectora — więc
     * firmy obsługiwane jednym kluczem muszą mieć ten sam odcisk. Dwa różne odciski znaczą, że
     * firmy obsługują dwie instalacje, a wtedy ta sama osoba zatrudniona w obu rozpadnie się
     * u nas na dwie osoby (ustalenie z autorem connectora, 20.09.2026).
     *
     * @return list<array{id: int, nip: string, name: string, fingerprint: ?string}>
     */
    public function companiesForKey(int $keyId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT c.id, c.nip, c.name, c.identity_fingerprint
             FROM api_key_company k JOIN company c ON c.nip = k.nip
             WHERE k.api_key_id = :key ORDER BY c.id'
        );
        $stmt->execute(['key' => $keyId]);

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'], 'nip' => (string) $row['nip'], 'name' => (string) $row['name'],
            'fingerprint' => $row['identity_fingerprint'] === null ? null : (string) $row['identity_fingerprint'],
        ], $stmt->fetchAll());
    }

    /** Ile osób ma zatrudnienia w więcej niż jednej firmie — liczba, po której widać skutek rozjazdu. */
    public function peopleInManyCompanies(): int
    {
        $sql = 'SELECT COUNT(*) FROM (SELECT person_id FROM employment
                GROUP BY person_id HAVING COUNT(DISTINCT company_id) > 1) t';

        return (int) $this->db->pdo()->query($sql)->fetchColumn();
    }

    /**
     * Rodzaje umów z kartoteki (`umowy` zbioru `pracownik`). ⚠ Kilka naraz jest normalne.
     *
     * @param array{oPrace: bool, zlecenie: bool, oDzielo: bool} $umowy
     */
    public function setContractKinds(int $employmentId, array $umowy): void
    {
        $this->db->pdo()->prepare(
            'UPDATE employment SET contract_employment = :praca, contract_mandate = :zlecenie, contract_work = :dzielo
             WHERE id = :id'
        )->execute([
            'praca' => $umowy['oPrace'] ? 1 : 0,
            'zlecenie' => $umowy['zlecenie'] ? 1 : 0,
            'dzielo' => $umowy['oDzielo'] ? 1 : 0,
            'id' => $employmentId,
        ]);
    }

    /** Zatrudnienie wypadło z zakresu — nieaktywne, nie usunięte. @return int|null osoba */
    public function deactivateEmployment(int $employmentId): ?int
    {
        $this->db->pdo()->prepare(
            'UPDATE employment SET is_active = 0, deactivated_at = COALESCE(deactivated_at, UTC_TIMESTAMP()) WHERE id = :id'
        )->execute(['id' => $employmentId]);
        $stmt = $this->db->pdo()->prepare('SELECT person_id FROM employment WHERE id = :id');
        $stmt->execute(['id' => $employmentId]);
        $person = $stmt->fetchColumn();

        return $person === false ? null : (int) $person;
    }

    public function activeEmploymentCount(int $personId): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM employment WHERE person_id = :person AND is_active = 1');
        $stmt->execute(['person' => $personId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Konto blokowane dopiero, gdy WSZYSTKIE zatrudnienia są nieaktywne (wielofirmowość).
     *
     * ⚠ Blokujemy **każdy stan poza już zablokowanym**, nie tylko `active`. Zmierzone: dawny
     * warunek `status = 'active'` sprawiał, że konto `invited` (albo `niezaproszone`) osoby,
     * która odeszła, **nie było blokowane wcale** — a jego zaproszenie pozostawało ważne, więc
     * były pracownik mógł jeszcze ustawić hasło i wejść na konto po zwolnieniu. Po tej zmianie
     * `InvitationService::accept()` odrzuca taki link, bo wymaga stanu `invited`.
     *
     * ⚠ Stan sprzed blokady zapisujemy w `status_before_block`, żeby odblokowanie miało do czego
     * wrócić — inaczej konto `niezaproszone` wróciłoby jako `active`, czyli logowalne bez hasła.
     *
     * @return int|null konto zablokowane
     */
    /**
     * @return array{konto: int, sesje: int}|null null = osoba nie ma konta do zablokowania
     */
    public function blockAccountOfPerson(int $personId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT id FROM account WHERE person_id = :person AND status <> 'blocked'"
        );
        $stmt->execute(['person' => $personId]);
        $accountId = $stmt->fetchColumn();
        if ($accountId === false) {
            return null;
        }
        // ⚠ POWÓD zapisany w kolumnie, nie tylko w dzienniku: bez niego droga powrotna nie umiałaby
        // rozpoznać, czy blokadę założył automat, czy człowiek — i zdjęłaby także tę drugą.
        $this->db->pdo()->prepare(
            "UPDATE account SET status_before_block = status, status = 'blocked', blocked_reason = 'sync',
                blocked_at = UTC_TIMESTAMP(), blocked_by_account_id = NULL
             WHERE id = :id"
        )->execute(['id' => (int) $accountId]);
        // Sesje unieważnione natychmiast — przy najbliższym żądaniu (mechanizm z Etapu 2).
        // ⚠ Liczba wraca na zewnątrz, żeby dziennik miał wpis `session.revoked` także dla blokady
        // z synchronizacji. Reguła zrobiona w jednym z dwóch wejść nie jest regułą.
        $sesje = $this->db->pdo()->prepare(
            'UPDATE session SET revoked_at = UTC_TIMESTAMP() WHERE account_id = :id AND revoked_at IS NULL'
        );
        $sesje->execute(['id' => (int) $accountId]);

        return ['konto' => (int) $accountId, 'sesje' => $sesje->rowCount()];
    }

    /**
     * ⚠ DROGA POWROTNA: konto odblokowane, gdy pracownik wraca — czyli gdy znów ma choć jedno
     * aktywne zatrudnienie (zlecenie `konta-budowa-2` §4 punkt 1).
     *
     * Dotąd tej drogi **nie było**: synchronizacja blokowała konto, gdy gasły wszystkie
     * zatrudnienia, i nic nie potrafiło tego cofnąć. Pracownik, który wrócił po przerwie,
     * zostawał bez dostępu **na zawsze** — a administrator nie miał nawet z czego się
     * dowiedzieć, że to jego blokada.
     *
     * ⚠ Zdejmujemy **wyłącznie blokadę założoną przez synchronizację** (`blocked_reason = 'sync'`).
     * Blokada ręczna jest decyzją człowieka i automat jej nie cofa — inaczej zwolnienie
     * dyscyplinarne odblokowywałoby się samo przy najbliższym przebiegu.
     *
     * ⚠ Przypisań dostępu blokada nigdy nie usuwała (historia jest potrzebna kadrom), więc
     * odblokowanie **przywraca dostępy same z siebie** — i tym samym z powrotem zajmuje licencje.
     * Skutek licencyjny opisany w raporcie etapu 5.
     *
     * @return int|null konto, które faktycznie odblokowano
     */
    public function unblockAccountAfterReturn(int $personId): ?int
    {
        // ⚠ Wracamy do stanu SPRZED blokady, nie do `active`: konto `niezaproszone` albo
        // `invited` musi wrócić właśnie tam, bo `active` znaczy „ma hasło i może wejść".
        // `COALESCE` na wypadek wiersza sprzed tej migracji, gdzie pamięci stanu jeszcze nie ma.
        $stmt = $this->db->pdo()->prepare(
            "UPDATE account SET status = COALESCE(status_before_block, 'active'), status_before_block = NULL,
                blocked_reason = NULL, blocked_at = NULL, blocked_by_account_id = NULL
             WHERE person_id = :person AND status = 'blocked' AND blocked_reason = 'sync'"
        );
        $stmt->execute(['person' => $personId]);
        if ($stmt->rowCount() === 0) {
            return null;
        }
        $id = $this->db->pdo()->prepare('SELECT id FROM account WHERE person_id = :person');
        $id->execute(['person' => $personId]);
        $accountId = $id->fetchColumn();

        return $accountId === false ? null : (int) $accountId;
    }

    /**
     * Konto **administracyjne** tej osoby, jeśli istnieje — wykrycie TRZECIEJ drogi łamania
     * rozdziału z `docs/etap3-logika-urlopowa.md` §8c.
     *
     * ⚠ **Ta droga nie przechodzi przez żadne kliknięcie.** Konto wisi na osobie i zatrudnienie
     * też wisi na osobie, więc gdy kadry zatrudnią administratora — właściciel przechodzi na etat,
     * księgowa z zewnątrz dostaje umowę — regułę łamie **connector przy synchronizacji**, nie
     * człowiek. Warunek w `PermissionRepository::assignRole()` tego nie złapie, bo nikt niczego
     * nie nadaje.
     *
     * ⚠ **Synchronizacji nie blokujemy i danych z kadr nie prostujemy** (§8c reguła 1–3):
     * zatrudnienie wchodzi zawsze, a my tylko ZAPISUJEMY zdarzenie i pokazujemy blokującą pozycję
     * na `/diagnostyka`. Automat, który sam zablokowałby konto administratora w dniu, w którym
     * właściciel przechodzi na etat, zamknąłby klientowi dostęp do własnego systemu — ta sama
     * pułapka co przy ostatnim administratorze.
     */
    public function kontoAdministracyjneOsoby(int $personId): ?int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT a.id FROM account a
               JOIN role_assignment ra ON ra.account_id = a.id
               JOIN role r ON r.id = ra.role_id
              WHERE a.person_id = :person AND r.code = :role LIMIT 1'
        );
        $stmt->execute(['person' => $personId, 'role' => PermissionRepository::ROLA_ADMIN]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    // --- typy nieobecności ---------------------------------------------------------------------------

    /** Typ z pary source+code; nieznany zakładany jako tymczasowy (nazwę dostarczy słownik). */
    public function leaveTypeId(string $source, int $code): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM leave_type WHERE source = :source AND code = :code');
        $stmt->execute(['source' => $source, 'code' => $code]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }
        $this->db->pdo()->prepare(
            'INSERT IGNORE INTO leave_type (source, code, name, requestable, read_only, is_absence, counts_to_limit, updated_at)
             VALUES (:source, :code, :name, 0, 1, 1, 0, UTC_TIMESTAMP())'
        )->execute(['source' => $source, 'code' => $code, 'name' => $source . ':' . $code]);
        $stmt->execute(['source' => $source, 'code' => $code]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * ⚠ `counts_to_limit` nie jest ustawiane ręcznie: obciąża limit dokładnie ten rodzaj, który ma
     * `limit_kind` (`obciazaLimit` ze słownika). Dzięki temu nie ma dwóch źródeł prawdy o tym samym —
     * a `urlop:6` (ekwiwalent pieniężny, `nieobecnosc: false`) też pomniejsza pulę, bo tak liczą kadry.
     */
    public function upsertLeaveType(string $source, int $code, string $name, bool $readOnly, bool $requestable, bool $absence,
        ?string $limitKind = null, ?string $limitKindSource = null): int
    {
        $id = $this->leaveTypeId($source, $code);
        $this->db->pdo()->prepare(
            'UPDATE leave_type SET name = :name, read_only = :ro, requestable = :req, is_absence = :abs,
                    limit_kind = :kind, limit_kind_source = :kind_source, counts_to_limit = :counts, updated_at = UTC_TIMESTAMP()
             WHERE id = :id'
        )->execute([
            'name' => mb_substr($name, 0, 200), 'ro' => $readOnly ? 1 : 0, 'req' => $requestable ? 1 : 0, 'abs' => $absence ? 1 : 0,
            'kind' => $limitKind, 'kind_source' => $limitKind === null ? null : $limitKindSource,
            'counts' => $limitKind === null ? 0 : 1, 'id' => $id,
        ]);

        return $id;
    }

    // --- saldo ---------------------------------------------------------------------------------------

    /** @param array<string, mixed> $l rekord `limit` po walidacji */
    public function upsertBalance(int $employmentId, array $l): int
    {
        $params = [
            'emp' => $employmentId, 'year' => $l['rok'], 'kind' => $l['rodzaj'], 'accrued' => $l['naliczony'] ? 1 : 0,
            'ent' => $l['wymiarMin'], 'carried' => $l['zaleglyMin'], 'start' => $l['dostepneNaStartMin'], 'used' => $l['wykorzystaneMin'],
            'adj' => $l['korektyMin'], 'rem' => $l['pozostaloMin'], 'demand' => $l['naZadanieWykorzystaneDni'],
        ];
        $update = $this->db->pdo()->prepare(
            'UPDATE leave_balance SET accrued = :accrued, entitlement_min = :ent, carried_over_min = :carried, available_start_min = :start,
                used_min = :used, adjustments_min = :adj, remaining_min = :rem, on_demand_used_days = :demand, out_of_window = 0,
                fetched_at = UTC_TIMESTAMP()
             WHERE employment_id = :emp AND year = :year AND kind = :kind'
        );
        $update->execute($params);
        $find = $this->db->pdo()->prepare('SELECT id FROM leave_balance WHERE employment_id = :emp AND year = :year AND kind = :kind');
        $find->execute(['emp' => $employmentId, 'year' => $l['rok'], 'kind' => $l['rodzaj']]);
        $id = $find->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }
        $this->db->pdo()->prepare(
            'INSERT INTO leave_balance (employment_id, year, kind, accrued, entitlement_min, carried_over_min, available_start_min,
                used_min, adjustments_min, remaining_min, on_demand_used_days, out_of_window, fetched_at)
             VALUES (:emp, :year, :kind, :accrued, :ent, :carried, :start, :used, :adj, :rem, :demand, 0, UTC_TIMESTAMP())'
        )->execute($params);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @return array{employment_id: int, year: int}|null */
    public function balance(int $balanceId): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT employment_id, year FROM leave_balance WHERE id = :id');
        $stmt->execute(['id' => $balanceId]);
        $row = $stmt->fetch();

        return is_array($row) ? ['employment_id' => (int) $row['employment_id'], 'year' => (int) $row['year']] : null;
    }

    public function markBalanceOutOfWindow(int $balanceId): void
    {
        $this->db->pdo()->prepare('UPDATE leave_balance SET out_of_window = 1 WHERE id = :id')->execute(['id' => $balanceId]);
    }

    /** Limit usunięty w kadrach (rok w oknie): lustro R2, nie historia — stan jak „nienaliczony”. */
    public function deleteBalance(int $balanceId): void
    {
        $this->db->pdo()->prepare('DELETE FROM leave_balance WHERE id = :id')->execute(['id' => $balanceId]);
    }

    /** Aktywne wnioski tego zatrudnienia w roku — dla informacji kadr po zniknięciu limitu. */
    public function activeRequestsInYear(int $employmentId, int $year): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM leave_request WHERE employment_id = :emp AND YEAR(date_from) = :year
               AND status IN ('submitted', 'escalated', 'approved', 'confirmed', 'cancel_requested')"
        );
        $stmt->execute(['emp' => $employmentId, 'year' => $year]);

        return (int) $stmt->fetchColumn();
    }

    // --- nieobecności zewnętrzne ---------------------------------------------------------------------

    /** @param array{employment_id: int, person_id: int, leave_type_id: int, record: array<string, mixed>} $a */
    public function upsertExternalAbsence(int $companyId, array $a): int
    {
        $r = $a['record'];
        $params = [
            'company' => $companyId, 'emp' => $a['employment_id'], 'person' => $a['person_id'], 'type' => $a['leave_type_id'],
            'ref' => $r['id'], 'from' => $r['dataOd'], 'to' => $r['dataDo'], 'days' => $r['dni'], 'min' => $r['minuty'],
            'cont' => $r['kontynuacja'] === null ? null : ($r['kontynuacja'] ? 1 : 0), 'src' => mb_substr((string) $r['zrodloWpisu'], 0, 16),
            'created' => self::utc($r['utworzono']), 'modified' => self::utc($r['zmieniono']),
        ];
        $update = $this->db->pdo()->prepare(
            'UPDATE external_absence SET employment_id = :emp, person_id = :person, leave_type_id = :type, date_from = :from, date_to = :to,
                days = :days, minutes = :min, continuation = :cont, entry_source = :src, r2_created_at = :created, r2_modified_at = :modified,
                out_of_window = 0, removed_at = NULL, fetched_at = UTC_TIMESTAMP()
             WHERE company_id = :company AND r2_record_id = :ref'
        );
        $update->execute($params);
        $id = $this->externalAbsenceId($companyId, (string) $r['id']);
        if ($id !== null) {
            return $id;
        }
        $this->db->pdo()->prepare(
            'INSERT INTO external_absence (company_id, employment_id, person_id, leave_type_id, r2_record_id, date_from, date_to, days, minutes,
                continuation, entry_source, r2_created_at, r2_modified_at, out_of_window, fetched_at)
             VALUES (:company, :emp, :person, :type, :ref, :from, :to, :days, :min, :cont, :src, :created, :modified, 0, UTC_TIMESTAMP())'
        )->execute($params);

        return (int) $this->db->pdo()->lastInsertId();
    }

    public function externalAbsenceId(int $companyId, string $ref): ?int
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM external_absence WHERE company_id = :company AND r2_record_id = :ref');
        $stmt->execute(['company' => $companyId, 'ref' => $ref]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /** @return array{date_to: string, employment_active: bool}|null */
    public function externalAbsence(int $companyId, string $ref): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT a.date_to, e.is_active FROM external_absence a JOIN employment e ON e.id = a.employment_id
             WHERE a.company_id = :company AND a.r2_record_id = :ref'
        );
        $stmt->execute(['company' => $companyId, 'ref' => $ref]);
        $row = $stmt->fetch();

        return is_array($row) ? ['date_to' => (string) $row['date_to'], 'employment_active' => (bool) $row['is_active']] : null;
    }

    public function markExternalAbsence(int $companyId, string $ref, bool $outOfWindow): void
    {
        $this->db->pdo()->prepare(
            $outOfWindow
                ? 'UPDATE external_absence SET out_of_window = 1 WHERE company_id = :company AND r2_record_id = :ref'
                : 'UPDATE external_absence SET removed_at = UTC_TIMESTAMP() WHERE company_id = :company AND r2_record_id = :ref'
        )->execute(['company' => $companyId, 'ref' => $ref]);
    }

    private static function utc(mixed $timestamp): ?string
    {
        if (!is_string($timestamp)) {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat(DATE_ATOM, (string) preg_replace('/\.\d+/', '', $timestamp));

        return $d === false ? null : $d->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** NWD dla ułamka etatu (bez rozszerzenia gmp). */
    private static function gcd(int $a, int $b): int
    {
        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }

        return max(1, abs($a));
    }
}
