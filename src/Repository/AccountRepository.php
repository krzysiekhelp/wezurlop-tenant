<?php

declare(strict_types=1);

namespace Wezurlop\Repository;

use PDOException;
use PDOStatement;
use Wezurlop\Db\Connection;
use Wezurlop\Support\AdresKontaktowy;
use Wezurlop\Support\NazwaOsoby;

/**
 * Konta logowania. Zwracane tablice zawierają pola techniczne (hash hasła, sekret TOTP) —
 * są wyłącznie do użytku w serwisach bezpieczeństwa i nigdy nie trafiają do szablonu (CLAUDE.md §3).
 *
 * @phpstan-type AccountRow array{id: int, person_id: int, email: string, password_hash: ?string,
 *     status: string, blocked_reason: ?string, blocked_at: ?string, totp_secret_enc: ?string, totp_enabled_at: ?string, totp_last_window: ?int,
 *     password_changed_at: ?string, first_name: string, last_name: string}
 */
final class AccountRepository
{
    private const SELECT = 'SELECT a.id, a.person_id, a.email, a.password_hash, a.status,
        a.blocked_reason, a.blocked_at, a.blocked_by_account_id, a.totp_secret_enc,
            a.totp_enabled_at, a.totp_last_window, a.password_changed_at, p.first_name, p.last_name
        FROM account a JOIN person p ON p.id = a.person_id';

    /**
     * **Zapamiętana spółka kontekstu** — trwała część kontekstu (zlecenie 10.10.2026 §1).
     *
     * ⚠ Sesja jest szybsza, ale ginie przy wylogowaniu, a kontekst ma przeżyć ponowne
     * zalogowanie. Ta kolumna jest **podpowiedzią, nie uprawnieniem**: `KontekstSpolki`
     * waliduje ją przy każdym żądaniu przeciw liście spółek czytanej z bazy (CLAUDE.md §10).
     */
    public function zapamietanaSpolka(int $accountId): ?int
    {
        $stmt = $this->db->pdo()->prepare('SELECT context_company_id FROM account WHERE id = :id');
        $stmt->execute(['id' => $accountId]);
        $wartosc = $stmt->fetchColumn();

        return $wartosc === false || $wartosc === null ? null : (int) $wartosc;
    }

    public function zapiszSpolke(int $accountId, ?int $companyId): void
    {
        $this->db->pdo()->prepare('UPDATE account SET context_company_id = :spolka WHERE id = :id')
            ->execute(['spolka' => $companyId, 'id' => $accountId]);
    }

    public function __construct(private readonly Connection $db)
    {
    }

    /** @return AccountRow|null */
    public function findByEmail(string $email): ?array
    {
        $stmt = $this->db->pdo()->prepare(self::SELECT . ' WHERE a.email = :email');
        $stmt->execute(['email' => $email]);

        return $this->row($stmt->fetch());
    }

    /** @return AccountRow|null */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(self::SELECT . ' WHERE a.id = :id');
        $stmt->execute(['id' => $id]);

