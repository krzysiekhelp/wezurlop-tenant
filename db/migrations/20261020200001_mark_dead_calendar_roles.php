<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * **Etykieta „nieużywane" przy martwych rolach i uprawnieniach kalendarza**
 * (zlecenie Z67 §2, decyzja zarządcy z 20.10.2026 po znalezisku 5b z Z66).
 *
 * ⚠ **Nie kasujemy wierszy, tylko je opisujemy.** Role `calendar_viewer` i `calendar_viewer_full`
 * oraz uprawnienia `calendar.view_all` i `calendar.view_all_details` nie dają od Z53 (19.10.2026)
 * żadnego wglądu — o tym, kto widzi czyje nieobecności, decydują reguły w `calendar_access_rule`
 * (ekran `/admin/widocznosc`). Zmierzone 20.10.2026: w kodzie aplikacji nie ma ani jednego
 * czytania tych uprawnień, a przypisań ról w bazie jest zero. Usunięcie wierszy zabrałoby jednak
 * rozwinięcie etykiety starym wpisom w dzienniku zdarzeń, które wciąż mówią o nadaniu i odebraniu
 * tych ról — a dziennik ma zostać czytelny także dla zdarzeń z przeszłości.
 *
 * ⚠ **Czemu to służy, skoro katalog nie jest nigdzie wyświetlany** (sprawdzone: `role.name`
 * i `permission.name` nie są czytane przez żadne zapytanie w `src/`, nazwy ról na ekranach są
 * literałami w szablonach): odbiorcą jest ten, kto patrzy na bazę — audyt uprawnień, konsola
 * diagnostyczna, phpMyAdmin u klienta. Pułapka, którą to usuwa, jest konkretna: bez etykiety
 * ktoś przy następnym audycie nada rolę „Kalendarz spółki — pełny szczegół", zobaczy ją
 * w `role_assignment` i będzie szukał przyczyny, dla której pracownik nadal nie widzi kalendarza.
 *
 * ⚠ **Jedna zmiana = jedno wywołanie** (CLAUDE.md §4 o przestawianiu kolejności przez Phinxa),
 * a każda jest **idempotentna**: warunek `name NOT LIKE` pomija wiersz już opisany, więc
 * powtórzony przebieg nie dokleja etykiety dwa razy. `down()` zdejmuje dokładnie ten sufiks
 * i tylko jego.
 *
 * ⚠ Długości sprawdzone przed napisaniem migracji, bo `role.name` to `varchar(100)`:
 * najdłuższa etykieta ma 92 znaki (`permission.name` ma 150, tam najdłuższa 98).
 */
final class MarkDeadCalendarRoles extends AbstractMigration
{
    /** Sufiks doklejany do nazwy w katalogu — jedno miejsce, bo `up()` i `down()` muszą się zgadzać. */
    private const ETYKIETA = ' · NIEUŻYWANE od Z53 — wgląd przez reguły /admin/widocznosc';

    /** Rozpoznanie wiersza już opisanego; krótszy niż cały sufiks, żeby zadziałał też po korekcie literówki. */
    private const ZNAK = '%NIEUŻYWANE od Z53%';

    private const ROLE = ['calendar_viewer', 'calendar_viewer_full'];

    private const UPRAWNIENIA = ['calendar.view_all', 'calendar.view_all_details'];

    public function up(): void
    {
        $this->opisz('role', self::ROLE, true);
        $this->opisz('permission', self::UPRAWNIENIA, true);
    }

    public function down(): void
    {
        $this->opisz('role', self::ROLE, false);
        $this->opisz('permission', self::UPRAWNIENIA, false);
    }

    /**
     * @param 'role'|'permission' $tabela nazwa z białej listy w sygnaturze — nie z danych
     * @param list<string>        $kody
     */
    private function opisz(string $tabela, array $kody, bool $dopisz): void
    {
        $pdo = $this->getAdapter()->getConnection();
        // ⚠ Nazwa tabeli nie da się sparametryzować, więc pochodzi z białej listy w typie
        // parametru i z dwóch stałych wyżej — nigdy z danych (CLAUDE.md §4).
        $sql = $dopisz
            ? "UPDATE `{$tabela}` SET name = CONCAT(name, :etykieta)
                WHERE code = :kod AND name NOT LIKE :znak"
            : "UPDATE `{$tabela}` SET name = REPLACE(name, :etykieta, '')
                WHERE code = :kod AND name LIKE :znak";
        $stmt = $pdo->prepare($sql);
        foreach ($kody as $kod) {
            $stmt->execute([
                'etykieta' => self::ETYKIETA,
                'kod' => $kod,
                'znak' => self::ZNAK,
            ]);
        }
    }
}
