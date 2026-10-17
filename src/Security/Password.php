<?php

declare(strict_types=1);

namespace Wezurlop\Security;

use ErrorException;
use Wezurlop\Support\ErrorGuard;

/**
 * Hasła (CLAUDE.md §9): password_hash(PASSWORD_DEFAULT) i password_verify().
 *
 * ⚠ PASSWORD_DEFAULT to bcrypt, który czyta tylko 72 bajty. Hasło przechodzi więc najpierw przez
 * SHA-256 i base64 (zawsze 44 bajty ASCII, bez bajtów zerowych) — dłuższe hasła po prostu działają.
 * Bez peppera: utrata klucza unieważniłaby wszystkie hasła w instalacji. To jedyne miejsce,
 * w którym ta transformacja się odbywa.
 */
final class Password
{
    public const MIN_LENGTH = 12;
    /** Górny limit tylko przeciw nadużyciom (wielomegabajtowe pole) — daleko ponad wymagane 64 znaki. */
    public const MAX_BYTES = 4096;

    /**
     * Hash do weryfikacji „na próżno”, gdy konto nie istnieje — wyrównuje czas odpowiedzi.
     * Ten sam algorytm i koszt co prawdziwe hasła; generowany raz i trzymany w var/cache, żeby próba
     * na nieistniejącym koncie kosztowała dokładnie jedną weryfikację, jak na istniejącym.
     */
    private ?string $dummyHash = null;

    public function __construct(private readonly string $dummyHashFile)
    {
    }

    public function hash(#[\SensitiveParameter] string $password): string
    {
        return password_hash(self::prepare($password), PASSWORD_DEFAULT);
    }

    public function verify(#[\SensitiveParameter] string $password, ?string $hash): bool
    {
        if ($hash === null || $hash === '') {
            $this->verifyDummy($password);
            return false;
        }

        return password_verify(self::prepare($password), $hash);
    }

    public function verifyDummy(#[\SensitiveParameter] string $password): void
    {
        password_verify(self::prepare($password), $this->dummyHash());
    }

    /** SHA-256 + base64: stała długość wejścia bcrypta (CLAUDE.md §9). */
    private static function prepare(#[\SensitiveParameter] string $plain): string
    {
        return base64_encode(hash('sha256', $plain, true));
    }

    private function dummyHash(): string
    {
        if ($this->dummyHash !== null) {
            return $this->dummyHash;
        }
        try {
            $cached = is_file($this->dummyHashFile)
                ? ErrorGuard::run(fn (): string|false => file_get_contents($this->dummyHashFile))
                : false;
        } catch (ErrorException) {
            $cached = false;
        }
        if (is_string($cached) && !password_needs_rehash($cached, PASSWORD_DEFAULT)) {
            return $this->dummyHash = $cached;
        }

        $hash = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
        try {
            // Hash losowego, nieznanego nikomu hasła — nie jest sekretem, ale i tak poza webrootem.
            ErrorGuard::run(fn (): int|false => file_put_contents($this->dummyHashFile, $hash, LOCK_EX));
        } catch (ErrorException) {
            // Bez cache działa dalej, tylko pierwsza próba w żądaniu jest wolniejsza.
        }

        return $this->dummyHash = $hash;
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_DEFAULT);
    }

    /** @return string|null komunikat błędu dla użytkownika albo null, gdy hasło spełnia zasady */
    public function policyError(#[\SensitiveParameter] string $password): ?string
    {
        if (mb_strlen($password) < self::MIN_LENGTH) {
            return 'Hasło musi mieć co najmniej ' . self::MIN_LENGTH . ' znaków.';
        }
        if (strlen($password) > self::MAX_BYTES) {
            return 'Hasło jest za długie.';
        }

        return null;
    }
}
