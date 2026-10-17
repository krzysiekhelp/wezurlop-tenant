<?php

declare(strict_types=1);

namespace Wezurlop\Support;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * Konfiguracja Twiga zgodna z CLAUDE.md §5. Funkcje globalne — tylko lista z rozdziału 8:
 * csrf_token(), asset(), url(), current_user(), can(), active(). Żadna nie wykonuje zapytań
 * ani operacji na plikach.
 *
 * Zmienne globalne wybierające layout (variant, layout_app, layout_auth) pochodzą z białej listy
 * w tej klasie, nigdy z danych żądania.
 */
final class TwigFactory
{
    private const LAYOUTS = [
        UrlGenerator::WEB    => ['app' => 'layout/web.twig', 'auth' => 'layout/auth.twig'],
        UrlGenerator::MOBILE => ['app' => 'layout/mobile.twig', 'auth' => 'layout/auth-mobile.twig'],
    ];

    /**
     * @param array<string, callable> $functions csrf_token, current_user, can, active — dostarcza bootstrap
     */
    public static function create(
        string $templatesPath,
        ?string $cachePath,
        bool $debug,
        UrlGenerator $urls,
        array $functions = [],
    ): Environment {
        $twig = new Environment(new FilesystemLoader($templatesPath), [
            'autoescape'       => 'html',
            'strict_variables' => true,
            'cache'            => $cachePath ?? false,
            'debug'            => $debug,
        ]);

        $twig->addFunction(new TwigFunction('url', $urls->to(...)));
        $twig->addFunction(new TwigFunction('asset', $urls->asset(...)));
        foreach ($functions as $name => $callable) {
            $twig->addFunction(new TwigFunction($name, $callable));
        }

        $layouts = self::LAYOUTS[$urls->variant()];
        $twig->addGlobal('variant', $urls->variant());
        $twig->addGlobal('layout_app', $layouts['app']);
        $twig->addGlobal('layout_auth', $layouts['auth']);

        return $twig;
    }
}
