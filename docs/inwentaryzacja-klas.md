# Inwentaryzacja klas CSS — co żyje, co jest sierotą, co jest martwe

Stan na **21.09.2026**. Zlecenia: `2026-09-21-sierota-po-migracji.md` §3.2 oraz
`2026-09-21-pary-na-defs-i-narzedzia.md` §1.2.
⚠ Dane produkuje **`bin/inwentarz-klas.php`** — nie ręcznie i nie jednorazowo.
Pełna tabela: **`docs/inwentarz-klas.tsv`**, odtwarzana jednym przebiegiem.

⚠ **Po co to jest.** Migracja wariantu mobilnego na nowy system wizualny odsłoniła, że nowy system
pokrywa powłokę i rdzeń komponentów, a **reszta ekranów stała na starym arkuszu, który wciąż się
wczytywał na komputerze**. Dopóki `tokens.css`/`base.css`/`components.css` lecą obok nowych,
braku nie widać. Ta tabela mówi, ile go jest.

## Liczby

| miara | wartość |
|---|---|
| klas łącznie (użycia ∪ reguły) | **284** |
| `BRAK_REGUL` — w szablonie albo w JS, bez reguły gdziekolwiek | **9** |
| `TYLKO_STARY` — praca przed skasowaniem starych arkuszy | **63** |
| `TYLKO_NOWY` | 122 |
| `OBA` | 32 |
| `MARTWA_REGULA` — reguła bez użycia | 56 |
| `PREFIKS_Z_DANYCH` — klasa sklejana w szablonie, prefiks ma pokrycie | 2 |

Stary system definiuje **145** klas, nowy **160**.

⚠ **Ruch od pierwszego pomiaru tego dnia:** `TYLKO_STARY` spadło **79 → 63**, a `MARTWA_REGULA`
wzrosła **38 → 56**. To ta sama zmiana widziana z dwóch stron: przemianowania (`badge` → `chip`,
`data-table` → `table`, `card--pad` → `card__body`, `box` → `notice`) i przejście par
na `.defs` przeniosły klasy ze „starego, używanego" do „starego, nieużywanego".
⚠ **Martwa reguła nie blokuje skasowania arkusza — używana blokuje.** Dlatego bramka patrzy
wyłącznie na `TYLKO_STARY`.

⚠ **`PREFIKS_Z_DANYCH`** to klasa sklejana w szablonie (`class="sg-swatch--{{ x }}"`). Narzędzie
sprawdza, czy **jakakolwiek** istniejąca klasa zaczyna się tym prefiksem — jeśli nie, zgłasza
`BRAK_REGUL`. Tak właśnie powstawało `badge--approved`: szablon generował nazwę, której system
nie znał, i nic tego nie zgłaszało.

⚠ **„Ekran" liczony przez graf `include`/`extends`, nie przez położenie pliku.** Potwierdzone
czytaniem: `page/mobile/*.twig` dołącza **te same** `partials/page/*.twig`, co `page/web/*.twig` —
wariant komputerowy dokłada wokół nich tylko `<section class="page-header">`. Dlatego klasa
z `partials/page/calendar.twig` liczy się do dwóch ekranów, nie do jednego pliku.
Ekrany uwierzytelnienia (`page/auth/*`) robią `{% extends layout_auth %}` — zmienną z białej listy
w `TwigFactory::LAYOUTS` — więc są **wspólne dla obu wariantów**, nie komputerowe.

---

## 1. ⚠ `BRAK_REGUL` — usterki istniejące DZIŚ, w obu wariantach

To nie jest dług po migracji. Te klasy nie mają reguły w **żadnym** arkuszu, więc nie działają
także na komputerze — po prostu nikt tego nie zauważył, bo to klasy szkieletowe.

