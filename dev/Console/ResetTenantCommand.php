<?php

declare(strict_types=1);

namespace Wezurlop\Dev\Console;

use Wezurlop\Repository\EventLogRepository;
use Wezurlop\Service\Maintenance\TenantReset;
use Wezurlop\Support\Config;
use Wezurlop\Support\EnvFile;
use Wezurlop\Support\Logger;
use Wezurlop\Support\Services;

/**
 * Reset instalacji z wiersza poleceń — jedno z dwóch wejść do `Service\Maintenance\TenantReset`
 * (drugie to przycisk na `/diagnostyka`). Logika kasowania jest w serwisie, tutaj tylko warunki
 * i wypisanie wyniku.
 *
 * ⚠ Odmawia pracy poza `APP_ENV=dev` i bez `--tak-wiem-co-robie`. Plik leży w `dev/`, więc nie
 * wchodzi do paczki klienta (dev/package-allowlist.txt go nie przepuszcza).
 *
 *   dev/reset-tenanta.php --tak-wiem-co-robie
 *   dev/reset-tenanta.php --tak-wiem-co-robie --baza=<nazwa-bazy>_test
 */
final class ResetTenantCommand
{
    private const FLAG = '--tak-wiem-co-robie';

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
            fwrite($this->out, "Reset instalacji działa wyłącznie przy APP_ENV=dev.\n");

            return 2;
        }
        if (!in_array(self::FLAG, array_slice($this->argv, 1), true)) {
            fwrite($this->out, "Reset kasuje WSZYSTKIE dane instalacji (firmy, osoby, nieobecności, klucze API,\n"
                . "sesje, dziennik zdarzeń). Zostaje schemat, uprawnienia, ustawienia domyślne i konta administratorów.\n"
                . 'Czyszczone tabele: ' . implode(', ', TenantReset::tables()) . "\n"
                . 'Jeśli naprawdę tego chcesz: dev/reset-tenanta.php ' . self::FLAG . "\n");

            return 2;
        }
        foreach (array_slice($this->argv, 1) as $arg) {
            if (preg_match('/^--baza=([a-z0-9_]{1,64})$/', $arg, $m) === 1) {
                $env = EnvFile::load(EnvFile::path($this->appRoot, []));
                $factory = require $this->appRoot . '/config/config.php';
                $services = new Services(Config::fromArray($factory(['DB_NAME' => $m[1]] + $env, [], $this->appRoot)));
                fwrite($this->out, "baza: {$m[1]}\n");
            }
        }
        $reset = new TenantReset($services->db, new EventLogRepository($services->db), $this->appRoot);
        $result = $reset->run(null, 'narzedzie');
        foreach ($result['tabele'] as $table => $count) {
            if ($count > 0) {
                fwrite($this->out, sprintf("  %-26s %6d\n", $table, $count));
            }
        }
        fwrite($this->out, sprintf("Reset wykonany w %.1f ms. Instalacja jest w stanie jak po instalacji.\n", $result['czasMs']));

        return 0;
    }
}
