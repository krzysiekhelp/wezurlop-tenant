<?php

declare(strict_types=1);

namespace Wezurlop\Service\Install;

use ErrorException;
use Wezurlop\Service\ServerProfile;
use Wezurlop\Support\ErrorGuard;

/**
 * Pliki wdrożenia zależne od profilu serwera (docs/profile-hostingu.md): .htaccess korzenia aplikacji
 * i katalogu domeny, public/app-path.php (wariant 2) oraz shim crona cron-05min.php (tylko profil
 * z cronem przez HTTP).
 *
 * Zapis tylko tam, gdzie serwer WWW ma prawo zapisu; w pozostałych przypadkach instrukcja dla
 * instalującego. ⚠ Obecność pliku niczego nie dowodzi — rozstrzyga weryfikacja HTTP w kroku 7.
 */
final class DeployFiles
{
    public const MATCHING = 'zgodny';
    public const WRITTEN = 'zapisany';
    public const MANUAL = 'do skopiowania ręcznie';

    public function __construct(private readonly string $appRoot)
    {
    }

    /**
     * @param string $documentRoot katalog, w którym leży uruchomiony index.php (katalog domeny w wariancie 2)
     * @return list<array{file: string, status: string, source: string}>
     */
    public function apply(string $profile, string $documentRoot, bool $applicationAboveDocumentRoot, bool $httpCron): array
    {
        $templates = ServerProfile::htaccessTemplates($this->appRoot, $profile);
        $results = [
            $this->place($this->appRoot . '/.htaccess', (string) file_get_contents($templates['root']), $templates['root']),
            $this->place($documentRoot . '/.htaccess', (string) file_get_contents($templates['public']), $templates['public']),
        ];
        if ($applicationAboveDocumentRoot) {
            // Droga podstawowa w wariancie 2 — działa na każdym serwerze, w przeciwieństwie do SetEnv.
            $content = "<?php\n\ndeclare(strict_types=1);\n\n// Położenie aplikacji — zapisane przez instalator (docs/profile-hostingu.md).\nreturn "
                . var_export(rtrim($this->appRoot, '/'), true) . ";\n";
            $results[] = $this->place($documentRoot . '/app-path.php', $content, '(treść wyżej)');
        }
        if ($httpCron) {
            $source = $this->appRoot . '/deploy/cron-05min.php';
            $results[] = $this->place($documentRoot . '/cron-05min.php', (string) file_get_contents($source), $source);
        }

        return $results;
    }

    /** @return array{file: string, status: string, source: string} */
    private function place(string $target, string $content, string $source): array
    {
        $result = ['file' => $target, 'status' => self::MANUAL, 'source' => $source];
        if (is_file($target) && file_get_contents($target) === $content) {
            $result['status'] = self::MATCHING;

            return $result;
        }
        $writable = is_file($target) ? is_writable($target) : is_writable(dirname($target));
        if (!$writable) {
            return $result;
        }
        try {
            ErrorGuard::run(static function () use ($target, $content): void {
                file_put_contents($target, $content, LOCK_EX);
                chmod($target, 0644);
            });
            $result['status'] = self::WRITTEN;
        } catch (ErrorException) {
            // zostaje „do skopiowania ręcznie”
        }

        return $result;
    }
}
