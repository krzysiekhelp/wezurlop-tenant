<?php

declare(strict_types=1);

namespace Wezurlop\Service\Mail;

use Wezurlop\Repository\TenantSettingRepository;
use Wezurlop\Security\SecretBox;

/**
 * Konfiguracja poczty wychodzącej — **w bazie, nie w `.env`** (etap 7.2).
 *
 * ⚠ Dlaczego w bazie: `.env` pisze instalator raz, a serwer SMTP klienta zmienia się później
 * (przeprowadzka skrzynki, zmiana hasła, inny dostawca). Edycja `.env` na hostingu to praca
 * przez FTP i ryzyko literówki w pliku, który przy błędzie wywraca całą aplikację, a nie jedną
 * funkcję. Ekran ustawień z wysyłką próbną jest tu jedyną drogą, którą klient sam sobie poradzi.
 *
 * ⚠ **Hasło SMTP szyfrowane kluczem z `.env`** (`SecretBox`, ten sam mechanizm co sekret TOTP):
 * wyciek samej bazy nie może dać dostępu do skrzynki, z której wychodzą zaproszenia i resety
 * haseł — czyli do drogi na każde konto w instalacji.
 *
 * ⚠ Hasło **nigdy nie wraca na ekran** (§9 reguł). Do widoku idzie wyłącznie informacja
 * „ustawione / nie ustawione"; puste pole przy zapisie znaczy „nie zmieniaj", a nie „wyczyść".
 */
final class MailSettings
{
    public const KLUCZ_HOST = 'mail_host';
    public const KLUCZ_PORT = 'mail_port';
    public const KLUCZ_SZYFROWANIE = 'mail_encryption';
    public const KLUCZ_UZYTKOWNIK = 'mail_username';
    public const KLUCZ_HASLO = 'mail_password_enc';
    public const KLUCZ_NADAWCA = 'mail_from';
    public const KLUCZ_NAZWA_NADAWCY = 'mail_from_name';

    /** Ostatnia udana wysyłka — do diagnostyki („czy poczta w ogóle wychodzi"). */
    public const KLUCZ_OSTATNIA_WYSYLKA = 'mail_last_sent_at';

    /** @var list<string> */
    public const SZYFROWANIA = ['tls', 'ssl', 'none'];

    public const PORT_DOMYSLNY = 587;

    public function __construct(
        private readonly TenantSettingRepository $settings,
        private readonly SecretBox $secretBox,
        /**
         * Nazwa hosta z ADRESU INSTALACJI (`APP_URL`) — ta sama, z której budujemy linki
         * w wiadomościach. ⚠ Nie `gethostname()`: nazwa maszyny u klienta bywa czymkolwiek
         * („localhost", „srv12"), a trafiała w `Message-ID` wysyłanej poczty.
         */
        private readonly string $hostname = '',
    ) {
    }

    public function host(): string
    {
        return trim($this->settings->get(self::KLUCZ_HOST));
    }

    public function port(): int
    {
        $port = (int) $this->settings->get(self::KLUCZ_PORT, (string) self::PORT_DOMYSLNY);

        return $port >= 1 && $port <= 65535 ? $port : self::PORT_DOMYSLNY;
    }

    public function szyfrowanie(): string
    {
        $wartosc = strtolower(trim($this->settings->get(self::KLUCZ_SZYFROWANIE, 'tls')));

        // Biała lista (CLAUDE.md §11): wartość spoza listy znaczy „tls", nie „none" —
        // pomyłka w bazie nie ma prawa wyłączyć szyfrowania po cichu.
        return in_array($wartosc, self::SZYFROWANIA, true) ? $wartosc : 'tls';
    }

    public function uzytkownik(): string
    {
        return trim($this->settings->get(self::KLUCZ_UZYTKOWNIK));
    }

    public function nadawca(): string
    {
        return trim($this->settings->get(self::KLUCZ_NADAWCA));
    }

    /**
     * Nazwa wyświetlana nadawcy — **nazwa firmy klienta**, nie nasza.
     *
     * ⚠ Zmierzone na doręczonej wiadomości 22.09.2026: nadawca nazywał się `wezurlop`, czyli
     * pracownik dostawał wiadomość o **swoim** urlopie od firmy, o której nigdy nie słyszał.
     * To wygląda jak phishing i tak zostanie potraktowane — a przy zaproszeniu, które ma
     * skłonić do ustawienia hasła, jest to najgorszy możliwy nadawca.
     *
     * ⚠ **Brak nazwy jest lepszy niż nazwa wymyślona**: puste znaczy „wyślij bez nazwy
     * wyświetlanej", a nie „podstaw `wezurlop`". Adres zostaje ten z konfiguracji SMTP —
     * zmieniamy nazwę wyświetlaną, nie adres.
     */
    public function nazwaNadawcy(): string
    {
        return trim($this->settings->get(self::KLUCZ_NAZWA_NADAWCY));
    }

