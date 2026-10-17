<?php

declare(strict_types=1);

namespace Wezurlop\Dev\Console;

use InvalidArgumentException;
use Wezurlop\Support\Logger;
use Wezurlop\Support\Services;

/**
 * dev/przygotuj-wniosek.php — wniosek do przebiegu integracyjnego, dla zatrudnienia, które connector
 * już przysłał. Ekranu składania wniosku jeszcze nie ma (tura B), a bez zaakceptowanego wniosku nie
 * powstanie żadne zadanie i kierunek aplikacja → R2 zostałby niesprawdzony.
 *
 * ⚠ Narzędzie NIE pisze do tabel. Woła `Service\Leave\LeaveRequestService` — tę samą ścieżkę, którą
 * pójdzie interfejs: walidacja rodzaju i terminu, dni robocze z kalendarza firmy, kolizje z innymi
 * wnioskami i z nieobecnościami z kadr, saldo pod blokadą wiersza, wpisy w historii decyzji i w event_log.
 * Poza paczką klienta (dev/package-allowlist.txt).
 *
 *   dev/przygotuj-wniosek.php --zatrudnienie=207 --rodzaj=urlop:0 --od=2026-10-29 --do=2026-11-03
 *   dev/przygotuj-wniosek.php --zatrudnienie=311 --rodzaj=urlop:5 --od=2026-11-09 --do=2026-11-09 --stan=submitted
 *   dev/przygotuj-wniosek.php --wniosek=12 --anuluj        (T11: anulowanie wniosku już zapisanego w kadrach)
 *
 * ⚠ Anulowanie wniosku, który nie ma wpisu w kadrach ani wydanego zapisu, zamyka go lokalnie
 * (`cancelled`) — bez zadania `usuniecie` (rozstrzygnięcie 20.09.2026).
 *
 * Opcje: --stan=approved (domyślnie) | submitted, --anuluj (zatwierdzone anulowanie), --konto=ID
 * (decydent w historii decyzji; domyślnie „narzedzie”), --wniosek=ID (nie zakłada nowego wniosku,
 * tylko anuluje wskazany — tak wygląda T11 po udanym zapisie w R2).
 */
final class PrepareRequestCommand
{
    private const OPTIONS = ['zatrudnienie', 'rodzaj', 'od', 'do', 'stan', 'konto', 'anuluj', 'wniosek'];

    /**
     * @param list<string> $argv
     * @param resource $out
     */
    public function __construct(private readonly array $argv, private $out)
    {
    }

    public function __invoke(Services $services, Logger $logger): int
    {
        if (!$services->config->isDev()) {
            fwrite($this->out, "Narzędzie deweloperskie — działa wyłącznie przy APP_ENV=dev.\n");

            return 2;
        }
        $options = [];
        foreach (array_slice($this->argv, 1) as $arg) {
            if (preg_match('/^--(' . implode('|', self::OPTIONS) . ')(?:=(.*))?$/', $arg, $m) !== 1) {
                fwrite($this->out, "Nieznana opcja: {$arg} (opis w dev/Console/PrepareRequestCommand.php)\n");

                return 2;
            }
            $options[$m[1]] = $m[2] ?? '1';
        }
        $existing = isset($options['wniosek']) ? filter_var($options['wniosek'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : null;
        if ($existing === false) {
            fwrite($this->out, "--wniosek musi być liczbą\n");

            return 2;
        }
        if ($existing !== null && !isset($options['anuluj'])) {
            fwrite($this->out, "--wniosek działa tylko razem z --anuluj\n");

            return 2;
        }
        if ($existing === null) {
            foreach (['zatrudnienie', 'rodzaj', 'od', 'do'] as $required) {
                if (($options[$required] ?? '') === '') {
                    fwrite($this->out, "Brak --{$required}\n");

                    return 2;
                }
            }
        }
        $state = $options['stan'] ?? 'approved';
        if (!in_array($state, ['approved', 'submitted'], true)) {
            fwrite($this->out, "--stan przyjmuje approved albo submitted\n");

            return 2;
        }
        $account = isset($options['konto']) ? filter_var($options['konto'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : null;
        if ($account === false) {
            fwrite($this->out, "--konto musi być liczbą\n");

            return 2;
        }

        $leave = $services->leaveRequests();
        try {
            if ($existing !== null) {
                $leave->requestCancel($existing, $account, 'przygotowanie przebiegu integracyjnego (T11)');
                $state = $leave->approveCancel($existing, $account, 'przygotowanie przebiegu integracyjnego (T11)');
                fwrite($this->out, "wniosek #{$existing} — anulowanie zatwierdzone, stan {$state}"
                    . ($state === 'cancelled' ? " (nic nie było w kadrach → zamknięty lokalnie, bez zadania)\n" : " (connector dostanie usuniecie)\n"));
                $logger->info('Narzędzie: anulowano wniosek', ['id' => $existing]);

                return 0;
            }
            $request = $leave->submit($options['zatrudnienie'], $options['rodzaj'], $options['od'], $options['do'], $account);
            $steps = ['submitted'];
            if ($state === 'approved' || isset($options['anuluj'])) {
                $leave->approve($request['id'], $account, 'przygotowanie przebiegu integracyjnego');
                $steps[] = 'approved';
            }
            if (isset($options['anuluj'])) {
                $leave->requestCancel($request['id'], $account, 'przygotowanie przebiegu integracyjnego (T11)');
                $steps[] = 'cancel_requested';
                $steps[] = $leave->approveCancel($request['id'], $account, 'przygotowanie przebiegu integracyjnego (T11)') === 'cancelled'
                    ? 'cancelled (bez wpisu w kadrach → zamknięty lokalnie)'
                    : 'anulowanie zatwierdzone (connector dostanie usuniecie)';
            }
        } catch (InvalidArgumentException $e) {
            // Odmowa serwisu to poprawny wynik narzędzia: tak samo odmówiłby interfejs.
            fwrite($this->out, 'Odmowa: ' . $e->getMessage() . "\n");

            return 1;
        }
        fwrite($this->out, sprintf(
            "wniosek #%d — %s, zatrudnienie %s (id %d), %s … %s, dni %d, minuty %d\nstan: %s\n",
            $request['id'], $request['typ'], $options['zatrudnienie'], $request['zatrudnienie'],
            $options['od'], $options['do'], $request['dni'], $request['minuty'], implode(' → ', $steps),
        ));
        $logger->info('Narzędzie: przygotowano wniosek', ['id' => $request['id'], 'stan' => end($steps)]);

        return 0;
    }
}
