<?php

declare(strict_types=1);

namespace Wezurlop\Service\Mail;

/**
 * Wysyłka wiadomości. Dwie implementacje: SMTP (produkcja) i zrzut do plików (wyłącznie dev).
 * Wywoływana tylko z kolejki zadań, nigdy w żądaniu użytkownika (CLAUDE.md §0).
 */
interface MailTransport
{
    /** @throws MailException gdy wiadomości nie udało się wysłać */
    public function send(MailMessage $message): void;
}
