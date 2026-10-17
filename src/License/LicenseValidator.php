<?php

declare(strict_types=1);

namespace Wezurlop\License;

use ParagonIE_Sodium_Compat as Sodium;

/**
 * Weryfikacja licencji: podpis Ed25519 panelu, powiązanie z instalacją, terminy, cofnięcie zegara.
 * Siła licencji bierze się z podpisu, nie z ionCube (docs/specyfikacja.md). STREFA KODOWANA.
 */
final class LicenseValidator
{
    /** Górna granica karencji niezależnie od ustawienia (parametr handlowy, nie furtka). */
    private const MAX_GRACE_DAYS = 90;
    /** Tolerancja przesunięcia zegara wstecz (NTP, zmiana czasu). */
    private const CLOCK_TOLERANCE = 300;

    /**
     * @param callable(): ?int $lastKnownTime odczyt ostatniego znanego czasu
     * @param callable(int): void $storeKnownTime zapis ostatniego znanego czasu
     * @param callable(): int $graceDays karencja subskrypcji w dniach (tenant_setting) — czytana tylko po terminie
     */
    public function __construct(
        private $lastKnownTime,
        private $storeKnownTime,
        private $graceDays,
    ) {
    }

    /**
     * @param array{payload: string, signature: string} $signed
     */
    public function validate(array $signed, ?string $publicKey, string $installationHost, int $now): LicenseState
    {
        if ($publicKey === null) {
            return $this->limited('L101');
        }
        if ($signed['payload'] === '' || strlen($signed['signature']) !== 64) {
            return $this->limited('L102');
        }
        try {
            $valid = Sodium::crypto_sign_verify_detached($signed['signature'], $signed['payload'], $publicKey);
        } catch (\Throwable) {
            $valid = false;
        }
        if (!$valid) {
            return $this->limited('L103');
        }

        $data = json_decode($signed['payload'], true);
        if (!is_array($data) || !is_string($data['license_id'] ?? null) || !is_string($data['installation'] ?? null)) {
            return $this->limited('L104');
        }
        if (strtolower($data['installation']) !== strtolower($installationHost)) {
            return $this->limited('L105', $data);
        }

        $last = ($this->lastKnownTime)();
        if ($last !== null && $now + self::CLOCK_TOLERANCE < $last) {
            // Zegar serwera cofnięty — bez tej kontroli wygasła licencja „odżywałaby”.
            return $this->limited('L106', $data);
        }
        ($this->storeKnownTime)(max($now, $last ?? 0));

        $issuedAt = is_int($data['issued_at'] ?? null) ? $data['issued_at'] : 0;
        if ($issuedAt > $now + self::CLOCK_TOLERANCE) {
            return $this->limited('L107', $data);
        }

        // Dwie daty, dwa skutki: expires_at (subskrypcja) → karencja, potem tryb ograniczony;
        // updates_until → wyłącznie brak nowych wersji, aplikacja działa dalej. Licencja wieczysta
        // ma expires_at = null i nigdy nie przechodzi w tryb ograniczony z powodu terminu.
        $expiresAt = is_int($data['expires_at'] ?? null) ? $data['expires_at'] : null;
        $mode = LicenseState::MODE_ACTIVE;
        $reason = 'L000';
        if ($expiresAt !== null && $now > $expiresAt) {
            $grace = max(0, min(self::MAX_GRACE_DAYS, ($this->graceDays)())) * 86400;
            if ($now > $expiresAt + $grace) {
                return $this->limited('L108', $data);
            }
            $mode = LicenseState::MODE_GRACE;
            $reason = 'L200';
        }

        return $this->state($mode, $reason, $data);
    }

    /** @param array<string, mixed> $data */
    private function limited(string $reason, array $data = []): LicenseState
    {
        return $this->state(LicenseState::MODE_LIMITED, $reason, $data);
    }

    /** @param array<string, mixed> $data */
    private function state(string $mode, string $reason, array $data): LicenseState
    {
        $limits = [];
        foreach (is_array($data['limits'] ?? null) ? $data['limits'] : [] as $key => $value) {
            if (is_string($key) && is_int($value) && $value >= 0) {
                $limits[$key] = $value;
            }
        }
        $features = [];
        foreach (is_array($data['features'] ?? null) ? $data['features'] : [] as $key => $value) {
            if (is_string($key) && is_bool($value)) {
                $features[$key] = $value;
            }
        }
        if ($mode === LicenseState::MODE_LIMITED) {
            // W trybie ograniczonym żadna funkcja płatna nie jest dostępna.
            $features = array_map(static fn (): bool => false, $features);
        }

        return new LicenseState(
            $mode,
            $reason,
            is_string($data['license_id'] ?? null) ? $data['license_id'] : null,
            is_int($data['expires_at'] ?? null) ? $data['expires_at'] : null,
            is_int($data['updates_until'] ?? null) ? $data['updates_until'] : null,
            $limits,
            $features,
        );
    }
}
