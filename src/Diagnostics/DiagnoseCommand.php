<?php

declare(strict_types=1);

namespace Wezurlop\Diagnostics;

use InvalidArgumentException;
use RuntimeException;
use Wezurlop\Support\EnvFile;

/**
 * bin/diagnose.php — diagnostyka środowiska w CLI, wynik tekstem.
 *
 * Opcje:
 *   --app-url=URL      adres aplikacji (domyślnie APP_URL z .env)
 *   --base-path=PATH   prefiks public/ w adresie (domyślnie APP_BASE_PATH z .env)
 *   --resolve=IP       połącz się z tym adresem IP zamiast pytać DNS (np. 127.0.0.1)
 *   --no-http          pomiń test pobierania plików przez HTTP
 *
 * Kod wyjścia: 0 — brak błędów blokujących, 1 — są błędy, 2 — złe wywołanie.
 */
final class DiagnoseCommand
{
    private const STATUS_LABELS = [
        CheckResult::OK   => 'OK',
        CheckResult::WARN => 'UWAGA',
        CheckResult::FAIL => 'BŁĄD',
    ];

    public function __construct(private readonly string $appRoot)
    {
    }

    /**
     * @param list<string> $argv
     * @param resource $out
     */
    public function run(array $argv, $out): int
    {
        $options = $this->parseOptions(array_slice($argv, 1));
        if ($options === null) {
            fwrite($out, "Użycie: bin/diagnose.php [--app-url=URL] [--base-path=PATH] [--resolve=IP] [--no-http]\n");
            return 2;
        }

        $envFile = EnvFile::path($this->appRoot, []);
        try {
            $env = EnvFile::load($envFile);
        } catch (RuntimeException $e) {
            fwrite($out, $e->getMessage() . ": {$envFile}\n");
            $env = [];
        }

        $http = null;
        if (!isset($options['no-http'])) {
            $appUrl = $options['app-url'] ?? ($env['APP_URL'] ?? '');
            $basePath = $options['base-path'] ?? ($env['APP_BASE_PATH'] ?? '');
            try {
                $http = $appUrl === '' ? null : new HttpProbeTarget($appUrl, $basePath, $options['resolve'] ?? null);
            } catch (InvalidArgumentException $e) {
                fwrite($out, $e->getMessage() . "\n");
                return 2;
            }
        }

        $check = new EnvironmentCheck(
            $this->appRoot,
            $this->configuredPath($env, 'STORAGE_PATH', 'storage'),
            $this->configuredPath($env, 'VAR_PATH', 'var'),
            $env,
            true,
            $http,
            null,
            null,
            $envFile,
        );
        $results = $check->run();

        $counts = [CheckResult::OK => 0, CheckResult::WARN => 0, CheckResult::FAIL => 0];
        $group = null;
        foreach ($results as $result) {
            if ($result->group !== $group) {
                $group = $result->group;
                fwrite($out, "\n== {$group}\n");
            }
            $counts[$result->status]++;
            fwrite($out, '  ' . $this->pad(self::STATUS_LABELS[$result->status], 7)
                . $this->pad($result->name, 33) . $result->value
                . ($result->note !== '' ? '  — ' . $result->note : '') . "\n");
        }
        if ($http !== null) {
            fwrite($out, "\nTest HTTP: {$http->origin}{$http->basePath}"
                . ($http->resolveIp !== null ? " (połączenie z {$http->resolveIp})" : '') . "\n");
        }
        fwrite($out, sprintf(
            "\nOK: %d  ostrzeżenia: %d  błędy: %d\n",
            $counts[CheckResult::OK],
            $counts[CheckResult::WARN],
            $counts[CheckResult::FAIL],
        ));

        return $counts[CheckResult::FAIL] > 0 ? 1 : 0;
    }

    /** Dopełnienie liczone w znakach, nie w bajtach (mb_str_pad jest dopiero w PHP 8.3). */
    private function pad(string $text, int $width): string
    {
        return $text . str_repeat(' ', max(1, $width - mb_strlen($text)));
    }

    /**
     * @param list<string> $args
     * @return array<string, string>|null
     */
    private function parseOptions(array $args): ?array
    {
        $options = [];
        foreach ($args as $arg) {
            if ($arg === '--no-http') {
                $options['no-http'] = '1';
                continue;
            }
            if (preg_match('/^--(app-url|base-path|resolve)=(.*)$/', $arg, $m) !== 1) {
                return null;
            }
            $options[$m[1]] = $m[2];
        }

        return $options;
    }

    /** @param array<string, string> $env */
    private function configuredPath(array $env, string $key, string $default): string
    {
        $value = $env[$key] ?? '';

        return $value !== '' ? rtrim($value, '/') : $this->appRoot . '/' . $default;
    }
}
