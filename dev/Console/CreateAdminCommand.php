<?php

declare(strict_types=1);

namespace Wezurlop\Dev\Console;

use InvalidArgumentException;
use Wezurlop\Repository\RozdzialRolException;
use Wezurlop\Support\Config;
use Wezurlop\Support\EnvFile;
use Wezurlop\Support\Logger;
use Wezurlop\Support\Services;

/**
 * dev/utworz-administratora.php — konto z hasłem ustawionym od razu, bez linku i bez poczty.
 *
 * ⚠ ODSTĘPSTWO NA CZAS PRZEBIEGU INTEGRACYJNEGO, nie zmiana reguły. U klienta obowiązuje ścieżka
 * z jednorazowym linkiem ustawiającym hasło (CLAUDE.md §15: instalujący nie ma znać hasła klienta) —
 * `bin/invite.php` zostaje nietknięty i będzie sprawdzony przy przebiegu na home.pl.
 * Dlatego to polecenie leży w `dev/` (poza paczką klienta) i odmawia pracy poza APP_ENV=dev.
 *
 * Hasło idzie przez `Security\Password` jak każde inne: polityka, SHA-256 + base64, bcrypt.
 *
 *   dev/utworz-administratora.php --email=krzysiek@help.pl --haslo=… --imie=Krzysztof --nazwisko=Nadzor
 *   dev/utworz-administratora.php --email=… --haslo=… --rola=employee --firma=1
 *   dev/utworz-administratora.php --email=… --haslo=… --baza=<nazwa-bazy>_test   (baza testowa)
 *   dev/utworz-administratora.php --email=… --haslo=… --osoba=92 --rola=      (konto bez dostępu)
 *
 * ⚠ `--osoba` podłącza konto do OSOBY, KTÓRA JUŻ JEST w kartotece, zamiast tworzyć nową —
 * inaczej powstałaby druga osoba bez zatrudnienia i nie dałoby się odtworzyć stanu
 * „pracownik z zatrudnieniem, bez nadanego dostępu". Puste `--rola=` znaczy „żadna rola":
 * ten stan jest potrzebny bramce ekranowej, bo tylko wtedy renderuje się ekran braku dostępu.
 */
final class CreateAdminCommand
{
    private const OPTIONS = ['email', 'haslo', 'imie', 'nazwisko', 'rola', 'firma', 'baza', 'osoba'];

    /**
     * @param list<string> $argv
     * @param resource $out
     */
    public function __construct(private readonly array $argv, private $out, private readonly string $appRoot)
    {
    }

    public function __invoke(Services $services, Logger $logger): int
    {
        if (!$services->config->isDev()) {
            fwrite($this->out, "Polecenie deweloperskie — hasło ustawia się poleceniem wyłącznie przy APP_ENV=dev.\n"
                . "U klienta: bin/invite.php i jednorazowy link ustawiający hasło.\n");

            return 2;
        }
        $options = [];
        foreach (array_slice($this->argv, 1) as $arg) {
            if (preg_match('/^--(' . implode('|', self::OPTIONS) . ')=(.*)$/', $arg, $m) !== 1) {
                fwrite($this->out, "Nieznana opcja: {$arg} (opis w dev/Console/CreateAdminCommand.php)\n");

                return 2;
            }
            $options[$m[1]] = $m[2];
        }
        foreach (['email', 'haslo'] as $required) {
            if (($options[$required] ?? '') === '') {
                fwrite($this->out, "Brak --{$required}\n");

                return 2;
            }
        }
        $email = strtolower(trim($options['email']));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            fwrite($this->out, "Nieprawidłowy adres e-mail\n");

            return 2;
        }
        // ⚠ `--rola=` (puste) znaczy „bez roli", a brak opcji — administrator. Rozróżnienie jest
        // celowe: konto bez żadnego przypisania to osobny, potrzebny stan, a nie literówka.
        $role = $options['rola'] ?? 'tenant_admin';
        $person = filter_var($options['osoba'] ?? '0', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($person === false) {
            fwrite($this->out, "Nieprawidłowe --osoba\n");

            return 2;
        }
        $company = filter_var($options['firma'] ?? '0', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($company === false) {
            fwrite($this->out, "Nieprawidłowe --firma\n");

            return 2;
        }
        if (($options['baza'] ?? '') !== '') {
            if (preg_match('/^[a-z0-9_]{1,64}$/', $options['baza']) !== 1) {
                fwrite($this->out, "Nazwa bazy: małe litery, cyfry i podkreślenia.\n");

                return 2;
            }
            $env = EnvFile::load(EnvFile::path($this->appRoot, []));
            $factory = require $this->appRoot . '/config/config.php';
            $services = new Services(Config::fromArray($factory(['DB_NAME' => $options['baza']] + $env, [], $this->appRoot)));
            fwrite($this->out, "baza: {$options['baza']}\n");
        }
        $policyError = $services->password->policyError($options['haslo']);
        if ($policyError !== null) {
            fwrite($this->out, $policyError . "\n");

            return 2;
        }
        if ($services->accounts->findByEmail($email) !== null) {
            fwrite($this->out, "Konto {$email} już istnieje — hasło zmienia się przez /haslo/reset.\n");

            return 1;
        }

        try {
            $accountId = $services->db->transaction(function () use ($services, $options, $email, $role, $company, $person): int {
                $personId = $person > 0
                    ? $person
                    : $services->people->create(trim($options['imie'] ?? 'Administrator'), trim($options['nazwisko'] ?? 'Tenanta'));
                $accountId = $services->accounts->createInvited($personId, $email);
                $services->accounts->setPassword($accountId, $services->password->hash($options['haslo']));
                if ($role !== '') {
                    $services->permissions->assignRole($accountId, $role, $company, null);
                }

                return $accountId;
            });
        } catch (InvalidArgumentException | RozdzialRolException $e) {
            // ⚠ `RozdzialRolException` łapiemy TAK SAMO jak złą rolę: narzędzie sprawdza rozdział
            // „administrator tenanta ≠ pracownik" nie własnym warunkiem, a tym w `assignRole()`
            // (`etap3` §8c reguła 5 — „to samo sprawdzenie, w tym samym miejscu"). Komunikat
            // musi być czytelny, bo to jedyna droga, którą ktoś puszcza z konsoli.
            fwrite($this->out, 'Odmowa: ' . $e->getMessage() . "\n");

            return 1;
        }
        // ⚠ Hasła nie ma ani w logu, ani w zdarzeniu (CLAUDE.md §14) — tylko fakt założenia konta.
        $services->events->record('account.created_by_cli', null, null, 'narzedzie', 'account', $accountId, $company > 0 ? $company : null,
            ['rola' => $role]);
        $logger->info('Narzędzie: konto administratora utworzone', ['account_id' => $accountId, 'rola' => $role]);
        fwrite($this->out, sprintf("konto #%d — %s, osoba %s, rola %s, firma %d, status active\n",
            $accountId, $email, $person > 0 ? (string) $person : 'nowa', $role === '' ? 'brak' : $role, $company));

        return 0;
    }
}
