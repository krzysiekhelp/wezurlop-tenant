<?php

declare(strict_types=1);

namespace Wezurlop\Service\Mail;

/**
 * Transport, który po udanej wysyłce stawia znacznik „poczta naprawdę wyszła" (etap 7.2).
 *
 * ⚠ Dwie różne rzeczy, których diagnostyka nie może mieszać: **czy poczta jest skonfigurowana**
 * i **kiedy ostatnio coś wyszło**. Ustawienia bywają poprawne, a skrzynka i tak odrzuca —
 * limit dostawcy, zmienione hasło, blokada portu wychodzącego. Bez tego znacznika ekran
 * mówiłby „skonfigurowana" o instalacji, z której od tygodnia nic nie wychodzi.
 *
 * ⚠ Znacznik stawia **wyłącznie wysyłka SMTP**. Zrzut do pliku na dev udaje się zawsze, więc
 * „ostatnia udana wysyłka" po zapisie do pliku byłaby dokładnie tym rodzajem nieprawdy,
 * którą naprawiamy w tym etapie.
 */
final class TransportZeZnacznikiem implements MailTransport
{
    public function __construct(
        private readonly MailTransport $transport,
        private readonly MailSettings $ustawienia,
    ) {
    }

    public function send(MailMessage $message): void
    {
        $this->transport->send($message);
        $this->ustawienia->zapiszUdanaWysylke();
    }
}
