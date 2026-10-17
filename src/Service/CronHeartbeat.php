<?php

declare(strict_types=1);

namespace Wezurlop\Service;

/**
 * Znacznik ostatniego przebiegu crona (var/cron-heartbeat). Deklaracja z instalatora („cron co 5 minut”)
 * nie jest potwierdzeniem — potwierdza go dopiero przebieg. Dopóki go nie ma albo jest nieświeży,
 * kolejka pilnych zadań domyka się także po zwykłych żądaniach (CLAUDE.md §0, QUEUE_ON_REQUEST):
 * link resetu hasła nie może czekać na cron, który nie działa.
 */
final class CronHeartbeat
{
    /** Najkrótszy próg nieświeżości — nierówny start crona i dłuższy przebieg nie mogą go przełączać. */
    private const MIN_STALE_MINUTES = 20;

    public function __construct(private readonly string $varPath)
    {
    }

    public function touch(): void
    {
        touch($this->file());
    }

    /** Minuty od ostatniego przebiegu; null = cron jeszcze nigdy nie przeszedł. */
    public function ageMinutes(): ?int
    {
        clearstatcache(true, $this->file());
        $mtime = is_file($this->file()) ? filemtime($this->file()) : false;

        return $mtime === false ? null : intdiv(max(0, time() - $mtime), 60);
    }

    /**
     * Czas ostatniego przebiegu w UTC (`Y-m-d H:i:s`) albo `null`, gdy cron nigdy nie przeszedł.
     *
     * ⚠ Ten sam znacznik, z którego liczy się „ile temu" — jedno źródło, dwie formy. Ekrany
     * pokazują OBIE: „ile temu" czyta się szybciej, ale tylko czas bezwzględny da się zestawić
     * z logiem, z dziennikiem i z tym, co klient mówi przez telefon.
     */
    public function ostatniUtc(): ?string
    {
        clearstatcache(true, $this->file());
        $mtime = is_file($this->file()) ? filemtime($this->file()) : false;

        return $mtime === false ? null : gmdate('Y-m-d H:i:s', $mtime);
    }

    /**
     * Czy czynny jest **drugi wyzwalacz kolejki** (zadania pilne domykane po odpowiedzi).
     *
     * ⚠ Jedna reguła dla `Kernel` i dla ekranów. Zmierzone 22.09.2026: ekran czytał samą
     * flagę instalatora (`QUEUE_ON_REQUEST=0`) i pisał „nic z tej kolejki nie wyjdzie",
     * a `Kernel` włączał wyzwalacz **także przy niepotwierdzonym cronie** — wiadomość wyszła
     * sekundę po komunikacie, który to wykluczał. Dwa odczyty jednej decyzji zawsze się
     * rozejdą; tu rozeszły się w treści pokazanej człowiekowi.
     */
    public static function kolejkaPoZadaniu(bool $flagaInstalatora, bool $cronPotwierdzony): bool
    {
        return $flagaInstalatora || !$cronPotwierdzony;
    }

    /** Cron potwierdzony: przeszedł w ciągu trzech zadeklarowanych interwałów (co najmniej 20 minut). */
    public function confirmed(int $cronMinutes): bool
    {
        $age = $this->ageMinutes();

        return $cronMinutes > 0 && $age !== null && $age <= max(3 * $cronMinutes, self::MIN_STALE_MINUTES);
    }

    private function file(): string
    {
        return $this->varPath . '/cron-heartbeat';
    }
}
