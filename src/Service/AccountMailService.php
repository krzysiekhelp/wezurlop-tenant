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
use Wezurlop\Service\Mail\MailMessage;
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
     * Cisza connectora (zlecenie 21.09.2026 §2.2). ⚠ Komunikat mówi, CO SPRAWDZIĆ — „brak połączenia”
     * nie pomaga nikomu, kto ma zdecydować, czy dzwonić do informatyka, czy do nas.
     *
     * @param array<string, mixed> $payload
     */
    public function deliverIntegrationAlert(array $payload, MailTransport $transport): bool
    {
        $to = $this->settings->get('alert_email');
        if ($to === '' || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }
        $prog = is_int($payload['prog_godzin'] ?? null) ? $payload['prog_godzin'] : 24;
        $klucze = is_array($payload['klucze'] ?? null) ? $payload['klucze'] : [];
        $lista = '';
        foreach ($klucze as $nazwa => $godzin) {
            $lista .= '  - ' . (string) $nazwa . ': ' . (string) $godzin . " h bez ani jednego żądania\n";
        }
        $strefa = $this->settings->get('timezone', Strefa::DOMYSLNA);
        $transport->send(new MailMessage(
            $to,
            'wezurlop: connector przestał się odzywać',
            "Synchronizacja kadr stoi.\n\n"
            . "Instalacja wezurlop pod adresem {$this->config->appUrl} nie otrzymała od connectora żadnego żądania "
            . "przez ponad {$prog} godzin. Klucz instalacji jest ważny — to nie jest wygaśnięcie klucza.\n\n"
            . "Klucze bez ruchu:\n" . $lista . "\n"
            . "Co sprawdzić po kolei:\n"
            . "  1. czy maszyna z connectorem jest włączona,\n"
            . "  2. czy usługa connectora wstała po restarcie,\n"
            . "  3. czy nie zmienił się adres serwera programu kadrowego ani hasło do niego,\n"
            . "  4. czy z tej maszyny da się otworzyć adres {$this->config->appUrl}.\n\n"
            . "Stan bieżący: {$this->config->appUrl}/admin/integracja oraz /diagnostyka.\n"
            . "Czas w tej wiadomości i na ekranach: strefa {$strefa}.\n"
            . "Kolejny alert dopiero wtedy, gdy connector się odezwie i zamilknie ponownie.\n",
        ));

        return true;
    }

    /**
     * Klucz wydany i nieużyty (rozstrzygnięcie 20.09.2026). ⚠ Pierwsze zdanie mówi, że NIC SIĘ
     * NIE ZEPSUŁO — alert brzmiący jak awaria, a awarią niebędący, uczy ludzi ignorować alerty.
     *
     * @param array<string, mixed> $payload
     */
    public function deliverUnusedKeyAlert(array $payload, MailTransport $transport): bool
    {
        $to = $this->settings->get('alert_email');
        if ($to === '' || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }
        $prog = is_int($payload['prog_godzin'] ?? null) ? $payload['prog_godzin'] : 72;
        $klucze = is_array($payload['klucze'] ?? null) ? $payload['klucze'] : [];
        $lista = '';
        foreach ($klucze as $nazwa => $godzin) {
            $lista .= '  - ' . (string) $nazwa . ': wydany ' . (string) $godzin . " h temu, nigdy nieużyty\n";
        }
        $strefa = $this->settings->get('timezone', Strefa::DOMYSLNA);
        $transport->send(new MailMessage(
            $to,
            'wezurlop: wydany klucz czeka na wklejenie',
            "Nic się nie zepsuło — synchronizacja kadr działa na dotychczasowym kluczu.\n\n"
            . "W instalacji wezurlop pod adresem {$this->config->appUrl} wydano klucz instalacji ponad {$prog} godzin temu "
            . "i connector ani razu go nie użył:\n" . $lista . "\n"
            . "Najczęstsza przyczyna: klucz został wydany, ale nikt nie wkleił go w konfiguracji connectora.\n"
            . "Co zrobić: wklej klucz w connectorze i uruchom test połączenia, albo — jeśli wydano go przez pomyłkę —\n"
            . "unieważnij go na stronie {$this->config->appUrl}/admin/integracja. Dotychczasowy klucz działa do czasu,\n"
            . "aż nowy po raz pierwszy zakończy synchronizację.\n\n"
            . "Czas w tej wiadomości i na ekranach: strefa {$strefa}.\n"
            . "Kolejna wiadomość dopiero wtedy, gdy ten klucz zostanie użyty albo unieważniony i sytuacja powtórzy się od nowa.\n",
        ));

        return true;
    }

    /**
     * Dwie instalacje connectora na jednym kluczu (rozstrzygnięcie 20.09.2026).
     *
     * ⚠ To jest CICHA DEGRADACJA danych kadrowych: wszystko działa, nikt tego sam nie zauważy,
     * a osoby z dwóch firm przestają się scalać. Dlatego wiadomość idzie raz przy wykryciu.
     * Budowa jak w pozostałych alertach: stan → co to znaczy → co zrobić.
     *
     * @param array<string, mixed> $payload
     */
    public function deliverIdentitySplitAlert(array $payload, MailTransport $transport): bool
    {
        $to = $this->settings->get('alert_email');
        if ($to === '' || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }
        $klucz = is_string($payload['klucz'] ?? null) ? $payload['klucz'] : '—';
        $firmy = is_string($payload['firmy'] ?? null) ? $payload['firmy'] : '';
        $osob = is_int($payload['osob'] ?? null) ? $payload['osob'] : 0;
        $strefa = $this->settings->get('timezone', Strefa::DOMYSLNA);
        $transport->send(new MailMessage(
            $to,
            'wezurlop: dwie instalacje connectora obsługują firmy jednego klucza',
            "Synchronizacja działa, ale dane osób się rozchodzą.\n\n"
            . "W instalacji wezurlop pod adresem {$this->config->appUrl} firmy obsługiwane kluczem „{$klucz}” "
            . "liczą identyfikatory osób z dwóch różnych sekretów. To znaczy, że obsługują je dwie osobne "
            . "instalacje connectora.\n\n"
            . "Co to znaczy dla danych: osoba zatrudniona w dwóch takich firmach jest w wezurlop widziana "
            . "jako DWIE osoby — jej limity i nieobecności nie połączą się. Osób zatrudnionych w więcej niż "
            . "jednej firmie jest obecnie: {$osob}.\n\n"
            . "Firmy i ich grupy:\n  " . str_replace(' | ', "\n  ", $firmy) . "\n\n"
            . "Co zrobić: jeśli to pomyłka, obie firmy powinien obsługiwać jeden connector — jedna instalacja "
            . "i jeden sekret. Jeśli tak ma być, zostaw; synchronizacja obu firm działa.\n"
            . "Stan bieżący: {$this->config->appUrl}/admin/integracja oraz /diagnostyka.\n\n"
            . "Czas w tej wiadomości i na ekranach: strefa {$strefa}.\n"
            . "Kolejna wiadomość dopiero wtedy, gdy rozjazd ustanie i pojawi się ponownie.\n",
        ));

        return true;
    }

    public function deliverSecurityAlert(array $payload, MailTransport $transport): bool
    {
        $to = $this->settings->get('alert_email');
        if ($to === '' || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }
        if (($payload['kind'] ?? '') === 'storage_exposed') {
            $transport->send(new MailMessage(
                $to,
                'wezurlop: katalog storage/ jest dostępny z internetu',
                "Dobowa kontrola instalacji wezurlop pod adresem {$this->config->appUrl} pobrała przez HTTP plik "
                . "kontrolny z katalogu storage/. Oznacza to, że pliki spoza katalogu public/ (w tym załączniki, "
                . "a potencjalnie także konfiguracja) mogą być dostępne z internetu.\n\n"
                . "Sprawdź ustawienie katalogu domeny i plik .htaccess (np. po zmianie typu serwera albo wgraniu innej "
                . "aplikacji). Szczegóły w dzienniku zdarzeń (security.storage_exposed) i na stronie /diagnostyka.\n",
            ));

            return true;
        }
        $failures = is_int($payload['failures'] ?? null) ? $payload['failures'] : 0;
        $window = is_int($payload['window_minutes'] ?? null) ? $payload['window_minutes'] : 0;
        $temat = 'Nietypowa liczba nieudanych logowań';
        // ⚠ WYŁĄCZNIE LICZBY — zero adresów i zero nazw kont (CLAUDE.md §14). Kto i skąd
        // próbował, zostaje w dzienniku zdarzeń, do którego trzeba się zalogować.
        $transport->send($this->renderer->wiadomosc('alert-bezpieczenstwa', $to, $temat, [
            'temat'    => $temat,
            'podglad'  => $failures . ' nieudanych logowań w ciągu ' . $window . ' minut.',
            'naglowek' => $temat,
            'zdanie'   => 'W ciągu ostatnich ' . $window . ' minut odnotowaliśmy ' . $failures
                . ' nieudanych prób logowania. Żadne konto nie zostało zablokowane — blokada całej '
                . 'firmy byłaby skutkiem, o który atakującemu chodzi.',
            'wiersze'  => [
                ['etykieta' => 'Nieudanych prób', 'wartosc' => (string) $failures],
                ['etykieta' => 'W ciągu', 'wartosc' => $window . ' minut'],
                ['etykieta' => 'Kolejny alert', 'wartosc' => 'najwcześniej za godzinę'],
            ],
            'firma'    => $this->companies->singleR2Name(),
        ]));

        return true;
    }

    /**
     * Alert do kadr z synchronizacji (docs/api-connector.md 3.5). Na skrzynkę alertową tenanta — rola kadr
     * powstanie w turze B. Bez danych osobowych: numer wniosku i kod (komunikat programu kadrowego bywa
     * z imieniem i nazwiskiem, więc zostaje w aplikacji).
     *
     * @param array<string, mixed> $payload
     */
    public function deliverHrAlert(array $payload, MailTransport $transport): bool
    {
        $to = $this->settings->get('alert_email');
        if ($to === '' || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }
        $id = is_int($payload['leave_request_id'] ?? null) ? $payload['leave_request_id'] : 0;
        $code = is_string($payload['kod'] ?? null) ? $payload['kod'] : '—';
        [$subject, $body] = match ($payload['kind'] ?? '') {
            'zmiana_czesciowa' => ['PILNE: wniosek bez wpisu w kadrach',
                "Wniosek nr {$id}: zmiana w programie kadrowym nie powiodła się, a przywrócenie poprzednich wpisów też nie. "
                . "Urlop NIE jest zapisany w kadrach, a pracownik mógł już wyjechać. Skontaktuj się z pracownikiem i popraw wpis.\n"],
            'ostrzezenie'      => ['Wniosek zapisany z ostrzeżeniem', "Wniosek nr {$id} zapisano w kadrach z ostrzeżeniem ({$code}).\n"],
            'weryfikacja'      => ['Wniosek zatrzymany przed wysyłką do kadr', "Wniosek nr {$id} nie przeszedł weryfikacji na danych z kadr i czeka na wyjaśnienie.\n"],
            'obcy_wpis'        => ['Wpis w kadrach podpisany jako nasz, ale przez nas niewydany',
                'W programie kadrowym pojawił się wpis ' . (is_string($payload['wpis'] ?? null) ? $payload['wpis'] : '—')
                . " oznaczony jako utworzony przez connector, a my nie wydaliśmy takiego zadania.\n"
                . "Najczęściej to pozostałość po testach. Jeśli nie — może znaczyć, że do tej samej bazy kadrowej "
                . "pisze DRUGA instalacja. Sprawdź to, zanim zacznie się mieszać z urlopami pracowników.\n"],
            default            => ['Błąd zapisu wniosku w kadrach', "Wniosek nr {$id}: program kadrowy odrzucił zapis ({$code}). Wniosek czeka na wyjaśnienie.\n"],
        };
        $transport->send(new MailMessage($to, 'wezurlop: ' . $subject,
            $body . 'Szczegóły w aplikacji: ' . $this->config->absoluteUrl('/') . "\n"));

        return true;
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
                'temat'   => $temat,
                'podglad' => 'Link do ustawienia nowego hasła, ważny ' . self::RESET_VALID_MINUTES . ' minut.',
                'login'   => $account['email'],
                'link'    => $this->config->absoluteUrl('/haslo/nowe') . '?token=' . $generated['token'],
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
            $temat = 'Dostęp do systemu urlopowego — ustaw hasło';
            $zaprosil = $createdBy === null ? null : $this->accounts->findById($createdBy);
            $message = $this->renderer->wiadomosc('zaproszenie', $account['email'], $temat, [
                'temat'   => $temat,
                'podglad' => 'Ustaw hasło i zaloguj się do systemu urlopowego.',
                'imie_w_wolaczu' => $this->imieWWolaczu($account['first_name'] ?? null),
                'firma'   => $this->nazwaPracodawcy($accountId),
                'link'    => $this->config->absoluteUrl('/zaproszenie') . '?token=' . $generated['token'],
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
