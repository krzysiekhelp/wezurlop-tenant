# Komponenty do dopisania w §4 — plik roboczy

Zamówione w `zlecenia/2026-09-21-przed-makieta.md` §4. Każdy wpis ma **jedno zdanie „do czego"**
i **jedno „czego NIE robi"** — drugie jest ważniejsze, bo spis mówiący tylko „do czego"
nie powstrzyma nikogo przed drugim paskiem komunikatu.

⚠ **Stan po wyczyszczeniu do zera (`2026-09-21-wyczyszczenie-do-zera.md`).** Policzone,
nie oszacowane: `30-components.css` definiuje **164 klasy**, a spis §4 wymienia **64** z nich —
**100 nie ma w spisie**. Liczba urosła ze 115, bo cały stary system wszedł do nowego arkusza:
instalator, ekran błędu, siatka miesiąca, stronicowanie, stepper, kody zapasowe, QR i reszta.
Nie wszystkie to komponenty — duża część to *elementy* rodzin (`modal__head`, `wizard__step`,
`month-grid__cell`), ale rodzin brakuje w spisie około **trzydziestu**.

Odtworzenie tej liczby: `bin/inwentarz-klas.php` daje klasy z arkuszy, a różnicę wobec spisu
policzyłem porównaniem z sekcją „Komponenty" w `docs/system-wizualny.md`.

---

## ⚠ 0. ROZSTRZYGNIĘTE — został `.notice`

**`.note` i `.notice` to DWA paski komunikatu w jednym systemie.** Oba są w nowym arkuszu,
oba mają warianty `--warn` i `--danger`, a wyglądają inaczej:

| | `.note` (starsze, 10+ użyć) | `.notice` (moje, z §4b) |
|---|---|---|
| obramowanie | pełne, 1 px, `--radius-card` | lewa krawędź 3 px |
| tło | `--c-surface` / `--c-warn-bg` | `--c-neutral-soft` / `--c-warn-soft` |
| gdzie | `/admin/integracja` (7×), `/dane/firmy`, kreator podłączenia (3×) | `/logowanie`, `/m/wnioski`, `/diagnostyka`, fragmenty htmx, `.flash` |

⚠ **WYKONANE.** Został **`.notice`**, `.note` usunięte z arkusza i z szablonów.
Zmierzone po zmianie: każdy widoczny pasek stoi na białej karcie, tło paska
`rgb(240,242,245)` / bursztyn / czerwień — nigdzie szare na szarym.

---

## 1. Komunikaty i tekst pomocniczy