| klasa | reguła w | ekranów | użyć w szablonach | wariant | gdzie |
|---|---|---|---|---|---|
| `.app-body` | — | 21 | 3 | oba | `layout/auth-mobile.twig`, `layout/error.twig`, `layout/mobile.twig` |
| `.app-web` | — | 25 | 1 | komputerowy | `layout/web.twig` |
| `.devices` | — | 2 | 1 | oba | `fragments/devices.twig` |
| `.nav` | — | 25 | 1 | komputerowy | `partials/nav-web.twig` |
| `.page-auth` | — | 8 | 1 | oba | `layout/auth.twig` |
| `.page-error` | — | 0 | 1 | komputerowy | `layout/error.twig` |
| `.has-native-bridge` | — | 0 | 0 | js | `JS:public/assets/js/modules/bridge.js` |
| `.js` | — | 0 | 0 | js | `JS:public/assets/js/app-mobile.js`, `JS:public/assets/js/app-web.js` |
| `.sidebar-open` | — | 0 | 0 | js | `JS:public/assets/js/modules/ui.js` |

⚠ **Trzy ostatnie to haki JS bez odpowiadającego stylu**, i jeden z nich jest podejrzany:
`modules/ui.js` przełącza `sidebar-open`, żeby otworzyć pasek boczny, a **CSS nie obsługuje tej
klasy w ogóle**. Czyli albo przycisk nic nie robi, albo to martwy kod po przepisaniu powłoki.
Do sprawdzenia osobno — to jest funkcja, nie wygląd.

---

## 2. `TYLKO_STARY` — lista pracy przed skasowaniem starego systemu

Odpowiedź na pytanie otwarte nr 2 z poprzedniego raportu: **tyle** trzeba przenieść, zanim
`tokens.css`, `base.css` i `components.css` wyjdą z `head.twig` na dobre.

