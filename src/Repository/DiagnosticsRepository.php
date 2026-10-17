<?php

declare(strict_types=1);

namespace Wezurlop\Repository;

use PDO;

/**
 * Zapytania używane przez diagnostykę środowiska. Tylko odczyt, bez tworzenia obiektów w bazie.
 */
final class DiagnosticsRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function serverVersion(): string
    {
        return (string) $this->pdo->query('SELECT VERSION()')->fetchColumn();
    }

    public function schemaCollation(): string
    {
        $stmt = $this->pdo->query(
            'SELECT DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = DATABASE()'
        );

        return (string) $stmt->fetchColumn();
    }

    /** Szyfr TLS bieżącego połączenia; pusty = połączenie nieszyfrowane (CLAUDE.md §4). */
    public function sslCipher(): string
    {
        $row = $this->pdo->query("SHOW SESSION STATUS LIKE 'Ssl_cipher'")->fetch(PDO::FETCH_NUM);

        return is_array($row) ? (string) $row[1] : '';
    }

    /** @return list<string> treść poleceń GRANT bieżącego konta */
    public function currentGrants(): array
    {
        $grants = [];
        foreach ($this->pdo->query('SHOW GRANTS FOR CURRENT_USER()')->fetchAll(PDO::FETCH_NUM) as $row) {
            $grants[] = (string) $row[0];
        }

        return $grants;
    }
}
