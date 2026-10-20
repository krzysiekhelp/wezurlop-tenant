<?php

declare(strict_types=1);

namespace Wezurlop\Service;

use Wezurlop\Repository\AccountRepository;
use Wezurlop\Repository\CompanyRepository;
use Wezurlop\Repository\InvitationRepository;
use Wezurlop\Repository\JobRepository;
use Wezurlop\Repository\PasswordResetTokenRepository;
use Wezurlop\Repository\TenantSettingRepository;
use Wezurlop\Security\OneTimeToken;
use Wezurlop\Service\Mail\MailRenderer;
use Wezurlop\Service\Mail\MailTransport;
use Wezurlop\Support\NazwaOsoby;
use Wezurlop\Support\Strefa;
use Wezurlop\Support\Wolacz;
use Wezurlop\Support\Config;

/**
 * Wiadomości z jednorazowymi linkami (reset hasła, zaproszenie).
 *
 * Żądanie użytkownika tylko zapisuje zadanie w kolejce (bez tokenu). Token powstaje dopiero przy
 * wysyłce, w przebiegu crona — jego jawna postać nie trafia do bazy ani do ładunku zadania.
 * Linki budowane wyłącznie z APP_URL, nigdy z nagłówka Host (CLAUDE.md §11).
 */
final class AccountMailService
{
    public const JOB_PASSWORD_RESET = 'mail.password_reset';
    public const JOB_INVITATION = 'mail.invitation';

    public const RESET_VALID_MINUTES = 30;
    public const INVITATION_VALID_MINUTES = 7 * 24 * 60;

    public function __construct(
        private readonly TenantSettingRepository $settings,
        private readonly JobRepository $jobs,
        private readonly AccountRepository $accounts,
        private readonly PasswordResetTokenRepository $resetTokens,
        private readonly InvitationRepository $invitations,
        private readonly CompanyRepository $companies,
        private readonly MailRenderer $renderer,
        private readonly Config $config,
    ) {
    }

    /**
     * Nazwa pracodawcy do wiadomości — **pełna nazwa z R2**, nie nasza nazwa wyświetlana.
     *
     * ⚠ Kolejność: spółka aktywnego zatrudnienia → jedyna spółka tenanta → `null`.
     * Przy dwóch spółkach i koncie bez zatrudnienia (administrator) wolimy napisać bez nazwy
     * firmy niż zgadnąć, w czyim imieniu piszemy.
     */
    private function nazwaPracodawcy(int $accountId): ?string
    {
        return $this->companies->r2NameForAccount($accountId) ?? $this->companies->singleR2Name();
    }

    /** Imię w wołaczu albo `null` — najpierw przywracamy pisownię z WERSALIKÓW z R2. */
    private function imieWWolaczu(?string $imie): ?string
    {
        return $imie === null || trim($imie) === ''
            ? null
            : Wolacz::imie(NazwaOsoby::doWyswietlenia($imie));
    }

    public function queuePasswordReset(int $accountId, string $ipHash): void
    {
        $this->jobs->enqueue(self::JOB_PASSWORD_RESET, ['account_id' => $accountId, 'ip_hash' => $ipHash]);
    }

    public function queueInvitation(int $accountId, ?int $createdBy): void
    {
        $this->jobs->enqueue(self::JOB_INVITATION, ['account_id' => $accountId, 'created_by' => $createdBy]);
    }

