<?php

declare(strict_types=1);

namespace Wezurlop\Service\Mail;

/**
 * Wybór transportu poczty (etap 7.2).
 *
 * ⚠ **Transport plikowy wyłącznie przy `APP_ENV=dev`.** Do dziś przy braku konfiguracji SMTP
 * kolejka meldowała **sukces**, bo zapis do pliku się udawał — czyli zaproszenie „wyszło",
 * a nie wyszło. To najgorszy możliwy stan: link do ustawienia hasła nigdy nie dotarł,
 * a dziennik mówił, że wszystko w porządku.
 *
 * ⚠ **W produkcji brak konfiguracji SMTP to BŁĄD INSTALACJI, nie tryb pracy.** Zadanie kończy
 * się niepowodzeniem z czytelnym komunikatem, ponawia się według harmonogramu kolejki
 * i widać je na `/diagnostyka`. Aplikacja nie udaje, że wysłała.
 */
final class TransportFactory
{
    public const POWOD_BRAK_KONFIGURACJI =
        'Poczta wychodząca nie jest skonfigurowana (serwer SMTP i adres nadawcy). '
        . 'Ustaw ją na ekranie Ustawienia → Poczta; do tego czasu wiadomości nie wychodzą.';

    public function __construct(
        private readonly MailSettings $ustawienia,
        private readonly string $katalogZrzutu,
        private readonly bool $dev,
    ) {
    }

    /**
     * @throws MailException w produkcji, gdy poczta nie jest skonfigurowana
     */
    public function transport(): MailTransport
    {
        if ($this->ustawienia->skonfigurowana()) {
            // ⚠ Znacznik udanej wysyłki tylko dla SMTP — zrzut do pliku udaje się zawsze,
            // więc „ostatnia udana wysyłka" po zapisie do pliku byłaby nieprawdą.
            return new TransportZeZnacznikiem(
                new SmtpTransport($this->ustawienia->dlaTransportu()),
                $this->ustawienia,
            );
        }
        if ($this->dev) {
            // Na dev brak SMTP jest normalny: wiadomości lądują w `var/mail/*.eml` i da się
            // przeczytać token z linku. ⚠ Ta ścieżka nie istnieje poza APP_ENV=dev.
            return new FileTransport($this->katalogZrzutu);
        }

        throw new MailException(self::POWOD_BRAK_KONFIGURACJI);
    }

    /** Czy wysyłka jest w ogóle możliwa — do diagnostyki i do ekranu ustawień. */
    public function gotowa(): bool
    {
        return $this->ustawienia->skonfigurowana() || $this->dev;
    }

    /** Czym poleci wiadomość: `smtp` albo `plik` (tylko dev). Do pokazania na ekranie. */
    public function rodzaj(): string
    {
        return $this->ustawienia->skonfigurowana() ? 'smtp' : ($this->dev ? 'plik' : 'brak');
    }
}