| klasa | reguła w | ekranów | użyć w szablonach | wariant | gdzie |
|---|---|---|---|---|---|
| `.helper` | base.css | 5 | 13 | oba | `page/install/installer.twig`, `partials/install/step-1.twig`, `partials/install/step-10.twig` +4 |
| `.control` | base.css | 7 | 8 | oba | `partials/install/step-3.twig`, `partials/install/step-4.twig`, `partials/install/step-5.twig` +4 |
| `.actions` | components.css | 7 | 6 | oba | `page/web/admin-accounts.twig`, `page/web/data-calendar.twig`, `page/web/data-companies.twig` +3 |
| `.field--check` | base.css | 5 | 5 | oba | `partials/install/step-10.twig`, `partials/install/step-4.twig`, `partials/install/step-7.twig` +2 |
| `.grid-2` | components.css | 3 | 5 | oba | `partials/install/step-4.twig`, `partials/install/step-5.twig`, `partials/install/step-6.twig` +2 |
| `.event-id` | components.css | 4 | 3 | oba | `partials/error-message.twig`, `partials/page/license-limited.twig`, `partials/page/styleguide.twig` |
| `.backup-code` | components.css | 4 | 2 | oba | `partials/page/styleguide.twig`, `partials/page/two-factor-codes.twig` |
| `.backup-grid` | components.css | 4 | 2 | oba | `partials/page/styleguide.twig`, `partials/page/two-factor-codes.twig` |
| `.card__hint` | components.css | 2 | 2 | komputerowy | `page/web/data-calendar.twig`, `page/web/data-sync-run.twig` |
| `.control--code` | base.css | 4 | 2 | oba | `partials/page/styleguide.twig`, `partials/page/two-factor.twig` |
| `.devices__item` | components.css | 4 | 2 | oba | `fragments/devices.twig`, `partials/page/styleguide.twig` |
| `.devices__item--current` | components.css | 4 | 2 | oba | `fragments/devices.twig`, `partials/page/styleguide.twig` |
| `.devices__list` | components.css | 4 | 2 | oba | `fragments/devices.twig`, `partials/page/styleguide.twig` |
| `.devices__meta` | components.css | 4 | 2 | oba | `fragments/devices.twig`, `partials/page/styleguide.twig` |
| `.devices__name` | components.css | 4 | 2 | oba | `fragments/devices.twig`, `partials/page/styleguide.twig` |
| `.link-button` | base.css | 3 | 2 | oba | `page/auth/two-factor.twig`, `partials/page/styleguide.twig` |
| `.muted` | components.css | 4 | 2 | oba | `partials/diagnostics-table.twig`, `partials/page/styleguide.twig` |
| `.step-dot` | components.css | 3 | 2 | oba | `page/install/installer.twig`, `partials/page/styleguide.twig` |
| `.step-dot--active` | components.css | 3 | 2 | oba | `page/install/installer.twig`, `partials/page/styleguide.twig` |
| `.step-dot--done` | components.css | 3 | 2 | oba | `page/install/installer.twig`, `partials/page/styleguide.twig` |
| `.stepper` | components.css | 3 | 2 | oba | `page/install/installer.twig`, `partials/page/styleguide.twig` |
| `.textarea` | base.css | 4 | 2 | oba | `partials/page/new-request.twig`, `partials/page/styleguide.twig` |
| `.company-mark` | components.css | 2 | 1 | oba | `partials/page/context.twig` |
| `.context-copy` | components.css | 2 | 1 | oba | `partials/page/context.twig` |
| `.context-list` | components.css | 2 | 1 | oba | `partials/page/context.twig` |
| `.context-option` | components.css | 2 | 1 | oba | `partials/page/context.twig` |
| `.context-option--current` | components.css | 2 | 1 | oba | `partials/page/context.twig` |
| `.control--backup` | base.css | 2 | 1 | oba | `partials/page/styleguide.twig` |
| `.devices__actions` | components.css | 2 | 1 | oba | `fragments/devices.twig` |
| `.diag-group` | components.css | 2 | 1 | komputerowy | `partials/diagnostics-table.twig` |
| `.field--error` | base.css | 2 | 1 | oba | `partials/page/styleguide.twig` |
| `.field__error` | base.css | 2 | 1 | oba | `partials/page/styleguide.twig` |
| `.highlight` | components.css | 2 | 1 | oba | `partials/page/styleguide.twig` |
| `.input-wrap` | base.css | 2 | 1 | oba | `partials/page/styleguide.twig` |
| `.installer-body` | components.css | 1 | 1 | komputerowy | `page/install/installer.twig` |
| `.installer-brand` | components.css | 1 | 1 | komputerowy | `layout/install.twig` |
| `.installer-card` | components.css | 1 | 1 | komputerowy | `layout/install.twig` |
| `.installer-footer` | components.css | 1 | 1 | komputerowy | `page/install/installer.twig` |
| `.installer-frame` | components.css | 1 | 1 | komputerowy | `layout/install.twig` |
| `.installer-shell` | components.css | 1 | 1 | komputerowy | `layout/install.twig` |
| `.installer-top` | components.css | 1 | 1 | komputerowy | `page/install/installer.twig` |
| `.month-grid` | components.css | 1 | 1 | komputerowy | `page/web/data-calendar.twig` |
| `.month-grid__cell` | components.css | 1 | 1 | komputerowy | `page/web/data-calendar.twig` |
| `.month-grid__cell--absence` | components.css | 1 | 1 | komputerowy | `page/web/data-calendar.twig` |
| `.month-grid__cell--empty` | components.css | 1 | 1 | komputerowy | `page/web/data-calendar.twig` |
| `.month-grid__cell--free` | components.css | 1 | 1 | komputerowy | `page/web/data-calendar.twig` |
| `.month-grid__day` | components.css | 1 | 1 | komputerowy | `page/web/data-calendar.twig` |
| `.month-grid__mark` | components.css | 1 | 1 | komputerowy | `page/web/data-calendar.twig` |
| `.pager` | components.css | 1 | 1 | komputerowy | `page/web/data-sync-run.twig` |
| `.pager__info` | components.css | 1 | 1 | komputerowy | `page/web/data-sync-run.twig` |
| `.progress` | components.css | 2 | 1 | oba | `partials/page/styleguide.twig` |
| `.progress--warning` | components.css | 2 | 1 | oba | `partials/page/styleguide.twig` |
| `.progress-line` | components.css | 2 | 1 | oba | `partials/page/styleguide.twig` |
| `.qr-card` | components.css | 2 | 1 | oba | `partials/page/two-factor.twig` |
| `.sg-flash-demo` | components.css | 2 | 1 | oba | `partials/page/styleguide.twig` |
| `.sg-row` | components.css | 2 | 1 | oba | `partials/page/styleguide.twig` |
| `.sg-section` | components.css | 2 | 1 | oba | `partials/page/styleguide.twig` |
| `.sg-swatch` | components.css | 2 | 1 | oba | `partials/page/styleguide.twig` |
| `.sg-swatch--bg` | components.css | 2 | 1 | oba | `partials/page/styleguide.twig` |
| `.sg-swatch--blue` | components.css | 2 | 1 | oba | `partials/page/styleguide.twig` |
| `.sg-swatch--blue-soft` | components.css | 2 | 1 | oba | `partials/page/styleguide.twig` |
| `.sg-swatch--danger` | components.css | 2 | 1 | oba | `partials/page/styleguide.twig` |
| `.sg-swatch--green` | components.css | 2 | 1 | oba | `partials/page/styleguide.twig` |
| `.sg-swatch--green-soft` | components.css | 2 | 1 | oba | `partials/page/styleguide.twig` |
| `.sg-swatch--line` | components.css | 2 | 1 | oba | `partials/page/styleguide.twig` |
| `.sg-swatch--muted` | components.css | 2 | 1 | oba | `partials/page/styleguide.twig` |
| `.sg-swatch--navy` | components.css | 2 | 1 | oba | `partials/page/styleguide.twig` |
| `.sg-swatch--orange` | components.css | 2 | 1 | oba | `partials/page/styleguide.twig` |
| `.sg-swatch--orange-soft` | components.css | 2 | 1 | oba | `partials/page/styleguide.twig` |
| `.sg-swatch--pink` | components.css | 2 | 1 | oba | `partials/page/styleguide.twig` |
| `.sg-swatch--pink-soft` | components.css | 2 | 1 | oba | `partials/page/styleguide.twig` |
| `.sg-swatch--surface` | components.css | 2 | 1 | oba | `partials/page/styleguide.twig` |
| `.sg-swatch--text` | components.css | 2 | 1 | oba | `partials/page/styleguide.twig` |
| `.sg-swatches` | components.css | 2 | 1 | oba | `partials/page/styleguide.twig` |
| `.spinner` | components.css | 2 | 1 | oba | `partials/page/styleguide.twig` |
| `.status-brand` | components.css | 0 | 1 | komputerowy | `layout/error.twig` |
| `.status-card` | components.css | 0 | 1 | komputerowy | `layout/error.twig` |
| `.status-code` | components.css | 0 | 1 | komputerowy | `layout/error.twig` |
| `.status-layout` | components.css | 0 | 1 | komputerowy | `layout/error.twig` |