    /**
     * Alert o podejrzeniu password spraying na skrzynkę alertową tenanta.
     *
     * @param array<string, mixed> $payload
     * @return bool false = brak skonfigurowanej skrzynki (zdarzenie jest w dzienniku)
     */
    /**
     * **Alert systemowy jedną drogą** (zlecenie Z60): wspólny szablon `alert`, wspólna stopka,
     * wersja HTML i tekstowa z jednego zestawu zmiennych.
     *
     * ⚠ Do 20.10.2026 każdy z tych alertów sklejał własny napis w PHP — osiem rodzajów, osiem
     * układów, zero HTML-a i zero wspólnej stopki. Treść różni się, ale KSZTAŁT nie: zdanie
     * o stanie, dane w parach, co sprawdzić po kolei. Ta metoda jest tym kształtem.
     *
     * ⚠ Adres bierzemy ze skrzynki alertowej tenanta i **nic nie wysyłamy, gdy jej nie ma** —
     * wartość `false` znaczy „zadanie bezprzedmiotowe", nie „błąd" (patrz `JobRunner`).
     *
     * @param list<string> $zdania
     * @param list<array{etykieta: string, wartosc: string}> $wiersze
     * @param list<string> $kroki
     */
    private function alert(
        MailTransport $transport,
        string $temat,
        string $podglad,
        array $zdania,
        array $wiersze = [],
        array $kroki = [],
        bool $pilny = false,
        ?string $stopka = null,
        ?array $akcja = null
    ): bool {
        $to = (string) $this->settings->get('alert_email');
        if ($to === '' || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }
        $transport->send($this->renderer->wiadomosc('alert', $to, 'Alert: ' . $temat, [
            'podglad'  => $podglad,
            'naglowek' => $temat,
            'zdania'   => $zdania,
            'wiersze'  => $wiersze,
            'kroki'    => $kroki,
            'pilny'    => $pilny,
            'stopka_zdanie' => $stopka,
            'akcja'    => $akcja,
            // ⚠ Nazwa firmy w stopce alertu: adresatem jest administrator TEJ instalacji,
            // a jedna skrzynka alertowa obsługuje czasem dwie instalacje u tego samego klienta.
            'firma'    => $this->companies->singleR2Name(),
        ]));

        return true;
    }

    /** Ekran integracji — wspólna akcja alertów o connectorze. */
    private function akcjaIntegracja(): array
    {
        return ['napis' => 'Otwórz ekran integracji', 'link' => $this->config->absoluteUrl('/admin/integracja')];
    }

    /**
     * Cisza connectora (zlecenie 21.09.2026 §2.2). ⚠ Komunikat mówi, CO SPRAWDZIĆ — „brak
     * połączenia" nie pomaga nikomu, kto ma zdecydować, czy dzwonić do informatyka, czy do nas.
     *
     * @param array<string, mixed> $payload
     */
    public function deliverIntegrationAlert(array $payload, MailTransport $transport): bool
    {
        $prog = is_int($payload['prog_godzin'] ?? null) ? $payload['prog_godzin'] : 24;
        $klucze = is_array($payload['klucze'] ?? null) ? $payload['klucze'] : [];
        $wiersze = [['etykieta' => 'Cisza dłuższa niż', 'wartosc' => $prog . ' h']];
        foreach ($klucze as $nazwa => $godzin) {
            $wiersze[] = ['etykieta' => 'Klucz „' . (string) $nazwa . '"',
                'wartosc' => (string) $godzin . ' h bez ani jednego żądania'];
        }

        return $this->alert(
            $transport,
            'connector przestał się odzywać',
            'Synchronizacja kadr stoi od ponad ' . $prog . ' godzin.',
            [
                'Synchronizacja kadr stoi.',
                'Ta instalacja nie otrzymała od connectora żadnego żądania przez ponad ' . $prog
                    . ' godzin. Klucz instalacji jest ważny — to nie jest wygaśnięcie klucza.',
            ],
            $wiersze,
            [
                'czy maszyna z connectorem jest włączona,',
                'czy usługa connectora wstała po restarcie,',
                'czy nie zmienił się adres serwera programu kadrowego ani hasło do niego,',
                'czy z tej maszyny da się otworzyć adres ' . $this->config->appUrl . '.',
            ],
            true,
            'Kolejny alert dopiero wtedy, gdy connector się odezwie i zamilknie ponownie.',
            $this->akcjaIntegracja(),
        );
    }

