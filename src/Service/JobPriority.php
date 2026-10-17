<?php

declare(strict_types=1);

namespace Wezurlop\Service;

/**
 * Priorytet zadania wynika z jego typu (docs/profile-hostingu.md): niższa liczba = pilniej.
 * Zadania pilne mogą też być domykane po odpowiedzi na zwykłe żądanie (QUEUE_ON_REQUEST).
 */
final class JobPriority
{
    /** Linki z terminem ważności — reset hasła, zaproszenie. */
    public const URGENT = 10;
    /** Powiadomienia i alerty. */
    public const NOTIFICATION = 50;
    /** Sprzątanie i kontrole cykliczne. */
    public const MAINTENANCE = 90;

    public static function forType(string $type): int
    {
        return match (true) {
            $type === AccountMailService::JOB_PASSWORD_RESET, $type === AccountMailService::JOB_INVITATION,
            // ZMIANA_CZESCIOWA: wniosek bez wpisu w kadrach, pracownik mógł już wyjechać
            $type === Sync\TaskService::JOB_HR_ALERT_URGENT => self::URGENT,
            str_starts_with($type, 'maintenance.') => self::MAINTENANCE,
            default => self::NOTIFICATION,
        };
    }
}
