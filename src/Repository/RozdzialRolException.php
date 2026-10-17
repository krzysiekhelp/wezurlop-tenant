<?php

declare(strict_types=1);

namespace Wezurlop\Repository;

use RuntimeException;

/**
 * Próba nadania roli, która łamie rozdział „administrator tenanta ≠ pracownik”
 * (`docs/etap3-logika-urlopowa.md` §8c).
 *
 * ⚠ To **nie jest** błąd w kodzie, tylko odmowa na wejściu człowieka — dlatego wyjątek, a nie
 * cichy `return`: każda z czterech dróg nadania roli (`AccessService`, `InvitationService`,
 * `CalendarVisibility`, `dev/utworz-administratora.php`) musi tę odmowę ZOBACZYĆ i opisać
 * po swojemu. Ciche pominięcie dałoby ekran mówiący „zapisano” przy niezapisanym dostępie.
 *
 * ⚠ Wyjątek leży przy repozytorium, nie w `Service`, bo warunek stoi **w `assignRole()`** —
 * tam, gdzie rola staje się przypisaniem. Ta sama konwencja co `Db\DdlBlockedException`:
 * wyjątek mieszka przy warstwie, która go rzuca.
 */
final class RozdzialRolException extends RuntimeException
{
    /** Kod powodu do przekazania interfejsowi — nigdy treść komunikatu (ta stoi w warstwie widoku). */
    public const POWOD_ADMIN_Z_ETATEM = 'admin_z_etatem';

    public const POWOD_PRACOWNIK_ADMINA = 'pracownik_admina';

    public function __construct(public readonly string $powod, string $message)
    {
        parent::__construct($message);
    }
}
