<?php

declare(strict_types=1);

namespace Wezurlop\Security;

/**
 * Adres IP zamieniony na HMAC — adres to dana osobowa, a do limitów i dziennika wystarcza skrót
 * (docs/szkielet-tenanta.md rozdz. 5). Adres klienta wyłącznie z TrustedProxy.
 */
final class ClientFingerprint
{
    public function __construct(
        private readonly TrustedProxy $proxy,
        #[\SensitiveParameter] private readonly string $key,
    ) {
    }

    /** @param array<string, mixed> $server */
    public function ipHash(array $server): string
    {
        return hash_hmac('sha256', $this->proxy->clientIp($server), $this->key);
    }
}
