<?php

declare(strict_types=1);

namespace Wezurlop\Service;

use InvalidArgumentException;
use Wezurlop\Repository\AccountRepository;
use Wezurlop\Repository\AdresZajetyException;
use Wezurlop\Repository\EventLogRepository;
use Wezurlop\Repository\InvitationRepository;
use Wezurlop\Repository\JobRepository;
use Wezurlop\Repository\PermissionRepository;
use Wezurlop\Repository\PersonRepository;
use Wezurlop\Security\OneTimeToken;
use Wezurlop\Security\Password;
use Wezurlop\Db\Connection;

/**
 * Rejestracja zaproszeniowa (docs/szkielet-tenanta.md rozdz. 6). Otwartej rejestracji nie ma.
 * Ten sam mechanizm tworzy pierwszego administratora w instalatorze (M8).
 *
 * ⚠ Ta klasa **nie zna już licencji**. Dotąd miała własną decyzję o limicie obok tej
 * w `AccessService` — jedna reguła, dwa punkty decyzji. Po przeniesieniu dostępu do
 * `AccessService` zależność od `LicenseGate` zniknęła sama, i to jest najlepszy dowód,
 * że decyzja jest teraz w jednym miejscu.
 */
final class InvitationService
{
    public function __construct(
        private readonly Connection $db,
        private readonly PersonRepository $people,
        private readonly AccountRepository $accounts,
        private readonly PermissionRepository $permissions,
        private readonly InvitationRepository $invitations,
        private readonly JobRepository $jobs,
        private readonly AccountMailService $mail,
        private readonly Password $password,
        private readonly EventLogRepository $events,
    ) {
    }

    /**
     * Czy adres jest zajęty przez **inne** konto — jeden warunek dla trzech wejść:
     * `invite()`, `inviteExisting()` i `changeEmail()`.
     *
     * ⚠ Powód istnienia tej metody jest zmierzony (22.09.2026). `inviteExisting()` pytało
     * „czy ktokolwiek ma ten adres" i odrzucało żądanie **zanim** dojdzie do gałęzi
     * `niezaproszone → invited`, która powstała właśnie na ten przypadek:
     *
     * ```
     * POST /admin/konta/zapros  osoby[]=107   (konto #12 `niezaproszone`, ten sam adres)
     * → „adres jest już użyty przez inną osobę lub konto"
     * ```
     *
     * Skutek u klienta: osoba, której nadano dostęp **przed** zaproszeniem, była nie do
     * zaproszenia z listy kont i nie istniało obejście w interfejsie. To ta sama rodzina co
     * inne pomiary z tego tygodnia: *warstwa wcześniejsza odrzuca żądanie, więc poprawka
     * w warstwie późniejszej wygląda słusznie i nigdy się nie wykonuje.*
     *
     * ⚠ Wykluczenie jest **jawne i wąskie**: pomijamy dokładnie jedno konto — wskazane
     * identyfikatorem konta albo konto wskazanej osoby. „Zajęty przez kogoś innego" musi
     * dalej znaczyć odmowę, bo `account.email` jest loginem i ma pozostać `UNIQUE`.
     *
     * @param int|null $pomijanaOsoba  konto TEJ osoby nie liczy się jako „inne" (zapraszanie)
     * @param int|null $pomijaneKonto  to konkretne konto nie liczy się jako „inne" (zmiana adresu)
     */
    private function adresZajetyPrzezInneKonto(
        string $email,
        ?int $pomijanaOsoba = null,
        ?int $pomijaneKonto = null,
    ): bool {
        return self::adresInnegoKonta($this->accounts->findByEmail($email), $pomijanaOsoba, $pomijaneKonto);
    }

