<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Uprawnienie `admin.hierarchy` — ekran hierarchii przełożonych (zlecenie `hierarchia` §6.3).
 *
 * ⚠ **Dlaczego osobne uprawnienie, a nie `admin.users`.** Ten ekran nie zmienia kont ani
 * dostępów — zmienia **kto oglądać będzie czyje dane kadrowe** i przez kogo pójdzie wniosek
 * urlopowy. To inna powinność niż zakładanie kont i liczenie licencji, a klient może chcieć
 * powierzyć strukturę kadrom, nie dając im zarządzania kontami. Zlecenie rozdziela te dwa
 * ekrany wprost (§6.6: „ten ekran ustawia strukturę, ekran «Konta» ustawia uprawnienia").
 *
 * ⚠ Pozycja w menu i trasa dostają **to samo** uprawnienie — inaczej menu prowadzi do 403
 * (albo, gorzej, kryje ekran, do którego dostęp jest).
 *
 * `tenant_only = 1`: to jest uprawnienie wewnątrz instalacji klienta, nie w naszym panelu.
 */
final class PermissionAdminHierarchy extends AbstractMigration
{
    private const KOD = 'admin.hierarchy';

    public function up(): void
    {
        $this->execute(
            'INSERT INTO permission (code, name, tenant_only) VALUES '
            . "('" . self::KOD . "', 'Hierarchia przełożonych', 1)"
        );
        // ⚠ Nadane wyłącznie administratorowi tenanta. Kadry dostaną je decyzją klienta
        // na ekranie uprawnień — domyślnie odmawiamy (`CLAUDE.md` §10).
        $this->execute(
            'INSERT INTO role_permission (role_id, permission_id)
             SELECT r.id, p.id FROM role r, permission p
             WHERE r.code = \'tenant_admin\' AND p.code = \'' . self::KOD . "'"
        );
    }

    public function down(): void
    {
        $this->execute(
            'DELETE rp FROM role_permission rp JOIN permission p ON p.id = rp.permission_id
             WHERE p.code = \'' . self::KOD . "'"
        );
        $this->execute('DELETE FROM permission WHERE code = \'' . self::KOD . "'");
    }
}
