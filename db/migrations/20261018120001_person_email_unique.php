<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Jeden adres e-mail = jedna osoba (zlecenie Z40, 18.10.2026).
 *
 * ⚠ **Powód jest bezpieczeństwa, nie porządku**: logujemy się adresem e-mail. `account.email`
 * ma `UNIQUE` od pierwszej migracji, ale `person.email_private` nie miał **żadnego** indeksu —
 * a to właśnie ten adres staje się loginem w dniu zaproszenia
 * (`InvitationService::inviteExisting()`). Dwie osoby z jednym adresem w kartotece to zaproszenie,
 * którego dla drugiej z nich nie da się wysłać, i nikt nie wie dlaczego.
 *
 * ⚠ **Indeks na kolumnie, a nie na kolumnie wyliczanej.** Kolacja `utf8mb4_unicode_ci` nie
 * rozróżnia wielkości liter ani ogona spacji (zmierzone: `'abc@x.pl' = 'ABC@x.pl '` → 1),
 * a aplikacja i tak normalizuje adres przed każdym zapisem i każdym wyszukaniem
 * (`AuthService::normalizeEmail()` — `mb_strtolower(trim())`). Kolumna wyliczana dokładałaby
 * wymaganie wobec wersji silnika (generated columns) po to, żeby objąć jedyny przypadek,
 * którego kolacja nie łapie — spację NA POCZĄTKU adresu, czyli coś, czego żadna droga zapisu
 * nie przepuszcza. To samo rozwiązanie, które od początku działa na `account.email`.
 *
 * ⚠ **`NULL` zostaje poza indeksem i tak ma być**: brak adresu to brak wartości, a osób bez
 * adresu są dziesiątki. To ten sam świadomy wyjątek od CLAUDE.md §4, co w migracji
 * `20260921140001` — pusty napis byłby wartością, więc dopuściłby dokładnie jedną taką osobę.
 *
 * ⚠ **Migracja NIE poprawia duplikatów sama.** Scalenie dwóch kartotek albo odebranie komuś
 * adresu to decyzja o danych kadrowych, nie zmiana schematu — przy duplikatach zatrzymuje się
 * z listą identyfikatorów do rozstrzygnięcia (polecenie Krzysztofa w zleceniu Z40).
 *
 * Zmierzone przed wydaniem: baza robocza 0 grup duplikatów (8 osób z adresem, 80 bez),
 * baza testowa 0 grup (0 z adresem, 1006 bez).
 *
 * Jedna zmiana = jedno wywołanie (CLAUDE.md §4).
 */
final class PersonEmailUnique extends AbstractMigration
{
    public function up(): void
    {
        $duplikaty = $this->fetchAll(
            "SELECT LOWER(TRIM(email_private)) AS adres, COUNT(*) AS ile,
                    GROUP_CONCAT(id ORDER BY id) AS identyfikatory
               FROM person
              WHERE email_private IS NOT NULL AND email_private <> ''
              GROUP BY adres HAVING ile > 1"
        );
        if ($duplikaty !== []) {
            $opis = [];
            foreach ($duplikaty as $d) {
                // ⚠ Same identyfikatory — adres e-mail jest daną osobową i nie trafia do logu
                // ani do komunikatu migracji (CLAUDE.md §14, §18).
                $opis[] = $d['ile'] . ' osoby o id ' . $d['identyfikatory'];
            }
            throw new RuntimeException(
                'Nie mogę założyć klucza unikalnego na person.email_private: duplikaty w bazie ('
                . implode('; ', $opis) . '). Rozstrzygnij, która osoba zostaje przy adresie, '
                . 'i wyczyść pozostałe — migracja nie wybierze tego za nikogo.'
            );
        }
        $this->execute('ALTER TABLE person ADD UNIQUE INDEX email_private (email_private)');
    }

    public function down(): void
    {
        $this->execute('ALTER TABLE person DROP INDEX email_private');
    }
}