    /**
     * Sama decyzja, bez bazy — żeby dała się zamknąć testem (`tests/zaproszenia.php`).
     *
     * ⚠ Rozdzielone celowo: usterka, którą to naprawia, była **decyzją**, nie zapytaniem,
     * a decyzji ukrytej w metodzie z dostępem do bazy nie da się sprawdzić testem
     * jednostkowym. Nasze testy nie mają bazy (`tests/*.php`), więc reguła bez tego podziału
     * zostałaby pilnowana wyłącznie ręcznym pomiarem po HTTP — czyli do pierwszego pośpiechu.
     *
     * @param array{id: int|string, person_id: int|string}|null $konto wiersz konta z tym adresem
     */
    public static function adresInnegoKonta(?array $konto, ?int $pomijanaOsoba, ?int $pomijaneKonto): bool
    {
        if ($konto === null) {
            return false;
        }
        if ($pomijaneKonto !== null && (int) $konto['id'] === $pomijaneKonto) {
            return false;
        }

        return !($pomijanaOsoba !== null && (int) $konto['person_id'] === $pomijanaOsoba);
    }

    /**
     * **Konto administracyjne** — jedyna ręczna ścieżka tworzenia osoby i konta (etap 4 zlecenia
     * `konta-budowa-2`). Wiadomość wychodzi przez kolejkę.
     *
     * ⚠ **Rola jest zablokowana na administratorze tenanta** i nie jest już parametrem. Powód
     * nie jest wygodą: konto zakładane tutaj należy do osoby, której **nie ma w programie
     * kadrowym**, więc nie ma żadnego zatrudnienia — a dostęp pracowniczy wolno nadać wyłącznie
     * do spółki z aktywnym etatem (`AccessService`). Rola pracownika dawałaby zatem konto, które
     * loguje się i natychmiast widzi odmowę, albo — gorzej — dostęp, którego licznik licencji
     * nie liczy, bo nie ma etatu. Dawny parametr `$role` pozwalał CLI utworzyć dokładnie ten
     * stan, który interfejs odrzuca.
     *
     * ⚠ **Sprawdzania limitu licencji tu NIE MA i nie ma go po co przywracać.** Rola
     * administratora to `company_id = 0`, a licencja liczy wyłącznie dostępy do konkretnych
     * spółek (§0.2). Po tej zmianie o limicie decyduje **jedno** miejsce w całym kodzie:
     * `AccessService`. Dotąd były dwa punkty decyzji przy jednej regule.
     *
     * ⚠ Osoba powstaje z `outside_hr = 1` — „spoza kadr" jako **oświadczenie w kolumnie**,
     * nie jako wniosek z braku wiersza w `person_external_ref` (§3 zlecenia).
     *
     * @return int identyfikator konta
     */
    public function invite(string $firstName, string $lastName, string $email, ?int $invitedBy): int
    {
        $email = AuthService::normalizeEmail($email);
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 254) {
            throw new InvalidArgumentException('Nieprawidłowy adres e-mail');
        }
        $firstName = trim($firstName);
        $lastName = trim($lastName);
        if ($firstName === '' || $lastName === '' || mb_strlen($firstName) > 100 || mb_strlen($lastName) > 100) {
            throw new InvalidArgumentException('Imię i nazwisko są wymagane (do 100 znaków każde)');
        }
        if ($this->adresZajetyPrzezInneKonto($email)) {
            throw new InvalidArgumentException('Konto z tym adresem już istnieje');
        }
        // ⚠ DRUGIE pytanie, nie powtórzenie pierwszego (zlecenie Z40): wyżej pytamy, czy adres
        // jest już czyimś LOGINEM, tutaj — czy jest już czyimś adresem w KARTOTECE. Adres
        // kontaktowy staje się loginem w dniu zaproszenia, więc bez tego warunku zakładalibyśmy
        // konto na adresie, którym za chwilę nie da się zaprosić osoby, przy której już stoi.
        // ⚠ `0` jako „osoba pomijana": tutaj osoba dopiero powstanie, więc nie ma czego pomijać.
        if ($this->people->ktoMaAdres($email, 0, null) !== null) {
            throw new InvalidArgumentException('Ten adres ma już inna osoba');
        }

