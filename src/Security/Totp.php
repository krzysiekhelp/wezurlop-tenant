<?php

declare(strict_types=1);

namespace Wezurlop\Security;

use RobThree\Auth\Providers\Qr\BaconQrCodeProvider;
use RobThree\Auth\TwoFactorAuth;

/**
 * TOTP (RFC 6238) przez robthree/twofactorauth. Kod QR WYŁĄCZNIE lokalnie (Bacon, SVG) —
 * zewnętrzny generator obrazków dostałby sekret w adresie (CLAUDE.md §9).
 */
final class Totp
{
    private const ISSUER = 'wezurlop';
    /** Tolerancja zegara: ±1 okno, nie więcej (CLAUDE.md §9). */
    private const DISCREPANCY = 1;

    private readonly TwoFactorAuth $tfa;

    public function __construct()
    {
        $this->tfa = new TwoFactorAuth(new BaconQrCodeProvider(1, '#ffffff', '#000000', 'svg'), self::ISSUER);
    }

    public function createSecret(): string
    {
        return $this->tfa->createSecret(160);
    }

    /**
     * Numer okna czasowego, w którym kod jest poprawny, albo null. Okno musi być nowsze niż ostatnio
     * użyte — ten sam kod drugi raz nie przejdzie (CLAUDE.md §9, pkt 3).
     */
    public function matchingWindow(#[\SensitiveParameter] string $secret, string $code, ?int $lastWindow, ?int $time = null): ?int
    {
        if (preg_match('/^\d{6}$/', $code) !== 1) {
            return null;
        }
        $window = 0;
        if (!$this->tfa->verifyCode($secret, $code, self::DISCREPANCY, $time, $window) || $window <= 0) {
            return null;
        }

        return $lastWindow === null || $window > $lastWindow ? $window : null;
    }

    public function qrSvg(string $label, #[\SensitiveParameter] string $secret): string
    {
        return (new BaconQrCodeProvider(1, '#ffffff', '#000000', 'svg'))
            ->getQRCodeImage($this->tfa->getQRText($label, $secret), 240);
    }

    /** Sekret w grupach po 4 znaki — do ręcznego wpisania w aplikacji. */
    public static function formatSecret(#[\SensitiveParameter] string $secret): string
    {
        return trim(chunk_split($secret, 4, ' '));
    }
}