    /**
     * Cisza JEDNEJ firmy przy działającym connectorze (zlecenie Z18, 17.10.2026).
     *
     * ⚠ Pierwsze zdanie mówi, czym to się RÓŻNI od ciszy connectora, bo adresat dostaje oba listy
     * z tej samej skrzynki: tam nie działa cały connector, tu nie synchronizuje się jedna firma,
     * a reszta chodzi. Dwa listy o tym samym brzmieniu nauczyłyby czytelnika kasować oba.
     * ⚠ Bez NIP-u i bez danych osobowych — w liście jest nazwa firmy i liczby.
     *
     * @param array<string, mixed> $payload
     */
    public function deliverCompanyIdleAlert(array $payload, MailTransport $transport): bool
    {
        $firma = is_string($payload['firma'] ?? null) ? $payload['firma'] : '(bez nazwy)';
        $prog = is_int($payload['prog_godzin'] ?? null) ? $payload['prog_godzin'] : 24;
        $godzin = is_int($payload['godzin'] ?? null) ? $payload['godzin'] : $prog;
        $ostatni = is_string($payload['ostatni_przebieg'] ?? null) ? $payload['ostatni_przebieg'] : null;
        $strefa = (string) $this->settings->get('timezone', Strefa::DOMYSLNA);

        return $this->alert(
            $transport,
            'jedna firma przestała się synchronizować',
            'Connector działa, ale dane firmy ' . $firma . ' stoją.',
            [
                'Connector działa, ale dane jednej firmy stoją.',
                'To NIE jest cisza całego connectora — pozostałe firmy tej instalacji synchronizują '
                    . 'się dalej, więc maszyna i usługa connectora działają. Stoi strumień tej jednej firmy.',
                'Dane kadrowe tej firmy są w tym czasie nieaktualne: limity i nieobecności pokazują '
                    . 'stan sprzed ostatniego domkniętego przebiegu.',
            ],
            [
                ['etykieta' => 'Firma', 'wartosc' => $firma],
                ['etykieta' => 'Ostatni domknięty przebieg',
                    'wartosc' => $ostatni === null ? 'nieznany' : Strefa::czas($ostatni, $strefa)],
                ['etykieta' => 'Cisza', 'wartosc' => $godzin . ' h (próg ' . $prog . ' h)'],
            ],
            [
                'czy ta firma nie czeka na decyzję — zmieniony odcisk tożsamości zatrzymuje strumień '
                    . 'do czasu potwierdzenia,',
                'czy klucz instalacji ma tę firmę w zakresie,',
                'czy connector ma połączenie z bazą kadrową TEJ firmy — druga firma może mieć inną,',
                'dziennik zdarzeń: wpisy `integration.company_idle` i `sync.*` tej firmy.',
            ],
            true,
            'Kolejny alert o tej firmie dopiero wtedy, gdy znów się zsynchronizuje i zamilknie ponownie.',
            $this->akcjaIntegracja(),
        );
    }

    /**
     * Klucz wydany i nieużyty (rozstrzygnięcie 20.09.2026). ⚠ Pierwsze zdanie mówi, że NIC SIĘ
     * NIE ZEPSUŁO — alert brzmiący jak awaria, a awarią niebędący, uczy ludzi ignorować alerty.
     *
     * @param array<string, mixed> $payload
     */
    public function deliverUnusedKeyAlert(array $payload, MailTransport $transport): bool
    {
        $prog = is_int($payload['prog_godzin'] ?? null) ? $payload['prog_godzin'] : 72;
        $klucze = is_array($payload['klucze'] ?? null) ? $payload['klucze'] : [];
        $wiersze = [];
        foreach ($klucze as $nazwa => $godzin) {
            $wiersze[] = ['etykieta' => 'Klucz „' . (string) $nazwa . '"',
                'wartosc' => 'wydany ' . (string) $godzin . ' h temu, nigdy nieużyty'];
        }

        return $this->alert(
            $transport,
            'wydany klucz czeka na wklejenie',
            'Nic się nie zepsuło — synchronizacja działa na dotychczasowym kluczu.',
            [
                'Nic się nie zepsuło — synchronizacja kadr działa na dotychczasowym kluczu.',
                'W tej instalacji wydano klucz instalacji ponad ' . $prog
                    . ' godzin temu i connector ani razu go nie użył.',
                'Najczęstsza przyczyna: klucz został wydany, ale nikt nie wkleił go w konfiguracji '
                    . 'connectora. Wklej go i uruchom test połączenia albo — jeśli wydano go przez '
                    . 'pomyłkę — unieważnij. Dotychczasowy klucz działa do czasu, aż nowy po raz '
                    . 'pierwszy zakończy synchronizację.',
            ],
            $wiersze,
            [],
            false,
            'Kolejna wiadomość dopiero wtedy, gdy ten klucz zostanie użyty albo unieważniony '
                . 'i sytuacja powtórzy się od nowa.',
            $this->akcjaIntegracja(),
        );
    }