        // ⚠ Sprawdzenie wyżej mówi „adres jest wolny TERAZ", klucz unikalny — „był wolny
        // W CHWILI ZAPISU". Między jednym a drugim mieści się drugi administrator zapraszający
        // ten sam adres, więc wyścig ma dać tę samą odmowę, a nie stronę błędu (zlecenie Z40).
        try {
            $accountId = $this->db->transaction(function () use ($firstName, $lastName, $email, $invitedBy): int {
                $personId = $this->people->create($firstName, $lastName, true);
                $accountId = $this->accounts->createInvited($personId, $email);
                // ⚠ Rozdziału z §8c ta droga nie łamie z definicji: osoba powstaje TUTAJ, świeża,
                // z `outside_hr = 1`, więc nie ma zatrudnienia. Warunek i tak stoi w `assignRole()`
                // — żeby trzymał także wtedy, gdy kiedyś zaprosimy osobę już istniejącą.
                $this->permissions->assignRole($accountId, PermissionRepository::ROLA_ADMIN, 0, $invitedBy);

                return $accountId;
            });
        } catch (AdresZajetyException) {
            throw new InvalidArgumentException('Konto z tym adresem już istnieje');
        }
        // ⚠ Kolejkowanie i dziennik POZA transakcją — zadanie wrzucone w środku mogłoby zostać
        // podjęte przez runnera, zanim konto zostanie zatwierdzone.
        $this->mail->queueInvitation($accountId, $invitedBy);
        $this->events->record('account.invited', $invitedBy, null, $invitedBy === null ? 'cli' : null, 'account', $accountId,
            null, ['rola' => 'tenant_admin', 'spoza_kadr' => true]);

