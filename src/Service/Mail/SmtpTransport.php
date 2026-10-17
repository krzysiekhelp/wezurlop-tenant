<?php

declare(strict_types=1);

namespace Wezurlop\Service\Mail;

use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * SMTP przez PHPMailer. Weryfikacja certyfikatu serwera poczty pozostaje włączona (CLAUDE.md §13);
 * krótki limit czasu, żeby zamulony serwer nie zjadał całego przebiegu crona.
 */
final class SmtpTransport implements MailTransport
{
    private const TIMEOUT_SECONDS = 10;

    /** @param array{host: string, port: int, username: string, password: string, encryption: string, from: string, from_name: string, hostname?: string} $config */
    public function __construct(#[\SensitiveParameter] private readonly array $config)
    {
    }

    public function send(MailMessage $message): void
    {
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = $this->config['host'];
            $mail->Port = $this->config['port'];
            $mail->Timeout = self::TIMEOUT_SECONDS;
            $mail->SMTPAutoTLS = $this->config['encryption'] !== 'none';
            $mail->SMTPSecure = match ($this->config['encryption']) {
                'ssl'   => PHPMailer::ENCRYPTION_SMTPS,
                'tls'   => PHPMailer::ENCRYPTION_STARTTLS,
                default => '',
            };
            if ($this->config['username'] !== '') {
                $mail->SMTPAuth = true;
                $mail->Username = $this->config['username'];
                $mail->Password = $this->config['password'];
            }
            $mail->CharSet = PHPMailer::CHARSET_UTF8;
            $mail->XMailer = ' ';
            // ⚠ `Hostname` z adresu instalacji: bez tego PHPMailer bierze nazwę maszyny
            // i wystawia `Message-ID: <…@wezurlop>` — prawa strona nie jest nazwą domenową.
            // Zmierzone na doręczonej wiadomości 22.09.2026 (docs/wymagania-hostingu.md §1a).
            if (($this->config['hostname'] ?? '') !== '') {
                $mail->Hostname = $this->config['hostname'];
            }
            // ⚠ Pusta nazwa nadawcy znaczy „wyślij bez nazwy wyświetlanej" — nazwa wymyślona
            // przez nas („wezurlop") u pracownika klienta wygląda jak phishing.
            $mail->setFrom($this->config['from'], $this->config['from_name']);
            $mail->addAddress($message->to);
            $mail->Subject = $message->subject;
            // ⚠ Gdy jest HTML, wysyłamy DWIE części: HTML i tekst jako `AltBody`. Wiadomość
            // wyłącznie HTML-owa zbiera punkty spamowe, a klient tekstowy nie pokaże nic.
            // Gdy HTML-a nie ma (alerty techniczne), leci sam tekst — bez udawania HTML-a.
            if ($message->html !== null) {
                $mail->isHTML(true);
                $mail->Body = $message->html;
                $mail->AltBody = $message->body;
            } else {
                $mail->isHTML(false);
                $mail->Body = $message->body;
            }
            $mail->send();
        } catch (PHPMailerException $e) {
            // Komunikat PHPMailera nie zawiera treści wiadomości ani hasła SMTP.
            throw new MailException('SMTP: ' . $mail->ErrorInfo, 0, $e);
        }
    }
}
