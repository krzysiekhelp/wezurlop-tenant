<?php

declare(strict_types=1);

namespace Wezurlop\Security;

use ParagonIE_Sodium_Compat as Sodium;
use SodiumException;

/**
 * Szyfrowanie sekretów (sekret TOTP) kluczem APP_KEY — sodium_crypto_secretbox przez
 * paragonie/sodium_compat (CLAUDE.md §0: bez twardej zależności od ext-sodium).
 * Nikt poza tą klasą nie dotyka kryptografii (docs/szkielet-tenanta.md rozdz. 5).
 *
 * Format: "v1:" + base64(nonce || szyfrogram).
 */
final class SecretBox
{
    private const PREFIX = 'v1:';

    public function __construct(#[\SensitiveParameter] private readonly string $key)
    {
        if (strlen($key) !== Sodium::CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \InvalidArgumentException('Klucz szyfrujący musi mieć 32 bajty');
        }
    }

    public function seal(#[\SensitiveParameter] string $plaintext): string
    {
        $nonce = random_bytes(Sodium::CRYPTO_SECRETBOX_NONCEBYTES);

        return self::PREFIX . base64_encode($nonce . Sodium::crypto_secretbox($plaintext, $nonce, $this->key));
    }

    /** @return string|null treść albo null, gdy dane są uszkodzone lub zaszyfrowane innym kluczem */
    public function open(string $sealed): ?string
    {
        if (!str_starts_with($sealed, self::PREFIX)) {
            return null;
        }
        $raw = base64_decode(substr($sealed, strlen(self::PREFIX)), true);
        $minLength = Sodium::CRYPTO_SECRETBOX_NONCEBYTES + Sodium::CRYPTO_SECRETBOX_MACBYTES;
        if ($raw === false || strlen($raw) < $minLength) {
            return null;
        }
        try {
            $plain = Sodium::crypto_secretbox_open(
                substr($raw, Sodium::CRYPTO_SECRETBOX_NONCEBYTES),
                substr($raw, 0, Sodium::CRYPTO_SECRETBOX_NONCEBYTES),
                $this->key,
            );
        } catch (SodiumException) {
            return null;
        }

        return is_string($plain) ? $plain : null;
    }
}