        return $accountId;
    }

    /**
     * Powody, dla których zaproszenie nie może powstać — ⚠ zwracane jako TEKST, nie wyjątek,
     * bo wysyłka partii musi pójść dalej i pokazać, kogo pominęła i dlaczego (§2.2 zlecenia).
     */
    public const POWOD_BRAK_ADRESU = 'brak adresu e-mail';
    public const POWOD_MA_KONTO = 'ma już konto';
    public const POWOD_ADRES_ZAJETY = 'adres jest już użyty przez inną osobę lub konto';
    public const POWOD_BRAK_OSOBY = 'osoby już nie ma w kartotece';

    /**
     * ⚠ DRUGA ŚCIEŻKA: konto dla osoby, która JUŻ ISTNIEJE w kartotece.
     *
     * Powód istnienia tej metody: `invite()` wyżej **zawsze** woła `PersonRepository::create()`,
     * więc zaproszenie kogoś z listy osób tworzyłoby DRUGĄ osobę — jedną z R2 (z wpisem
     * w `person_external_ref`), drugą świeżo utworzoną bez zatrudnienia. Tu osoby nie tworzymy
     * w ogóle; sprawdzamy, że istnieje i nie ma jeszcze konta, i to w jednej transakcji z zapisem.
     *
     * ⚠ NIE nadaje roli ani dostępu do spółki i NIE sprawdza limitu licencji.
     * Konto to możliwość zalogowania się; dostęp do spółek to osobna rzecz
     * (`AccessService::grantCompanyAccess()`), i to on kosztuje licencję.
     * Dlatego zaproszenie nie może się już przerwać na limicie w środku partii.
     *
     * ⚠ Zwraca **także identyfikator konta**, nie tylko powód. Bez tego trasa partii musiałaby
     * po zaproszeniu dopytać bazę „które konto właśnie powstało", a nadanie dostępu (osobny krok,
     * §0.1 zlecenia) potrzebuje tego identyfikatora od razu.
     *
     * @return array{powod: ?string, account: ?int}
     */
    public function inviteExisting(int $personId, string $email, ?int $invitedBy): array
    {
        $email = AuthService::normalizeEmail($email);
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 254) {
            return ['powod' => self::POWOD_BRAK_ADRESU, 'account' => null];
        }
        // ⚠ `pomijanaOsoba`: konto TEJ osoby nie jest „innym kontem". Bez tego ścieżka
        // `niezaproszone → invited` niżej była martwa (patrz `adresZajetyPrzezInneKonto()`).
        if ($this->adresZajetyPrzezInneKonto($email, pomijanaOsoba: $personId)
            || $this->people->ktoMaAdres($email, $personId, null) !== null) {
            return ['powod' => self::POWOD_ADRES_ZAJETY, 'account' => null];
        }

        try {
            $wynik = $this->db->transaction(function () use ($personId, $email): array {
                if (!$this->accounts->personExists($personId)) {
                    return ['powod' => self::POWOD_BRAK_OSOBY, 'account' => null];
                }
                $konto = $this->accounts->findForPersonForUpdate($personId);
                if ($konto === null) {
                    return ['powod' => null, 'account' => $this->accounts->createInvited($personId, $email)];
                }
            // ⚠ Konto `niezaproszone` już istnieje — powstało przy nadaniu dostępu (§4 zlecenia),
            // więc zaproszenie go NIE ZAKŁADA, tylko przestawia stan i dopisuje adres, który
            // od tej chwili jest loginem. Bez tego gałęzi administrator, który najpierw nadał
            // dostęp, dostawałby na własne konto odmowę „ma już konto".
                if ($konto['status'] === AccountRepository::STAN_NIEZAPROSZONE
                    && $this->accounts->promoteToInvited($konto['id'], $email)) {
                    return ['powod' => null, 'account' => $konto['id']];
                }

                return ['powod' => self::POWOD_MA_KONTO, 'account' => null];
            });
        } catch (AdresZajetyException) {
            // Wyścig dwóch zaproszeń na ten sam adres — ta sama odmowa, co ze sprawdzenia wyżej.
            return ['powod' => self::POWOD_ADRES_ZAJETY, 'account' => null];
        }
        if ($wynik['powod'] !== null) {
            return $wynik;
        }
        // ⚠ Kolejkowanie i dziennik POZA transakcją — tak samo jak w `invite()`. Zadanie wrzucone
        // w środku mogłoby zostać podjęte przez runnera, zanim konto zostanie zatwierdzone,
        // i wysyłka trafiłaby na wiersz, którego jeszcze nie ma.
        $this->mail->queueInvitation($wynik['account'], $invitedBy);
        $this->events->record('account.invited', $invitedBy, null, null, 'account', $wynik['account']);

        return $wynik;
    }

    /**
     * ⚠ **Minimalny odstęp między ponowieniami zaproszenia — 5 minut na konto.**
     *
     * Nazwany tutaj, a nie ukryty w kontrolerze, bo to nie jest ustawienie interfejsu, tylko
     * zabezpieczenie **naszego serwera poczty**: przycisk bez odstępu to sposób, żeby z naszego
     * adresu zasypać czyjąś skrzynkę. Skutkiem nie jest zirytowany odbiorca, a nasz adres
     * na czarnej liście dostawcy — i wtedy **żadna wiadomość nie wychodzi u wszystkich klientów
     * na tym serwerze**.
     */
    public const PONOWIENIE_ODSTEP_SEKUND = 300;

    public const POWOD_NIE_ZAPROSZONE = 'ponowić można tylko zaproszenie, które czeka na przyjęcie';
    public const POWOD_W_KOLEJCE = 'zaproszenie czeka już w kolejce i wyjdzie w ciągu kilku minut';
    public const POWOD_AKTYWNE = 'konto jest aktywne — adres jest loginem i zmienia się osobną ścieżką';

    /**
     * Ponowna wysyłka zaproszenia (zlecenie `wyslij-ponownie` §2).
     *
     * ⚠ **Ponowienie unieważnia poprzedni link NATYCHMIAST**, a nie dopiero przy wysyłce: dwa
     * czynne zaproszenia to dwa działające linki, z których jeden krąży w nieznanej skrzynce.
     * Między unieważnieniem a przebiegiem kolejki konto jest bez czynnego linku i to jest stan
     * zamierzony — widoczny w panelu i odwracalny tym samym przyciskiem.
     *
     * ⚠ Wiadomość idzie **kolejką**, nie w tym żądaniu (CLAUDE.md §0).
     *
     * @return string|null powód odmowy albo null, gdy zaproszenie przyjęto do wysyłki
     */
    public function resend(int $accountId, int $byAccountId, ?string $actorLabel): ?string
    {
        $account = $this->accounts->findById($accountId);
        if ($account === null) {
            return self::POWOD_BRAK_OSOBY;
        }
        if ($account['status'] === AccountRepository::STAN_AKTYWNE) {
            return self::POWOD_AKTYWNE;
        }
        if ($account['status'] !== AccountRepository::STAN_ZAPROSZONE) {
            return self::POWOD_NIE_ZAPROSZONE;
        }
        if ((string) ($account['email'] ?? '') === '') {
            return self::POWOD_BRAK_ADRESU;
        }
        // ⚠ Dwa różne warunki, bo łapią dwie różne rzeczy: zadanie w kolejce (kliknięcia przed
        // przebiegiem crona) i czas od ostatniego WYDANEGO linku (kliknięcia po przebiegu).
        if ($this->jobs->hasPendingFor(AccountMailService::JOB_INVITATION, $accountId)) {
            return self::POWOD_W_KOLEJCE;
        }
        $zostalo = $this->pozostalyOdstep($accountId);
        if ($zostalo > 0) {
            // ⚠ Odmowa TEŻ idzie do dziennika, tak jak odmowa blokady: seria kliknięć w ten
            // przycisk to albo nerwowy administrator, albo próba zasypania czyjejś skrzynki
            // z naszego serwera — i jedno, i drugie chce się widzieć.
            $this->events->record('account.invitation_resend_refused', $byAccountId, null, $actorLabel,
                'account', $accountId, null, ['powod' => 'odstep', 'zostalo_s' => $zostalo]);

            return 'ostatnie zaproszenie wyszło niedawno — ponowienie będzie możliwe za '
                . self::minuty($zostalo);
        }
        // Unieważnienie starego linku i nowe zadanie — w tej kolejności.
        $this->invitations->revokeAllForAccount($accountId);
        $this->mail->queueInvitation($accountId, $byAccountId);
        $this->events->record('account.invitation_resent', $byAccountId, null, $actorLabel,
            'account', $accountId);

        return null;
    }

    /**
     * Ile sekund zostało do wolnego ponowienia (0 = wolno teraz).
     *
     * ⚠ Liczone z **wydania** ostatniego linku, także unieważnionego: inaczej unieważnienie
     * poprzedniego zaproszenia zerowałoby odstęp i cała reguła byłaby do obejścia jednym
     * dodatkowym kliknięciem.
     */
    /**
     * Kiedy wydano ostatni link zaproszenia (UTC) — do zdania w panelu osoby (zlecenie §3).
     *
     * ⚠ Liczy także linki **unieważnione**: pytanie brzmi „kiedy ostatnio coś do tej osoby
     * poszło", a nie „czy link jeszcze działa". To ta sama wartość, z której liczy się odstęp
     * minimalny ponowienia — jedno źródło, dwa pytania.
     */
    public function ostatnioWydane(int $accountId): ?string
    {
        return $this->invitations->lastIssuedAt($accountId);
    }

    /**
     * Ostatni link wraz ze stanem ważności — do zdania w panelu osoby.
     *
     * ⚠ Panel musi mówić, czy link **działa**, a nie tylko kiedy został wydany: „Link wydany
     * 12:50:59" o linku unieważnionym czyta się jak informacja, że jest czym kliknąć.
     *
     * @return array{wydany_utc: ?string, stan: string}
     */
    public function ostatniLink(int $accountId): array
    {
        return $this->invitations->ostatniLink($accountId);
    }

    public function pozostalyOdstep(int $accountId): int
    {
        $ostatnie = $this->invitations->lastIssuedAt($accountId);
        if ($ostatnie === null) {
            return 0;
        }
        $minelo = time() - (int) strtotime($ostatnie . ' UTC');

        return max(0, self::PONOWIENIE_ODSTEP_SEKUND - $minelo);
    }

    private static function minuty(int $sekundy): string
    {
        $minuty = (int) ceil($sekundy / 60);

        return $minuty <= 1 ? 'niespełna minutę' : $minuty . ' min';
    }

    /**
     * Zmiana adresu (loginu) konta, które **nie jest aktywne** (zlecenie `wyslij-ponownie` §3).
     *
     * ⚠ Reguła zmieniona 22.09.2026: adres jest edytowalny, **dopóki konto nie jest aktywne**,
     * a nie „do zaproszenia". Konto zaproszone nie ma hasła i **nikt nie potwierdził, że ten
     * adres do niego należy** — zamrażanie loginu, którego właściciel jeszcze nie istnieje,
     * chroni wyłącznie literówkę. A literówka bez drogi wyjścia to osoba, która nigdy nie
     * dostanie zaproszenia (`account.email` i `account.person_id` są `UNIQUE`).
     *
     * ⚠ Zmiana **unieważnia czynne zaproszenie i NIE wysyła nowego**: administrator ma zobaczyć,
     * że adres się zmienił, zanim cokolwiek poleci.
     *
     * @return string|null powód odmowy albo null, gdy zapisano
     */
    public function changeEmail(int $accountId, string $email, int $byAccountId, ?string $actorLabel): ?string
    {
        $account = $this->accounts->findById($accountId);
        if ($account === null) {
            return self::POWOD_BRAK_OSOBY;
        }
        if ($account['status'] === AccountRepository::STAN_AKTYWNE) {
            return self::POWOD_AKTYWNE;
        }
        $stary = (string) ($account['email'] ?? '');
        if ($stary === $email) {
            return null;
        }
        if ($this->adresZajetyPrzezInneKonto($email, pomijaneKonto: $accountId)
            || $this->people->ktoMaAdres($email, (int) $account['person_id'], null) !== null) {
            return self::POWOD_ADRES_ZAJETY;
        }
        try {
            if (!$this->accounts->changeEmailBeforeActivation($accountId, $email)) {
                return self::POWOD_AKTYWNE;
            }
        } catch (AdresZajetyException) {
            return self::POWOD_ADRES_ZAJETY;
        }
        // Adres kontaktowy osoby idzie razem z loginem — inaczej panel pokazywałby dwie wartości.
        $this->people->updateContactEmail((int) $account['person_id'], $email, null);
        $this->invitations->revokeAllForAccount($accountId);
        // ⚠ JEDYNE miejsce, w którym adres trafia do dziennika, i to ze starą wartością:
        // bez niej nie da się odtworzyć, co się stało z kontem (rozstrzygnięcie 22.09.2026).
        $this->events->record('account.email_changed', $byAccountId, null, $actorLabel,
            'account', $accountId, null, ['z' => $stary, 'na' => $email]);

        return null;
    }

    public function isTokenValid(mixed $token): bool
    {
        return $this->accountIdForToken($token) !== null;
    }

    /**
     * Konto, do którego należy link — **bez zużywania i bez unieważniania tokenu**.
     *
     * ⚠ Potrzebne, żeby rozpoznać „to samo konto" przy zalogowanej sesji (`Service\LinkHasla`).
     * Samo otwarcie linku nie ma prawa go zużyć: kto trafił tam przez pomyłkę, musi móc otworzyć
     * go ponownie na swoim komputerze.
     */
    public function accountIdForToken(mixed $token): ?int
    {
        return OneTimeToken::isWellFormed($token)
            ? $this->invitations->validAccountId(OneTimeToken::hash($token))
            : null;
    }

    /**
     * Ustawienie pierwszego hasła. Bez automatycznego logowania — użytkownik trafia na ekran logowania.
     *
     * @return string|null komunikat błędu albo null przy sukcesie
     */
    public function accept(mixed $token, #[\SensitiveParameter] string $newPassword, string $ipHash): ?string
    {
        if (!OneTimeToken::isWellFormed($token)) {
            return 'Zaproszenie jest nieprawidłowe lub wygasło.';
        }
        $policyError = $this->password->policyError($newPassword);
        if ($policyError !== null) {
            return $policyError;
        }
        $hash = $this->password->hash($newPassword);

        $accountId = $this->db->transaction(function () use ($token, $hash): ?int {
            $accountId = $this->invitations->consume(OneTimeToken::hash($token));
            if ($accountId === null) {
                return null;
            }
            $account = $this->accounts->findById($accountId);
            if ($account === null || $account['status'] !== 'invited') {
                return null;
            }
            $this->accounts->setPassword($accountId, $hash);

            return $accountId;
        });
        if ($accountId === null) {
            return 'Zaproszenie jest nieprawidłowe lub wygasło.';
        }
        $this->events->record('account.activated', $accountId, $ipHash, null, 'account', $accountId);

        return null;
    }
}
