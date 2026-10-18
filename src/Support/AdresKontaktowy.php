<?php

declare(strict_types=1);

namespace Wezurlop\Support;

use PDOException;

/**
 * Adres e-mail osoby: jedna reguła odmowy i jedno rozpoznanie duplikatu z bazy
 * (zlecenie Z40, 18.10.2026 — „jeden adres nie może należeć do kilku osób ani kont").
 *
 * ⚠ **Normalizacji TU NIE MA.** Robi ją `Service\AuthService::normalizeEmail()` i robiła już
 * wcześniej — na wszystkich drogach zapisu i przy każdym wyszukaniu konta (logowanie, reset
 * hasła, zaproszenie). Druga kopia `strtolower(trim(...))` w tym pliku byłaby drugim licznikiem
 * tej samej rzeczy: rozjechałyby się przy pierwszej zmianie reguły i to po cichu, bo każda
 * z osobna wygląda na poprawną.
 *
 * ⚠ Komunikat **nazywa osobę tylko wtedy, gdy administrator i tak ją widzi** na swojej liście.
 * Nazwisko osoby z firmy spoza jego zakresu jest daną osobową, do której nie ma dostępu
 * (CLAUDE.md §10) — a sama odmowa musi paść i tak, bo duplikat jest duplikatem.
 */
final class AdresKontaktowy
{
    /** Odmowa bez nazwiska — kolizja z osobą spoza zakresu administratora. */
    public const ODMOWA_BEZ_NAZWISKA = 'Ten adres ma już inna osoba.';

    /**
     * Treść odmowy albo `null`, gdy adres jest wolny.
     *
     * @param array{id: int, nazwa: string, w_zakresie: bool}|null $kolizja wynik `PersonRepository::ktoMaAdres()`
     */
    public static function odmowa(?array $kolizja): ?string
    {
        if ($kolizja === null) {
            return null;
        }

        return $kolizja['w_zakresie'] && trim($kolizja['nazwa']) !== ''
            ? 'Ten adres ma już inna osoba: ' . trim($kolizja['nazwa']) . '.'
            : self::ODMOWA_BEZ_NAZWISKA;
    }

    /**
     * Czy ten wyjątek PDO to naruszenie klucza unikalnego (duplikat), a nie awaria bazy.
     *
     * ⚠ Sprawdzamy **kod sterownika 1062**, a nie sam SQLSTATE `23000`: pod `23000` kryją się
     * także naruszenia klucza obcego i wartość `NULL` w kolumnie `NOT NULL`. Potraktowanie
     * ich jako „adres zajęty" dałoby komunikat, który kłamie o przyczynie.
     */
    public static function duplikat(PDOException $e): bool
    {
        return (string) $e->getCode() === '23000' && (int) ($e->errorInfo[1] ?? 0) === 1062;
    }
}