    /**
     * Dwie instalacje connectora na jednym kluczu (rozstrzygnięcie 20.09.2026).
     *
     * ⚠ To jest CICHA DEGRADACJA danych kadrowych: wszystko działa, nikt tego sam nie zauważy,
     * a osoby z dwóch firm przestają się scalać. Dlatego wiadomość idzie raz przy wykryciu.
     *
     * @param array<string, mixed> $payload
     */
    public function deliverIdentitySplitAlert(array $payload, MailTransport $transport): bool
    {
        $klucz = is_string($payload['klucz'] ?? null) ? $payload['klucz'] : '—';
        $firmy = is_string($payload['firmy'] ?? null) ? $payload['firmy'] : '';
        $osob = is_int($payload['osob'] ?? null) ? $payload['osob'] : 0;
        $wiersze = [
            ['etykieta' => 'Klucz', 'wartosc' => $klucz],
            ['etykieta' => 'Osób w więcej niż jednej firmie', 'wartosc' => (string) $osob],
        ];
        foreach (explode(' | ', $firmy) as $grupa) {
            if (trim($grupa) !== '') {
                $wiersze[] = ['etykieta' => 'Grupa firm', 'wartosc' => trim($grupa)];
            }
        }

        return $this->alert(
            $transport,
            'dwie instalacje connectora obsługują firmy jednego klucza',
            'Synchronizacja działa, ale dane osób się rozchodzą.',
            [
                'Synchronizacja działa, ale dane osób się rozchodzą.',
                'Firmy obsługiwane kluczem „' . $klucz . '" liczą identyfikatory osób z dwóch '
                    . 'różnych sekretów. To znaczy, że obsługują je dwie osobne instalacje connectora.',
                'Co to znaczy dla danych: osoba zatrudniona w dwóch takich firmach jest widziana '
                    . 'jako DWIE osoby — jej limity i nieobecności nie połączą się.',
                'Co zrobić: jeśli to pomyłka, obie firmy powinien obsługiwać jeden connector — jedna '
                    . 'instalacja i jeden sekret. Jeśli tak ma być, zostaw; synchronizacja obu firm działa.',
            ],
            $wiersze,
            [],
            true,
            'Kolejna wiadomość dopiero wtedy, gdy rozjazd ustanie i pojawi się ponownie.',
            $this->akcjaIntegracja(),
        );
    }

    /**
     * Alerty bezpieczeństwa: odsłonięty katalog danych i podejrzane logowania.
     *
     * @param array<string, mixed> $payload
     */
    public function deliverSecurityAlert(array $payload, MailTransport $transport): bool
    {
        if (($payload['kind'] ?? '') === 'storage_exposed') {
            return $this->alert(
                $transport,
                'katalog storage/ jest dostępny z internetu',
                'Dobowa kontrola pobrała przez HTTP plik z katalogu storage/.',
                [
                    'Dobowa kontrola instalacji pobrała przez HTTP plik kontrolny z katalogu storage/.',
                    'Oznacza to, że pliki spoza katalogu public/ — w tym załączniki, a potencjalnie '
                        . 'także konfiguracja — mogą być dostępne z internetu.',
                    'Sprawdź ustawienie katalogu domeny i plik .htaccess (np. po zmianie typu serwera '
                        . 'albo wgraniu innej aplikacji). Szczegóły w dzienniku zdarzeń '
                        . '(security.storage_exposed) i na stronie diagnostyki.',
                ],
                [],
                [],
                true,
                null,
                ['napis' => 'Otwórz diagnostykę', 'link' => $this->config->absoluteUrl('/diagnostyka')],
            );
        }
        $failures = is_int($payload['failures'] ?? null) ? $payload['failures'] : 0;
        $window = is_int($payload['window_minutes'] ?? null) ? $payload['window_minutes'] : 0;

        // ⚠ WYŁĄCZNIE LICZBY — zero adresów i zero nazw kont (CLAUDE.md §14). Kto i skąd
        // próbował, zostaje w dzienniku zdarzeń, do którego trzeba się zalogować.
        return $this->alert(
            $transport,
            'nietypowa liczba nieudanych logowań',
            $failures . ' nieudanych logowań w ciągu ' . $window . ' minut.',
            [
                'W ciągu ostatnich ' . $window . ' minut odnotowaliśmy ' . $failures
                    . ' nieudanych prób logowania.',
                'Żadne konto nie zostało zablokowane — blokada całej firmy byłaby skutkiem, o który '
                    . 'atakującemu chodzi. Kto i skąd próbował, zapisał dziennik zdarzeń.',
            ],
            [
                ['etykieta' => 'Nieudanych prób', 'wartosc' => (string) $failures],
                ['etykieta' => 'W ciągu', 'wartosc' => $window . ' minut'],
                ['etykieta' => 'Kolejny alert', 'wartosc' => 'najwcześniej za godzinę'],
            ],
            [],
            true,
            null,
            ['napis' => 'Otwórz dziennik zdarzeń', 'link' => $this->config->absoluteUrl('/admin/dziennik')],
        );
    }

