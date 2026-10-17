<?php

declare(strict_types=1);

namespace Wezurlop\Db;

use PDO;
use PDOStatement;

/**
 * PDO profilu `data` z bezpiecznikiem DDL (CLAUDE.md §4, §16 pkt 11): aplikacja w czasie pracy
 * nie zmienia schematu, także gdy konto bazy technicznie by na to pozwoliło (tryb jednego konta).
 */
final class GuardedPdo extends PDO
{
    /** Pierwsze słowo polecenia po pominięciu białych znaków, komentarzy i nawiasów. */
    private const DDL = '~^(?:\s|/\*.*?\*/|--[^\n]*(?:\n|$)|#[^\n]*(?:\n|$)|\()*(CREATE|ALTER|DROP|TRUNCATE|RENAME|GRANT|REVOKE)\b~is';

    /** @var (callable(string): void)|null */
    private $onBlocked;

    /** @param array<int, mixed> $options */
    public function __construct(
        string $dsn,
        string $username,
        #[\SensitiveParameter] string $password,
        array $options,
        ?callable $onBlocked,
    ) {
        parent::__construct($dsn, $username, $password, $options);
        $this->onBlocked = $onBlocked;
    }

    public static function ddlKeyword(string $sql): ?string
    {
        return preg_match(self::DDL, $sql, $m) === 1 ? strtoupper($m[1]) : null;
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->guard($query);

        return parent::prepare($query, $options);
    }

    public function exec(string $statement): int|false
    {
        $this->guard($statement);

        return parent::exec($statement);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $this->guard($query);

        return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
    }

    private function guard(string $sql): void
    {
        $keyword = self::ddlKeyword($sql);
        if ($keyword === null) {
            return;
        }
        if ($this->onBlocked !== null) {
            ($this->onBlocked)($keyword);
        }
        throw new DdlBlockedException('Polecenie ' . $keyword . ' odrzucone: zmiana schematu jest dozwolona wyłącznie w migracjach');
    }
}
