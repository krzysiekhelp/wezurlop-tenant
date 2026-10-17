<?php

declare(strict_types=1);

namespace Wezurlop\Http;

/**
 * Definicja trasy z config/routes.php po walidacji.
 *
 * Trasa nieprawidłowa ($error !== null) w trybie deweloperskim zatrzymuje start aplikacji,
 * a w produkcji odpowiada 403 — nigdy nie jest cicho przepuszczana.
 */
final class Route
{
    public const AUTH_NONE = 'none';
    public const AUTH_PARTIAL = 'partial';
    public const AUTH_FULL = 'full';
    /** API connectora: klucz instalacji zamiast sesji, bez CSRF (to nie przeglądarka), uprawnienie api.* obowiązkowe. */
    public const AUTH_API = 'api';

    /** Wyjątki z łańcucha: trasa dostępna przed ustawieniem 2FA / przed wyborem firmy. */
    public const EXEMPT_2FA = '2fa';
    public const EXEMPT_CONTEXT = 'context';
    public const EXEMPTIONS = [self::EXEMPT_2FA, self::EXEMPT_CONTEXT];

    /**
     * **Zakres ekranu**: czy pokazuje dane JEDNEJ spółki, czy całego tenanta.
     *
     * ⚠ Stoi w definicji trasy, nie w layoucie (zlecenie 10.10.2026 §1). Lista ścieżek zaszyta
     * w szablonie zgniłaby przy pierwszej nowej trasie i **nikt by tego nie zauważył**, bo
     * znacznik spółki i tak coś pokaże — a pokazywałby nazwę spółki na ekranie, który dotyczy
     * całego tenanta, czyli **kłamałby**. Dlatego zakres jest deklaracją obok uprawnienia
     * i jest **obowiązkowy** dla każdej trasy wymagającej pełnej sesji: nowy ekran bez
     * deklaracji to błędna trasa, a nie ekran z domyślnym zakresem.
     */
    public const ZAKRES_SPOLKA = 'spolka';
    public const ZAKRES_TENANT = 'tenant';
    public const ZAKRESY = [self::ZAKRES_SPOLKA, self::ZAKRES_TENANT];

    /**
     * @param class-string $controller
     * @param list<string> $exempt
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly string $controller,
        public readonly string $action,
        public readonly string $auth,
        public readonly ?string $permission,
        public readonly ?string $error = null,
        public readonly array $exempt = [],
        public readonly ?string $zakres = null,
    ) {
    }

    /** Czy ekran dotyczy jednej spółki (wtedy znacznik w belce się rozwija). */
    public function dotyczySpolki(): bool
    {
        return $this->zakres === self::ZAKRES_SPOLKA;
    }

    public function isValid(): bool
    {
        return $this->error === null;
    }

    public function isExempt(string $what): bool
    {
        return in_array($what, $this->exempt, true);
    }

    /** Trasa dostępna także dla sesji częściowej (po haśle, przed kodem 2FA). */
    public function allowsPartial(): bool
    {
        return $this->auth === self::AUTH_PARTIAL;
    }

    public function isApi(): bool
    {
        return $this->auth === self::AUTH_API;
    }

    public function requiresAuthentication(): bool
    {
        return $this->auth !== self::AUTH_NONE;
    }

    public function describe(): string
    {
        return $this->method . ' ' . $this->path;
    }
}
