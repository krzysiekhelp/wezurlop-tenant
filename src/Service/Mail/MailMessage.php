<?php

declare(strict_types=1);

namespace Wezurlop\Service\Mail;

/**
 * Wiadomość do wysłania. Treść może zawierać jednorazowy link — nie trafia do logów (§9, §14).
 *
 * ⚠ `body` to **wersja tekstowa i ona jest obowiązkowa**, `html` jest dodatkiem. Kolejność nie
 * jest przypadkowa: wiadomość wyłącznie HTML-owa zbiera punkty spamowe i nie otwiera się
 * w czytniku tekstowym, a spam jest przy tej funkcji naszym głównym ryzykiem. Obie wersje
 * powstają z **jednego zestawu zmiennych** (`MailRenderer`), więc nie da się poprawić jednej
 * i zapomnieć o drugiej.
 */
final class MailMessage
{
    public function __construct(
        public readonly string $to,
        public readonly string $subject,
        #[\SensitiveParameter] public readonly string $body,
        #[\SensitiveParameter] public readonly ?string $html = null,
    ) {
    }
}