        return $this->row($stmt->fetch());
    }

    public function updatePasswordHash(int $id, string $hash): void
    {
        // Przehashowanie tym samym hasłem — bez zmiany password_changed_at (sesje zostają).
        $stmt = $this->db->pdo()->prepare('UPDATE account SET password_hash = :hash WHERE id = :id');
        $stmt->execute(['hash' => $hash, 'id' => $id]);
    }

    /**
     * Nowe hasło: konto aktywne (zaproszenie), `password_changed_at` = teraz — unieważnia sesje
     * zalogowane wcześniej (`Authenticate` porównuje z czasem logowania) — **i unieważnia
     * czynne zaproszenia tego konta**.
     *
     * ⚠ Drugie zdanie nie jest uprzejmością, tylko niezmiennikiem: **konto z hasłem nie ma
     * po co mieć czynnego zaproszenia.** Zaproszenie znaczy „ustaw pierwsze hasło", a gdy hasło
     * już jest, `InvitationService::accept()` i tak odmawia (sprawdza stan konta). Czynny wiersz
     * zostawał wtedy wyłącznie w tabeli — i to jest stan **zmierzony 22.09.2026**: dwa konta
     * (`#6`, `#15`) miały czynne zaproszenia z 21.09, bo hasło ustawiło im narzędzie
     * deweloperskie, a nie ścieżka zaproszenia (w dzienniku brak `account.activated`).
     *
     * ⚠ Dlaczego tutaj, a nie w serwisie: hasło ustawia kilka dróg (przyjęcie zaproszenia,
     * reset hasła, narzędzia `dev/`), a stan „są czynne zaproszenia" musi zniknąć **na każdej
     * z nich**. Warunek pilnowany w jednym serwisie zostaje w tyle przy pierwszej nowej drodze —
     * ta sama rodzina co „warstwa wcześniejsza odrzuca, więc poprawka w późniejszej się nie
     * wykonuje". Tabela `invitation` czytana jest tu wprost, bo to jedno `UPDATE` bez logiki.
     */
    public function setPassword(int $id, string $hash): void
    {
        $stmt = $this->db->pdo()->prepare(
            "UPDATE account SET password_hash = :hash, password_changed_at = UTC_TIMESTAMP(6),
                status = CASE WHEN status = 'invited' THEN 'active' ELSE status END
             WHERE id = :id"
        );
        $stmt->execute(['hash' => $hash, 'id' => $id]);
        $zaproszenia = $this->db->pdo()->prepare(
            'UPDATE invitation SET used_at = UTC_TIMESTAMP()
              WHERE account_id = :id AND used_at IS NULL'
        );
        $zaproszenia->execute(['id' => $id]);
    }

    /** Włączenie 2FA; okno użyte przy potwierdzeniu staje się ostatnio użytym. */
    public function enableTotp(int $id, string $secretSealed, int $window): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE account SET totp_secret_enc = :secret, totp_enabled_at = UTC_TIMESTAMP(), totp_last_window = :window
             WHERE id = :id'
        );
        $stmt->execute(['secret' => $secretSealed, 'window' => $window, 'id' => $id]);
    }

    public function disableTotp(int $id): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE account SET totp_secret_enc = NULL, totp_enabled_at = NULL, totp_last_window = NULL WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
    }

    /**
     * Zapis użytego okna TOTP. Warunek „nowsze niż zapisane” w UPDATE — z dwóch równoległych żądań
     * z tym samym kodem przejdzie jedno (docs/szkielet-tenanta.md rozdz. 6).
     */
    public function claimTotpWindow(int $id, int $window): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE account SET totp_last_window = :window
             WHERE id = :id AND totp_enabled_at IS NOT NULL AND (totp_last_window IS NULL OR totp_last_window < :window2)'
        );
        $stmt->execute(['window' => $window, 'id' => $id, 'window2' => $window]);

        return $stmt->rowCount() === 1;
    }

    public function countNotBlocked(): int
    {
        return (int) $this->db->pdo()->query("SELECT COUNT(*) FROM account WHERE status <> 'blocked'")->fetchColumn();
    }

    public function touchLastLogin(int $id): void
    {
        $stmt = $this->db->pdo()->prepare('UPDATE account SET last_login_at = UTC_TIMESTAMP() WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    /**
     * ⚠ Licencja liczy DOSTĘPY, nie konta (zlecenie `konta-budowa-2` §0.2).
     *
     * Liczone jest przypisanie do KONKRETNEJ spółki (`company_id >= 1`), gdy:
     *  - konto nie jest zablokowane — blokada zwalnia licencję,
     *  - osoba ma aktywne zatrudnienie **W TEJ SPÓŁCE** — zwolnienie zwalnia licencję,
     *    a przypisania zostają w bazie, bo historia jest potrzebna kadrom.
     *
     * ⚠ „W TEJ SPÓŁCE", a nie „jakiekolwiek zatrudnienie" — rozstrzygnięcie po etapie 5.
     * Pierwsza wersja pytała, czy osoba ma gdziekolwiek aktywny etat, więc dostęp do spółki,
     * w której umowa wygasła, **dalej kosztował licencję**, dopóki osoba pracowała gdzie indziej.
     * Trzy powody zmiany, w tej kolejności:
     *  1. **dostęp, którego nie wolno dziś nadać, nie ma prawa kosztować** — `AccessService`
     *     odrzuca nadanie dostępu do spółki bez aktywnego etatu, więc liczenie takiego dostępu
     *     było niespójnością, nie ostrożnością,
     *  2. przy wątpliwości co do tego, za co klient płaci, mylimy się **na jego korzyść**,
     *  3. jedna reguła, jedno pytanie: dwa różne pytania o tę samą rzecz zawsze się rozjeżdżają
     *     (dokładnie to zdarzyło się tutaj).
     *
     * ⚠ Rola administratora (`company_id = 0`) nie liczy się wcale: administrator nie jest kontem
     * pracowniczym. Dlatego kadrowa, która sama bierze urlop, ma rolę administratora (darmową)
     * i osobny dostęp pracowniczy do swojej spółki (za licencję) — role się nie zastępują.
     *
     * ⚠ `FOR UPDATE` nie jest tu potrzebne samo z siebie; blokadę zakłada wywołujący, w jednej
     * transakcji z zapisem przypisania (patrz `PermissionRepository::grantCompanyAccess()`).
     */
    public function countLicensedAccesses(): int
    {
        return (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM role_assignment ra
             JOIN account a ON a.id = ra.account_id
             WHERE ra.company_id >= 1 AND a.status <> 'blocked'
               AND EXISTS (SELECT 1 FROM employment e
                            WHERE e.person_id = a.person_id AND e.company_id = ra.company_id
                              AND e.is_active = 1)"
        )->fetchColumn();
    }

    /**
     * **Ile licencji zajmuje (albo zajęłoby) JEDNO konto** — ta sama reguła, co licznik całej
     * instalacji, zawężona do jednego konta.
     *
     * ⚠ **Bez warunku o statusie konta**, i to jest sedno: pytamy o konto, którego status
     * właśnie zmieniamy. Przy blokadzie liczba mówi, ile się zwolni; przy odblokowaniu — ile
     * się zajmie. Warunek `status <> 'blocked'` dawałby przy odblokowaniu zawsze zero.
     *
     * ⚠ **Nie licznik `dostepy` z wiersza listy.** Tamten liczy wszystkie przypisania do spółek,
     * także te bez aktywnego zatrudnienia — a takie licencji nie zajmują. Panel blokady liczył
     * nim skutek licencyjny do 24.10.2026 i przy osobie z wygasłym etatem w jednej firmie
     * obiecywał zwolnienie licencji, której nikt nie zajmował.
     */
    public function licensedAccessesOfAccount(int $accountId): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM role_assignment ra
             JOIN account a ON a.id = ra.account_id
             WHERE ra.account_id = :id AND ra.company_id >= 1
               AND EXISTS (SELECT 1 FROM employment e
                            WHERE e.person_id = a.person_id AND e.company_id = ra.company_id
                              AND e.is_active = 1)"
        );
        $stmt->execute(['id' => $accountId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Ten sam licznik, ale z blokadą czytanych wierszy i luk — do użycia WEWNĄTRZ transakcji,
     * razem z zapisem nowego przypisania.
     *
     * ⚠ Bez tego dwóch administratorów nadających dostęp w tej samej chwili policzyłoby ten sam
     * stan i obaj przeszliby przez limit. To ta sama pułapka, którą zamknęliśmy `FOR UPDATE`
     * przy sprawdzaniu, czy osoba ma już konto — tam chodziło o duplikat, tu o przekroczony limit.
     * Blokada jest szeroka (obejmuje zliczany zbiór), ale nadanie dostępu jest czynnością rzadką.
     */
    public function countLicensedAccessesForUpdate(): int
    {
        return (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM role_assignment ra
             JOIN account a ON a.id = ra.account_id
             WHERE ra.company_id >= 1 AND a.status <> 'blocked'
               AND EXISTS (SELECT 1 FROM employment e
                            WHERE e.person_id = a.person_id AND e.company_id = ra.company_id
                              AND e.is_active = 1)
             FOR UPDATE"
        )->fetchColumn();
    }

    /**
     * Czy osoba ma już konto. ⚠ Z blokadą wiersza (`FOR UPDATE`), bo sprawdzenie i wstawienie
     * muszą być jedną decyzją: bez tego dwa jednoczesne zaproszenia dla tej samej osoby
     * przeszłyby oba i powstałyby dwa konta. `UNIQUE (person_id)` by je złapał, ale komunikatem
     * o błędzie bazy, a nie czytelną odmową.
     */
    public function existsForPerson(int $personId): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM account WHERE person_id = :person LIMIT 1 FOR UPDATE');
        $stmt->execute(['person' => $personId]);

        return $stmt->fetchColumn() !== false;
    }

    /** Czy osoba istnieje — sprawdzane w tej samej transakcji co zakładanie konta. */
    public function personExists(int $personId): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM person WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $personId]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Stany konta — pełny cykl życia (zlecenie `konta-lista-dostep` §4).
     *
     * ⚠ `niezaproszone` to konto, które **nie może się zalogować i nie może przejść przez reset
     * hasła**: nie ma hasła, nie ma zaproszenia, a `AuthService` i `PasswordResetService`
     * wpuszczają wyłącznie `active`. Istnieje z jednego powodu: uprawnienie wisi na `account_id`,
     * więc dostęp do spółki nadany przed zaproszeniem musi mieć na czym stanąć.
     */
    public const STAN_NIEZAPROSZONE = 'niezaproszone';
    public const STAN_ZAPROSZONE = 'invited';
    public const STAN_AKTYWNE = 'active';
    public const STAN_ZABLOKOWANE = 'blocked';

    /**
     * Konto założone **przed zaproszeniem**, bez adresu i bez hasła (§4 zlecenia).
     *
     * ⚠ `email` zostaje `NULL`, nie `''`: administrator może jeszcze nie znać adresu, a `''`
     * przy `UNIQUE` pozwoliłoby na dokładnie jedno takie konto w całej instalacji
     * (migracja `20260921140001`).
     */
    public function createUninvited(int $personId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO account (person_id, email, status) VALUES (:person, NULL, :stan)'
        );
        $stmt->execute(['person' => $personId, 'stan' => self::STAN_NIEZAPROSZONE]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * Konto `niezaproszone` → `zaproszone`: dostaje adres (który od tej chwili jest loginem)
     * i przechodzi w stan, w którym zaproszenie ma sens.
     *
     * ⚠ Warunek `status = niezaproszone` jest **w zapytaniu**, nie w `if` przed nim: inaczej dwa
     * jednoczesne zaproszenia przestawiłyby stan dwa razy i wysłały dwa linki.
     *
     * @return bool czy to wywołanie faktycznie przestawiło stan
     */
    /**
     * Zmiana adresu (loginu) konta, które **nie jest jeszcze aktywne**.
     *
     * ⚠ Warunek `status <> active` stoi w ZAPYTANIU, nie w `if` przed nim (CLAUDE.md §10):
     * login konta aktywnego zmienia się osobną ścieżką, której dziś nie ma, i żądanie wysłane
     * obok interfejsu nie ma prawa jej ominąć.
     *
     * @return bool false = konta nie ma albo jest aktywne
     */
    public function changeEmailBeforeActivation(int $accountId, string $email): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "UPDATE account SET email = :email WHERE id = :id AND status <> 'active'"
        );
        self::zapiszZAdresem($stmt, ['email' => $email, 'id' => $accountId]);

        return $stmt->rowCount() === 1;
    }

    public function promoteToInvited(int $accountId, string $email): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE account SET email = :email, status = :nowy WHERE id = :id AND status = :stary'
        );
        self::zapiszZAdresem($stmt, [
            'email' => $email,
            'nowy'  => self::STAN_ZAPROSZONE,
            'id'    => $accountId,
            'stary' => self::STAN_NIEZAPROSZONE,
        ]);

        return $stmt->rowCount() === 1;
    }

    /**
     * Konto osoby wraz ze stanem — z blokadą wiersza, bo na tej odpowiedzi stoi decyzja
     * „zakładać konto czy użyć istniejącego".
     *
     * @return array{id: int, status: string, email: ?string}|null
     */
    public function findForPersonForUpdate(int $personId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, status, email FROM account WHERE person_id = :person LIMIT 1 FOR UPDATE'
        );
        $stmt->execute(['person' => $personId]);
        $row = $stmt->fetch();

        return is_array($row)
            ? ['id' => (int) $row['id'], 'status' => (string) $row['status'], 'email' => $row['email'] === null ? null : (string) $row['email']]
            : null;
    }

    /**
     * Identyfikatory **aktywnych** administratorów tenanta.
     *
     * ⚠ „Aktywny" znaczy `status = 'active'`, nie „istniejący": konto `invited`
     * i `niezaproszone` nie ustawiło hasła i może tego nigdy nie zrobić, a wiadomość mogła
     * nie dojść. Zaproszony administrator, który nie aktywował konta, to zabezpieczenie,
     * którego nie ma — i dokładnie ta różnica („aktywne" kontra „istniejące") była przyczyną
     * dziury w blokadzie kont zwolnionych pracowników.
     *
     * ⚠ `company_id = 0` to zakres całego tenanta — rola administratora nie wisi na spółce.
     *
     * @return list<int>
     */
    public function activeTenantAdminIds(): array
    {
        return $this->adminIds('');
    }

    /**
     * To samo z blokadą czytanych wierszy — do użycia WEWNĄTRZ transakcji, razem z blokowaniem
     * konta. Bez tego dwie równoległe blokady widzą ten sam stan „jest jeszcze dwóch"
     * i obie przechodzą, zostawiając tenant bez administratora.
     *
     * @return list<int>
     */
    public function activeTenantAdminIdsForUpdate(): array
    {
        return $this->adminIds(' FOR UPDATE');
    }

    /** @return list<int> */
    private function adminIds(string $lock): array
    {
        $stmt = $this->db->pdo()->query(
            "SELECT a.id FROM account a
               JOIN role_assignment ra ON ra.account_id = a.id
               JOIN role r ON r.id = ra.role_id
             WHERE r.code = 'tenant_admin' AND ra.company_id = 0 AND a.status = 'active'" . $lock
        );

        return array_map(static fn (array $r): int => (int) $r['id'], $stmt->fetchAll());
    }

    /** Kto zablokował — zapisywane po zatwierdzeniu transakcji blokady. */
    public function setBlockedBy(int $accountId, int $byAccountId): void
    {
        $this->db->pdo()->prepare('UPDATE account SET blocked_by_account_id = :by WHERE id = :id')
            ->execute(['by' => $byAccountId, 'id' => $accountId]);
    }

    /** Powody blokady — wartości kolumny `blocked_reason`. */
    public const BLOKADA_SYNC = 'sync';
    public const BLOKADA_RECZNA = 'manual';

    /**
     * Ręczna blokada konta przez administratora.
     *
     * ⚠ Powód `manual` jest istotny, nie opisowy: synchronizacja zdejmuje **wyłącznie** blokadę
     * z powodem `sync`, więc ta zostaje do decyzji człowieka. Stan sprzed blokady pamiętamy,
     * żeby odblokowanie nie zamieniło konta `niezaproszone` w logowalne.
     *
     * ⚠ **Ta metoda nie zna reguł** (blokada siebie, ostatni administrator) i nie ma ich znać —
     * to niski poziom, samo przestawienie stanu. Decyzję podejmuje `AccountService::block()`,
     * w transakcji i pod `FOR UPDATE`; `grep` pokazuje, że to **jedyny** wywołujący.
     *
     * @return bool czy to wywołanie faktycznie zablokowało konto
     */
    /**
     * ⚠ Nie zna reguł blokady; jedyny wywołujący to `AccountService` (pilnuje `bin/check-rules.php`).
     *
     * @return int|null liczba uciętych sesji albo `null`, gdy stan konta się nie zmienił
     */
    public function blockManually(int $accountId): ?int
    {
        $stmt = $this->db->pdo()->prepare(
            "UPDATE account SET status_before_block = status, status = 'blocked',
                blocked_reason = :powod, blocked_at = UTC_TIMESTAMP()
             WHERE id = :id AND status <> 'blocked'"
        );
        $stmt->execute(['powod' => self::BLOKADA_RECZNA, 'id' => $accountId]);
        if ($stmt->rowCount() === 0) {
            return null;
        }
        // ⚠ Sesje unieważnione natychmiast — blokada, która działa „od następnego logowania",
        // nie jest blokadą (CLAUDE.md §8: zakończenie działa przy następnym żądaniu).
        // ⚠ Liczba wraca do serwisu, bo to ona idzie do dziennika jako JEDEN wpis `session.revoked`
        // z licznikiem: konto z sześcioma urządzeniami nie ma dawać sześciu wierszy o tej samej decyzji.
        $sesje = $this->db->pdo()->prepare(
            'UPDATE session SET revoked_at = UTC_TIMESTAMP() WHERE account_id = :id AND revoked_at IS NULL'
        );
        $sesje->execute(['id' => $accountId]);

        return $sesje->rowCount();
    }

    /**
     * Ręczne odblokowanie — wraca stan sprzed blokady, niezależnie od tego, kto blokował.
     *
     * ⚠ Człowiek może zdjąć także blokadę założoną przez synchronizację (np. wie, że umowa
     * wraca od poniedziałku, a R2 jeszcze tego nie przysłało). Odwrotnie nie: automat nie
     * cofa decyzji człowieka.
     *
     * @return bool czy to wywołanie faktycznie odblokowało konto
     */
    public function unblockManually(int $accountId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "UPDATE account SET status = COALESCE(status_before_block, 'active'), status_before_block = NULL,
                blocked_reason = NULL, blocked_at = NULL, blocked_by_account_id = NULL
             WHERE id = :id AND status = 'blocked'"
        );
        $stmt->execute(['id' => $accountId]);

        return $stmt->rowCount() === 1;
    }

    /**
     * Zapis, w którym duplikat adresu jest ODMOWĄ, a nie awarią (zlecenie Z40).
     *
     * ⚠ Sprawdzenie przed zapisem odpowiada na pytanie „czy adres jest wolny TERAZ", a klucz
     * unikalny — „czy był wolny W CHWILI ZAPISU". Między jednym a drugim mieści się drugi
     * administrator zapisujący ten sam adres, więc bez tego tłumaczenia wyścig kończyłby się
     * stroną błędu zamiast zdaniem, co poprawić.
     */
    private static function zapiszZAdresem(PDOStatement $stmt, ?array $params = null): void
    {
        try {
            $stmt->execute($params);
        } catch (PDOException $e) {
            if (AdresKontaktowy::duplikat($e)) {
                throw new AdresZajetyException('Adres e-mail należy już do innej osoby albo konta.');
            }

            throw $e;
        }
    }

    /** Konto w stanie „zaproszone”, bez hasła. */
    public function createInvited(int $personId, string $email): int
    {
        $stmt = $this->db->pdo()->prepare(
            "INSERT INTO account (person_id, email, status) VALUES (:person, :email, 'invited')"
        );
        self::zapiszZAdresem($stmt, ['person' => $personId, 'email' => $email]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * Lista kont dla administratora. Administrator firmy widzi tylko konta z przypisaniem w tej firmie
     * (warunek w zapytaniu). Bez hashy haseł i sekretów.
     *
     * @return list<array{id: int, name: string, email: string, status: string, two_factor: bool, sessions: int, last_login_at: ?string}>
     */
    public function listForAdmin(?int $companyId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT a.id, p.first_name, p.last_name, a.email, a.status,
                a.totp_enabled_at IS NOT NULL AS two_factor, a.last_login_at,
                (SELECT COUNT(*) FROM session s WHERE s.account_id = a.id AND s.revoked_at IS NULL
                    AND s.expires_at > UTC_TIMESTAMP()) AS sessions
             FROM account a JOIN person p ON p.id = a.person_id
             WHERE :company_all = 1
                OR EXISTS (SELECT 1 FROM role_assignment ra WHERE ra.account_id = a.id AND ra.company_id = :company)
             ORDER BY p.last_name, p.first_name, a.id
             LIMIT 500'
        );
        $stmt->execute(['company_all' => $companyId === null ? 1 : 0, 'company' => $companyId ?? 0]);

        return array_map(static fn (array $r): array => [
            'id'            => (int) $r['id'],
            // Lista kont to tabela — forma „nazwisko imię" (§9), sortowana po kolumnach źródłowych.
            'name'          => NazwaOsoby::nazwiskoImie((string) $r['last_name'], (string) $r['first_name']),
            'email'         => (string) $r['email'],
            'status'        => (string) $r['status'],
            'blocked_reason' => $r['blocked_reason'] === null ? null : (string) $r['blocked_reason'],
            'blocked_at'     => $r['blocked_at'] === null ? null : (string) $r['blocked_at'],
            'two_factor'    => (bool) $r['two_factor'],
            'sessions'      => (int) $r['sessions'],
            'last_login_at' => $r['last_login_at'] === null ? null : (string) $r['last_login_at'],
        ], $stmt->fetchAll());
    }

    /** Czas zmiany hasła jako liczba sekund (z mikrosekundami) od epoki, UTC. */
    public static function timestamp(?string $datetime): float
    {
        if ($datetime === null) {
            return 0.0;
        }
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $datetime, new \DateTimeZone('UTC'))
            ?: \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $datetime, new \DateTimeZone('UTC'));

        return $parsed === false ? 0.0 : (float) $parsed->format('U.u');
    }

    /** @return AccountRow|null */
    private function row(mixed $row): ?array
    {
        if (!is_array($row)) {
            return null;
        }
        $row['id'] = (int) $row['id'];
        $row['person_id'] = (int) $row['person_id'];
        $row['totp_last_window'] = $row['totp_last_window'] === null ? null : (int) $row['totp_last_window'];

        return $row;
    }
}
