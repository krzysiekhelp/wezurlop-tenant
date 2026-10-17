<?php

declare(strict_types=1);

namespace Wezurlop\License;

/**
 * Jedyne wejście do licencji dla reszty aplikacji (CLAUDE.md §15). STREFA KODOWANA.
 *
 * Brak metody isValid(): bool — stan dostarcza limity i funkcje używane dalej, a decyzje zapadają
 * w kilku miejscach (LicenseGuard, limity kont, funkcje płatne).
 */
final class LicenseGate
{
    private ?LicenseState $state = null;

    public function __construct(
        private readonly LicenseReader $reader,
        private readonly LicenseValidator $validator,
        private readonly string $installationHost,
        private readonly bool $developmentMode,
    ) {
    }

    public function state(): LicenseState
    {
        if ($this->state !== null) {
            return $this->state;
        }
        if (!$this->reader->licenseExists()) {
            // Środowisko deweloperskie bez pliku licencji: bez ograniczeń (docs/szkielet-tenanta.md rozdz. 11).
            return $this->state = $this->developmentMode
                ? new LicenseState(LicenseState::MODE_DEV, 'L001', null, null, null, [], [])
                : new LicenseState(LicenseState::MODE_LIMITED, 'L100', null, null, null, [], []);
        }

        $signed = $this->reader->signedLicense() ?? ['payload' => '', 'signature' => ''];

        return $this->state = $this->validator->validate($signed, $this->reader->publicKey(), $this->installationHost, time());
    }

    /** Limit z licencji; null = brak limitu (tryb deweloperski albo licencja bez tego limitu). */
    public function limit(string $key): ?int
    {
        $state = $this->state();
        if ($state->mode === LicenseState::MODE_DEV) {
            return null;
        }
        if ($state->isLimited()) {
            return 0;
        }

        return $state->limits[$key] ?? null;
    }

    /** Czy aktualizacja wydana w danej chwili może zostać zainstalowana (aktualizator, Etap 3+). */
    public function updatesAllowedFor(int $releasedAt): bool
    {
        return $this->state()->coversRelease($releasedAt);
    }

    public function featureEnabled(string $key): bool
    {
        $state = $this->state();
        if ($state->mode === LicenseState::MODE_DEV) {
            return true;
        }

        return !$state->isLimited() && ($state->features[$key] ?? false);
    }
}
