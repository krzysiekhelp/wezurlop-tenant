<?php

declare(strict_types=1);

namespace Wezurlop\Http;

/**
 * Rodzaj klienta: przeglądarka albo powłoka aplikacji (docs/szkielet-tenanta.md rozdz. 8,
 * docs/mostek-natywny.md).
 *
 * ⚠ Znacznik prezentacji, NIE poświadczenie (CLAUDE.md §11): ustawi go każdy w narzędziach
 * przeglądarki. Wolno na nim oprzeć wybór layoutu, przekierowanie i komunikat o aktualizacji —
 * nigdy uprawnienia, pominięcie 2FA ani skrócenie ścieżki logowania.
 *
 * Źródła: ciastko wu_client / wu_app (powłoka ustawia je przez CookieManager, więc idą także
 * z żądaniami htmx), a dla starszych powłok nagłówek X-Wezurlop-App. Po zalogowaniu decyduje
 * wartość zapisana w sesji (AuthSession), nie ciastko.
 */
final class ClientKind
{
    public const WEB = 'web';
    public const APP = 'app';

    public const COOKIE_CLIENT = 'wu_client';
    public const COOKIE_VERSION = 'wu_app';
    public const HEADER = 'X-Wezurlop-App';

    /** wersja / platforma / wersja kontraktu mostka, np. 1.0.3/android/1 */
    private const VERSION_PATTERN = '#^\d{1,4}\.\d{1,4}\.\d{1,4}/(android|ios)/\d{1,4}$#';

    private function __construct(
        public readonly string $client,
        public readonly ?string $appVersion,
    ) {
    }

    public static function web(): self
    {
        return new self(self::WEB, null);
    }

    /** Wartość zapisana wcześniej w sesji — nieznana wartość traktowana jak przeglądarka. */
    public static function fromStored(mixed $client, mixed $appVersion): self
    {
        if ($client !== self::APP) {
            return self::web();
        }

        return new self(self::APP, self::validVersion($appVersion));
    }

    public static function fromRequest(Request $request): self
    {
        $version = self::validVersion($request->cookie(self::COOKIE_VERSION));
        if ($request->cookie(self::COOKIE_CLIENT) === self::APP) {
            return new self(self::APP, $version);
        }
        // Wariant zapasowy: starsze powłoki wysyłają tylko nagłówek (na nawigacji głównej).
        $header = self::validVersion($request->header(self::HEADER));
        if ($header !== null) {
            return new self(self::APP, $header);
        }

        return self::web();
    }

    public function isApp(): bool
    {
        return $this->client === self::APP;
    }

    /** Platforma z wersji (android / ios) — do opisu urządzenia. */
    public function platform(): ?string
    {
        return $this->appVersion !== null ? explode('/', $this->appVersion)[1] : null;
    }

    /** Czytelny opis do listy urządzeń; null = opis z User-Agent. */
    public static function describe(string $client, ?string $appVersion): ?string
    {
        if ($client !== self::APP) {
            return null;
        }
        $version = self::validVersion($appVersion);
        if ($version === null) {
            return 'Aplikacja wezurlop';
        }
        [$number, $platform] = explode('/', $version);

        return 'Aplikacja ' . ($platform === 'ios' ? 'iOS' : 'Android') . ' ' . $number;
    }

    private static function validVersion(mixed $value): ?string
    {
        return is_string($value) && strlen($value) <= 32 && preg_match(self::VERSION_PATTERN, $value) === 1 ? $value : null;
    }
}
