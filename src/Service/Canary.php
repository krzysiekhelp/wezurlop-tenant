<?php

declare(strict_types=1);

namespace Wezurlop\Service;

use ErrorException;
use InvalidArgumentException;
use RuntimeException;
use Wezurlop\Diagnostics\ExposureProbe;
use Wezurlop\Diagnostics\HttpProbeTarget;
use Wezurlop\Repository\EventLogRepository;
use Wezurlop\Repository\JobRepository;
use Wezurlop\Repository\TenantSettingRepository;
use Wezurlop\Security\RateLimiter;
use Wezurlop\Support\ErrorGuard;

/**
 * Plik-kanarek (docs/profile-hostingu.md, „Ciągła weryfikacja”): ochrona potwierdzona przy instalacji
 * może przestać działać bez naszego udziału (zmiana typu serwera, nadpisany .htaccess).
 *
 * `storage/canary-<losowe>.txt` — ⚠ BEZ kropki na początku nazwy: plik z kropką bywa odmawiany przez
 * serwer albo przez naszą regułę o kropce, więc mierzyłby tę regułę zamiast dostępności katalogu.
 * Raz na dobę zadanie pobiera go po HTTP pod APP_URL tymi samymi adresami co krok 7 instalatora.
 * Treść w odpowiedzi = alert na skrzynkę alertową i wpis w event_log.
 */
final class Canary
{
    public const JOB_CHECK = 'maintenance.canary_check';
    public const CHECK_EVERY = 86400;

    private const KEY_FILE = 'canary_file';
    private const KEY_TOKEN = 'canary_token';
    private const KEY_RESULT = 'canary_last_result';
    private const KEY_AT = 'canary_last_at';

    public function __construct(
        private readonly TenantSettingRepository $settings,
        private readonly EventLogRepository $events,
        private readonly JobRepository $jobs,
        private readonly string $storagePath,
        private readonly string $appUrl,
        private readonly string $basePath,
        private readonly ?string $selfAddr,
    ) {
    }

    /** Tworzy kanarka, jeśli go nie ma (instalator, a zapasowo pierwsze zadanie). */
    public function ensure(): string
    {
        $name = $this->settings->get(self::KEY_FILE);
        $token = $this->settings->get(self::KEY_TOKEN);
        if ($name !== '' && $token !== '' && is_file($this->storagePath . '/' . $name)) {
            return $name;
        }
        $name = 'canary-' . bin2hex(random_bytes(12)) . '.txt';
        $token = 'wezurlop-canary-' . bin2hex(random_bytes(16));
        $file = $this->storagePath . '/' . $name;
        try {
            ErrorGuard::run(static function () use ($file, $token): void {
                file_put_contents($file, $token . "\n", LOCK_EX);
                // Czytelny dla serwera WWW — inaczej odsłonięty katalog dałby 403 i fałszywe „chronione”.
                chmod($file, 0644);
            });
        } catch (ErrorException $e) {
            throw new RuntimeException('Nie można utworzyć pliku-kanarka w storage/', 0, $e);
        }
        $this->settings->set(self::KEY_FILE, $name, null);
        $this->settings->set(self::KEY_TOKEN, $token, null);
        // Poprzednie kanarki (np. po odtworzeniu bazy) — jeden plik naraz, bez osieroconych.
        foreach (glob($this->storagePath . '/canary-*.txt') ?: [] as $old) {
            if (basename($old) !== $name && is_writable($this->storagePath)) {
                unlink($old);
            }
        }

        return $name;
    }

    /** @return array{result: string, detail: string} */
    public function check(): array
    {
        $name = $this->ensure();
        $token = $this->settings->get(self::KEY_TOKEN);
        try {
            $target = new HttpProbeTarget($this->appUrl, $this->basePath, $this->selfAddr);
        } catch (InvalidArgumentException) {
            return $this->record(ExposureProbe::UNRESOLVED, 'brak poprawnego APP_URL');
        }
        $probe = (new ExposureProbe($target))->check('storage/' . $name, [$token]);
        $detail = $probe['result'] === ExposureProbe::EXPOSED
            ? implode('; ', $probe['leaks'])
            : ($probe['codes'] === [] ? 'brak odpowiedzi' : 'HTTP ' . implode(', ', $probe['codes']));

        return $this->record($probe['result'], $detail);
    }

    /** @return array{result: string, at: string} ostatni wynik — do strony /diagnostyka */
    public function lastResult(): array
    {
        return ['result' => $this->settings->get(self::KEY_RESULT), 'at' => $this->settings->get(self::KEY_AT)];
    }

    /** @return array{result: string, detail: string} */
    private function record(string $result, string $detail): array
    {
        $this->settings->set(self::KEY_RESULT, $result, null);
        $this->settings->set(self::KEY_AT, gmdate('Y-m-d H:i:s'), null);
        if ($result === ExposureProbe::EXPOSED) {
            $this->events->record('security.storage_exposed', null, null, 'system', null, null, null, ['detail' => $detail]);
            $this->jobs->enqueue(RateLimiter::JOB_SECURITY_ALERT, ['kind' => 'storage_exposed']);
        } elseif ($result === ExposureProbe::UNRESOLVED) {
            // Brak odpowiedzi to nie „chronione” — np. ktoś zablokował APP_URL sam przed sobą.
            $this->events->record('security.canary_unresolved', null, null, 'system', null, null, null, ['detail' => $detail]);
        }

        return ['result' => $result, 'detail' => $detail];
    }
}
