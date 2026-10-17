<?php

declare(strict_types=1);

namespace Wezurlop\Console;

use Wezurlop\Service\CronHeartbeat;
use Wezurlop\Support\Logger;
use Wezurlop\Support\Services;

/**
 * bin/run-jobs.php — cron co 5 minut. Budżet czasu poniżej limitu 30 s (CLAUDE.md §0).
 */
final class RunJobsCommand
{
    /** ~25 s z 30 (docs/profile-hostingu.md) — resztę dostaje następny przebieg. */
    public const BUDGET_SECONDS = 25.0;

    /** @param resource $out */
    public function __construct(private $out)
    {
    }

    public function __invoke(Services $services, Logger $logger): int
    {
        $runner = $services->jobRunner($logger);
        $stats = $runner->run(self::BUDGET_SECONDS);
        (new CronHeartbeat($services->config->varPath))->touch();
        fwrite($this->out, sprintf(
            "run-jobs: wykonane %d, ponowienia %d, nieudane %d, pominięte %d\n",
            $stats['done'],
            $stats['retried'],
            $stats['failed'],
            $stats['skipped'],
        ));

        return $stats['failed'] > 0 ? 1 : 0;
    }
}
