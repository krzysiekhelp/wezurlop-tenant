<?php

declare(strict_types=1);

// Konfiguracja phinx. Migracje puszcza WYŁĄCZNIE konto migracyjne (DB_MIG_USER) — konto aplikacji
// ma tylko SELECT, INSERT, UPDATE, DELETE (CLAUDE.md §4). Poświadczenia wyłącznie z .env.
//   vendor/bin/phinx migrate  -c config/phinx.php
//   vendor/bin/phinx rollback -c config/phinx.php -t 0
//   vendor/bin/phinx seed:run -c config/phinx.php        (tylko APP_ENV=dev)

use Wezurlop\Db\Connection;
use Wezurlop\Support\EnvFile;

return (static function (): array {
    $appRoot = dirname(__DIR__);
    $env = EnvFile::load(EnvFile::path($appRoot, []));

    return [
        'paths' => [
            'migrations' => $appRoot . '/db/migrations',
            'seeds'      => $appRoot . '/db/seeds',
        ],
        'environments' => [
            'default_migration_table' => 'phinxlog',
            'default_environment'     => 'tenant',
            'tenant' => [
                'adapter'   => 'mysql',
                'host'      => $env['DB_HOST'] ?? 'localhost',
                'port'      => (int) (($env['DB_PORT'] ?? '') !== '' ? $env['DB_PORT'] : 3306),
                // ⚠ WEZURLOP_DB_NAME nadpisuje nazwę bazy wyłącznie ze zmiennej środowiskowej procesu
                // (narzędzia deweloperskie: osobna baza testowa, żeby regresja nie dotykała bazy roboczej).
                // W zwykłym uruchomieniu zmiennej nie ma i obowiązuje DB_NAME z .env.
                'name'      => getenv('WEZURLOP_DB_NAME') !== false && getenv('WEZURLOP_DB_NAME') !== ''
                    ? (string) getenv('WEZURLOP_DB_NAME')
                    : ($env['DB_NAME'] ?? ''),
                'user'      => $env['DB_MIG_USER'] ?? '',
                'pass'      => $env['DB_MIG_PASS'] ?? '',
                'charset'   => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                // Czas w bazie zawsze UTC (to samo ustawia połączenie aplikacji).
                'mysql_attr_init_command' => "SET time_zone = '+00:00'",
            ] + Connection::phinxSslOptions(
                (string) ($env['DB_HOST'] ?? 'localhost'),
                strtolower((string) (($env['DB_SSL'] ?? '') !== '' ? $env['DB_SSL'] : Connection::SSL_AUTO)),
                (string) ($env['DB_SSL_CA'] ?? ''),
            ),
        ],
        'version_order' => 'creation',
    ];
})();
