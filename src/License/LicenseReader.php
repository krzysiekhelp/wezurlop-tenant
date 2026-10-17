<?php

declare(strict_types=1);

namespace Wezurlop\License;

/**
 * Odczyt pliku licencji i klucza publicznego. Ścieżki podaje wywołujący — ta strefa nie zna
 * konfiguracji klienta (CLAUDE.md §15). STREFA KODOWANA.
 *
 * Format pliku: {"payload": "<base64 dokładnie podpisanych bajtów JSON>", "signature": "<base64 Ed25519>"}.
 */
final class LicenseReader
{
    public function __construct(
        private readonly string $licenseFile,
        private readonly string $publicKeyFile,
    ) {
    }

    /** @return array{payload: string, signature: string}|null */
    public function signedLicense(): ?array
    {
        $raw = $this->read($this->licenseFile);
        if ($raw === null) {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || !is_string($data['payload'] ?? null) || !is_string($data['signature'] ?? null)) {
            return ['payload' => '', 'signature' => ''];
        }
        $payload = base64_decode($data['payload'], true);
        $signature = base64_decode($data['signature'], true);

        return ['payload' => is_string($payload) ? $payload : '', 'signature' => is_string($signature) ? $signature : ''];
    }

    public function licenseExists(): bool
    {
        return is_file($this->licenseFile);
    }

    public function publicKey(): ?string
    {
        $raw = $this->read($this->publicKeyFile);
        if ($raw === null) {
            return null;
        }
        $key = base64_decode(trim($raw), true);

        return is_string($key) && strlen($key) === 32 ? $key : null;
    }

    private function read(string $file): ?string
    {
        if (!is_file($file) || !is_readable($file)) {
            return null;
        }
        $content = file_get_contents($file);

        return is_string($content) ? $content : null;
    }
}