    /**
     * Alerty kadrowe z synchronizacji wniosków (zlecenie Etapu 3).
     *
     * ⚠ Pięć różnych stanów, jedna rodzina wiadomości — i tylko jeden z nich jest PILNY:
     * wniosek bez wpisu w kadrach, przy którym pracownik mógł już wyjechać.
     *
     * @param array<string, mixed> $payload
     */
    public function deliverHrAlert(array $payload, MailTransport $transport): bool
    {
        $id = is_int($payload['leave_request_id'] ?? null) ? $payload['leave_request_id'] : 0;
        $code = is_string($payload['kod'] ?? null) ? $payload['kod'] : '—';
        $wpis = is_string($payload['wpis'] ?? null) ? $payload['wpis'] : '—';
        [$temat, $zdania, $pilny] = match ($payload['kind'] ?? '') {
            'zmiana_czesciowa' => ['wniosek bez wpisu w kadrach', [
                'Zmiana w programie kadrowym nie powiodła się, a przywrócenie poprzednich wpisów też nie.',
                'Urlop NIE jest zapisany w kadrach, a pracownik mógł już wyjechać. Skontaktuj się '
                    . 'z pracownikiem i popraw wpis.',
            ], true],
            'ostrzezenie' => ['wniosek zapisany z ostrzeżeniem', [
                'Wniosek zapisano w kadrach, ale program kadrowy zgłosił ostrzeżenie.',
            ], false],
            'weryfikacja' => ['wniosek zatrzymany przed wysyłką do kadr', [
                'Wniosek nie przeszedł weryfikacji na danych z kadr i czeka na wyjaśnienie.',
            ], false],
            'obcy_wpis' => ['wpis w kadrach podpisany jako nasz, ale przez nas niewydany', [
                'W programie kadrowym pojawił się wpis ' . $wpis . ' oznaczony jako utworzony przez '
                    . 'connector, a my nie wydaliśmy takiego zadania.',
                'Najczęściej to pozostałość po testach. Jeśli nie — może znaczyć, że do tej samej '
                    . 'bazy kadrowej pisze DRUGA instalacja. Sprawdź to, zanim zacznie się mieszać '
                    . 'z urlopami pracowników.',
            ], true],
            default => ['błąd zapisu wniosku w kadrach', [
                'Program kadrowy odrzucił zapis wniosku. Wniosek czeka na wyjaśnienie.',
            ], false],
        };
        $wiersze = [['etykieta' => 'Wniosek', 'wartosc' => 'nr ' . $id]];
        if ($code !== '—') {
            $wiersze[] = ['etykieta' => 'Kod z programu kadrowego', 'wartosc' => $code];
        }

        return $this->alert(
            $transport,
            $temat,
            'Wniosek nr ' . $id . ': ' . $temat . '.',
            $zdania,
            $wiersze,
            [],
            $pilny,
            null,
            ['napis' => 'Otwórz aplikację', 'link' => $this->config->absoluteUrl('/')],
        );
    }

