<?php

declare(strict_types=1);

namespace Wezurlop\Service\Install;

use Phinx\Config\Config as PhinxConfig;
use Phinx\Migration\Manager;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Wezurlop\Db\Connection;

/**
 * Migracje z instalatora porcjami — w jednym żądaniu tyle, ile zmieści się w budżecie czasu
 * (limit 30 s, CLAUDE.md §0). Konto migracyjne, nie aplikacyjne.
 */
final class MigrationRunner
{
    private const ENVIRONMENT = 'tenant';

    /** @param array{host: string, port?: int, name: string, mig_user: string, mig_pass: string, ssl?: string, ssl_ca?: string} $db */
    public function __construct(
        private readonly string $appRoot,
        #[\SensitiveParameter] private readonly array $db,
    ) {
    }

    /** @return array{done: bool, applied: int, pending: int} */
    public function run(float $budgetSeconds): array
    {
        $manager = $this->manager();
        $deadline = microtime(true) + $budgetSeconds;
        $applied = 0;
        foreach ($this->pending($manager) as $version) {
            if (microtime(true) >= $deadline) {
                break;
            }
            $manager->migrate(self::ENVIRONMENT, $version);
            $applied++;
        }
        $left = count($this->pending($manager));

        return ['done' => $left === 0, 'applied' => $applied, 'pending' => $left];
    }

    /** @return list<int> */
    private function pending(Manager $manager): array
    {
        $done = $manager->getEnvironment(self::ENVIRONMENT)->getVersions();
        $all = array_keys($manager->getMigrations(self::ENVIRONMENT));
        sort($all);

        return array_values(array_filter($all, static fn (int $v): bool => !in_array($v, $done, false)));
    }

    private function manager(): Manager
    {
        $config = new PhinxConfig([
            'paths' => ['migrations' => $this->appRoot . '/db/migrations'],
            'environments' => [
                'default_migration_table' => 'phinxlog',
                'default_environment'     => self::ENVIRONMENT,
                self::ENVIRONMENT => [
                    'adapter'   => 'mysql',
                    'host'      => $this->db['host'],
                    'port'      => (int) ($this->db['port'] ?? 3306),
                    'name'      => $this->db['name'],
                    'user'      => $this->db['mig_user'],
                    'pass'      => $this->db['mig_pass'],
                    'charset'   => 'utf8mb4',
                    'collation' => 'utf8mb4_unicode_ci',
                    'mysql_attr_init_command' => "SET time_zone = '+00:00'",
                ] + Connection::phinxSslOptions($this->db['host'], $this->db['ssl'] ?? Connection::SSL_AUTO, $this->db['ssl_ca'] ?? ''),
            ],
            'version_order' => 'creation',
        ]);

        return new Manager($config, new ArrayInput([]), new NullOutput());
    }
}
