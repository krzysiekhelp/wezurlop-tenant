<?php

declare(strict_types=1);

// Test terminów licencji (zlecenie M8b, punkt 4): licencja wieczysta nie wygasa, subskrypcja
// przechodzi przez karencję z tenant_setting. Uruchomienie: php tests/license-terms.php
// Bez zależności testowych — kod wyjścia 0 = wszystko przeszło.

require dirname(__DIR__) . '/vendor/autoload.php';

use ParagonIE_Sodium_Compat as Sodium;
use Wezurlop\License\LicenseState;
use Wezurlop\License\LicenseValidator;

(static function (): void {
    $keypair = Sodium::crypto_sign_keypair();
    $secret = Sodium::crypto_sign_secretkey($keypair);
    $public = Sodium::crypto_sign_publickey($keypair);
    $now = time();
    $day = 86400;
    $host = 'tenant.example.test';

    $sign = static function (array $payload) use ($secret): array {
        $json = json_encode($payload, JSON_THROW_ON_ERROR);

        return ['payload' => $json, 'signature' => Sodium::crypto_sign_detached($json, $secret)];
    };
    $license = static fn (?int $expiresAt, ?int $updatesUntil): array => [
        'license_id'    => 'TEST',
        'installation'  => $host,
        'issued_at'     => $now - 400 * $day,
        'expires_at'    => $expiresAt,
        'updates_until' => $updatesUntil,
        'limits'        => ['accounts' => 10],
        'features'      => [],
    ];
    $validate = static function (array $payload, int $graceDays) use ($sign, $public, $host, $now): LicenseState {
        $validator = new LicenseValidator(static fn (): ?int => null, static function (int $t): void {
        }, static fn (): int => $graceDays);

        return $validator->validate($sign($payload), $public, $host, $now);
    };

    $failures = 0;
    $check = static function (string $name, bool $ok) use (&$failures): void {
        echo ($ok ? 'OK    ' : 'BŁĄD  ') . $name . "\n";
        $failures += $ok ? 0 : 1;
    };

    // Licencja wieczysta: expires_at = null, okres aktualizacji minął rok temu.
    $s = $validate($license(null, $now - 365 * $day), 7);
    $check('wieczysta po updates_until: działa (L000)', $s->mode === LicenseState::MODE_ACTIVE && $s->reasonCode === 'L000');
    $check('wieczysta po updates_until: limity dostępne', ($s->limits['accounts'] ?? null) === 10);
    $check('wieczysta po updates_until: nowa wersja nieobjęta', !$s->coversRelease($now));
    $check('wieczysta po updates_until: wersja sprzed terminu objęta', $s->coversRelease($now - 400 * $day));

    // Subskrypcja w terminie, okres aktualizacji minął — nadal działa.
    $s = $validate($license($now + 30 * $day, $now - $day), 7);
    $check('subskrypcja w terminie, po updates_until: działa', $s->mode === LicenseState::MODE_ACTIVE);

    // Subskrypcja po terminie: karencja z ustawienia.
    $s = $validate($license($now - 3 * $day, $now + 30 * $day), 7);
    $check('subskrypcja 3 dni po terminie, karencja 7: karencja (L200)', $s->mode === LicenseState::MODE_GRACE && $s->reasonCode === 'L200');
    $s = $validate($license($now - 10 * $day, $now + 30 * $day), 7);
    $check('subskrypcja 10 dni po terminie, karencja 7: tryb ograniczony (L108)', $s->isLimited() && $s->reasonCode === 'L108');
    $check('tryb ograniczony: nowa wersja nieobjęta', !$s->coversRelease($now - 400 * $day));
    $s = $validate($license($now - 10 * $day, $now + 30 * $day), 14);
    $check('subskrypcja 10 dni po terminie, karencja 14: karencja', $s->mode === LicenseState::MODE_GRACE);
    $s = $validate($license($now - 1, $now + 30 * $day), 0);
    $check('karencja 0: od razu tryb ograniczony', $s->isLimited());
    $s = $validate($license($now - 100 * $day, $now + 30 * $day), 1000);
    $check('karencja ponad 90 dni przycięta do 90', $s->isLimited());

    echo $failures === 0 ? "Wszystkie sprawdzenia przeszły.\n" : "Nieudane: {$failures}\n";
    exit($failures === 0 ? 0 : 1);
})();
