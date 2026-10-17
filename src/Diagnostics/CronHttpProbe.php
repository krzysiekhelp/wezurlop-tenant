<?php

declare(strict_types=1);

namespace Wezurlop\Diagnostics;

/**
 * Cron przez HTTP (cron-05min.php w katalogu domeny) sprawdzany WYNIKIEM po HTTP, nie obecnością pliku:
 * dwa wywołania pod rząd — drugie ma nic nie zrobić (429 z Retry-After: lock/odstęp), oba bez treści.
 * Pierwsze wywołanie może wykonać prawdziwy przebieg kolejki — to zamierzone (to jest jego zadanie).
 */
final class CronHttpProbe
{
    public const FILE = '/cron-05min.php';

    public function __construct(private readonly HttpProbeTarget $target)
    {
    }

    /** @return array{status: string, value: string, note: string} status: CheckResult::OK|WARN|FAIL */
    public function check(): array
    {
        $first = $this->get();
        $second = $this->get();
        if ($first === null || $second === null) {
            return ['status' => CheckResult::WARN, 'value' => 'nierozstrzygnięte', 'note' => 'serwer nie odpowiedział pod APP_URL' . self::FILE];
        }
        if ($first['code'] === 404) {
            return ['status' => CheckResult::FAIL, 'value' => 'brak pliku', 'note' => self::FILE . ' nie odpowiada (404) — hosting nie uruchomi kolejki'];
        }
        $silent = $first['body'] === '' && $second['body'] === '';
        $guarded = $second['code'] === 429 && preg_match('/^\d+$/', $second['retry']) === 1;
        if (!in_array($first['code'], [204, 429], true) || !$guarded || !$silent) {
            return ['status' => CheckResult::FAIL, 'value' => 'bez ochrony',
                'note' => sprintf('kody %d i %d%s — drugie wywołanie musi zwrócić 429 z Retry-After, oba bez treści',
                    $first['code'], $second['code'], $silent ? '' : ', odpowiedź z treścią')];
        }

        return ['status' => CheckResult::OK, 'value' => $first['code'] === 204 ? 'działa' : 'działa (przebieg przed chwilą)',
            'note' => 'drugie wywołanie pod rząd niczego nie zrobiło (429, Retry-After ' . $second['retry'] . ' s)'];
    }

    /** @return array{code: int, body: string, retry: string}|null */
    private function get(): ?array
    {
        $retry = '';
        $ch = curl_init($this->target->origin . self::FILE);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT        => 28,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_RESOLVE        => $this->target->curlResolve(),
            CURLOPT_USERAGENT      => 'wezurlop-diag',
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$retry): int {
                if (stripos($line, 'Retry-After:') === 0) {
                    $retry = trim(substr($line, 12));
                }

                return strlen($line);
            },
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        return is_string($body) ? ['code' => $code, 'body' => $body, 'retry' => $retry] : null;
    }
}
