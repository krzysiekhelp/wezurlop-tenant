<?php

declare(strict_types=1);

namespace Wezurlop\Service\Mail;

use ErrorException;
use Wezurlop\Support\ErrorGuard;

/**
 * TYLKO DEV: zrzut wiadomości do var/mail/ (prawa 700), poza storage/logs, poza rotacją i paczką
 * wydania — treść zawiera jednorazowe tokeny (docs/szkielet-tenanta.md rozdz. 6).
 */
final class FileTransport implements MailTransport
{
    public function __construct(private readonly string $directory)
    {
    }

    public function send(MailMessage $message): void
    {
        // ⚠ W zrzucie OBIE części, w tej samej kolejności co w wiadomości: tekst (to on jest
        // obowiązkowy) i HTML. Inaczej podgląd na dev pokazywałby połowę tego, co dostaje odbiorca.
        $content = 'To: ' . $message->to . "\n" . 'Subject: ' . $message->subject . "\n"
            . 'Date: ' . gmdate('r') . "\n"
            . 'Content-Type: ' . ($message->html === null ? 'text/plain' : 'multipart/alternative') . "\n\n"
            . "--- tekst ---\n" . $message->body . "\n"
            . ($message->html === null ? '' : "\n--- html ---\n" . $message->html . "\n");
        $file = $this->directory . '/' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.eml';

        try {
            ErrorGuard::run(function () use ($file, $content): void {
                if (!is_dir($this->directory)) {
                    mkdir($this->directory, 0700, true);
                }
                file_put_contents($file, $content, LOCK_EX);
                chmod($file, 0600);
            });
        } catch (ErrorException $e) {
            throw new MailException('Zrzut wiadomości: ' . $e->getMessage(), 0, $e);
        }
    }
}
