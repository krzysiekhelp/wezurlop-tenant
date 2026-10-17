<?php

declare(strict_types=1);

namespace Wezurlop\Db;

use RuntimeException;

/** Próba DDL z profilu `data` — błąd w kodzie aplikacji, nigdy stan oczekiwany. */
final class DdlBlockedException extends RuntimeException
{
}