| komponent | do czego | czego **NIE** robi |
|---|---|---|
| `.notice` (+ `--warn`, `--danger`, `--dev`) | pasek komunikatu w treści ekranu: stan sesji, ostrzeżenie, błąd formularza | **nie jest kartą** (nie ma obramowania ani cienia), **nie ma wariantu zielonego** — powodzenie to brak modyfikatora, **nie pozycjonuje się sam** (pasek pływający to `.flash` w arkuszu powłoki) |
| `.field__hint` | podpowiedź pod polem i cichy tekst pomocniczy | **nie jest komunikatem błędu** — błąd to `.notice--danger`; podpowiedź jest szara i nie krzyczy |
| `.eyebrow` | nadtytuł nad nagłówkiem („LOGOWANIE" nad „Zaloguj się") | **nie jest kolorem akcji** — stara reguła świeciła zielenią, teraz to etykieta w `--c-text-3`; **nie zastępuje `<h1>`** |
| `.empty-state` | miejsce, w którym danych jeszcze nie ma („Brak wniosków") | **nie jest błędem ani ostrzeżeniem** — brak danych to stan normalny, więc bez koloru; kreskowana krawędź mówi „tu będzie treść" |

## 2. Układ treści

| komponent | do czego | czego **NIE** robi |
|---|---|---|
| `.stack` (+ `--sm`, `--lg`) | rytm pionowy: odstęp daje **kontener**, nie marginesy dzieci | **nie jest kartą ani sekcją** — nie ma tła, obramowania ani odstępu wewnętrznego; `--lg` to wyjątek dla kreatora, nie „większy stack na wszelki wypadek" |
| `.divider` | separator z wyrazem w środku („lub" między logowaniem a dostawcami) | **nie rozdziela sekcji** — do tego jest odstęp albo `.card`; nie jest linią bez treści |
| `.form-column` | pola formularza jedno pod drugim, wyrównane do lewej | **nie układa dwóch pól obok siebie** — do tego jest **`.form-grid`** |
| `.btn-row` | kilka przycisków w jednym rzędzie | **nie jest stopką karty** (`.card__foot`) i nie wyrównuje do prawej |
| `.form-link` | pomocniczy odnośnik pod przyciskiem formularza („Nie pamiętasz hasła?") | **nie jest przyciskiem** i nie występuje obok przycisku — stoi **pod** nim, wyśrodkowany |
| `.inline-form` | `<form>` z jednym przyciskiem w zdaniu albo w wierszu listy | **nie stylizuje przycisku** — robi tylko tyle, że formularz nie łamie linii |

## 3. Lista wierszy

| komponent | do czego | czego **NIE** robi |
|---|---|---|
| `.settings-list` | lista wierszy rozdzielonych linią, o wysokości celu dotykowego: ustawienia konta, wybór firmy, lista urządzeń | **nie jest tabelą** — nie ma nagłówków ani kolumn; **nie jest listą rekordów z danymi w kolumnach** (do tego `.table--rekordy`) |
| `.settings-list__row` | jeden wiersz; **znacznik jest ten sam, tag decyduje o sposobie klikania**: `<a>` i `<button>` — cały wiersz jest celem, `<div>` — celem nie jest, akcja stoi w środku | **nie rysuje się jak przycisk**, nawet gdy jest `<button>`: bez tła i bez obramowania, kursor mówi wszystko |
| `.settings-list__copy` | treść wiersza: nazwa nad metryką, druga linia ciszej | **nie przyjmuje trzeciej linii** — trzy linie znaczą, że to nie jest wiersz listy, tylko karta |

⚠ **Dlaczego jeden komponent, a nie trzy:** różnica między wyborem firmy, listą urządzeń
i ustawieniami konta jest w **sposobie klikania**, nie w wyglądzie. Gdyby wymagały różnych
odstępów albo różnych krawędzi, byłyby to trzy komponenty. Trzy rodzaje wiersza stoją obok
siebie na `/styleguide` — ⚠ **specjalnie razem**, bo bez zestawienia ktoś zrobi z tego trzy nazwy.

## 4. Kalendarz miesięczny

| komponent | do czego | czego **NIE** robi |
|---|---|---|
| `.calendar-grid` | siatka miesiąca: **siedem kolumn**, zawsze | **nie przewija się w bok** i **nie zmienia liczby kolumn** — miesiąc ma siedem dni i to nie zależy od szerokości |
| `.calendar-weekdays` | wiersz nazw dni nad siatką | **nie jest nagłówkiem tabeli** — to podpisy, `aria-hidden`, nie `<th>` |
| `.day-cell` (+ `--muted`) | jedna komórka dnia; wysokość zależy od **szerokości karty** (`@container karta`), nie od okna | **nie ma stałej wysokości** — 90 px przy szerokiej karcie, 56 px przy wąskiej; `--muted` to dzień z sąsiedniego miesiąca, **nie** dzień wolny |
| `.day-number` | numer dnia w komórce | nie jest odnośnikiem ani przyciskiem — na razie nic nie robi |

## 5. Kontrolki, których spis nie wymienia, a które istnieją

| komponent | do czego | czego **NIE** robi |
|---|---|---|
| `.input-group` (+ `__action`) | kontrolka **w środku** pola: pokazanie hasła, ikona, jednostka | **nie stawia przycisku obok pola** — to był powód powstania (pole hasła było węższe od pola loginu) |
| `.input--code` | pole na kod jednorazowy: cyfry tabelaryczne, rozstrzelone, wyśrodkowane | nie waliduje i nie ogranicza długości — to robi `maxlength` i serwer |
| `.btn--danger` | jedyna akcja nieodwracalna na ekranie | **nie jest domyślnym ogniskiem** w oknie potwierdzenia (§5.6) |
| `.btn--block` | przycisk na całą szerokość formularza | **nie jest regułą** „przycisk w formularzu jest blokowy" — to uderzyłoby w paski filtrów; klasa w szablonie, świadomie |
| `.chip--accent` | plakietka z akcentem — **dziś nieużywana** | ⚠ **nie jest plakietką stanu normalnego** („Zaakceptowany" to gołe `.chip`); jeśli nie znajdzie użycia, powinna zniknąć |
| `.table__grow`, `.table__empty` | kolumna przyjmująca nadmiar szerokości; wiersz „brak danych" | `__grow` nie jest „kolumną najważniejszą", tylko tą, która ma się rozciągać |
| `.toolbar` | pasek filtrów nad kartą | ⚠ w spisie jest w §3.3 jako powłoka, a reguła siedzi w komponentach — do uporządkowania |
| `.card__body--tight`, `.ico--sm`, `.disclosure__body`, `.htmx-request` | odmiany istniejących komponentów | — |

## 6. Rodziny, które są w arkuszu i nie ma ich w spisie w ogóle

Wypisuję je, bo brak w spisie znaczy „ktoś to wymyśli drugi raz", a nie „tego nie ma":

- **`.modal`** + `__head`, `__body`, `__foot`, `__close` — okno potwierdzenia i kreatora,
- **`.confirm`** + `__title`, `__lead`, `__list`, `__actions` — treść potwierdzenia operacji nieodwracalnej,
- **`.checklist`** + `__item`, `__label` — lista warunków do odhaczenia przed operacją,
- **`.secret`** + `__row`, `__value`, `__done` — pokazanie klucza raz, z kopiowaniem,
- **`.wizard`** + `__form`, `__field`, `__state`, `__step`, `__step--biezacy`, `__step--zrobione`,
  `__mark`, `__name`, `__hint`, `__next` — kreator podłączenia connectora.

## 7. Czego w systemie NIE MA — po wyczyszczeniu do zera

⚠ **Lista luk jest pusta.** Wszystko, co wcześniej tu stało, weszło do `30-components.css`
w zleceniu `2026-09-21-wyczyszczenie-do-zera.md`:

| dawna luka | co się z nią stało |
|---|---|
| `grid-2` | **`.form-grid`** — dwie kolumny pól, warunek na KONTENERZE (`@container karta`, próg 560 px) |
| `textarea` | **`.input--area`** (weszło turę wcześniej) |
| `company-mark` | **`.company-mark`** na tokenach — 40 px, `--c-neutral-soft`, bez gradientu |
| `qr-card` | **`.qr-card`** — `min(210px, 100%)`; 210 px to wymiar funkcjonalny (skanowalność) |
| `backup-code`, `backup-grid`, `control--backup` | **`.backup-code`**, **`.backup-grid`**; pole kodu to `.input mono` |
| `stepper`, `step-dot*` | **`.stepper`**, **`.step-dot`** — kropki elastyczne, trzy odcienie szarości |
| `progress`, `progress--warning`, `progress-line` | **`.progress`**, **`.progress-line`** — wypełnienie neutralne |
| `event-id` | **usunięte** — to `.chip mono`, czyli dwa istniejące komponenty |
| `field--check`, `field--error` | **`.field--check`**, **`.field--error`** (czerwień na obrysie, nie na tle) |
| `highlight` | **usunięte** — kolor akcji na nazwie firmy |
| `month-grid*` | przeniesione jako wariant `.table` |
| `pager`, `pager__info` | **`.pager`** jako nowy komponent |
| `installer-*`, `status-*` | karta instalatora to `.card`; zostały `installer-shell/frame/brand` i `status-*` |
| `diag-group`, `sg-*`, `spinner` | przeniesione |

⚠ **`tokens.css`, `base.css` i `components.css` nie istnieją.** `bin/inwentarz-klas.php --bramka`
zwraca **0**.

## 8. ⚠ Dwie siatki miesiąca — decyzja odłożona świadomie

`.calendar-grid` (`/kalendarz`, siatka `div`, siedem kolumn, dziś bez danych) i `.month-grid`
(`/dane/kalendarze`, **tabela** z nieobecnościami w komórkach) pokazują **to samo**: miesiąc
w siedmiu kolumnach. Różnią się nośnikiem — jedna jest siatką CSS, druga tabelą z nagłówkami dni —
i to jest różnica **implementacji**, nie przeznaczenia.

⚠ **Nie scaliłem ich**, bo `/kalendarz` jest jednym z czterech ekranów makiety, a scalenie
przesądziłoby jej wygląd. Rekomendacja na makietę: **zostaje jedna**, a skoro kalendarz z danymi
musi nieść wpisy w komórkach i nagłówki dni, prawdopodobnie zostaje wariant tabelaryczny.

## 9. Komponenty dodane przy wyczyszczeniu do zera

| komponent | do czego | czego **NIE** robi |
|---|---|---|
| `.form-grid` | dwie kolumny pól formularza; przy wąskiej **karcie** zwija się do jednej | **nie jest `.form-column`** — tamta układa pola jedno pod drugim zawsze; ⚠ warunek jest na kontenerze, nie na oknie |
| `.stepper`, `.step-dot` (+ `--done`, `--active`) | pozycja w wieloetapowym procesie (instalator, 11 kroków) | ⚠ **nie używa koloru** — pozycję niesie jasność: czeka `--c-line`, zrobiony `--c-line-strong`, bieżący `--c-text-2`. Kropki są **elastyczne**, bo dziesięć stałych nie mieści się w karcie telefonu |
| `.progress` (+ `--warning`), `.progress-line` | postęp znany (natywny `<progress>`) i nieznany | ⚠ **wypełnienie NEUTRALNE** — postęp jest daną, nie akcją; bursztyn zostaje dla stanu wymagającego uwagi |
| `.pager`, `.pager__info` | stronicowanie listy | **nie jest stopką karty**; licznik to metryka — cyfry tabelaryczne, żeby nie skakał z 9 na 10 |
| `.qr-card` | oprawa kodu QR do parowania 2FA | ⚠ **210 px i biała ramka to wymiary FUNKCJONALNE** (skanowalność, strefa cicha), nie ozdoba; `min()` chroni przed wyjściem poza wąską kartę |
| `.backup-grid`, `.backup-code` | kody zapasowe 2FA: siatka i pojedynczy kod | **nie wprowadza drugiego kroju** — `.mono` to cyfry tabelaryczne i lekki odstęp (§4) |
| `.company-mark` | inicjał firmy w wierszu wyboru | ⚠ **nie jest kolorowym znacznikiem** — stary miał niebieski gradient i białą literę, czyli kolor na stanie normalnym |
| `.field--check`, `.field--error` | pole wyboru (etykieta obok) i pole z błędem | ⚠ błąd znaczy **obrys**, nie tło — tło robiłoby z pola komunikat, a komunikat to `.notice--danger` |
| `.month-grid` + `__cell` (`--empty`, `--free`, `--absence`), `__day`, `__mark` | siatka miesiąca **z danymi** (`/dane/kalendarze`) — wariant `.table` | ⚠ dzień z nieobecnością **nie ma zielonego tła**; ma krawędź po lewej, jak `.notice`. Patrz §8 |
| `.installer-shell`, `.installer-frame`, `.installer-brand` | rama ekranu instalatora i pasek z logo | **nie są kartą** — karta instalatora to zwykłe `.card` + `__head`/`__body`/`__foot` |
| `.status-layout`, `.status-card`, `.status-code`, `.status-brand` | ekran błędu: wyśrodkowana karta z numerem | ⚠ numer jest **wyliczony ze skali** (`calc(2 * var(--t-h1))`), a jego kolor to `--c-text-4` — to metryka, nie treść |
| `.sg-section`, `.sg-row` (+ `--top`), `.sg-swatches`, `.sg-swatch`, `.sg-flash-demo`, `.spinner`, `.diag-group` | styleguide i diagnostyka | ⚠ próbki pokazują paletę **nowego** systemu; styleguide z nieistniejącymi tokenami byłby gorszy niż jego brak |

⚠ **Żaden z nich nie jest zielony.** Kolor mają wyłącznie: `.progress--warning` (bursztyn
`--c-warn`) i `.field--error` (czerwień `--c-danger` na obrysie) — oba znaczą odstępstwo.

## 10. Komponenty dodane przy przebudowie ekranu grup (10.10.2026)

Zamówione w `zlecenia/2026-10-10-grupy-ekran-dwie-kolumny.md` §2: „obie rodziny dopisz jednym
wierszem każda, z jednym zdaniem »na jakie pytanie odpowiada«".

⚠ **`.toast` / `.toast__undo` zniknęło tego samego dnia** (aneks
`2026-10-10-grupy-okno-i-symetria.md` §3a, decyzja Krzysztofa): pasek „Cofnij" dublował akcję,
która stoi dwa centymetry dalej — osoba właśnie dodana jest w drugiej kolumnie i ma przy sobie
akcję odwrotną. Wpisu **nie zostawiam martwego**: nieużywany wiersz w spisie jest zaproszeniem,
żeby ten pasek wrócił. Pływający pasek komunikatu w systemie jest **jeden** — `.flash`
(wypełniany przez JS, bez akcji).

| komponent | na jakie pytanie odpowiada | czego **NIE** robi |
|---|---|---|
| `.assign` + `__col`, `__head`, `__title`, `__count`, `__search`, `__wynik`, `__list`, `__row`, `__name`, `__tag`, `__btn` (`--add`/`--del`), `__empty`, `__foot`, `__note`, `__blad` | „kto należy do tej grupy, a kto jeszcze nie" — **dwie równe (50:50) i lustrzane** kolumny, zapis natychmiastowy po jednym kliknięciu; oprawą jest podstrona albo okno (`.modal--assign`) | ⚠ **nie jest `.pickbox`** — tamta jest NAKŁADKĄ nad siatką (filtr kalendarza), ta jest TREŚCIĄ strony; **nie ma pól wyboru ani przycisku „Zapisz"** (nie istnieje stan niezapisany), **kolumny nie są `.card`** (`.card` ma `overflow: hidden` i właśnie on uciął poprzednią wersję tego ekranu), przewija się **wyłącznie** `__list` |
| `.group-list` + `__row`, `__main`, `__name`, `__sklad`, `__wiecej`, `__count`, `__note`, `__menu`; `.bez-grupy` + `__head`, `__nazwa`, `__count`, `__body`, `__sklad`, `__uwaga`; `.menu__powod` | „jakie są grupy i co w nich jest" — lista dwuliniowych wierszy wchodzących w ekran, plus pasek stanu „Bez grupy" | ⚠ **nie jest tabelą** (kolumny `NAZWA` z polem edycji i `USUNIĘCIE` z wymówką zniknęły) ani `.settings-list` (tam wiersz jest ustawieniem z kontrolką, tu wejściem w ekran); `.bez-grupy` **nie jest wierszem listy** — przerywana ramka mówi, że to stan, nie grupa |

## 11. Znacznik spółki w belce (10.10.2026, wieczór)

Zamówione w `zlecenia/2026-10-10-globalny-wybor-spolki.md` §1: jedna kontrolka wyboru spółki
na cały program, widoczna na każdym ekranie.

⚠ **Rodzina `.combo` (lista rozwijana spółek z 06.10) ZNIKNĘŁA tego samego dnia** razem
z kontrolkami w nagłówkach Kalendarza, Grup i Hierarchii oraz `<select name="spolka">`
na Kontach. Reguły CSS skasowałem, nie zostawiłem martwych: nieużywany zestaw klas wraca
do użycia przez przypadek, a wtedy na ekranie znów stoją **dwie** kontrolki do jednego wyboru.

| komponent | na jakie pytanie odpowiada | czego **NIE** robi |
|---|---|---|
| `.spolka` + `--wybor`, `--staly`, `__nazwa`, `__trigger`, `__strzalka`, `__panel`, `__grupa`, `__pozycja` (`--on`), `__stopka`, `__uwaga` | „w jakiej spółce teraz jestem i czym mogę to zmienić" — jeden znacznik w górnej belce, **na jej środku**, na **każdym** ekranie | ⚠ **nie jest `<select>`** — panel to `<details>` z formularzami `POST`, więc działa bez JavaScriptu; na ekranie o zakresie `tenant` **nie rozwija się** i mówi „Wszystkie firmy" (przerywana ramka = napis, nie kontrolka); `__uwaga` to **nie błąd** (bursztyn), tylko „pokazujemy inną spółkę, niż wybrałeś" |

⚠ **Uzupełnienie 11.10.2026 (prośba Krzysztofa): znacznik stoi na ŚRODKU belki i wygląda
dokładnie jak dawna `.combo`** z nagłówków zakładek — stała szerokość 14 rem, ramka, nazwa
biorąca całe wolne miejsce, strzałka na prawej krawędzi (obracana po rozwinięciu), bieżąca
pozycja wyróżniona **tłem**, nie ptaszkiem. Zniknęły przy tym dwie rzeczy, których tamta
kontrolka nie miała: etykieta `__etykieta` („SPÓŁKA") i nagłówek panelu `__naglowek`
(„Przełącz kontekst"). Klasy `.combo` **nie wróciły** — wrócił jej język wizualny, w jednym
komponencie; dwa zestawy klas na jeden wygląd to dokładnie ta choroba, dla której ten spis
istnieje.

⚠ Belka ma od tej zmiany **trzy gniazda** (`.crumbs` / `.topbar__srodek` / `.topbar__prawa`),
a nie rozpychacz `.topbar__spacer`: środek geometryczny wymaga, żeby lewa i prawa strona
rosły po równo. Z jednym rozpychaczem kontrolka wędrowałaby za długością ścieżki.

⚠ Na wąskim ekranie (`/m/*`) znacznik ma **zmienną** szerokość (stała 14 rem rozpychałaby
pasek 374 px) i panel otwierany od lewej krawędzi — warunek odbioru to **0 przewijania
w poziomie**.

## 12. Pasek nieobecności i legenda — kolor niesie WYPEŁNIENIE (11.10.2026)

Zamówione w `zlecenia/2026-10-11-paski-nieobecnosci.md` §1–§3: wzór zatwierdzony przez Krzysztofa
na żywo w przeglądarce. **Zakres był wyłącznie wyglądem paska** — siatka, wiersze, rozpiętości
i widoki bez zmian.

| komponent | na jakie pytanie odpowiada | czego **NIE** robi |
|---|---|---|
| `.team-grid__bar` + `--urlop`, `--zasilek`, `--kadrowe`, `--jeden` | „jakiego rodzaju jest ta nieobecność i od kiedy do kiedy trwa" — wypełnienie w kolorze rodzaju, pełne zaokrąglenie, napis pogrubiony w ciemnym odcieniu tego samego koloru | ⚠ **nie ma obrysu** (do 11.10 rodzaj niósł obrys i gruba lewa krawędź — oba zniknęły); **nie znaczy OSOBY**, tylko rodzaj (na wzorze kolor znaczył osobę — przy 53 osobach kolorów nie starczy); `--jeden` **nie jest stanem**, tylko rozpiętością jednodniową: niesie numer dnia i jest uchwytem pomiaru |
| `.legend__mark` + `--urlop`, `--zasilek`, `--kadrowe`, `--free` | „co znaczy ten kolor na pasku" — próbka jest **miniaturą paska** (18 × 10 px, `border-radius: 999px`) z **tych samych tokenów** | ⚠ `--free` **nie jest pigułką** — dzień wolny to tło kolumny, nie nieobecność, więc próbka zostaje prostokątem z obrysem |

⚠ **Sześć tokenów, nie sześć liczb w komponencie** (`00-tokens.css`): `--c-pasek-urlop-tlo`
/ `-tekst`, `--c-pasek-zwolnienie-tlo` / `-tekst`, `--c-pasek-kadrowe-tlo` / `-tekst`. Token
`--c-absence-fill` (dawne wypełnienie neutralne) **skasowany razem z obrysem** — nieużywana
wartość wraca do użycia przez przypadek.

⚠ **`.month-grid` został NIETKNIĘTY i to jest pomiar, nie przeoczenie** (§4 zlecenia). Zmierzone
na `/dane/pracownik/94/kalendarz?miesiac=2026-01`: 12 komórek z nieobecnością, rodzaj niesie
**TEKST** (`month-grid__mark` = „urlop wypoczynkowy" ze słownika kadrowego), krawędź lewa jest
**bezbarwna** (`--c-line-strong`, ta sama dla każdego rodzaju), tło komórki żadne, legendy
kolorów **zero**. To inny język niż oś czasu, więc przeniesienie tokenów nic by tam nie
znaczyło — osobna decyzja, nie ta tura.

## 13. Skale czasu w „Tylko ja" i nazwa ekranu w belce (11.10.2026)

Zamówione w `zlecenia/2026-10-11-skale-czasu-i-tytul-ekranu.md`; oba bloki zatwierdzone przez
Krzysztofa na żywo w przeglądarce.

| komponent | na jakie pytanie odpowiada | czego **NIE** robi |
|---|---|---|
| `.rok-pole`, `.rok`, `.msiatka` + `__nazwa`, `__suma`, `__siatka`, `__dzien-tygodnia`, `__kratka` (`--poza`, `--wolny`, trzy rodzaje, `--dzis`) | „kiedy w tym roku byłem nieobecny, ile mi zeszło i **w jaki dzień tygodnia to wypada**" — dwanaście siatek miesięcznych w ramkach, cztery w rzędzie | ⚠ **nie jest nowym komponentem**: to pudełko miesiąca ze zniesionej skali kwartału, z przemianowaną rodziną (`kwartal__*` → `msiatka__*`); `.rok-pole` to **kontener zapytania** (`container-name: rok`), bo element nie może pytać siebie; `--poza` jest **pusty, nie szary** — brak dnia to nie dzień wolny; `__suma` to **dni z kadr**, nie liczba kratek |
| `.table--kolumny-prawe` | „gdzie kończy się liczba" — dwie ostatnie kolumny tabeli rekordów przy prawej krawędzi karty | ⚠ **modyfikator na jednej tabeli**, nie zmiana rodziny `.table--rekordy`: na `/wnioski` ostatnia kolumna to plakietka stanu, w styleguide przycisk; w układzie dwuliniowym jest **wyzerowany** (`width: 1%` na bloku to pasek 1% karty) |
| `.crumbs__now` (teraz `h1`) | „na jakim ekranie jestem" — **jedyne** miejsce z nazwą ekranu | ⚠ **nie jest `span`**: strona bez `h1` to regres dostępności; reguła **zdejmuje z `h1` jego rozmiar** (`font-size: inherit`), inaczej bieżąca pozycja rośnie z 13 px do 30 px i rozpycha belkę 56 px |

⚠ **Zasada, nie lista ekranów:** nazwa ekranu występuje **raz**, w górnej belce, i pochodzi
z **jednego źródła** — bloku `nazwa_ekranu` (z niego powstaje `<title>` i `h1`). Nagłówek karty
powtarzający nazwę ekranu jest nadmiarowy i znika (wtedy zdanie spod tytułu wchodzi do karty);
nagłówek mówiący coś innego — miesiąc, limit, nazwisko — jest treścią i zostaje. Pilnuje tego
kryterium `tytul_ekranu` (trzy warunki naraz).

⚠ Bloki `title` i `heading` **zniknęły z ekranów** — nie są martwe, są zastąpione. Ekran bez belki
(logowanie, instalator, strona błędu) zostaje z `h1` w treści, bo belki tam nie ma; kryterium
wymaga wtedy **dokładnie jednego** `h1`.

### Aneks z 11.10.2026 wieczorem — „Rok" przestał być paskiem, „Kwartał" zniknął

Zlecenie `2026-10-11-rok-jako-dwanascie-miesiecy.md`, prototyp zatwierdzony w przeglądarce.
Dwie pozycje z tabeli wyżej (pasek `.rok__*` 12 × 31 i rodzina `.kwartal__*`) **przestały
istnieć** i zastąpiła je jedna: dwanaście tych samych siatek miesięcznych.

⚠ Powód jest jeden i da się go powiedzieć w zdaniu: **z paska nie dało się odczytać dnia
tygodnia**, bo jego kolumnami były numery dni — a to jedyne, po co człowiek patrzy na kalendarz
przed złożeniem wniosku. Kwartał umiał to na trzech miesiącach, więc po rozszerzeniu Roku
odpowiadał na to samo pytanie mniejszym zakresem: trzecia skala, zero nowej informacji.

⚠ **Czego tu NIE ma:** nowego komponentu. Pudełko, siatka, kratka, weekendy, kolory rodzaju,
suma dni roboczych i znacznik „dziś" przeszły z kwartału bez zmian; zmieniła się nazwa rodziny
(bo „kwartał" na widoku roku nie znaczył już nic) i liczba pudełek.

⚠ **Przewijanie strony jest WYBRANE, nie przeoczone.** Krzysztof widział wariant gęstszy
(sześć kolumn, kratka 29 px, cały rok bez przewijania) i wybrał cztery kolumny: ten widok ma być
czytelny, a nie „wszystko naraz". Zagęszczenie nie jest tu ulepszeniem i nie proponujemy go.

⚠ Stary adres `?skala=kwartal` **nie jest błędem** — wypada z białej listy
w `KalendarzPracownika::skala()` i cicho wraca do Miesiąca, jak każda inna literówka w adresie.

### Aneks drugi z 11.10.2026 wieczorem — trzy bloki, nawigacja w nagłówku, plakietka „dziś"

Zlecenie `2026-10-11-trzy-bloki-nawigacja-w-naglowku.md`, prototyp zatwierdzony w przeglądarce.

| komponent | na jakie pytanie odpowiada | czego **NIE** robi |
|---|---|---|
| `.bloki` | „gdzie kończy się jedna rzecz, a zaczyna druga" — ekran kalendarza jako trzy bloki: nagłówek · kalendarz · objaśnienia | ⚠ **nie jest komponentem wizualnym**: nie ma tła, ramki ani promienia — blok to zwyczajna `.card`. Pojemnik ustawia je w kolumnie i trzyma odstęp; ramka na ramce byłaby czwartą ramką wokół trzech |
| `.kal-nav` + `__okres`, `__kontrolki` | „który okres widzę i jak przejść do innego" — wiersz na górze pierwszego bloku | ⚠ **nie stoi nad kartą**: `.page__actions` z tego ekranu zniknęło, bo nawigacja dotyczy tego, co jest w karcie. Strzałki są **przy nazwie okresu**, nie w prawym kącie razem z przełącznikami, które dotyczą czego innego |
| `.ikona-btn` | „poprzedni / następny okres" — przycisk z samą ikoną, 32 px | ⚠ **nie jest przyciskiem bez etykiety**: `aria-label` i `title` są obowiązkowe, a ich treść **zależy od skali** („Poprzedni rok" kontra „Poprzedni miesiąc") — tego nie widać na obrazku, więc rozstrzyga szablon, nie arkusz |
| `.dzien-dzis` | „który dzień jest dzisiaj” — okrągła plakietka na numerze dnia | ⚠ **nie zasłania danych**: w osi czasu stoi w nagłówku kolumny, a w Roku na numerze w kratce — czyli w obszarze danych, ale na cyfrze, której i tak nic innego nie niesie. ⚠ **nie jest wyjątkiem od „zieleń = akcja”**: para `accent-soft` + `accent-text` znaczy w tym systemie „tu teraz jesteś” (`.nav-item--on`), a dzień dzisiejszy mówi to samo. ⚠ **od 11.10.2026 w nocy ma OBWÓDKĘ** 1 px `--c-accent-text`, bo samo wypełnienie ma do teł kratki Roku kontrast **1,008 – 1,102** i przy dniu wolnym nie widać go wcale; obwódka daje **5,25 – 6,34** na każdym z pięciu teł. Jest w OBU widokach, inaczej znów wyglądałyby inaczej. ⚠ **to nie jest powrót obwódki `--c-dzis`**: tamta obrysowywała siedem komórek kolumny osobno (oko składało je w przerywaną ramkę), ta obrysowuje jedną plakietkę na jednym numerze. |

⚠ **Dwie rodziny reguł zniknęły tego samego dnia, obie po jednym spojrzeniu na ekran:**

- `.team-grid__cell--dzis` i `.msiatka__kratka--dzis` (obwódka `--c-dzis`) — **liczba była dobra**
  (kontrast 1,152, słabszy od każdej nieobecności, mocniejszy od weekendu), **kształt nie**:
  obwódka siedziała na każdej komórce kolumny osobno, a siedem prostokątów jeden pod drugim oko
  składa w **przerywaną ramkę**. Token `--c-dzis` wypadł razem z nimi, bo nie został ani jeden
  użytkownik. ⚠ To jest reguła na przyszłość: **dobry pomiar nie ratuje złego kształtu.**
- własna prawa krawędź ostatniej kolumny osi czasu — przy ramce bloku dawała **dwie kreski
  w dwóch szarościach, 1 px od siebie** (komórka `--c-line-soft` do 1855, ramka `--c-line`
  na 1856–1857). Krawędź zewnętrzną rysuje pojemnik, więc kolumna swojej mieć nie może;
  zdejmuje ją `nth-child` po liczbie kolumn, bo dzieci siatki są płaskie i ostatnia komórka
  wiersza nie jest ostatnim dzieckiem niczego.
  ⚠ **Mechanizm z 11.10 wieczorem był BŁĘDNY i poprawiony w nocy.** Stał na `nth-child` po liczbie
  kolumn (`--days-30 > *:nth-child(31n)`) z założeniem, że co `1 + dni` dzieci wypada koniec
  wiersza. Założenie jest nieprawdziwe: **pasek wielodniowy to JEDNA komórka z `span N`**, więc
  wiersz z nieobecnością ma mniej dzieci niż kolumn i licznik rozjeżdża się od pierwszego paska.
  Zmierzone na czerwcu 2026: z 50 komórek bez prawej krawędzi **48 leżało w środku wiersza**,
  a na widoku „Zespół" licznik trafiał w `.team-grid__person` czterech osób — ich **nazwisko
  zlewało się z pierwszym dniem miesiąca**, bo znikała jedyna kreska między nimi. Teraz ostatnią
  komórkę wskazuje szablon (`loop.last` → `--ostatnia` / `--ostatni`), bo tylko on wie, gdzie
  kończy się wiersz; cztery reguły zależne od długości miesiąca zniknęły.
  ⚠ Nauka: **`nth-child` liczy dzieci, a nie kolumny** — w siatce ze scalaniem to dwie różne liczby.

⚠ **Czego ten aneks NIE obejmuje:** przeglądu pozostałych miejsc, w których ostatnia komórka ma
`border-right`, a kontener ramkę. Krzysztof przeglądu nie zamówił — pozycja jest
w `decyzje/do-sprawdzenia.md`.

---

## 14. `.card--panel` — blok, z którego wysuwa się panel kontrolki (11.10.2026, noc)

| komponent | na jakie pytanie odpowiada | czego **NIE** robi |
|---|---|---|
| `.card--panel` | „gdzie ma się zmieścić panel, który jest większy niż blok" — blok nagłówka „Zespołu" i „Firmy", z którego wychodzi kontrolka wyboru osób | ⚠ **nie jest drugim `.card--os`**: tamten tylko przestaje obcinać (dla przyklejonego nagłówka dni), ten dodatkowo **podnosi cały blok w warstwach**. Jedno bez drugiego nie działa |

⚠ **Dlaczego dwie deklaracje, a nie jedna** — zmierzone na `?widok=firma`, okno 1440 × 855,
po zgłoszeniu „panel jest przykryty i go nie widać":

- `overflow: hidden` z `.card` **obcinał** panel: blok kończy się na 317 px, panel ma 513 px,
- a po samym odsłonięciu panel i tak **leżał pod** następnym blokiem — `.card__body` jest
  kontenerem zapytań (`container-type: inline-size`), czyli **kontekstem układania**, więc
  `z-index: 20` z `.pickbox` nigdy nie wychodzi poza swój blok. `elementFromPoint` w środku
  panelu zwracał `team-grid__bar`.

⚠ **To jest cena rozpadu ekranu na bloki** (drugi aneks do §13): dopóki kontrolka i siatka stały
w jednej karcie, panel nie miał czego przebijać. Każdy następny blok z kontrolką, która
otwiera coś większego od siebie, będzie potrzebował tego samego modyfikatora — i to jest
powód, dla którego ma nazwę opisującą **zachowanie**, a nie ekran.

⚠ **Warstwa `4` jest wyliczona, nie wybrana**: przyklejony nagłówek dni ma 2, a jego narożnik 3
(`.team-grid__head`, `--person`) i **nie są** zamknięte we własnym kontekście układania, więc
leżą w tej samej warstwie co bloki; pasek komunikatów ma 9, a belka górna 10 — panel ma się
pod nie wsuwać, a nie na nie wchodzić. Sprawdzone hit-testem: przy zamkniętej kontrolce numer
dnia w przyklejonym wierszu zostaje osiągalny na każdym przewinięciu.

---

## 15. Wąskie ekrany: układ arkusza w osi czasu, kadr powłoki, Rok bez progów (11.10.2026, noc)

Zlecenie `2026-10-11-waskie-ekrany-i-dni-tygodnia.md`; pomiary i warianty odrzucone:
`wezurlop-talk/docs/kalendarz-waskie-ekrany.md`.

| komponent | na jakie pytanie odpowiada | czego **NIE** robi |
|---|---|---|
| `.team-scroll` | „jak czytać trzydzieści kolumn w bloku, który ma ich osiem" — opakowanie siatki przewija się **w obu osiach**, z zamrożonym pierwszym wierszem i pierwszą kolumną | ⚠ **nie przewija się „tylko gdy trzeba"**: do 11.10 `overflow-x` włączał się poniżej 1320 px i to był błąd, bo **przycinanie w jednej osi wylicza drugą na `auto`** — opakowanie stawało się kontenerem przewijania i `top: 64px` przyklejonego nagłówka liczyło się od góry siatki, wpychając wiersz z numerami **pod pierwsze nazwisko** (zmierzone: nagłówek 402 px przy pierwszym wierszu 374 px). ⚠ **nie decyduje o własnej wysokości**: pyta powłokę o `--kadr-h` |
| `--kadr-h` (warstwa 20) | „ile pionu zostaje na treść po belkach tej powłoki" | ⚠ **nie jest tokenem**: w `00-tokens.css` nie da się jej policzyć, bo odpowiedź zależy od powłoki — komputer odejmuje belkę górną, telefon belkę górną, dolną nawigację i pasek gestów. ⚠ **nie liczy się w `vh` bez `dvh`**: `vh` liczy pasek adresu przeglądarki |
| `.team-grid__dow` | „jaki to dzień tygodnia" — skrót nad numerem w nagłówku kolumny | ⚠ **nie jest liczony w szablonie ani w JS**: dzień tygodnia przychodzi z serwera (`d.skrot`), jak numer dnia na pasku jednodniowym. ⚠ **nie jest skrótem z `DNI_TYGODNIA`** (`pon`, `niedz`): te stoją w siatce Roku, gdzie kolumna ma ~45 px. Tu podłoga to 26 px, więc skróty są **dwuliterowe** — jednoliterowe dałyby `p` dwa razy i `s` dwa razy, czyli nagłówek, który kłamie |

⚠ **Rok stracił trzy progi i opakowanie `.rok-pole`.** Progi (1180 / 900 / 620 px) dawały dobrą
liczbę kolumn i **złą wielkość kratki**: pudełko `1fr` rozpychało się na całą kolumnę, więc
kratka miała 79 px na telefonie i 45 px na monitorze, a tuż przed każdym progiem spadała do 34.
Zastąpiła je jedna reguła `repeat(auto-fit, minmax(290px, 1fr))` z sufitem pudełka `360px` —
liczba kolumn wychodzi ta sama (zmierzone: 1460 → 4, 1130 → 3, 900 → 2, 570 → 1), a kratka
trzyma się pasma **34,8 – 44,8 px** na całym zakresie 270 – 1460 px. Obie liczby są wyprowadzone,
nie zgadnięte: `kratka = (pudełko − 44) / 7`, więc 290 → 35,1 px (podłoga) i 360 → 45,1 px (sufit).
Nazwany kontener bez ani jednego zapytania byłby martwą regułą, więc `.rok-pole` zniknęło
z arkusza **i z szablonu**.

⚠ **Czego ta zmiana nie załatwia:** przewinięcie strony poniżej bloku z siatką zabiera zamrożony
nagłówek razem z blokiem (zmierzone: góra opakowania −66 px przy przewinięciu 400). Pełna
widoczność całego opakowania istnieje w przedziale przewinięcia szerokim na 24 px przy oknie 900.
Lekarstwem byłby blok przyklejony do belki — **nie zamówione**, zapisane w `do-sprawdzenia.md`.

⚠ **Poprawka z 13.10.2026 — dwa błędy w tym nagłówku, oba widoczne tylko na ekranie.**

1. **Skróty były inne niż w Roku** (`pn wt śr cz pt so nd` wobec `pon … niedz`). Dobrałem je
   dwuliterowo, żeby mieściły się w kolumnie o podłodze 26 px — czyli ograniczenie techniczne
   wybrało nazwy. Krzysztof: „nazwy dni tygodnia powinny być takie same skróty jak w kalendarzu
   rok". Obie listy są teraz **jedną stałą** (`SKROTY_DNI`, a `DNI_TYGODNIA = self::SKROTY_DNI`),
   więc nie da się zmienić jednej i zapomnieć o drugiej.
2. **Skrót dnia dzisiejszego stał 6 px wyżej niż pozostałe** (zmierzone: `dow_y` 275 wobec 281),
   bo każda komórka centrowała swoją treść, a plakietka „dziś" ma 26 px przy 13 px zwykłego
   numeru. Jeden wiersz nagłówka miał dwa poziomy napisów. Teraz skróty są wyrównane **do góry**,
   a numer siedzi w pudełku o stałej wysokości plakietki (`.team-grid__numer`, 26 px) — zmierzone
   po zmianie: **jeden poziom skrótów i jeden poziom numerów** we wszystkich kolumnach, wysokość
   nagłówka stała **48 px** niezależnie od tego, czy „dziś" wypada w tym miesiącu (było 43 albo 56).

⚠ **Skrót gaśnie, gdy jego kolumna jest za wąska — decyduje KOLUMNA, nie okno.** Każda komórka
nagłówka jest własnym kontenerem zapytania, a reguła brzmi `@container (max-width: 33px)`.
Zmierzone szerokości napisów przy 11 px: `śr` 12, `pt` 13, `wt` 16, `sob` 23, `pon` 24, `czw` 25,
**`niedz` 35** — komórka nie ma odstępu bocznego, więc poniżej 34 px najdłuższy wyszedłby
na sąsiednią kolumnę. Gaśnie **cała kolejka**, nie jeden napis: nagłówek ucięty do `nied`
kłamałby. ⚠ Czego to nie ratuje: 31 dni × 34 px + kolumna nazwisk to ~1194 px karty, czyli
**pełne skróty dla najdłuższego miesiąca wymagają okna ≥ ~1500 px**; przy 1366 px zostają same
numery. To arytmetyka, nie wybór — alternatywą było podniesienie podłogi kolumny do 38 px
i pasek przewijania na pełnym ekranie.
