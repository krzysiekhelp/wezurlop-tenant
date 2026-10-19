<?php

declare(strict_types=1);

namespace Wezurlop\Service\Mail;

use Throwable;
use Wezurlop\Repository\EventLogRepository;

/**
 * Transport, który zapisuje **fakt wysyłki** w dzienniku zdarzeń (zlecenie Z43 §C).
 *
 * ⚠ Do 19.10.2026 wysłana poczta nie zostawiała w dzienniku ani jednego wpisu: były wpisy
 * o ZAMÓWIENIU wiadomości (`account.invited`, `auth.password_reset_requested`), a te mówią,
 * że ktoś kliknął — nie, że wiadomość wyszła. Między jednym i drugim stoi kolejka, cron
 * i serwer SMTP klienta, czyli wszystko, co się psuje. Przy zgłoszeniu „nie dostałem
 * zaproszenia" dziennik odpowiadał „zaproszono", a pytanie było inne.
 *
 * ⚠ **Dekorator, a nie wpis w kolejce ani w serwisie poczty** — bo odbiorcę zna wyłącznie
 * `MailMessage`, a wysyłek jest dziewięć rodzajów w pięciu miejscach. Wpis przy każdym z nich
 * znaczyłby dziewięć miejsc do zapomnienia; tutaj jest jedno i obejmuje też wysyłkę,
 * która dopiero powstanie.
 *
 * ⚠ **Niepowodzenie zapisujemy PRZED ponownym rzuceniem wyjątku**: ponowienie zadania,
 * wycofanie tokenu i zapis do logu dzieją się wyżej, a wpis o nieudanej wysyłce musi zostać
 * także wtedy, gdy zadanie ostatecznie się nie udaje (CLAUDE.md §14: wpis o niepowodzeniu
 * bywa ważniejszy od wpisu o sukcesie).
 *
 * ⚠ **Powód błędu obcięty i w jednej linii, bez sekretów.** Komunikat PHPMailera nie zawiera
 * hasła SMTP ani treści wiadomości (`SmtpTransport`), ale potrafi mieć kilka linii odpowiedzi
 * serwera — a to, co stoi w zdaniu dziennika, ma być jednym zdaniem.
 */
final class TransportZDziennikiem implements MailTransport
{
    /** Tyle powodu wchodzi do wpisu — dalej idzie już log (`storage/logs`), nie dziennik. */
    private const POWOD_ZNAKOW = 200;

    public const ZDARZENIE_WYSLANE = 'mail.sent';
    public const ZDARZENIE_NIEUDANE = 'mail.failed';

    public function __construct(
        private readonly MailTransport $transport,
        private readonly EventLogRepository $events,
        /** Rodzaj wiadomości w BIERNIKU („zaproszenie", „wiadomość próbną") — do zdania o sukcesie. */
        private readonly string $rodzaj,
        /**
         * Ten sam rodzaj w DOPEŁNIACZU („zaproszenia", „wiadomości próbnej") — do zdania
         * o niepowodzeniu.
         *
         * ⚠ Dwie formy, bo polskie zaprzeczenie rządzi dopełniaczem: jedna dawała „nie wysłał
         * link do ustawienia hasła". Transport nie wie z góry, które zdanie powstanie, więc
         * musi dostać obie (`Support\ZadaniaKolejki`).
         */
        private readonly string $rodzajDopelniacz,
        /** Sprawca: `cron` przy kolejce, nazwa osoby przy wysyłce próbnej z ekranu. */
        private readonly string $aktor,
        private readonly ?int $zadanie = null,
        private readonly ?int $konto = null,
        /**
         * Dopowiedzenie o drodze wiadomości — `null`, gdy poszła SMTP-em.
         *
         * ⚠ Na `APP_ENV=dev` bez konfiguracji SMTP wiadomość ląduje w `var/mail/*.eml`, więc
         * „wysłał zaproszenie" byłoby nieprawdą dokładnie tego rodzaju, którą naprawia
         * `TransportZeZnacznikiem`. Zdanie dostaje wtedy „— zrzut do pliku".
         */
        private readonly ?string $droga = null,
    ) {
    }

    public function send(MailMessage $message): void
    {
        try {
            $this->transport->send($message);
        } catch (Throwable $e) {
            $this->zapisz(self::ZDARZENIE_NIEUDANE, $message,
                ['rodzaj' => $this->rodzajDopelniacz, 'powod' => self::powod($e)]);

            throw $e;
        }
        $this->zapisz(self::ZDARZENIE_WYSLANE, $message, []);
    }

    /**
     * ⚠ Odbiorca w dzienniku jest celem wpisu, nie przeoczeniem: „komu co poszło" jest właśnie
     * tym pytaniem, na które ekran kolejki odpowiadać NIE MOŻE (tam stoją same liczby),
     * a dziennik może — za osobnym uprawnieniem. Treści wiadomości i linku z tokenem nie ma
     * tu ani w szczegółach (CLAUDE.md §9).
     *
     * @param array<string, string> $dodatkowe
     */
    private function zapisz(string $kod, MailMessage $message, array $dodatkowe): void
    {
        $detale = $dodatkowe + ['rodzaj' => $this->rodzaj, 'odbiorca' => $message->to];
        if ($this->zadanie !== null) {
            $detale['zadanie'] = (string) $this->zadanie;
        }
        if ($this->droga !== null) {
            $detale['droga'] = $this->droga;
        }
        $this->events->record($kod, $this->konto, null, $this->aktor, null, null, null, $detale);
    }

    private static function powod(Throwable $e): string
    {
        $tekst = trim((string) preg_replace('/\s+/u', ' ', $e->getMessage()));

        return $tekst === ''
            ? $e::class
            : mb_substr($tekst, 0, self::POWOD_ZNAKOW);
    }
}