    public function maHaslo(): bool
    {
        return $this->settings->get(self::KLUCZ_HASLO) !== '';
    }

    /**
     * ⚠ Jedyne miejsce, w którym hasło SMTP jest odszyfrowywane — i wychodzi **tylko** do
     * transportu. Nie wraca do widoku, nie trafia do dziennika ani do logu.
     */
    private function haslo(): string
    {
        $zapieczetowane = $this->settings->get(self::KLUCZ_HASLO);
        if ($zapieczetowane === '') {
            return '';
        }

        // `null` z `open()` znaczy „nie da się odszyfrować" (np. po zmianie APP_KEY).
        // Wtedy hasła nie ma — SMTP odmówi uwierzytelnienia z czytelnym błędem,
        // a nie wyśle pustego ciągu jako hasła w nieskończonej pętli ponowień.
        return $this->secretBox->open($zapieczetowane) ?? '';
    }

    /**
     * Czy poczta jest skonfigurowana na tyle, żeby dało się wysłać wiadomość.
     *
     * ⚠ Bez hosta i bez adresu nadawcy nie ma wysyłki — i to jest **błąd instalacji**,
     * nie stan „chwilowo bez poczty" (patrz `TransportFactory`).
     */
    public function skonfigurowana(): bool
    {
        return $this->host() !== '' && $this->nadawca() !== '';
    }

    /**
     * Konfiguracja dla `SmtpTransport`.
     *
     * @return array{host: string, port: int, username: string, password: string,
     *     encryption: string, from: string, from_name: string, hostname: string}
     */
    public function dlaTransportu(): array
    {
        return [
            'host'       => $this->host(),
            'port'       => $this->port(),
            'username'   => $this->uzytkownik(),
            'password'   => $this->haslo(),
            'encryption' => $this->szyfrowanie(),
            'from'       => $this->nadawca(),
            'from_name'  => $this->nazwaNadawcy(),
            // ⚠ Prawa strona `Message-ID` i nazwa w `EHLO` — z ADRESU INSTALACJI, nie z nazwy
            // maszyny. Zmierzone: PHPMailer wystawił `Message-ID: <…@wezurlop>`, a `@wezurlop`
            // nie jest nazwą domenową; część filtrów to punktuje, część przekaźników przepisuje.
            'hostname'   => $this->hostname,
        ];
    }

    /**
     * Zapis ustawień z ekranu.
     *
     * ⚠ `$haslo === null` albo puste znaczy **„nie zmieniaj"**: pole hasła na ekranie jest zawsze
     * puste (nie oddajemy sekretu do przeglądarki), więc zapis formularza nie może go czyścić.
     * Do wyczyszczenia służy osobna decyzja (`$wyczyscHaslo`), a nie pusty formularz.
     */
    public function zapisz(
        string $host,
        int $port,
        string $szyfrowanie,
        string $uzytkownik,
        #[\SensitiveParameter] ?string $haslo,
        string $nadawca,
        string $nazwaNadawcy,
        bool $wyczyscHaslo,
        ?int $przez,
    ): void {
        $this->settings->set(self::KLUCZ_HOST, trim($host), $przez);
        $this->settings->set(self::KLUCZ_PORT, (string) ($port >= 1 && $port <= 65535 ? $port : self::PORT_DOMYSLNY), $przez);
        $this->settings->set(
            self::KLUCZ_SZYFROWANIE,
            in_array($szyfrowanie, self::SZYFROWANIA, true) ? $szyfrowanie : 'tls',
            $przez,
        );
        $this->settings->set(self::KLUCZ_UZYTKOWNIK, trim($uzytkownik), $przez);
        $this->settings->set(self::KLUCZ_NADAWCA, strtolower(trim($nadawca)), $przez);
        $this->settings->set(self::KLUCZ_NAZWA_NADAWCY, trim($nazwaNadawcy), $przez);
        if ($wyczyscHaslo) {
            $this->settings->set(self::KLUCZ_HASLO, '', $przez);

            return;
        }
        if ($haslo !== null && $haslo !== '') {
            $this->settings->set(self::KLUCZ_HASLO, $this->secretBox->seal($haslo), $przez);
        }
    }

    public function ostatniaWysylka(): ?string
    {
        $wartosc = $this->settings->get(self::KLUCZ_OSTATNIA_WYSYLKA);

        return $wartosc === '' ? null : $wartosc;
    }

    /** Znacznik udanej wysyłki — stawiany przez kolejkę, nie przez ekran. */
    public function zapiszUdanaWysylke(): void
    {
        $this->settings->set(self::KLUCZ_OSTATNIA_WYSYLKA, gmdate('Y-m-d H:i:s'), null);
    }
}
