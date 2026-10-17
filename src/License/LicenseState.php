<?php

declare(strict_types=1);

namespace Wezurlop\License;

/**
 * Stan licencji — DTO dostarczające parametry potrzebne do działania (limity, funkcje),
 * a nie tylko „ważna / nieważna” (CLAUDE.md §15). STREFA KODOWANA.
 */
final class LicenseState
{
    public const MODE_DEV = 'dev';
    public const MODE_ACTIVE = 'active';
    public const MODE_GRACE = 'grace';
    public const MODE_LIMITED = 'limited';

    /**
     * @param array<string, int> $limits
     * @param array<string, bool> $features
     */
    public function __construct(
        public readonly string $mode,
        public readonly string $reasonCode,
        public readonly ?string $licenseId,
        public readonly ?int $expiresAt,
        public readonly ?int $updatesUntil,
        public readonly array $limits,
        public readonly array $features,
    ) {
    }

    public function isLimited(): bool
    {
        return $this->mode === self::MODE_LIMITED;
    }

    /**
     * Czy licencja obejmuje wersję wydaną w danej chwili. Upływ updates_until NIE ogranicza
     * działania aplikacji — blokuje wyłącznie instalację nowszych wersji.
     */
    public function coversRelease(int $releasedAt): bool
    {
        if ($this->mode === self::MODE_DEV) {
            return true;
        }

        return !$this->isLimited() && $this->updatesUntil !== null && $releasedAt <= $this->updatesUntil;
    }
}
