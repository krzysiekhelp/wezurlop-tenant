<?php

declare(strict_types=1);

namespace Wezurlop\Support;

/**
 * Cisza klucza instalacji: od ilu godzin connector się nie odzywa.
 *
 * ⚠ To jest realny tryb awarii, nie hipoteza: klucz nie musi wygasnąć, żeby synchronizacja umarła.
 * Wystarczy wyłączony serwer, zmiana hasła do programu kadrowego albo zablokowany port — klucz
 * jest aktywny, a danych nie ma. Bez tego ekran pokazuje „ostatnie użycie 3 dni temu” i milczy.
 *
 * Strefa jawna, funkcja czysta — bez bazy i bez plików. Znaczniki wejściowe są w UTC (jak w bazie).
 */
final class Cisza
{
    /**
     * Od czego liczymy ciszę: ostatnie użycie, a gdy klucza nigdy nie użyto — moment wydania.
     * Klucz wydany trzy dni temu i nigdy nieużyty to ta sama awaria co klucz, który zamilkł.
     */
    public static function odniesienie(?string $lastUsedAt, string $createdAt): string
    {
        return $lastUsedAt !== null && $lastUsedAt !== '' ? $lastUsedAt : $createdAt;
    }

    /** Pełne godziny od podanego znacznika UTC. Wartość nieparsowalna daje 0 — nie alarmujemy na błędzie. */
    public static function godziny(string $utc, ?int $teraz = null): int
    {
        $moment = strtotime($utc . ' UTC');
        if ($moment === false) {
            return 0;
        }

        return (int) max(0, floor((($teraz ?? time()) - $moment) / 3600));
    }

    public static function przekroczona(?string $lastUsedAt, string $createdAt, int $progGodzin, ?int $teraz = null): bool
    {
        return $progGodzin > 0 && self::godziny(self::odniesienie($lastUsedAt, $createdAt), $teraz) >= $progGodzin;
    }
}
