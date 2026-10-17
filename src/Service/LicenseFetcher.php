<?php

declare(strict_types=1);

namespace Wezurlop\Service;

use ErrorException;
use RuntimeException;
use Wezurlop\Support\ErrorGuard;

/**
 * Pobranie odnowionej licencji z panelu i zapis podpisanej odpowiedzi (docs/szkielet-tenanta.md rozdz. 11).
 *
 * STREFA JAWNA: musi działać także przy wygasłej licencji i dać się zdiagnozować u klienta.
 * Niczego nie rozstrzyga — tylko przenosi podpisaną odpowiedź; weryfikacja jest w strefie kodowanej.
 * Adres panelu wyłącznie z konfiguracji, klucz instalacji w nagłówku (CLAUDE.md §13).
 */
final class LicenseFetcher
{
    private const TIMEOUT = 10;
    private const MAX_BYTES = 65536;

    public function __construct(
        private readonly string $panelUrl,
        #[\SensitiveParameter] private readonly string $installationKey,
        private readonly string $licenseFile,
    ) {
    }

    /** @throws RuntimeException gdy panel nie odpowiedział poprawnie */
    public function fetch(): void
    {
        if ($this->panelUrl === '' || $this->installationKey === '') {
            throw new RuntimeException('Brak konfiguracji panelu (PANEL_URL, PANEL_INSTALLATION_KEY)');
        }
        if (preg_match('#^https://[^/\s]+(/[^\s]*)?$#i', $this->panelUrl) !== 1) {
            throw new RuntimeException('PANEL_URL musi być adresem https://');
        }

        $ch = curl_init(rtrim($this->panelUrl, '/') . '/api/license');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => '',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER     => ['X-Api-Key: ' . $this->installationKey, 'Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);

        if (!is_string($body) || $status !== 200) {
            throw new RuntimeException('Panel licencji: brak poprawnej odpowiedzi (HTTP ' . $status . ($error !== '' ? ', ' . $error : '') . ')');
        }
        $this->store($body);
    }

    /**
     * Zapis podpisanej odpowiedzi. Sprawdzana jest wyłącznie struktura — podpis weryfikuje strefa kodowana.
     *
     * @throws RuntimeException
     */
    public function store(string $json): void
    {
        if (strlen($json) > self::MAX_BYTES) {
            throw new RuntimeException('Odpowiedź licencyjna jest za duża');
        }
        $data = json_decode($json, true);
        if (!is_array($data) || !is_string($data['payload'] ?? null) || !is_string($data['signature'] ?? null)
            || base64_decode($data['payload'], true) === false || base64_decode($data['signature'], true) === false) {
            throw new RuntimeException('Odpowiedź licencyjna ma nieprawidłową strukturę');
        }

        $content = json_encode(['payload' => $data['payload'], 'signature' => $data['signature']], JSON_UNESCAPED_SLASHES);
        $temp = $this->licenseFile . '.' . bin2hex(random_bytes(6)) . '.tmp';
        try {
            ErrorGuard::run(function () use ($temp, $content): void {
                file_put_contents($temp, $content, LOCK_EX);
                chmod($temp, 0640);
                rename($temp, $this->licenseFile);
            });
        } catch (ErrorException $e) {
            throw new RuntimeException('Nie można zapisać pliku licencji', 0, $e);
        }
    }
}
