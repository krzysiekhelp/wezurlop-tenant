<?php

declare(strict_types=1);

namespace Wezurlop\Service\Install;

use ErrorException;
use RuntimeException;
use Wezurlop\Support\ErrorGuard;

/**
 * Stan instalatora (docs/szkielet-tenanta.md rozdz. 12), NIE w sesji PHP.
 *
 * Dwie fazy:
 * - PRZED tokenem stan (krok, wykryte prefiksy, CSRF) jedzie w ciasteczku podpisanym HMAC
 *   losowym sekretem instalacji. Na serwerze nie powstaje stan przebiegu i nikt nikogo nie
 *   blokuje — jedynym ograniczeniem jest limit prób tokenu liczony na adres.
 * - PO tokenie stan (z poświadczeniami do bazy) trafia do var/install-state.json (600),
 *   przeglądarka dostaje tylko losowy klucz. Dopiero wtedy obowiązuje „jeden przebieg naraz”
 *   (2 godziny bezczynności).
 */
final class InstallStateStore
{
    public const COOKIE = 'wezurlop_install';
    public const COOKIE_PRE = 'wezurlop_install_pre';

    private const PRE_TTL = 900;
    private const AUTHORIZED_TTL = 7200;
    private const TOKEN_ATTEMPTS = 5;
    private const TOKEN_WINDOW = 900;
    private const MAX_TRACKED_ADDRESSES = 500;

    private readonly string $stateFile;
    private readonly string $secretFile;
    private readonly string $attemptsFile;

    public function __construct(string $varPath)
    {
        $this->stateFile = $varPath . '/install-state.json';
        $this->secretFile = $varPath . '/install-secret';
        $this->attemptsFile = $varPath . '/install-attempts.json';
    }

    // --- faza przed tokenem -------------------------------------------------------------------

    /** @return array<string, mixed> */
    public function newPre(): array
    {
        return [
            'csrf'       => bin2hex(random_bytes(32)),
            'created_at' => time(),
            'step'       => 1,
            'authorized' => false,
        ];
    }

    /** @return array<string, mixed>|null stan z podpisanego ciasteczka, jeśli podpis i termin się zgadzają */
    public function loadPre(mixed $cookie): ?array
    {
        if (!is_string($cookie) || strlen($cookie) > 3000 || !str_contains($cookie, '.')) {
            return null;
        }
        [$body, $mac] = explode('.', $cookie, 2);
        if (!hash_equals(hash_hmac('sha256', $body, $this->secret()), $mac)) {
            return null;
        }
        $state = json_decode((string) base64_decode(strtr($body, '-_', '+/'), true), true);
        if (!is_array($state) || ($state['authorized'] ?? null) !== false || !is_int($state['created_at'] ?? null)
            || time() - $state['created_at'] > self::PRE_TTL) {
            return null;
        }

        return $state;
    }