    /**
     * Wykonanie zadania z kolejki. Gdy wysyłka się nie uda, świeżo utworzony token jest usuwany —
     * nikt go nie otrzymał, a ponowienie wygeneruje nowy.
     *
     * @param array<string, mixed> $payload
     * @return bool false = zadanie bezprzedmiotowe (np. konto usunięte lub nieaktywne)
     */
    public function deliver(string $type, array $payload, MailTransport $transport): bool
    {
        $accountId = is_int($payload['account_id'] ?? null) ? $payload['account_id'] : 0;
        $account = $this->accounts->findById($accountId);
        if ($account === null) {
            return false;
        }

        $generated = OneTimeToken::generate();
        if ($type === self::JOB_PASSWORD_RESET) {
            if ($account['status'] !== 'active') {
                return false;
            }
            $ipHash = is_string($payload['ip_hash'] ?? null) ? $payload['ip_hash'] : null;
            $this->resetTokens->create($accountId, $generated['hash'], self::RESET_VALID_MINUTES, $ipHash);
            $temat = 'Ustawienie nowego hasła';
            $message = $this->renderer->wiadomosc('haslo-reset', $account['email'], $temat, [
                'podglad' => 'Link do ustawienia nowego hasła, ważny ' . self::RESET_VALID_MINUTES . ' minut.',
                'login'   => $account['email'],
                // ⚠ Przycisk i adres pod nim rysuje układ (Z60) — szablon treści nie powtarza
                // tej konstrukcji, więc poprawka przycisku jest w jednym miejscu.
                'akcja'   => ['napis' => 'Ustaw nowe hasło',
                    'link' => $this->config->absoluteUrl('/haslo/nowe') . '?token=' . $generated['token']],
                'waznosc' => self::RESET_VALID_MINUTES . ' minut',
                'firma'   => $this->nazwaPracodawcy($accountId),
            ]);
            $revoke = fn () => $this->resetTokens->deleteByHash($generated['hash']);
        } elseif ($type === self::JOB_INVITATION) {
            if ($account['status'] !== 'invited') {
                return false;
            }
            $createdBy = is_int($payload['created_by'] ?? null) ? $payload['created_by'] : null;
            // Nowe zaproszenie unieważnia poprzednie niewykorzystane.
            $this->invitations->revokeAllForAccount($accountId);
            $this->invitations->create($accountId, $generated['hash'], self::INVITATION_VALID_MINUTES, $createdBy);
            // ⚠ Temat mówi, CO ZROBIĆ, i nie zawiera ani tokenu, ani nazwy naszego produktu
            // na pierwszym miejscu: pracownik ma poznać wiadomość od pracodawcy, nie od dostawcy.
            // ⚠ **Temat ma wzór wspólny dla całej poczty** (Z60): prefiks „wezurlop · " dokłada
            // `MailRenderer`, a tu zostaje to, CO ZROBIĆ. Wcześniejsze rozstrzygnięcie zabraniało
            // nazwy produktu na początku tematu zaproszenia; zarządca odwołał je w Z60, wymieniając
            // zaproszenie wprost we wzorze. Nazwa pracodawcy stoi w treści i w stopce.
            $temat = 'Zaproszenie do aplikacji — ustaw hasło';
            $zaprosil = $createdBy === null ? null : $this->accounts->findById($createdBy);
            $message = $this->renderer->wiadomosc('zaproszenie', $account['email'], $temat, [
                'podglad' => 'Ustaw hasło i zaloguj się do systemu urlopowego.',
                'imie_w_wolaczu' => $this->imieWWolaczu($account['first_name'] ?? null),
                'firma'   => $this->nazwaPracodawcy($accountId),
                'akcja'   => ['napis' => 'Ustaw hasło i zaloguj się',
                    'link' => $this->config->absoluteUrl('/zaproszenie') . '?token=' . $generated['token']],
                'login'   => $account['email'],
                'zapraszajacy' => $zaprosil === null ? null : NazwaOsoby::imieNazwisko(
                    (string) ($zaprosil['first_name'] ?? ''),
                    (string) ($zaprosil['last_name'] ?? ''),
                ),
                'waznosc_dni' => '7 dni',
            ]);
            $revoke = fn () => $this->invitations->deleteByHash($generated['hash']);
        } else {
            throw new \InvalidArgumentException('Nieznany typ wiadomości: ' . $type);
        }

        try {
            $transport->send($message);
        } catch (\Throwable $e) {
            $revoke();
            throw $e;
        }

        return true;
    }
}
