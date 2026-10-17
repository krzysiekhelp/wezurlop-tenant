<?php

declare(strict_types=1);

namespace Wezurlop\Service\Sync;

use JsonException;

/**
 * Treść żądania API connectora (docs/api-connector.md §2). STREFA JAWNA.
 *
 * - treść przed kompresją maks. 2 MB → inaczej 413,
 * - ⚠ gzip rozpakowywany STRUMIENIOWO z twardym limitem 8 MB — bomba kompresyjna nie zdąży zająć pamięci
 *   (rozpakowanie przerywane po przekroczeniu), a wynik i tak musi zmieścić się w 2 MB,
 * - JSON w UTF-8, obiekt na najwyższym poziomie.
 */
final class ApiBody
{
    public const MAX_BYTES = 2_097_152;
    public const MAX_INFLATED_BYTES = 8_388_608;

    /** Surowa treść żądania — czytana najwyżej o bajt ponad limit (więcej nie jest potrzebne do odrzucenia). */
    public static function readInput(): string
    {
        $raw = file_get_contents('php://input', false, null, 0, self::MAX_BYTES + 1);

        return is_string($raw) ? $raw : '';
    }

    /** @return array<string, mixed> */
    public static function parse(string $raw, ?string $contentEncoding): array
    {
        if (strlen($raw) > self::MAX_BYTES) {
            throw self::tooLarge();
        }
        $encoding = strtolower(trim((string) $contentEncoding));
        if ($encoding === 'gzip') {
            $raw = self::gunzip($raw);
        } elseif ($encoding !== '' && $encoding !== 'identity') {
            throw new ApiError(415, 'KODOWANIE_NIEOBSLUGIWANE', 'Obsługiwane kodowanie treści: gzip');
        }
        if (strlen($raw) > self::MAX_BYTES) {
            throw self::tooLarge();
        }
        try {
            $data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException) {
            throw new ApiError(400, 'ZADANIE_NIEPOPRAWNE', 'Treść nie jest poprawnym JSON-em');
        }
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new ApiError(400, 'ZADANIE_NIEPOPRAWNE', 'Oczekiwano obiektu JSON');
        }

        return $data;
    }

    private static function gunzip(string $compressed): string
    {
        if (!function_exists('inflate_init')) {
            // zlib jest prawie zawsze obecne, ale nie jest na liście wymagań — connector może wysłać bez kompresji.
            throw new ApiError(415, 'KODOWANIE_NIEOBSLUGIWANE', 'Serwer nie obsługuje gzip — wyślij treść bez kompresji');
        }
        $ctx = inflate_init(ZLIB_ENCODING_GZIP);
        if ($ctx === false) {
            throw new ApiError(400, 'ZADANIE_NIEPOPRAWNE', 'Niepoprawny strumień gzip');
        }
        // inflate_add() zgłasza ostrzeżenie przy uszkodzonych danych — to błąd klienta (400), nie serwera (500).
        // Własny handler tylko na czas rozpakowania, bez tłumienia `@` (CLAUDE.md §14).
        set_error_handler(static function (int $severity, string $message): bool {
            throw new ApiError(400, 'ZADANIE_NIEPOPRAWNE', 'Niepoprawny strumień gzip');
        }, E_WARNING);
        try {
            $out = '';
            foreach (str_split($compressed, 8192) ?: [] as $chunk) {
                $part = inflate_add($ctx, $chunk, ZLIB_SYNC_FLUSH);
                if ($part === false) {
                    throw new ApiError(400, 'ZADANIE_NIEPOPRAWNE', 'Niepoprawny strumień gzip');
                }
                $out .= $part;
                if (strlen($out) > self::MAX_INFLATED_BYTES) {
                    throw self::tooLarge();
                }
            }
            $tail = inflate_add($ctx, '', ZLIB_FINISH);
        } finally {
            restore_error_handler();
        }
        if ($tail === false || strlen($out . $tail) > self::MAX_INFLATED_BYTES) {
            throw $tail === false ? new ApiError(400, 'ZADANIE_NIEPOPRAWNE', 'Niepoprawny strumień gzip') : self::tooLarge();
        }

        return $out . $tail;
    }

    private static function tooLarge(): ApiError
    {
        return new ApiError(413, 'PACZKA_ZA_DUZA', 'Przekroczony limit', ['maks' => ['rekordy' => SyncService::MAX_RECORDS, 'bajty' => self::MAX_BYTES]]);
    }
}