    /** @param array<string, mixed> $state */
    public function encodePre(array $state): string
    {
        $body = rtrim(strtr(base64_encode(json_encode($state, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

        return $body . '.' . hash_hmac('sha256', $body, $this->secret());
    }

    public function tokenAttemptsExceeded(string $ip): bool
    {
        return count($this->attempts()[$this->ipKey($ip)] ?? []) >= self::TOKEN_ATTEMPTS;
    }

    public function recordTokenFailure(string $ip): void
    {
        $key = $this->ipKey($ip);
        $this->withAttempts(static function (array $all) use ($key): array {
            $all[$key][] = time();
            if (count($all) > self::MAX_TRACKED_ADDRESSES) {
                // Plik nie rośnie bez końca; najdawniej aktywne adresy wypadają pierwsze.
                uasort($all, static fn (array $a, array $b): int => max($a) <=> max($b));
                $all = array_slice($all, -self::MAX_TRACKED_ADDRESSES, null, true);
            }

            return $all;
        });
    }

    // --- faza po tokenie ----------------------------------------------------------------------

    /** @return array<string, mixed>|null */
    public function loadAuthorized(mixed $key): ?array
    {
        $state = $this->read();
        if ($state === null || !is_string($key) || !is_string($state['key_hash'] ?? null)
            || !hash_equals($state['key_hash'], hash('sha256', $key))) {
            return null;
        }

        return $this->isExpired($state) ? null : $state;
    }

    /** Czy trwa inny przebieg po tokenie — zająć instalator może tylko posiadacz tokenu. */
    public function busyForOthers(): bool
    {
        $state = $this->read();

        return $state !== null && !$this->isExpired($state);
    }

    /**
     * Stan przed tokenem → plik stanu po tokenie.
     *
     * @param array<string, mixed> $state
     * @return array{0: string, 1: array<string, mixed>} klucz do ciasteczka i nowy stan
     */
    public function promote(array $state): array
    {
        $key = bin2hex(random_bytes(32));
        $state['key_hash'] = hash('sha256', $key);
        $state['csrf'] = bin2hex(random_bytes(32));
        $state['authorized'] = true;
        $this->save($state);

        return [$key, $state];
    }

    /** @param array<string, mixed> $state */
    public function save(array $state): void
    {
        $state['updated_at'] = time();
        $this->writePrivate($this->stateFile, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /** Po instalacji: stan, sekret podpisu i liczniki prób nie są już potrzebne. */
    public function delete(): void
    {
        foreach ([$this->stateFile, $this->secretFile, $this->attemptsFile] as $file) {
            if (is_file($file)) {
                try {
                    ErrorGuard::run(static fn (): bool => unlink($file));
                } catch (ErrorException $e) {
                    throw new RuntimeException('Nie można usunąć plików stanu instalatora', 0, $e);
                }
            }
        }
    }

    // --- pomocnicze ---------------------------------------------------------------------------

    private function secret(): string
    {
        $secret = $this->readFile($this->secretFile);
        if ($secret !== null && strlen($secret) === 64) {
            return $secret;
        }
        $new = bin2hex(random_bytes(32));
        $file = $this->secretFile;
        try {
            // Tryb „x”: przy dwóch równoległych żądaniach wygrywa pierwszy zapis, drugi go czyta.
            ErrorGuard::run(static function () use ($file, $new): void {
                $old = umask(0077);
                try {
                    $handle = fopen($file, 'x');
                    fwrite($handle, $new);
                    fclose($handle);
                } finally {
                    umask($old);
                }
            });

            return $new;
        } catch (ErrorException) {
            $secret = $this->readFile($this->secretFile);
            if ($secret === null || strlen($secret) !== 64) {
                throw new RuntimeException('Nie można utworzyć sekretu instalatora w katalogu var/');
            }

            return $secret;
        }
    }

    private function ipKey(string $ip): string
    {
        return hash_hmac('sha256', $ip, $this->secret());
    }

    /** @return array<string, list<int>> próby z ostatnich 15 minut */
    private function attempts(): array
    {
        $raw = $this->readFile($this->attemptsFile);

        return $this->fresh($raw !== null ? json_decode($raw, true) : null);
    }

    /** @return array<string, list<int>> */
    private function fresh(mixed $all): array
    {
        if (!is_array($all)) {
            return [];
        }
        $cutoff = time() - self::TOKEN_WINDOW;
        $fresh = [];
        foreach ($all as $key => $times) {
            $recent = is_array($times) ? array_values(array_filter($times, static fn (mixed $t): bool => is_int($t) && $t > $cutoff)) : [];
            if (is_string($key) && $recent !== []) {
                $fresh[$key] = $recent;
            }
        }

        return $fresh;
    }

    /** @param callable(array<string, list<int>>): array<string, list<int>> $change */
    private function withAttempts(callable $change): void
    {
        $file = $this->attemptsFile;
        try {
            ErrorGuard::run(function () use ($file, $change): void {
                $old = umask(0077);
                try {
                    $handle = fopen($file, 'c+');
                } finally {
                    umask($old);
                }
                try {
                    flock($handle, LOCK_EX);
                    $current = $this->fresh(json_decode((string) stream_get_contents($handle), true));
                    $updated = $change($current);
                    ftruncate($handle, 0);
                    rewind($handle);
                    fwrite($handle, json_encode($updated, JSON_THROW_ON_ERROR));
                    fflush($handle);
                    flock($handle, LOCK_UN);
                } finally {
                    fclose($handle);
                }
            });
        } catch (ErrorException $e) {
            throw new RuntimeException('Nie można zapisać licznika prób instalatora w katalogu var/', 0, $e);
        }
    }

    private function writePrivate(string $file, string $content): void
    {
        $temp = $file . '.' . bin2hex(random_bytes(6)) . '.tmp';
        try {
            ErrorGuard::run(static function () use ($temp, $file, $content): void {
                $old = umask(0077);
                try {
                    file_put_contents($temp, $content, LOCK_EX);
                } finally {
                    umask($old);
                }
                chmod($temp, 0600);
                rename($temp, $file);
            });
        } catch (ErrorException $e) {
            throw new RuntimeException('Nie można zapisać stanu instalatora w katalogu var/', 0, $e);
        }
    }

    private function readFile(string $file): ?string
    {
        if (!is_file($file)) {
            return null;
        }
        try {
            $raw = ErrorGuard::run(static fn (): string|false => file_get_contents($file));
        } catch (ErrorException) {
            return null;
        }

        return is_string($raw) ? $raw : null;
    }

    /** @return array<string, mixed>|null */
    private function read(): ?array
    {
        $raw = $this->readFile($this->stateFile);
        $state = $raw !== null ? json_decode($raw, true) : null;

        return is_array($state) ? $state : null;
    }

    /** @param array<string, mixed> $state */
    private function isExpired(array $state): bool
    {
        return !is_int($state['updated_at'] ?? null) || time() - $state['updated_at'] > self::AUTHORIZED_TTL;
    }
}
