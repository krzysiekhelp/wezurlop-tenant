<?php

declare(strict_types=1);

namespace Wezurlop\Support;

/**
 * Adresy aplikacji (docs/szkielet-tenanta.md rozdz. 3 i 8).
 *
 * url(): APP_BASE_PATH + APP_FRONT_CONTROLLER + ścieżka; w wariancie mobilnym dodatkowo prefiks /m,
 * o ile trasa ma odpowiednik mobilny (instalator, diagnostyka i administracja go nie mają).
 * asset(): APP_BASE_PATH + /assets + ?v=wersja wyliczona z pliku (patrz AssetVersions).
 */
final class UrlGenerator
{
    public const WEB = 'web';
    public const MOBILE = 'mobile';

    /** @var (callable(string): bool)|null */
    private $hasMobileRoute = null;

    public function __construct(
        private readonly string $routePrefix,
        private readonly string $assetPrefix,
        private readonly string $assetVersion,
        private readonly string $variant,
        /**
         * ⚠ Wersja zasobu liczona Z PLIKU. `null` znaczy „licz starą drogą" i zostaje wyłącznie
         * tam, gdzie katalogu zasobów nie znamy (strona błędu z `ErrorHandler`) — wtedy adres
         * dostaje jedną wersję z konfiguracji, jak dotąd.
         */
        private readonly ?AssetVersions $wersje = null,
    ) {
    }

    /** Wariant z ścieżki trasy: /m i /m/… to widok mobilny. Nagłówek User-Agent nie ma znaczenia. */
    public static function variantForPath(string $path): string
    {
        return $path === '/m' || str_starts_with($path, '/m/') ? self::MOBILE : self::WEB;
    }

    /** @param callable(string): bool $hasMobileRoute */
    public function setMobileRouteCheck(callable $hasMobileRoute): void
    {
        $this->hasMobileRoute = $hasMobileRoute;
    }

    public function variant(): string
    {
        return $this->variant;
    }

    public function to(string $path): string
    {
        $path = '/' . ltrim($path, '/');
        if ($this->variant === self::MOBILE && !self::isMobilePath($path) && $this->hasMobileRoute !== null) {
            $mobile = $path === '/' ? '/m' : '/m' . $path;
            if (($this->hasMobileRoute)($mobile)) {
                $path = $mobile;
            }
        }

        return $this->routePrefix . $path;
    }

    /** Adres w wariancie web, niezależnie od bieżącego (np. linki do instalatora i diagnostyki). */
    public function web(string $path): string
    {
        return $this->routePrefix . '/' . ltrim($path, '/');
    }

    /**
     * Adres zasobu z wersją. ⚠ **Jedyna droga do `/assets/`** — `?v=` wpisane w szablonie
     * z palca jest trafieniem bramki (`bin/check-rules.php`), bo taka wersja nie zmienia się
     * przy zmianie pliku i przeglądarka zostaje ze starym arkuszem.
     */
    public function asset(string $path): string
    {
        $wersja = $this->wersje?->dla($path) ?? $this->assetVersion;

        return $this->assetPrefix . '/assets/' . ltrim($path, '/') . '?v=' . rawurlencode($wersja);
    }

    private static function isMobilePath(string $path): bool
    {
        return self::variantForPath($path) === self::MOBILE;
    }
}
