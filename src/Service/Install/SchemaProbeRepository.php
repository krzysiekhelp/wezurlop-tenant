<?php

declare(strict_types=1);

namespace Wezurlop\Service\Install;

use PDO;
use PDOException;

/**
 * Próby uprawnień kont bazy w instalatorze (docs/szkielet-tenanta.md rozdz. 12, krok 4).
 * DDL jest tu dozwolony (instalator), ale wyłącznie na stałej, własnej tabeli próbnej — nazwa nie
 * pochodzi z danych wejściowych. Sprawdzamy WYNIK próby, nie treść SHOW GRANTS.
 */
final class SchemaProbeRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** Czy konto może zmieniać schemat — utworzenie i usunięcie tabeli próbnej. */
    public function canCreateTable(): bool
    {
        try {
            $this->pdo->exec('DROP TABLE IF EXISTS wezurlop_install_probe');
            $this->pdo->exec('CREATE TABLE wezurlop_install_probe (id INT NOT NULL) ENGINE=InnoDB');
        } catch (PDOException) {
            return false;
        }
        $this->pdo->exec('DROP TABLE wezurlop_install_probe');

        return true;
    }
}
