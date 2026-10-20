<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Uprawnienie do ekranu „Widoczność" (zlecenie Z53 §4: „Dostęp przez osobne uprawnienie.
 * Kod sprawdza uprawnienie, nie rolę; administrator tenanta je ma").
 *
 * ⚠ **Osobne uprawnienie, a nie `admin.hierarchy`** — choć grupy i hierarchia stoją właśnie pod
 * nim i kusi, żeby dołożyć tam trzeci ekran. Różnica jest w tym, czym te ekrany rozporządzają:
 * hierarchia i grupy układają **strukturę**, a ten ekran rozdaje **wgląd w nieobecności**, czyli
 * w dane o zdrowiu. Zlanie ich znaczyłoby, że nie da się powierzyć komuś porządkowania działów
 * bez oddania mu kalendarzy całej firmy — a to jest dokładnie ten przypadek, dla którego
 * uprawnienia są osobne od ról.
 *
 * ⚠ **`tenant_only = 0`**, bo nadanie „na firmę ALFA" ma działać: `PermissionRepository::permissionsFor()`
 * bierze przy przypisaniu do konkretnej firmy wyłącznie uprawnienia z `tenant_only = 0`.
 * Ekran i tak działa per firma (reguła należy do firmy), więc i uprawnienie musi.
 *
 * ⚠ Rola `tenant_admin` dostaje je tą samą drogą, co `admin.hierarchy` i `data.view` — wpisem
 * w migracji, nie kodem w aplikacji.
 *
 * Jedna zmiana = jedno wywołanie (CLAUDE.md §4).
 */
final class PermissionCalendarVisibility extends AbstractMigration
{
    private const KOD = 'calendar.visibility_manage';

    public function up(): void
    {
        $this->execute(
            "INSERT INTO permission (code, name, tenant_only)
             VALUES ('" . self::KOD . "', 'Widoczność kalendarza — reguły dostępu', 0)"
        );
        $this->execute(
            "INSERT INTO role_permission (role_id, permission_id)
             SELECT r.id, p.id FROM role r, permission p
             WHERE r.code = 'tenant_admin' AND p.code = '" . self::KOD . "'"
        );
    }

    public function down(): void
    {
        $this->execute(
            "DELETE rp FROM role_permission rp JOIN permission p ON p.id = rp.permission_id
             WHERE p.code = '" . self::KOD . "'"
        );
        $this->execute("DELETE FROM permission WHERE code = '" . self::KOD . "'");
    }
}
