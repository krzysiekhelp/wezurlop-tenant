<?php

declare(strict_types=1);

namespace Wezurlop\Console;

use InvalidArgumentException;
use Wezurlop\Support\Logger;
use Wezurlop\Support\Services;

/**
 * bin/invite.php — zaproszenie nowej osoby z wiersza poleceń (administracja, M8: pierwszy administrator).
 *
 *   bin/invite.php --email=adres --imie=Anna --nazwisko=Nowak
 *
 * ⚠ Konto jest ZAWSZE administratorem tenanta. `--rola` i `--firma` usunięte w etapie 4:
 * osoba dodana tą ścieżką nie ma zatrudnienia, a dostęp pracowniczy wymaga aktywnego etatu
 * w spółce, więc `--rola=employee` tworzyło stan odrzucany przez interfejs.
 * Pracowników zaprasza się z listy `/admin/konta` — oni przychodzą z R2.
 *
 * Link wychodzi e-mailem przez kolejkę (bin/run-jobs.php); skrypt go nie wyświetla.
 */
final class InviteCommand
{
    /**
     * @param list<string> $argv
     * @param resource $out
     */
    public function __construct(private readonly array $argv, private $out)
    {
    }

    public function __invoke(Services $services, Logger $logger): int
    {
        $options = [];
        foreach (array_slice($this->argv, 1) as $arg) {
            if (preg_match('/^--(email|imie|nazwisko)=(.*)$/u', $arg, $m) !== 1) {
                // ⚠ `--rola` i `--firma` ZNIKNĘŁY, i to nie jest uproszczenie narzędzia.
                // Ta ścieżka tworzy osobę, której NIE MA w programie kadrowym, więc nie ma ona
                // żadnego zatrudnienia — a dostęp pracowniczy wolno nadać wyłącznie do spółki
                // z aktywnym etatem. `--rola=employee` tworzyło zatem dokładnie ten stan,
                // który interfejs odrzuca: konto, które loguje się i widzi odmowę.
                fwrite($this->out, "Użycie: bin/invite.php --email=... --imie=... --nazwisko=...\n"
                    . "Konto jest zawsze administratorem tenanta (osoba spoza kadr nie ma etatu,\n"
                    . "a dostęp pracowniczy wymaga etatu w spółce). Pracownicy przychodzą z R2\n"
                    . "i zaprasza się ich z listy /admin/konta.\n");

                return 2;
            }
            $options[$m[1]] = trim($m[2]);
        }
        foreach (['email', 'imie', 'nazwisko'] as $required) {
            if (($options[$required] ?? '') === '') {
                fwrite($this->out, "Brak --{$required}\n");

                return 2;
            }
        }

        try {
            $accountId = $services->invitations->invite(
                $options['imie'],
                $options['nazwisko'],
                $options['email'],
                null,
            );
        } catch (InvalidArgumentException $e) {
            fwrite($this->out, $e->getMessage() . "\n");

            return 1;
        }
        fwrite($this->out, "Zaproszenie w kolejce (konto #{$accountId}). Wiadomość wyśle bin/run-jobs.php.\n");

        return 0;
    }
}