⚠ **Wśród nich są `.status-*` i `.installer-*`** — dokładnie te, dla których w `head.twig` stoi
wyjątek `ekran_systemowy`. Dopóki są na tej liście, wyjątek zostaje.

⚠ **`.sg-*` (16 pozycji) to styleguide**, ekran wyłącznie deweloperski (`APP_ENV=dev`). Nie blokuje
paczki dla klienta i można go przenosić na końcu — ale wtedy trzeba o nim pamiętać, bo styleguide
bez reguł przestaje pokazywać to, do czego służy.

---

## 3. `MARTWA_REGULA` — reguły bez użycia

| klasa | reguła w | ekranów | użyć w szablonach | wariant | gdzie |
|---|---|---|---|---|---|
| `.badge` | components.css | 0 | 0 | - | `` |
| `.badge--approved` | components.css | 0 | 0 | - | `` |
| `.badge--current` | components.css | 0 | 0 | - | `` |
| `.badge--danger` | components.css | 0 | 0 | - | `` |
| `.badge--fail` | components.css | 0 | 0 | - | `` |
| `.badge--info` | components.css | 0 | 0 | - | `` |
| `.badge--ok` | components.css | 0 | 0 | - | `` |
| `.badge--pending` | components.css | 0 | 0 | - | `` |
| `.badge--warn` | components.css | 0 | 0 | - | `` |
| `.bg-blue` | components.css | 0 | 0 | - | `` |
| `.bg-green` | components.css | 0 | 0 | - | `` |
| `.bg-orange` | components.css | 0 | 0 | - | `` |
| `.box` | components.css | 0 | 0 | - | `` |
| `.box--error` | components.css | 0 | 0 | - | `` |
| `.box--info` | components.css | 0 | 0 | - | `` |
| `.box--success` | components.css | 0 | 0 | - | `` |
| `.box--warning` | components.css | 0 | 0 | - | `` |
| `.brand` | 20-layout.css | 0 | 0 | - | `` |
| `.btn--secondary` | base.css | 0 | 0 | - | `` |
| `.btn--small` | base.css | 0 | 0 | - | `` |
| `.calendar-event` | components.css | 0 | 0 | - | `` |
| `.calendar-event--leave` | components.css | 0 | 0 | - | `` |
| `.calendar-event--mine` | components.css | 0 | 0 | - | `` |
| `.card--pad` | components.css | 0 | 0 | - | `` |
| `.card__body--tight` | 30-components.css | 0 | 0 | - | `` |
| `.data-table` | components.css | 0 | 0 | - | `` |
| `.error--fragment` | components.css | 0 | 0 | - | `` |
| `.error-message` | components.css | 0 | 0 | - | `` |
| `.hero-card` | components.css | 0 | 0 | - | `` |
| `.label` | base.css | 0 | 0 | - | `` |
| `.metric-card` | components.css | 0 | 0 | - | `` |
| `.metric-copy` | components.css | 0 | 0 | - | `` |
| `.metric-icon` | components.css | 0 | 0 | - | `` |
| `.metrics` | components.css | 0 | 0 | - | `` |
| `.notice--warning` | components.css | 0 | 0 | - | `` |
| `.sg-row--top` | components.css | 0 | 0 | - | `` |
| `.stack--sm` | 30-components.css | 0 | 0 | - | `` |
| `.table-wrap` | components.css | 0 | 0 | - | `` |

