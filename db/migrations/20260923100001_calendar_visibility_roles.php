<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Trzy poziomy widoczności cudzych kalendarzy, nadawane **jawnie**
 * (`docs/etap3-logika-urlopowa.md` §8b, decyzja Krzysztofa 23.09.2026).
 *
 * ```
 * poziom 0 — nie widzi        brak uprawnień (DOMYŚLNY)
 * poziom 1 — rodzaj ogólny    calendar.view_all
 * poziom 2 — całość           calendar.view_all + calendar.view_all_details
 * ```
 *
 * ⚠ **Ta migracja NIKOMU NICZEGO NIE NADAJE** — także istniejącym administratorom. Nadanie jest
 * czynnością człowieka, z wpisem w dzienniku zdarzeń. Powód: „administrator tenanta" to rola
 * **techniczna** (konta, hierarchia, klucze integracji). U jednego klienta jest nią właściciel,
 * u drugiego informatyk z zewnątrz albo firma obsługująca serwer. Gdyby dostęp do nieobecności
 * wynikał z samej roli, u tego drugiego **nie dałoby się go odebrać bez zmiany kodu**.
 *
 * ⚠ **`tenant_only = 0` na obu uprawnieniach i to jest istotne.** `PermissionRepository::permissionsFor()`
 * przy przypisaniu na konkretną spółkę bierze tylko uprawnienia z `tenant_only = 0`; przy `1`
 * nadanie „na spółkę ALFA" nie dawałoby niczego, a działałoby wyłącznie nadanie na całego
 * tenanta. Widoczność kalendarza ma się dać nadać **na jedną spółkę**, więc `0`.
 *
 * ⚠ **Rola „full" ma OBA kody.** Gdyby miała tylko `..._details`, powstałby stan „szczegół bez
 * podstawy", czyli czwarta możliwość w trójstanowym wyborze — a poziom ma być jedną liczbą
 * wyliczaną w jednym miejscu (`CalendarVisibility::poziom()`).
 *
 * ⚠ **`requires_2fa = 1` na roli `calendar_viewer_full`** (decyzja Krzysztofa): poziom 2 otwiera
 * nazwy rodzajów nieobecności całej załogi, czyli dane o zdrowiu. To dokładnie ten przypadek,
 * dla którego ta kolumna powstała. Rola „ogólna" zostaje bez wymogu — sam fakt nieobecności
 * nie jest daną o zdrowiu.
 *
 * ⚠ **Jedna zmiana = jedno wywołanie** (`CLAUDE.md` §4): Phinx potrafi przestawić operacje
 * zebrane razem, a wtedy `role_permission` trafiłoby na nieistniejący jeszcze wiersz roli
 * i migracja przerwałaby się w połowie, zostawiając bazę w stanie pośrednim.
 */
final class CalendarVisibilityRoles extends AbstractMigration
{
    private const WIDOK = 'calendar.view_all';
    private const SZCZEGOL = 'calendar.view_all_details';
    private const ROLA_OGOLNA = 'calendar_viewer';
    private const ROLA_PELNA = 'calendar_viewer_full';

    public function up(): void
    {
        $this->execute(
            "INSERT INTO permission (code, name, tenant_only)
             VALUES ('" . self::WIDOK . "', 'Kalendarz całej spółki', 0)"
        );
        $this->execute(
            "INSERT INTO permission (code, name, tenant_only)
             VALUES ('" . self::SZCZEGOL . "', 'Kalendarz całej spółki — nazwy rodzajów', 0)"
        );
        $this->execute(
            "INSERT INTO role (code, name, is_system, requires_2fa)
             VALUES ('" . self::ROLA_OGOLNA . "', 'Kalendarz spółki — rodzaj ogólny', 1, 0)"
        );
        $this->execute(
            "INSERT INTO role (code, name, is_system, requires_2fa)
             VALUES ('" . self::ROLA_PELNA . "', 'Kalendarz spółki — pełny szczegół', 1, 1)"
        );
        $this->execute(
            "INSERT INTO role_permission (role_id, permission_id)
             SELECT r.id, p.id FROM role r, permission p
             WHERE r.code = '" . self::ROLA_OGOLNA . "' AND p.code = '" . self::WIDOK . "'"
        );
        // Rola „pełna" dostaje podstawę…
        $this->execute(
            "INSERT INTO role_permission (role_id, permission_id)
             SELECT r.id, p.id FROM role r, permission p
             WHERE r.code = '" . self::ROLA_PELNA . "' AND p.code = '" . self::WIDOK . "'"
        );
        // …i szczegół, żeby samo jej przypisanie wystarczało.
        $this->execute(
            "INSERT INTO role_permission (role_id, permission_id)
             SELECT r.id, p.id FROM role r, permission p
             WHERE r.code = '" . self::ROLA_PELNA . "' AND p.code = '" . self::SZCZEGOL . "'"
        );
    }

    public function down(): void
    {
        // ⚠ Najpierw przypisania kont, potem powiązania ról z uprawnieniami, na końcu słowniki.
        // Odwrotna kolejność zostawiłaby wiersze `role_assignment` wskazujące na skasowaną rolę.
        $this->execute(
            "DELETE ra FROM role_assignment ra JOIN role r ON r.id = ra.role_id
             WHERE r.code IN ('" . self::ROLA_OGOLNA . "', '" . self::ROLA_PELNA . "')"
        );
        $this->execute(
            "DELETE rp FROM role_permission rp JOIN role r ON r.id = rp.role_id
             WHERE r.code IN ('" . self::ROLA_OGOLNA . "', '" . self::ROLA_PELNA . "')"
        );
        $this->execute(
            "DELETE FROM role WHERE code IN ('" . self::ROLA_OGOLNA . "', '" . self::ROLA_PELNA . "')"
        );
        $this->execute(
            "DELETE rp FROM role_permission rp JOIN permission p ON p.id = rp.permission_id
             WHERE p.code IN ('" . self::WIDOK . "', '" . self::SZCZEGOL . "')"
        );
        $this->execute(
            "DELETE FROM permission WHERE code IN ('" . self::WIDOK . "', '" . self::SZCZEGOL . "')"
        );
    }
}
