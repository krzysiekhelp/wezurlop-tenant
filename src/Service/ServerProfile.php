<?php

declare(strict_types=1);

namespace Wezurlop\Service;

/**
 * Profil serwera WWW (docs/profile-hostingu.md). STREFA JAWNA — musi być diagnozowalny u klienta.
 *
 * SERVER_SOFTWARE wybiera profil KANDYDUJĄCY, rozstrzyga wynik sond HTTP (przepisywanie adresów,
 * a potem krok 7 instalatora). ⚠ Profil nie wpływa na żadną decyzję o uprawnieniach — tylko na treść
 * .htaccess, mechanizm crona i przekazanie ścieżki aplikacji (CLAUDE.md §0, §11).
 */
final class ServerProfile
{
    public const APACHE = 'apache';
    public const IDEAWEBSERVER = 'ideawebserver';
    public const MINIMAL = 'minimal';

    public const ALL = [self::APACHE, self::IDEAWEBSERVER, self::MINIMAL];

    /** Kandydat z nagłówka: IdeaWebServer → ideawebserver, wszystko inne → apache (i pełna weryfikacja). */
    public static function candidate(?string $serverSoftware): string
    {
        return is_string($serverSoftware) && stripos($serverSoftware, 'IdeaWebServer') !== false
            ? self::IDEAWEBSERVER
            : self::APACHE;
    }

    /** Rozstrzygnięcie: bez działającego przepisywania adresów profil jest „minimal”, niezależnie od nagłówka. */
    public static function decide(string $candidate, bool $rewriteWorks): string
    {
        return $rewriteWorks ? $candidate : self::MINIMAL;
    }

    /**
     * Profil minimal nie ma żadnej blokady katalogów — dopuszczalny wyłącznie, gdy katalog domeny
     * wskazuje na public/ (poza nim nic nie jest w drzewie dostępnym z internetu).
     */
    public static function allowsLayout(string $profile, bool $documentRootIsPublic): bool
    {
        return $profile !== self::MINIMAL || $documentRootIsPublic;
    }

    /** Cron przez HTTP (plik cron-05min.php w katalogu domeny) — tylko profil hostingu, który tak działa. */
    public static function usesHttpCron(string $profile): bool
    {
        return $profile === self::IDEAWEBSERVER;
    }

    /** @return array{root: string, public: string} szablony .htaccess dla profilu */
    public static function htaccessTemplates(string $appRoot, string $profile): array
    {
        $profile = in_array($profile, self::ALL, true) ? $profile : self::APACHE;
        $dir = $appRoot . '/deploy/htaccess/' . $profile;

        return ['root' => $dir . '/root.htaccess', 'public' => $dir . '/public.htaccess'];
    }
}