⚠ Część z nich zmartwiała **właśnie teraz**: `badge*`, `box*`, `btn--small`, `btn--secondary`,
`bg-*`, `metric*`, `data-table`, `table-wrap`, `card--pad` straciły użycia, bo szablony przeszły
na nazwy z §4. To jest dowód, że przemianowanie objęło **całe** `templates/`, a nie tylko ekrany,
na które patrzyliśmy.

---

## 4. Jak odtworzyć

⚠ **Narzędzie jest w repozytorium** — `bin/inwentarz-klas.php` (PHP, analiza statyczna,
obok `check-rules.php`). Pythonowy prototyp, którym powstała pierwsza wersja tej tabeli,
przestał być potrzebny.

```
php bin/inwentarz-klas.php                              # raport na ekran
php bin/inwentarz-klas.php --tsv=docs/inwentarz-klas.tsv  # tabela do pliku
php bin/inwentarz-klas.php --bramka                     # kod 1, gdy coś zależy od starych arkuszy
```

⚠ **Tryb `--bramka` jest bramką PRZED skasowaniem starych arkuszy** i dziś jest **czerwony** —
63 klasy mają reguły wyłącznie w `tokens.css`/`base.css`/`components.css`. Taki ma być: to miara
pracy, która została, a nie usterka. Zielony oznacza, że stare arkusze można usunąć.

⚠ **Pomiar wyrenderowanego układu to inne narzędzie** — `dev/bramka-ekranowa.py` (Python,
bo steruje przeglądarką). Ta tabela wie, czy reguła ISTNIEJE; nie wie, czy DZIAŁA.

## 5. Granice metody — czego ta tabela NIE mówi

- **Klasy sklejane z wyrażenia Twiga** (`class="chip--{{ x }}"`) są dopasowywane po prefiksie
  i liczone jako używane. Bez tego `chip--warn` wyszłoby fałszywie jako martwe. ⚠ Przy okazji to
  właśnie tu siedział prawdziwy błąd: szablony generowały `badge--approved`/`badge--ok`, czyli
  klasy, których system nie ma — i nic tego nie zgłaszało.
- **Reguła istniejąca ≠ reguła działająca.** Tabela nie wie o kaskadzie. Blok
  `@media (pointer: coarse)` w `10-base.css` był „zdefiniowany" i nie robił nic, bo
  `30-components.css` wczytuje się później. To wychodzi wyłącznie z pomiaru na ekranie.
- `fragments/error.twig` nie jest przez nic `include`owane (renderuje je `ErrorHandler` dla htmx),
  więc wychodzi z `ekrany=0` mimo że działa w obu wariantach.
