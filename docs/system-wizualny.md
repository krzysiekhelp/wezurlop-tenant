# System wizualny wezurlop — dokument obowiązujący

**Wersja:** 1.18.2, 17.10.2026 · **Właściciel dokumentu:** nadzór · **Status:** zatwierdzony przez Krzysztofa

⚠ **Od 17.10.2026 ten dokument mieszka w repozytorium tenanta** (`tenant/docs/system-wizualny.md`),
a nie w `wezurlop-talk`. Powód jest prosty: wszystkie cztery arkusze powołują się na ścieżkę
`docs/system-wizualny.md`, której w repozytorium kodu nie było — dokument obowiązujący stał poza
zasięgiem tego, co obowiązywał. **Każda zmiana wyglądu dopisuje się tutaj w tym samym commicie
co kod.** Kopia w `wezurlop-talk` jest od tej daty archiwalna i nie wolno jej już zmieniać.
⚠ Przykładowe nazwy firm i osób w tym dokumencie są **zastępcze** (`ALFA`, `BETA`, `KOWALSKI`,
`JAN`) — prawdziwe dane klienta nie wchodzą do repozytorium w żadnej postaci, także w przykładzie.

> **Zmiany 1.18.1 → 1.18.2:**
> **(1)** **`.kafle` NIE JEST kartą.** Pasek kafli na pulpicie nie ma tła, ramki, cienia ani
> wyściółki — kafle stoją wprost na tle strony, a krawędzie skrajnych równają się z krawędziami
> kart niżej. Karta wokół kafli-kart to ramka w ramce: białe tło paska i białe tło kafelka zlewały
> się w jedną plamę, a 2 × 24 px wyściółki zjadało szerokość kafli. Zmierzone po zmianie: kafelek
> rośnie z 242 do 250 px przy 1920, z 157 do 343 px przy 1366 (trzy kolumny) i ze 149 do 165 px
> w powłoce telefonu, a wysokość paska spada ze 164 do 114 px. Odstęp pod paskiem daje `gap`
> kolumny `.page`, więc jest taki sam, co między kartami — bez własnej reguły.
> **(2)** ⚠ **W napisach dla użytkownika używamy wyłącznie znaków, które SA w hostowanym podzbiorze
> kroju.** Zmierzone 17.10.2026: podzbiór IBM Plex Sans nie ma glifów ← → ≠ ≥ ⚠ ✕ — wszystkie
> rysują się jako pusty prostokąt (`.notdef`), a `unicode-range` w arkuszu jest tu bez winy, bo
> pliku z tymi glifami po prostu nie ma. Strzałki zastępuje słowo („Ustawienia, w sekcji Poczta"),
> krzyżyk — `×` (U+00D7, jest w podzbiorze), a znak uwagi znika z treści. **Lista dozwolonych
> znaków rozszerza się świadomie, osobnym zleceniem** (podzbiór trzeba zbudować od nowa
> z oryginalnego kroju) — nie przy okazji pisania zdania. W komentarzach kodu ⚠ zostaje: tam nic
> się nie renderuje.
>
> **Zmiana 1.18 → 1.18.1:** z §1 znika zapowiedź arkusza **`40-screens.css`** („wyjątki per
> ekran"). Nigdy nie powstał i nie jest potrzebny: wyjątki ekranowe stoją w `30-components.css`,
> przy komponencie, którego dotyczą, i mają tam komentarz z powodem — czyli dokładnie to, co
> tamten plik miał zapewnić, tylko bez szukania w dwóch miejscach. W zamian w spisie plików
> pojawia się `20-layout-mobile.css`, który istnieje od dawna, a w opisie go nie było.
>
> **Zmiany 1.17 → 1.18:** domknięcie tury Z6–Z15 (17.10.2026).
> **(1)** Nowy token typografii **`--t-stat: 24px`** — dana kafelka na pulpicie. Nie `--t-h1`:
> liczba w kaflu nie jest nagłówkiem strony, a przy 30 px pasek sześciu kafli wymagał 1320 px
> na jeden rząd, czyli zawijał się już na 1366 px.
> **(2)** Dwie nowe rodziny komponentów w spisie (§4): **`.kafle` / `.kafel`** (pasek kafli
> pulpitu) i **`.kal-nav` / `.kal-btn` / `.kal-grupa`** (blok nagłówka kalendarza, Z8). Obie mówią
> tym samym językiem: ramka 1 px `--c-line`, promień `--radius-card`, tło `--c-surface`.
> ⚠ `.kafle` **nie jest** modyfikatorem `.stats` i `.stats--duze` już nie istnieje — tamten
> modyfikator urósł do 82 linii, czyli przerósł komponent, który modyfikował, a `.stats` stoi
> na czterech innych ekranach, gdzie wartość kafelka bywa tekstem, nie liczbą.
> **(3)** Reguła zmierzona, nie wyczytana: **zapytanie kontenerowe nie dotyczy samego kontenera.**
> `@container` pyta o najbliższego PRZODKA-kontener, więc reguła ustawiająca `grid-template-columns`
> na elemencie, który sam jest kontenerem, nie wejdzie nigdy — i nie zgłosi błędu. Zmierzone:
> sześć kolumn po 38,7 px w powłoce telefonu. Zawijanie przestawia więc **rozpiętość dzieci**
> (`grid-column: span N`), nie liczbę kolumn rodzica.
> **(4)** Znacznik „dziś" to **obwódka całej kratki** (`msiatka__kratka--dzis`, `inset 0 0 0 2px`
> w `--c-accent`), a nie plakietka na numerze. Plakietka przy numerze dwucyfrowym wychodziła poza
> kratkę i myliła się z paskiem rodzaju.
> **(5)** Legenda rodzajów stoi **w bloku nagłówka kalendarza**, jedna dla wszystkich widoków —
> i **nie renderuje się na skali Lista**, bo tam rodzaj stoi słowem i nie ma ani jednej
> pokolorowanej kratki, którą legenda miałaby opisywać.
> ⚠ §4c (dwie siatki miesiąca) **przestaje być pytaniem** — patrz tam.

> **Zmiany 1.16 → 1.17:** nazwany wyjątek osi czasu (1.14) **przepisany**, nie opatrzony uwagą —
> kolor rodzaju niesie **wypełnienie**, obrysu nie ma, nośnikiem czytelności jest **tekst na pasku**
> (5,08–6,60), a legenda bierze te same tokeny. ⚠ Dopisane **zmierzone ograniczenie**: trzy pastele
> różnią się tylko barwą (wzajemnie 1,012–1,033), więc w odcieniach szarości rodzaj jest
> nierozpoznawalny, a ściemnianie pasteli tego nie naprawia — drugi, niekolorowy kanał rodzaju
> to osobna decyzja. §3.1 dostaje **drugi nazwany wyjątek** od „zieleń = akcja", z granicą
> na `.team-grid__bar`.

> **Zmiany 1.15 → 1.16:** nowy **§13 — słownictwo: jednostka organizacyjna nazywa się FIRMA**
> (rozstrzygnięcie Krzysztofa 11.10.2026: „nie każda firma jest spółką").

> **Zmiany 1.0 → 1.1** (po zmierzeniu pierwszego wdrożenia w przeglądarce): `--page-max` 1280 → 1560
> i odstęp doliczany **na zewnątrz** limitu; `--c-text-4` i `--c-text-5` przestają być kolorami tekstu
> (3,2:1 — poniżej normy); nagłówek kolumny dostaje z powrotem górny odstęp i wyrównanie zgodne
> ze swoją kolumną; nowa reguła kolumny rozciągliwej w tabeli; nowy §4a (wysokość kontrolek)
> i §5.5 (kreator podłączenia). Trzy z tych rzeczy były błędami w wersji 1.0, nie w kodzie.
>
> **Zmiany 1.1 → 1.2** (po obejrzeniu wdrożenia przez Krzysztofa): nowy token `--c-overlay`;
> reguła kontrastu dla podpisów leżących **poza kartą**; doprecyzowana reguła przedrostków w §9
> (sformułowanie 1.1 było sprzeczne z własnymi przypadkami testowymi); nowy §5.6 — **potwierdzanie
> operacji nieodwracalnych** i **pokazywanie sekretu jeden raz**, czego dokument nie opisywał wcale;
> nowy §5.7 — wymiana fragmentu zamiast przeładowania strony.
>
> **Zmiany 1.2 → 1.3:** nowy §10 — **czas i strefa czasowa** (zmierzone: ekrany pokazują UTC,
> czyli u klienta w Polsce godzinę o dwie wcześniejszą niż na jego zegarze); poprawiony zapis
> o `:focus-visible` w §5.9 (wymaganie 1.2 było niewykonalne i sprzeczne ze specyfikacją
> przeglądarek); `.btn--google` i `.btn--apple` dopisane do §4a.
>
> **Zmiany 1.3 → 1.4:** **licznik nakładki przy wymianie klucza znika** — stary klucz działa,
> dopóki nowy nie przejmie, bez terminu. Poprawiona treść reguły na ekranie integracji (§5.3).
>
> **Zmiany 1.9 → 1.10:** ⚠ `.input--sm` **usunięty ze spisu — nie istniał w arkuszu**, a spis
> go wymieniał; węższe pole robimy regułą kontekstową. ⚠ Nowa reguła w §4c: **jedna nazwa
> komponentu nagłówka strony** — `.page__head` zostaje, `.page-header` znika (trzeci raz ta sama
> klasa błędu po `.note`/`.notice` i dwóch siatkach miesiąca).
>
> **Zmiany 1.8 → 1.9:** §4 przepisany na **spis rodzin** po skasowaniu starego systemu — trzy
> stare arkusze nie istnieją, a `30-components.css` ma **164 klasy**. Pełne opisy
> („czego NIE robi") w `docs/komponenty-do-spisu.md`, **część obowiązująca**, nie załącznik.
> Nowy §4c — **dwie siatki miesiąca**, decyzja odłożona do makiety świadomie.
>
> **Zmiany 1.7 → 1.8:** §4b rozstrzyga **`.note` kontra `.notice`** — dwa paski komunikatu
> istniały obok siebie, a `.note` był w arkuszu **przed** regułą o jednej nazwie. Zostaje
> `.notice`. Do §4 dochodzą `.input--area` i nazwana luka `.form-grid`. ⚠ Zmierzone przy okazji:
> `30-components.css` definiuje **115 klas**, a spis §4 wymienia **45** — brakuje **dwudziestu
> jeden rodzin**. Pełny wykaz w `docs/komponenty-do-spisu.md`.
>
> **Zmiany 1.6 → 1.7:** ⚠ poprawiony §11.4 — warunek zamiany tabeli na listę był **błędny**
> (`wskaźnik zgrubny`, potem `szerokość okna`); obowiązuje **szerokość kontenera**, przez zapytanie
> `@container`. Zmierzone: mobilny na komputerze ma kolumnę **520 px** przy oknie 1920, więc żaden
> z poprzednich warunków nie zadziałał. Ta sama poprawka dotyczy komórki kalendarza. Do §4 dochodzą
> `.table__lead` i `.table__state`; nowy §4b — **jedna nazwa paska komunikatu**.
>
> **Zmiany 1.5 → 1.6:** nowy §11 — **wariant mobilny**, po obejrzeniu go po raz pierwszy
> na szerokości telefonu. Odwołana wcześniejsza diagnoza („mobilny bez reguł" — była błędna,
> wyciągnięta z jedynego ekranu widocznego bez sesji); powłoka mobilna na tokenach; fundament
> dotykowy jako reguła **wspólna**, nie mobilna; tabela na telefonie zamieniona w listę.
>
> **Zmiany 1.4 → 1.5:** nowy komponent **`.input-group`** w §4a — kontrolka wbudowana w pole
> (pokazanie hasła, ikona, jednostka). Powód: pole hasła było **węższe od pola loginu**
> o szerokość przycisku „Pokaż" stojącego obok niego.

Ten dokument jest źródłem prawdy dla warstwy wizualnej tenanta. Powstał na podstawie przeglądu
działającej instancji i zatwierdzonej makiety czterech ekranów.

> ⚠ **Zasada nadrzędna: nie poprawiamy ekranów, tylko wprowadzamy system.**
> Poprawka „na tym jednym ekranie" jest tym, co doprowadziło do stanu obecnego: każdy ekran ma
> dziś własne odstępy, własny rozmiar nagłówka i własne rozumienie tego, czym jest karta.
> Najpierw zmienne i klasy bazowe, potem ekran po ekranie — nigdy odwrotnie.

---

## 0. Diagnoza stanu zastanego (skrót)

Aplikacja nie jest brzydka — jest **bez systemu**. Objawy, które to zdradzają:

- bloki stykają się z krawędziami kontenera i ze sobą; tabela „wisi" wewnątrz karty,
- nagłówek strony ~44 px wersalikami, a zaraz pod nim 13 px — brak skali pośredniej,
- trzy zielenie konkurują ze sobą (logo, przycisk, plakietka), a 36 plakietek `aktywne`
  w jednej kolumnie to tapeta, nie informacja,
- dane techniczne (`Etat 1,0000`, `hmac:…`, `Identyfikator R2`) stoją obok danych dla człowieka,
- lewy pasek ma 12 pozycji bez podziału i ikony z różnych zestawów; górny pasek ma pustą lewą połowę.

---

## 1. Pliki i kolejność ładowania

```
/public/assets/css/
  00-tokens.css       <- WYŁĄCZNIE :root { --… }. Żadnego selektora poza :root.
  10-base.css         <- reset, @font-face, typografia, linki, focus
  20-layout.css       <- powłoka aplikacji: sidebar, topbar, main, page
  30-components.css   <- card, table, btn, chip, input, nav, segmented, stats, defs, kafle,
                         kal-nav, msiatka, team-grid — z wyjątkami ekranowymi na miejscu
  20-layout-mobile.css <- powłoka telefonu (§11.2) i nieliczne nadpisania wymuszone szerokością
/public/assets/fonts/
  ibm-plex-sans-{400,500,600}-{latin,latin-ext}.woff2
  OFL.txt             <- licencja, obowiązkowo w paczce klienta
```

Ładowane w `layout/base.twig` przez `asset()`, w tej kolejności, jako `<link rel="stylesheet">`.
Nie łączymy ich na razie w jeden plik — na etapie budowania systemu rozdział jest wart jednego
dodatkowego żądania. Gdy system się ustabilizuje, konkatenację robi krok builda, nie ręka.

⚠ **Reguła blokująca: poza `00-tokens.css` w żadnym pliku CSS nie ma wartości `#rrggbb`,
`rgb(...)` ani `px` odstępu spoza skali.** Dopisz to do checklisty odbioru i sprawdzaj
`grep -nE '#[0-9a-fA-F]{3,8}' public/assets/css/ --exclude=00-tokens.css` — ma nie zwracać nic.

### Krój pisma — hostujemy u siebie, nie z Google Fonts

**IBM Plex Sans**, wagi 400/500/600, podzbiory `latin` i `latin-ext` (polskie znaki), format `woff2`.

⚠ Pobieranie z `fonts.googleapis.com` **łamie CSP z §13** (`default-src 'self'`, bez `font-src`)
i wymagałoby rozluźnienia polityki — czyli dokładnie tego, czego §6 i §13 zabraniają.
Dodatkowo instalacje w intranecie bez wyjścia na świat dostałyby fallback systemowy.
Dlatego: pliki `woff2` jadą w paczce, `@font-face` w `10-base.css`, licencja OFL w `/public/assets/fonts/OFL.txt`.

---

## 2. `00-tokens.css` — do przepisania wprost

```css
:root {
  /* ——— kolor: role, nie nazwy barw ——— */
  --c-bg:             #F5F6F8;   /* tło strony */
  --c-surface:        #FFFFFF;   /* karta, pasek boczny, pasek górny */
  --c-surface-mute:   #FCFCFD;   /* stopka karty, co drugi wiersz w tabeli z zebrą */
  --c-surface-hover:  #F7F8FA;   /* wiersz tabeli pod kursorem */
  --c-line:           #E6E8EC;   /* linia rozdzielająca */
  --c-line-soft:      #F0F2F5;   /* linia między wierszami tabeli */
  --c-line-strong:    #D4D8DF;   /* obrys kontrolki */

  --c-text:           #15181D;   /* nagłówek, dana główna */
  --c-text-2:         #2A2F39;   /* treść */
  --c-text-3:         #6B7280;   /* dana drugorzędna, podtytuł */
  --c-text-4:         #8A909C;   /* ⚠ TYLKO ikony i separatory — nigdy tekst (3,2:1) */
  --c-text-5:         #A8AEB9;   /* ⚠ TYLKO elementy dekoracyjne — nigdy tekst */

  --c-accent:         #12874A;   /* AKCJA — i nic innego */
  --c-accent-hover:   #0E6E3C;
  --c-accent-soft:    #E8F4EC;
  --c-accent-text:    #0E6E3C;

  --c-warn:           #C07C00;
  --c-warn-soft:      #FDF3E2;
  --c-warn-text:      #8A5300;
  --c-warn-line:      #F0D8A8;
  --c-warn-bg:        #FFFCF5;

  --c-danger:         #B42318;
  --c-danger-soft:    #FDECEA;
  --c-danger-text:    #9F2115;

  --c-neutral-soft:   #F0F2F5;   /* plakietka stanu obojętnego */
  --c-dot:            #B9BFC9;   /* kropka stanu normalnego */
  --c-focus:          #12874A;
  --c-overlay:        rgba(20, 24, 32, .45);  /* przyciemnienie tła pod oknem modalnym */

  /* ——— odstępy: dozwolone są TYLKO te wartości ——— */
  --s-1: 4px;  --s-2: 8px;  --s-3: 12px; --s-4: 16px;
  --s-5: 24px; --s-6: 32px; --s-7: 48px;

  /* ——— typografia ——— */
  --f-sans: 'IBM Plex Sans', system-ui, -apple-system, 'Segoe UI', sans-serif;
  --t-h1:      30px;   /* nagłówek strony */
  --t-stat:    24px;   /* dana kafelka na pulpicie (1.18) */
  --t-h2:      20px;   /* nagłówek sekcji / karty */
  --t-lead:    16px;   /* wyróżniona treść, tytuł karty */
  --t-body:    14px;   /* treść i KAŻDA komórka tabeli */
  --t-small:   13px;   /* pomocnicze, przyciski drugorzędne */
  --t-caption: 12px;   /* podpis, opis pola, metryka */
  --t-label:   11px;   /* NAGŁÓWEK KOLUMNY, etykieta sekcji nawigacji */
  --lh-tight: 1.25;
  --lh-body:  1.55;
  --w-regular: 400; --w-medium: 500; --w-semibold: 600;
  --ls-tight: -0.5px;  /* tylko h1 */
  --ls-label:  0.5px;  /* tylko wersaliki 11px */

  /* ——— kształt: jeden promień, jeden cień ——— */
  --radius:       8px;
  --radius-card: 10px;
  --shadow-card: 0 1px 2px rgba(20, 24, 32, .04);
  --shadow-pop:  0 8px 24px rgba(20, 24, 32, .10);

  /* ——— wymiary powłoki ——— */
  --sidebar-w:  248px;
  --topbar-h:    64px;
  --row-h:       48px;   /* wiersz tabeli */
  --control-h:   38px;   /* input, select, przycisk */
  --control-h-sm: 32px;
  --page-max:  1560px;   /* szerokość TREŚCI; odstęp dolicza się na zewnątrz, nie od środka */
  --measure:     72ch;   /* maksymalna długość wiersza tekstu ciągłego */
}
```

⚠ **Ciemny motyw: nie teraz.** Tokeny są tak nazwane, żeby dało się go dołożyć jednym blokiem
`@media (prefers-color-scheme: dark)` nadpisującym wyłącznie `--c-*`. Robienie dwóch motywów przed
ustabilizowaniem jednego to podwójna praca — wracamy do tego po zamknięciu tury B.

---

## 3. Reguły, z których wynika wygląd

### 3.1 Kolor

- **Zieleń = akcja.** Przycisk główny, aktywna pozycja nawigacji, link. Nigdzie indziej.
- ⚠ **Jeden przycisk główny na ekran** — ten, po który użytkownik na ten ekran przyszedł.
  Dwa `btn--primary` obok siebie nie podpowiadają niczego: zieleń przestaje znaczyć „to
  zrób teraz" i zaczyna znaczyć „to jest przycisk". Zmierzone 21.09 na `/admin/konta`:
  `Wyślij zaproszenia` (główna czynność ekranu) i `Konto administracyjne` (czynność
  wykonywana raz na wdrożenie) miały tę samą klasę. **Czynność rzadka jest `btn--ghost`**,
  nawet jeśli jest ważna — waga w interfejsie opisuje częstość i kolejność, nie doniosłość.
  Okno modalne albo szuflada mają własny przycisk główny; liczy się jeden na warstwę.
- **Stan normalny nie dostaje koloru.** `aktywne` to szara kropka (`--c-dot`) i tekst
  `--c-text-3`, nie zielona plakietka. Kolorem świeci wyłącznie odstępstwo:
  `wygasa za 6 dni` (bursztyn), `przerwany` (czerwień), `zakończone` (szarość).
- **Kolumna nazwisk nie jest niebieska.** Nazwisko to `--c-text`, waga 500, `text-decoration: none`;
  podkreślenie pojawia się dopiero przy `:hover`.
- ⚠ **Jedyny nazwany wyjątek od „zieleń = akcja": zaznaczenie wiersza** (wybór osób do wysyłki
  zaproszeń). Zaznaczony wiersz ma tło `--c-accent-soft` i pasek `inset 3px 0 0 var(--c-accent)`
  przy lewej krawędzi. **To nie jest odstępstwo, tylko wyjątek z uzasadnieniem:** zaznaczenie
  jest **decyzją użytkownika**, a nie cechą danych — i prowadzi wprost do zielonego przycisku
  głównego („Wyślij zaproszenia") w pasku nad tabelą. Zieleń łączy tu decyzję z jej skutkiem,
  zamiast oceniać dane. ⚠ **Pasek jest ważniejszy niż tło** i on niesie tę informację: przy
  zmianie tonu albo w trybie wysokiego kontrastu tło może zniknąć, pasek zostaje.
  Rozstrzygnięcie 22.09.2026 (wariant C makiety `konta/07-warianty-wierszy.html`).
- ⚠ **Drugi nazwany wyjątek (11.10.2026): pastel urlopu w osi czasu.** Pasek nieobecności
  typu „urlop" ma zielone **wypełnienie** (`--c-pasek-urlop-tlo`, `#DCF0E4`) i stoi na **danej**,
  nie na akcji. Powód i granica w nazwanym wyjątku 1.14: ton jest o rząd słabszy od
  `--c-accent`, element jest nieklikalny i nigdy nie sąsiaduje z przyciskiem. ⚠ Wyjątek
  kończy się na `.team-grid__bar` — nie wolno go rozciągać na plakietki, wiersze tabel
  ani siatkę miesiąca.

### 3.2 Typografia

- Nagłówek strony: `--t-h1`, waga 600, `letter-spacing: var(--ls-tight)`, **bez `text-transform`**.
- Nagłówek kolumny tabeli: `--t-label`, wersaliki, **`--c-text-3`** — ciszej niż dane, ale wciąż
  czytelnie. ⚠ W wersji 1.0 było tu `--c-text-4`; zmierzone na żywo dało **3,21:1**, czyli poniżej
  wymaganych 4,5:1. Tekst nigdy nie jest jaśniejszy niż `--c-text-3`.
- ⚠ **Nazwisk nie transformujemy w CSS — nigdy `text-transform` na danych osobowych.**
  Wielkość liter poprawia **funkcja w PHP**, po stronie prezentacji, wedle zasad z §9.
  CSS nie wie, gdzie kończy się przedrostek, a gdzie zaczyna nazwisko.

### 3.3 Odstępy i proporcje

- Wewnątrz karty **24 px**, między kartami **24 px**, treść od krawędzi obszaru **32 px**.
- ⚠ **`--page-max` to szerokość treści, a odstęp dolicza się na zewnątrz**, nie od środka:
  ```css
  .page { max-width: calc(var(--page-max) + 2 * var(--s-6)); padding-inline: var(--s-6); margin-inline: auto; }
  ```
  W wersji 1.0 limit 1280 px obejmował również odstępy, więc przy oknie 1920 px treść dostawała
  **1216 px z 1657 px dostępnych** — 441 px pustki po bokach. Zmierzone, nie oszacowane.
- **Tekst ciągły ma własne ograniczenie `--measure` (72ch)**, niezależne od szerokości strony.
  Akapit czyta się źle w linii 1500 px, a tabela nie — jedno ograniczenie nie obsłuży obu.
- Nic nie styka się z krawędzią: kafelki, karty i tabele mają zawsze odstęp od kontenera.

### 3.4 Tabele — to jest 80% tej aplikacji

- Wiersz `--row-h` (48 px), padding poziomy 16 px; **pierwsza i ostatnia komórka 20 px**,
  żeby treść trzymała się krawędzi karty, a nie wisiała w niej.
- ⚠ **Nagłówek kolumny ma górny odstęp `--s-4`**: `th { padding: var(--s-4) var(--s-4) 10px; }`.
  Bez niego tekst nagłówka leży na krawędzi karty i jest przycinany przez `overflow: hidden`
  i zaokrąglenie — tak wyglądało pierwsze wdrożenie.
- ⚠ **`th` ma wyrównanie swojej kolumny.** Nagłówek kolumny liczbowej jest wyrównany w prawo
  razem z liczbami; nagłówek wyrównany w lewo nad liczbami wyrównanymi w prawo wisi obok nich,
  a nie nad nimi.
- ⚠ **Nadmiar szerokości idzie w JEDNĄ kolumnę, nie we wszystkie.** To jest prawdziwy powód,
  dla którego szeroka tabela wygląda źle: przeglądarka rozdziela zapas równo, więc liczba odsuwa się
  od swojego nagłówka. Kolumna niosąca najdłuższy tekst dostaje klasę `.table__grow`
  (`width: 100%`), pozostałe `white-space: nowrap` i kurczą się do treści.
  Kolumna rozciągliwa jest **wskazywana per tabela** — w tabeli pracowników to nazwisko,
  w tabeli przebiegów nazwa firmy.
- **Bez linii pionowych.** Pozioma linia `--c-line-soft` między wierszami, mocniejsza
  `--c-line` pod nagłówkiem. Ostatni wiersz bez linii.
- ⚠ **Tabela na pełną szerokość karty jest BEZPOŚREDNIM DZIECKIEM karty.** Wcięcie treści robi
  `padding` komórki (24 px + 1 px obramowania karty = **25 px** od krawędzi), nigdy opakowanie
  z własnym `padding`. Paski narzędzi nad tabelą i stopka pod nią mają swój `padding` osobno.
  Zmierzone 22.09.2026 przed poprawką: `/admin/konta` miało tabelę w `card__body` (padding 24),
  więc pierwsza komórka zaczynała się **49 px** od krawędzi karty, a tabela była **o 48 px
  węższa** niż karta, w której stała. Ten sam defekt miało jeszcze **pięć ekranów** (14 tabel):
  `/wnioski`, `/dane`, `/dane/synchronizacja`, `/diagnostyka`, `/styleguide`.
  ⚠ Pilnuje tego `dev/bramka-ekranowa.py` — jedna liczba na tabelę, sprawdzana na każdym ekranie
  (23 tabele, stan 22.09.2026). Sprawdzenie pomija tabelę rekordów w trybie karty, gdzie wiersz
  nie jest wierszem (`display: block`, zerowy padding komórki).
- ⚠ **Zebra i podświetlenie wiersza to MODYFIKATOR `.table--zebra`, nie domyślny wygląd tabeli**
  (rozstrzygnięcie 22.09.2026, wariant C makiety). Co drugi wiersz `--c-surface-mute`, wiersz
  pod kursorem `--c-surface-hover`, wysokość i linie bez zmian.

  **Reguła, po której się przydziela — nie lista ekranów:**

  > `.table--zebra` dostaje tabela, która **rośnie z pracą systemu** — jest stronicowana albo
  > jej długość zależy od tego, ile rekordów **przybyło** (osoby, zdarzenia, przebiegi, wnioski,
  > nieobecności) — **i ma co najmniej cztery kolumny**. Nie dostaje jej tabela o **stałej,
  > krótkiej** liczbie wierszy (konfiguracja, podsumowanie, karta jednego rekordu) ani żadna,
  > w której **kolor niesie znaczenie**.

  ⚠ Sformułowanie „rośnie z pracą systemu" jest **poprawką do pierwszej wersji reguły**, która
  mówiła „zależy od tego, ile klient ma rekordów". Tamta wersja wybierała też `/dane` (tyle
  wierszy, ile spółek) i „Limity z kadr" na karcie pracownika (tyle, ile limitów) — czyli tabele
  **konfiguracyjne**, o krótkiej i przewidywalnej długości. Rozróżnienie jest takie:
  spółek i limitów **przybywa od decyzji człowieka**, a zdarzeń i przebiegów **od samej pracy
  systemu**; zebra jest dla tych drugich.

  Powód, dla którego to nie weszło do `.table`: zmierzone 22.09 — tabelę ma **11 z 33 ekranów**
  (23 tabele), a `/diagnostyka` dziesięć tabel po kilka wierszy, gdzie zebra dokłada szumu
  w miejscu, w którym informację niesie kolor odchylenia. Zebra ma sens tam, gdzie wiersz jest
  **długi i trzeba go przewieść wzrokiem** — na liście osób między nazwiskiem a kolumną „Konto"
  jest 1200 px. Podświetlenie idzie tym samym modyfikatorem, bo na tabeli trzywierszowej
  sugeruje klikalność, której tam nie ma.

  Reguła wybrała (stan 22.09.2026): `/admin/konta`, `/admin/dziennik`, `/dane/firma/{id}`,
  `/dane/synchronizacja`, `/dane/synchronizacja/{id}` (tabela rekordów i tabela konfliktów,
  **bez** „Liczb przebiegu"), `/admin/integracja` (klucze i przebiegi), `/dane/pracownik/{id}`
  (nieobecności), `/wnioski` — a przez wspólny szablon także `/m/wnioski`, gdzie wiersz jest
  kartą i co drugi wpis dostaje przygaszone tło (sprawdzone: czyta się lepiej, nie gorzej).
- **Liczby do prawej**, `font-variant-numeric: tabular-nums`.
- **Formaty dla człowieka, nie z bazy:** `1,0000` → `1,00`; jeśli jest miejsce — `0,75 (3/4 etatu)`.
  Minuty normy → `8 h 00 min`. Jeden format daty w całej aplikacji: `RRRR-MM-DD`.
- **Kolumny techniczne znikają z widoku głównego.** `Identyfikator R2`, `Wpis`, `hmac:…`,
  `Zmieniono` idą pod zwijany pasek „Szczegóły techniczne" na dole ekranu.
- Grupowanie (np. po roku) to wiersz `.table__group` — 34 px, tło `--c-surface-mute`,
  tekst jak nagłówek kolumny.

### 3.5 Nawigacja

- Lewy pasek dzieli się na **trzy sekcje z etykietami**:
  - **Moja praca** — Pulpit, Kalendarz, Moje wnioski, Nowy wniosek
  - **Zespół** — Zespół, Dane z kadr
  - **Administracja** — Konta, Integracja, Diagnostyka, Licencja, Ustawienia
- Pozycja aktywna: tło `--c-accent-soft`, tekst `--c-accent-text`, waga 500, ikona `--c-accent`.
- Górny pasek: **ścieżka po lewej** (`Dane z kadr / Budomex / Nowak Piotr`) — rozwiązuje przy okazji
  brak nawigacji wstecz na ekranie pracownika. Po prawej sam awatar i imię, bez ramki i bez
  podtytułu „Moje konto"; **`Wyloguj` chowa się w menu pod awatarem** — to nie jest akcja pierwszego rzędu.

### 3.6 Ikony

Jeden zestaw, jedna grubość: **konturowe, `viewBox="0 0 20 20"`, `stroke-width: 1.5`,
`fill: none`, `stroke-linecap/linejoin: round`**, rozmiar 18 px w nawigacji, 16 px w treści.
Kolor dziedziczony z `currentColor` albo ustawiony klasą — nigdy `fill` na sztywno.

⚠ **Ikony wstawiamy makrami Twiga, nie `include` ze zmienną.** `{% include 'partials/ikony/' ~ nazwa %}`
to SSTI/LFI i jest zakazane w §5. Poprawnie:

```twig
{# templates/partials/ikony.twig #}
{% macro kalendarz() %}<svg class="ico" viewBox="0 0 20 20" aria-hidden="true">…</svg>{% endmacro %}
```
```twig
{% import 'partials/ikony.twig' as ikony %}
{{ ikony.kalendarz() }}
```

⚠ **Znak pisma nie jest ikoną** (dopisane 22.09.2026, po znalezisku na `/admin/hierarchia`).
Przycisk menu „⋯" renderował się jako **pusty prostokąt**: krój tego środowiska nie ma znaku
U+22EF. Skutek był nieproporcjonalny do przyczyny — **cała ścieżka obsługi bez myszki wisiała
na niewidocznej kontrolce**, a użytkownik widział puste miejsce tam, gdzie miał kliknąć.

Reguła: **do ikon wyłącznie SVG z `partials/ikony.twig`**. Nie używamy znaków spoza podstawowego
zakresu łacińskiego (`⋯ ⋮ × ▾ ✓ ⚙ ⚠ →` i podobnych) jako elementów interfejsu — krój u klienta
jest nieznany, a zastępczy glif albo wygląda inaczej, albo nie istnieje. W treści zdania takie
znaki są dopuszczalne, ale **nazwę kontrolki piszemy słowami**: „menu (trzy kropki)", nie „menu ⋯".

⚠ **Bramka tego nie złapie i nie ma jak.** Przycisk miał poprawne wymiary (28 × 24 px),
poprawny `aria-label` i poprawne miejsce w układzie — pustego glifu nie widać w żadnej mierze
liczbowej, tylko na zrzucie w powiększeniu. To jest znany limit bramki ekranowej, nie jej usterka.

### 3.7 Dostępność — nie na koniec, tylko od razu

- Kontrast tekstu 4.5:1 (3:1 od 24 px). Dlatego akcent to `#12874A`, a nie jaśniejsza zieleń
  z dzisiejszych przycisków — biały tekst na tamtej nie przechodzi (zmierzone: 4,57:1, przechodzi).
- ⚠ **Podpis leżący poza kartą bierze `--c-text-2`, nie `--c-text-3`.** `--c-text-3` jest
  sprawdzony względem `--c-surface` (biel) i daje tam 4,83:1; na tle strony `--c-bg` spada
  do **4,47:1**, czyli tuż pod próg. Dotyczy etykiet pól w pasku filtrów — jedynego miejsca,
  gdzie podpis 12 px leży poza kartą. Tokenu nie ruszamy: zmiana `--c-text-3` dotknęłaby
  wszystkich podpisów w aplikacji, żeby naprawić jedno miejsce.
- ⚠ **Żaden tekst nie jest jaśniejszy niż `--c-text-3`.** `--c-text-4` i `--c-text-5` służą
  do ikon i separatorów. Dotyczy to również podpisów 11–12 px — **im mniejszy tekst, tym bardziej
  potrzebuje kontrastu, a nie mniej.** Zmierzone na pierwszym wdrożeniu: nagłówki kolumn 3,21:1.
- `:focus-visible` z widocznym obrysem w kolorze akcentu — dziś go nie ma w ogóle,
  a aplikacja kadrowa bywa obsługiwana z klawiatury.
- Cel dotykowy ≥ 38 px wysokości dla każdej kontrolki.
- Element klikalny to `<button>` albo `<a href>`. **Nigdy `role="button"` na `<div>`** —
  tabulator go pomija. Przycisk z samą ikoną dostaje `aria-label`.
- Kolor nigdy nie jest jedynym nośnikiem informacji: przy kropce stanu zawsze stoi słowo.

### 3.8 htmx

- Reguły `.htmx-indicator` mieszkają w `30-components.css`, a w layoucie jest
  `<meta name="htmx-config" content='{"includeIndicatorStyles":false}'>` — inaczej htmx wstrzykuje
  własny `<style>` do `<head>` i łamie CSP na każdym ekranie (§5).
- Stan ładowania to **klasa**, nigdy `style=`.
- Fragmenty dziedziczą te same klasy co widok pełny — fragment nie ma własnego wyglądu.

---

## 4. Klasy — nazewnictwo i zakres

Konwencja: `blok`, `blok__element`, `blok--wariant`. Bez zagnieżdżania głębszego niż jeden poziom.

**Powłoka (`20-layout.css`)**
`.app` `.sidebar` `.sidebar__brand` `.nav-group` `.nav-group__label` `.nav-item` `.nav-item--on`
`.nav-item__ico` `.topbar` `.crumbs` `.crumbs__sep` `.user-menu` `.user-menu__avatar`
`.main` `.page` `.page__head` `.page__title` `.page__sub` `.page__actions` `.toolbar`

**Komponenty (`30-components.css`) — spis rodzin**

⚠ **Ten spis wymienia RODZINY, nie każdą klasę.** Pełny wykaz z opisami „do czego" i — ważniejszym —
„**czego NIE robi**" jest w `docs/komponenty-do-spisu.md` i jest jego **częścią obowiązującą**,
nie załącznikiem. ⚠ **Nazwa komponentu, której nie ma w spisie, to nazwa, którą ktoś za miesiąc
wymyśli drugi raz** — dokładnie tak powstały dwa paski komunikatu (§4b) i dwie siatki miesiąca.

*Karta i treść*
`.card` `.card__head` `.card__title` `.card__meta` `.card__body` `.card__body--tight` `.card__foot`
`.stack` `.stack--sm` `.stack--lg` `.divider` `.empty-state` `.disclosure` `.disclosure__body`

*Tabela i lista rekordów*
`.table` `.table--rekordy` `.table__lead` `.table__state` `.table__num` `.table__mute`
`.table__name` `.table__group` `.table__grow` `.table__empty` `.table-scroll` `.pager`

*Lista wierszy*
`.settings-list` `.settings-list__row` `.settings-list__copy`

*Kalendarz — siatka miesiąca (jedna, od 1.18)*
`.msiatka` `.msiatka__siatka` `.msiatka__kratka` `.msiatka__kratka--urlop|--zasilek|--kadrowe`
`.msiatka__kratka--wolny` `.msiatka__kratka--poza` `.msiatka__kratka--dzis` `.msiatka__nazwa`
`.msiatka__dzien-tygodnia` `.msiatka__suma` `.dzien-dzis`
⚠ Ta sama siatka obsługuje **skalę Roku** (dwanaście pudełek) i **Miesiąc „Tylko ja"** — różnicę
robią reguły zawężone do `.mies__kalendarz`, a nie drugi komponent (§4c).

*Kalendarz — oś czasu zespołu i firmy*
`.team-grid` `.team-grid--days-28…31` `.team-grid__head` `.team-grid__head--dzis|--free|--ostatni`
`.team-grid__person` `.team-grid__cell` `.team-grid__cell--free|--ostatnia|--span-N`
`.team-grid__bar` `.team-grid__bar--urlop|--zasilek|--kadrowe|--jeden` `.team-grid__dow`
`.team-grid__numer`

*Kalendarz — Miesiąc „Tylko ja" i wykaz wpisów*
`.mies` `.mies__kalendarz` `.mies__wpisy` `.mies__naglowek`
`.wpisy` `.wpisy__poz` `.wpisy__kropka` `.wpisy__kropka--urlop|--zasilek|--kadrowe`
`.wpisy__tresc` `.wpisy__rodzaj` `.wpisy__meta`
⚠ Wykaz pokazuje **pięć wpisów i odnośnik „wszystkie (N)"** — przycina serwer, nie wysokość
pudełka (lista ucięta stylem dalej niesie cały HTML dla czytnika ekranu).

*Kalendarz — blok nagłówka (1.18, zlecenie Z8)*
`.kal-nav` `.kal-nav__gora` `.kal-nav__dol` `.kal-nav__lewa` `.kal-nav__prawa`
`.kal-grupa` `.kal-btn` `.kal-btn--on` `.kal-btn--ikona` `.kal-btn__etykieta`
`.legend` `.legend__item` `.legend__mark` `.legend__mark--urlop|--zasilek|--kadrowe|--free`
⚠ Kwadraty `.kal-btn` to **osobny komponent, nie `.segmented`** — `.segmented` stoi na czterech
innych ekranach (przełącznik roku, zakres uprawnień) i tam zostaje taki, jaki jest.

*Pasek kafli pulpitu (1.18, zlecenie Z15)*
`.kafle` `.kafel` `.kafel__glowa` `.kafel__etykieta` `.kafel__dana` `.kafel__wartosc`
`.kafel__wartosc--brak` `.kafel__wartosc--odchylenie` `.kafel__reszta` `.kafel__opis`

*Wybór zakresu i kontekstu*
`.menu` `.menu--wybor` `.menu__trigger` `.menu__trigger--osoby` `.menu__body` `.menu__item`
`.menu__powod` `.wybor-osob` `.wybor-osob__etykieta` `.wybor-osob__stan`
`.pickbox` + `__head` `__szukaj` `__list` `__row` `__item` `__name` `__tag` `__licznik` `__foot`
`__akcja` `__note` `__form` `__box`, warianty `--js` `--nad` `--bez-masowych`
`.spolka` `.spolka--wybor` `.spolka--staly` `.spolka__trigger` `.spolka__nazwa` `.spolka__panel`
`.spolka__grupa` `.spolka__pozycja` `.spolka__pozycja--on` `.spolka__stopka` `.spolka__uwaga`
`.spolka__strzalka`

*Kontrolki*
`.btn` `.btn--primary` `.btn--ghost` `.btn--quiet` `.btn--danger` `.btn--sm` `.btn--block`
`.btn--google` `.btn--apple` `.btn-row`
`.field` `.field__label` `.field__hint` `.field--error` `.field--check`
`.input` `.input--code` `.input--area` `.input-group` `.input-group__action` `.select`
`.form-column` `.form-grid` `.form-link` `.inline-form`

*Stan i oznaczenia*
`.chip` `.chip--warn` `.chip--danger` `.notice` `.notice--warn` `.notice--danger` `.notice--dev`
`.status` `.status__dot` `.dot--ok` `.dot--warn` `.dot--err`
`.progress` `.progress--warning` `.progress-line` `.stepper` `.step-dot` `.step-dot--active`
`.step-dot--done` `.eyebrow` `.company-mark` `.event-id`

*Zestawienia*
`.stats` `.stats__item` `.stats__key` `.stats__value` `.defs` `.defs__term` `.defs__value`
`.segmented` `.segmented__item` `.segmented__item--on`

*Okna i przebiegi*
`.modal` + `__head` `__body` `__foot` `__close`
`.confirm` + `__title` `__lead` `__list` `__actions`
`.checklist` + `__item` `__label`
`.secret` + `__row` `__value` `__done`
`.wizard` + `__form` `__field` `__state` `__step` `__mark` `__name` `__hint` `__next`
`.qr-card` `.backup-grid` `.backup-code`

*Pozostałe*
`.ico` `.ico--sm` `.mono` `.htmx-indicator` `.htmx-request` `.spinner`

⚠ **`.input--sm` NIE ISTNIEJE i nie powstanie.** Był w tym spisie przez pomyłkę — spis kłamał.
Trzeci rozmiar kontrolki otwiera natychmiast pytanie, co z nim przy wskaźniku zgrubnym, gdzie
`.input` rośnie do 44 px (§11.3). **Węższe pole w konkretnym miejscu robimy regułą kontekstową**
(`.pick__adres .input { width: 15rem }`), wzorem `.toolbar .field__label` — kontekst zmienia
szerokość, a nie wysokość, więc nie kłóci się z fundamentem dotykowym.

⚠ **Kolor ma dokładnie dwa z nich**: `.progress--warning` (bursztyn — stan wymagający uwagi)
i `.field--error` (czerwień **na obrysie**, bo tło zrobiłoby z pola komunikat). Wszystko inne
jest bezbarwne, łącznie z krokiem zrobionym w kreatorze i wypełnieniem paska postępu —
**postęp jest daną, nie akcją** (§2).

⚠ `.mono` nie oznacza innego kroju — oznacza `font-variant-numeric: tabular-nums` i lekki
`letter-spacing` dla identyfikatorów i godzin. Drugiego kroju nie wprowadzamy.

---

## 4a. Kontrolki — wysokość jest ustawiana, nie dziedziczona

⚠ **`.input`, `.select` i `.btn` mają `height: var(--control-h)` wpisaną wprost.** Bez tego
w pasku ustawionym na flex z domyślnym `align-items: stretch` rozciągają się do wysokości
najwyższego sąsiada. Zmierzone na pierwszym wdrożeniu: pole tekstowe 38 px (poprawnie, bo siedzi
w opakowaniu z etykietą), a stojące obok `select` **56 px** i przycisk **52 px** — bo to opakowanie
z etykietą wyznaczyło wysokość wiersza.

- Pasek filtrów i każdy rząd kontrolek: `align-items: flex-end` (etykiety mają różną wysokość,
  a kontrolki mają stać równo dołem).
- `.btn--sm` i `.input--sm`: `--control-h-sm`.
- **Pole wyszukiwania to `input type="text"`, nie `type="search"`.** Przeglądarki dokładają
  polom wyszukiwania własne wykończenie i krzyżyk czyszczący, więc ten jeden element wyłamuje się
  z paska niezależnie od naszego CSS.
- ⚠ **`.btn--google` i `.btn--apple` są wariantami systemu, nie reliktami.** Zmierzone na
  `/logowanie`: oba mają dziś **przezroczyste tło i przezroczysty obrys**, czyli są niewidoczne —
  ich reguły zostały w starym `base.css`, a nasz `.btn` ładuje się później i przy tej samej wadze
  selektora wygrywa. Oba wchodzą do `30-components.css`: obrys `--c-line-strong`, tło `--c-surface`,
  tekst `--c-text-2`, wysokość `--control-h`, logo dostawcy jako `<svg>` w makrze Twiga.
  Kolorów marek nie odtwarzamy — przycisk ma być nasz, z ich znakiem.
- ⚠ **Pole z kontrolką w środku to `.input-group`, nie dwa elementy obok siebie.**
  Zmierzone na `/logowanie`: pole loginu leżało w `.field` i zajmowało całą szerokość, a pole hasła
  w `.input-row` (flex, `gap: 8px`) **dzieliło tę szerokość z przyciskiem „Pokaż"** — więc dwa pola
  tego samego formularza miały różną szerokość i nie ustawiały się w pionie.

  ```
  .input-group { position: relative; }
  .input-group .input { width: 100%; padding-right: calc(var(--s-2) + 34px); }
  .input-group__action { position: absolute; right: var(--s-1); top: 50%; transform: translateY(-50%);
                         width: 30px; height: 30px; }
  ```

  - kontrolka w środku jest **przyciskiem z samą ikoną**, `aria-label` i `aria-pressed`,
    ⚠ **nigdy przyciskiem z etykietą tekstową, która się zmienia** („Pokaż" ↔ „Ukryj"):
    zmiana szerokości napisu przesuwałaby wcięcie pola przy każdym kliknięciu,
  - `type="button"` — przycisk w formularzu bez tego atrybutu **wysyła formularz**,
  - ⚠ `input::-ms-reveal { display: none }` — inaczej Edge dokłada **drugi** znak oka obok naszego,
  - odstęp od prawej krawędzi zostawiamy niewielki, ale nie zerowy: menedżery haseł wstawiają
    w tym miejscu własną ikonę i dwie rzeczy nie mogą stać na sobie.

  Komponent jest ogólny — ta sama konstrukcja obsłuży pole wyszukiwania z ikoną i pole z jednostką.
- Nazwy wariantów: `.btn--primary`, `.btn--ghost`, `.btn--quiet`, `.btn--danger`, `.btn--sm`.
  ⚠ `.btn--secondary` i `.btn--small` **nie istnieją** — element z taką klasą zostaje bez stylu.

## 4b. Pasek komunikatu ma jedną nazwę

⚠ Zmierzone przy migracji: pasek komunikatu istniał w **czterech** odmianach —
`.notice`, `.box`, `.error-message` i `.error--fragment` w odpowiedziach htmx — o różnym
wyglądzie i różnych modyfikatorach. Obowiązuje **jedna**: `.notice`, z wariantami
`--warn` i `--danger`.

- ⚠ **Zielonego wariantu nie ma.** „Sesja wygasła", „zapisano", „gotowe" — to stany obojętne
  albo odstępstwa, nie akcje. Zieleń znaczy akcję (§2), a pasek komunikatu nie jest przyciskiem.
- ⚠ **Nazwy klas nie sklejamy w JavaScripcie z fragmentów** (`'flash ' + kind`). Zmierzone:
  `modules/flash.js` robił dokładnie to, więc przemianowanie komponentu wyłączyłoby styl
  **po cichu** — nie widać tego ani na zrzucie, ani w wyszukiwaniu po nazwie klasy.
- ⚠ **`.note` nie istnieje.** Zmierzone: pasek komunikatu był w arkuszu w **dwóch** wersjach —
  `.note` (pełne obramowanie 1 px, promień karty, tło `--c-surface`, **10+ użyć**) i `.notice`
  (lewa krawędź 3 px, tło `--c-neutral-soft`). Osobno oba wyglądają sensownie i właśnie dlatego
  nikt tego nie zauważył. **Zostaje `.notice`**, bo nie udaje karty i nie konkuruje z `.card`
  stojącą w tym samym miejscu; `.note` znika z szablonów.
- ⚠ **Modyfikatora nie budujemy z danych** (`chip--{{ status }}`). Stan mapuje na modyfikator
  **białą listą w jednym makrze**; inaczej pierwszy nowy status wytworzy klasę, której system
  nie ma, i nikt tego nie zauważy.
- ⚠ **Trzecie wystąpienie, 22.09.2026: `hidden`, który nie ukrywał.** `[hidden]` z arkusza
  przeglądarki ma najniższą możliwą wagę, więc `.notice { display: block }` je biło, i pusty
  `DIV#flash[hidden]` zajmował **1128 × 24 px na 27 ekranach**. Ponieważ stał **pod** `.app`,
  kontener siatki był o 24 px niższy niż dokument, a pasek boczny `position: sticky` odklejał się
  na ostatnich 24 px przewijania. Zgłoszenie brzmiało **„menu ucieka na dole strony"** — przyczyna
  siedziała 1000 px niżej, w elemencie, którego nie widać. Poprawka: `[hidden] { display: none
  !important }` w `10-base.css` (jedyne uprawnione `!important` w projekcie) i pasek przeniesiony
  **do środka układu**. Skanem znalazł się jeszcze jeden taki element: ukryty przycisk „Wyczyść"
  w pasku zaznaczania, 78 × 32 px (`display: flex` z `.btn`).

  ⚠ **Ten element ma historię i to jest powód, żeby ją tu czytać przed następną zmianą:**
  najpierw dwie nazwy (`.note` i `.notice`), potem `display: flex` rozbijający zdanie na trzy
  kolumny, teraz `hidden`, który nie ukrywa. Trzy razy ten sam mechanizm: **pasek komunikatu
  jest niewidoczny, gdy jest pusty, więc jego usterek nie widać, dopóki nie zobaczy się skutku
  gdzie indziej.** Pilnują tego dwie bramki: „żaden element z `hidden` nie ma pudełka"
  i „wysokość kontenera siatki równa się wysokości dokumentu".

## 4c. Jedna siatka miesiąca — decyzja zapadła (1.18, 17.10.2026)

Do 1.17 w arkuszu były **dwie** siatki miesiąca (`.calendar-grid` na `/kalendarz` i `.month-grid`
na `/dane/kalendarze`), które robiły to samo, i dokument zostawiał pytanie, która zostaje.
**Zostaje jedna i nie jest żadną z tamtych.**

| | stan 1.17 | stan 1.18 |
|---|---|---|
| siatka miesiąca | `.calendar-grid` i `.month-grid` | **`.msiatka`** — jedna, w `<div>` + CSS grid |
| oś czasu zespołu i firmy | — | **`.team-grid`** — osobny komponent, bo pokazuje OSOBY w wierszach, nie dni w kratkach |
| Miesiąc „Tylko ja" | plakietki w kratkach | **`.mies`**: `.msiatka` po lewej, wykaz `.wpisy` po prawej |

⚠ **Dwa komponenty zamiast jednego są tu ROZSTRZYGNIĘCIEM, nie nawrotem problemu.** `.msiatka`
i `.team-grid` **nie robią tego samego**: pierwsza rysuje jeden miesiąc jednej osoby (kratka = dzień),
druga rysuje wiele osób w czasie (wiersz = osoba, kolumna = dzień). Dwie siatki z 1.17 różniły się
wyłącznie implementacją — te różnią się przeznaczeniem, a to jest granica z §4b.

⚠ **Jak wyróżniamy dzień z nieobecnością** — druga odłożona decyzja — też zapadła: kratkę niesie
**wypełnienie w kolorze rodzaju** (te same tokeny `--c-pasek-*-tlo`, co pasek osi czasu i próbka
legendy), a nie krawędź. Skanuje się wzrokiem i jest jedną rzeczą w trzech miejscach.

⚠ **Znacznik „dziś" to obwódka całej kratki** (`msiatka__kratka--dzis`), nie plakietka na numerze:
plakietka przy numerze dwucyfrowym wychodziła poza kratkę i myliła się z paskiem rodzaju.

⚠ Siatka Miesiąca ma **sześć wierszy zawsze**, także gdy miesiąc mieści się w pięciu — inaczej
wysokość karty skakała przy przewijaniu miesięcy, a obok stoi wykaz wpisów o stałej wysokości.

## 4d. ⚠ Jedna nazwa komponentu nagłówka strony

`20-layout.css` ma dziś **dwa** komplety nazw na to samo: `.page__head` / `.page__title` /
`.page__sub` **oraz** `.page-header` / `.page-title`. Część ekranów używa jednego, część drugiego.

⚠ **To jest trzecie wystąpienie tej samej klasy błędu** — po dwóch paskach komunikatu (§4b)
i dwóch siatkach miesiąca (§4c). Za każdym razem mechanizm był ten sam: **nazwa, której nie ma
w spisie, zostaje wymyślona drugi raz.**

**Zostaje `.page__head`** — zgodna z konwencją `blok__element` z §4. `.page-header`
i `.page-title` znikają z szablonów i z arkusza.

⚠ **Jeden tytuł na ekran.** Karta dostaje własny nagłówek wyłącznie wtedy, gdy ekran ma
**więcej niż jedną** kartę i trzeba je od siebie odróżnić. Ekran z jedną kartą ma tytuł
w `.page__head` i nic więcej — drugi nagłówek powtarza to samo i konkuruje z pierwszym.
Zmierzone 22.09.2026: `/admin/konta` miało `H1 „Osoby i konta"` (30 px) i pod nim
`H2 „Lista osób"` (20 px), a `/ustawienia/spolki` — `H1 „Spółki"` i `H2 „Nazwy wyświetlane"`,
przy czym podtytuł strony mówił o nazwach wyświetlanych to samo. Oba `H2` usunięte.

⚠ **`/wnioski` — droga (b), 22.09.2026:** `H2 „Moje wnioski"` znika, a przycisk „+ Nowy" zostaje
w nagłówku karty, wyrównany do prawej (`.card__head--akcja`), **w obu wariantach**. Powód:
akcja główna należy do `.page__actions`, ale wariant mobilny go nie ma, a rozjechanie wspólnego
szablonu na web i mobilny kosztowałoby więcej niż ten zysk. ⚠ Gdy wariant mobilny dostanie
`.page__actions`, wracamy do tego świadomie — decyzja ma datę właśnie po to.

⚠ **Wykonane 22.09.2026**: 19 szablonów przepisanych, reguły `.page-header` / `.page-title`
usunięte z `20-layout.css`. Dowód: `bin/inwentarz-klas.php` pokazuje **zero wystąpień** obu nazw,
a `grep` po `public/assets`, `templates`, `src` i `config` — również zero. ⚠ Koszt zwłoki jest
wart zapisania: decyzja stała niewykonana jeden dzień i **użytkownik sam zgłosił**, że dwa ekrany
mają inny nagłówek. Nazwa w spisie bez wykonania nie jest jeszcze jedną nazwą — jest dwiema.

## 5. Ekrany — co się zmienia

Wspólny szkielet, którego trzyma się **każdy** ekran:

```
ścieżka (topbar)
Nagłówek 30 px + jedno zdanie kontekstu        [akcja główna po prawej]
──────────────────────────────────────────────
pasek narzędzi / filtrów   (jeśli jest — POZA kartą)
karta / karty z treścią
```

Dziś ekran firmy trzyma filtry wewnątrz karty z tabelą, a ekran integracji trzyma przycisk akcji
w środku karty ze statusem — te same rzeczy leżą za każdym razem gdzie indziej.

### 5.1 `/dane/firma/{id}` — lista pracowników firmy

- `ALFA` (44 px, wersaliki) → nazwa firmy w `--t-h1`, bez wersalików.
- Podtytuł: `NIP 525 252 50 06 · 36 zatrudnień · dane tylko do odczytu, źródło: R2 Płatnik`.
  **NIP formatowany grupami**, przy nazwie firmy, nie osobno.
- `Wszystkie firmy` → `.btn--ghost` w `.page__actions`.
- Filtry w `.toolbar` **nad kartą**, z etykietami `.field__label`; po prawej stronie paska
  licznik wyniku (`Wynik: 36 z 36`).
- Tabela: `Pracownik · Nr kadrowy · Etat · Zatrudniony od · Rozkład czasu pracy · Nieobecności {rok} · Stan`.
  Kolumna `Stan` **pusta dla stanu normalnego**.
- Stopka karty: `Pokazano 1–8 z 36` + stronicowanie `.btn--ghost .btn--sm`.

### 5.2 `/dane/pracownik/{id}`

- Nagłówek: nazwisko; podtytuł `Budomex · nr kadrowy 413 · zatrudniony od 2006-05-05`.
  ⚠ **`hmac:…` znika z podtytułu** — dziś wygląda jak awaria.
- Pasek `.stats` — cztery pozycje w jednej karcie, rozdzielone cienką linią, nie cztery osobne karty:
  **Wymiar etatu** (`0,75 (3/4 etatu)`), **Norma dobowa** (`6 h 00 min`),
  **Rozkład czasu pracy**, **Nieobecności w {rok}** (`13 dni`).
- Karta **Zatrudnienie** — siatka `.defs`, 4 kolumny: firma, numer kadrowy, rodzaj umowy, stanowisko,
  zatrudniony od, zatrudniony do, norma urlopowa, ostatnia zmiana.
- Karta **Nieobecności** — przełącznik `.segmented` (`{rok} / {rok-1} / wszystkie`),
  tabela `Rodzaj · Od · Do · Dni robocze · Źródło`, wiersze grupujące po roku,
  stopka `6 nieobecności · 30 dni roboczych`.
  Źródło: `R2 Płatnik` albo `.chip` „wniosek w wezurlop" — **to jest informacja, której dziś brakuje,
  a jest istotna: pokazuje, co wjechało z kadr, a co wyszło od nas.**
- Na dole `.disclosure` „Szczegóły techniczne" (identyfikator R2, odcisk tożsamości, historia
  synchronizacji) — **domyślnie zwinięte**.

### 5.3 `/admin/integracja`

- Zielony pasek „Podłączone — ostatnia synchronizacja…" **przestaje być komunikatem o sukcesie
  i staje się stanem**: kropka + `Połączone` w `--t-h2`, pod spodem czas **względny**
  („3 minuty temu") i dokładny w drugiej linii. Obok, za pionową linią, cztery liczby
  (`Pracownicy · Nieobecności · Przebiegi dziś · Zakończone błędem`) i wersje connectora i R2.
- `Otwórz kreator podłączenia` → `Kreator podłączenia`, `.btn--primary` w `.page__actions`.
- Karta **Klucze instalacji**: `Nazwa · Zakres (NIP) · Utworzony · Ostatnie użycie · Stan · Działanie`.
  W stopce `Wydaj nowy klucz` i ⚠ **poprawiona treść reguły nakładki** — dziś ekran mówi
  „stary działa 30 dni", a obowiązuje:
  > Po wydaniu nowego klucza stary działa do chwili, w której nowy po raz pierwszy zakończy
  > przebieg — wtedy zostaje unieważniony automatycznie. Nie ma żadnego terminu: dopóki nowy klucz
  > nie zadziała, synchronizacja idzie na starym. Kursory i rekordy należą do firmy, nie do klucza —
  > wymiana nie powoduje ponownego ładowania danych.
- Karta **Zgłoszenia firm**: zgłoszenie oczekujące jako wiersz z bursztynowym obrysem,
  NIP grupami, kto zgłosił, kiedy, ile pracowników po stronie R2, i **dwa przyciski obok siebie**
  (`Odrzuć` ghost, `Zatwierdź` primary). Zgłoszenia rozstrzygnięte niżej, wyciszone.
- Karta **Ostatnie przebiegi**: `Rozpoczęty · Czas · Rekordy · Konflikty · Wynik`,
  link `Pełny dziennik zdarzeń` w nagłówku karty.
- ⚠ **`Zapisz zakres` przestaje być przyciskiem na całą szerokość.** Przycisk ma szerokość treści.

### 5.4 Pozostałe ekrany — w drugim rzucie

`/`, `/kalendarz`, `/diagnostyka`, `/konta`, logowanie. Reguły te same; szczegóły w osobnym
zleceniu po odbiorze trzech ekranów powyżej. Dwie rzeczy już wiadome:

- **Kalendarz**: komórki dnia są dziś wysokie i puste; wysokość ma wynikać z treści,
  a plakietka „bez danych" znika — brak danych to brak treści, nie etykieta.
- **Diagnostyka**: podsumowanie OK/ostrzeżenie/błąd jest dziś drobne i szare; to najważniejsza
  informacja na ekranie i ma być pierwszym blokiem, w rolach kolorów z §3.1.
- **Logowanie**: bez żargonu projektowego, ze stopką z numerem wersji.

---

### 5.5 Kreator podłączenia — okno modalne

⚠ **Wersja 1.0 tego dokumentu nie opisywała okien modalnych w ogóle** — opisywała strony.
Dlatego kreator został na starym wyglądzie i to on trzyma przy życiu stare arkusze CSS.
Zmierzone w działającej aplikacji:

| Co | Stan | Ma być |
|---|---|---|
| tło kroku „zrobione" | `rgb(235,249,241)`, obrys `rgb(215,239,226)` | brak tła; znacznik zrobienia, nie kolorowe pudełko |
| znacznik kroku | trzecia zieleń: `rgb(234,248,240)` / `rgb(22,132,80)` | `--c-accent` |
| promień | **16 px** i **999 px** | `--radius-card` (10 px) i `--radius` (8 px) |
| cień okna | **brak** | `--shadow-pop` |
| wysokość okna | 735 px z własnym paskiem przewijania przy oknie 855 px | `max-height: calc(100vh - 2 * var(--s-7))`, wyśrodkowane |
| przyciski w środku | `.btn--secondary`, `.btn--small` — klasy nieistniejące | `.btn--ghost`, `.btn--sm` |
| „Zamknij" | `.btn--quiet` rozciągnięty do 52 px | przycisk z ikoną × i `aria-label`, `--control-h-sm` |
| „Otwórz kreator jako osobną stronę" | `<a>` bez klasy | zwykły link w stopce okna |
| lista danych połączenia | `<dl>` bez klas systemu | `.defs` |

**Zasada, która z tego wynika i obowiązuje dalej:** kolorem oznaczamy **to, co wymaga uwagi**.
Cztery kroki z czterema zielonymi prostokątami to informacja „wszystko w porządku" pokazana
najbardziej krzykliwym środkiem, jaki mamy. Krok zrobiony ma być **cichy** — szara linia,
zielony znacznik, tytuł. Kolor rezerwujemy dla kroku, który czeka albo się nie udał.

**Komponent `.modal`** (do `30-components.css`): `<dialog>` otwierany przez `showModal()`,
`.modal__head` (tytuł + zamknięcie), `.modal__body` (jedyny obszar z `overflow-y: auto`),
`.modal__foot`. Blokada przewijania tła na czas otwarcia. `aria-labelledby` wskazujące tytuł.
Zamknięcie klawiszem Esc działa z `<dialog>` samo — nie dokładamy do tego JS.

### 5.6 Operacje nieodwracalne i sekrety — czego dokument nie opisywał

⚠ Wersje 1.0 i 1.1 opisywały, **jak coś wygląda**, i nie opisywały, **co się dzieje, gdy ktoś
to kliknie**. Zmierzone w działającej aplikacji: przyciski `Wymień` i `Unieważnij` przy kluczu
to formularze wysyłające `POST` prosto pod `/admin/integracja/klucze/{id}/wymien`
i `/uniewaznij` — **bez żadnego potwierdzenia**. Jedno kliknięcie zatrzymuje synchronizację kadr.

**Zasada: operacja, której nie da się cofnąć, ma osobny krok potwierdzenia.**

Dotyczy tego ekranu: **unieważnienie klucza, wymiana klucza, odrzucenie zgłoszenia firmy,
akceptacja zmienionego odcisku tożsamości**. Wzorzec obowiązuje też wszędzie, gdzie takie operacje
pojawią się później (kasowanie konta, wylogowanie wszystkich sesji, reset 2FA).

Wymagania:

- potwierdzenie to **osobny krok po stronie serwera**, nie `confirm()` w JS ani atrybut `hx-confirm`.
  Powód jest prosty: potwierdzenie ma **pokazać konsekwencje**, a `confirm()` pokazuje jedno zdanie
  bez formatowania i znika przy wyłączonym JS,
- ⚠ **`GET` pokazuje pytanie, `POST` wykonuje** (§3). Żądanie `GET` nigdy niczego nie zmienia,
- treść potwierdzenia mówi **co dokładnie zniknie i co przestanie działać**, po ludzku:
  której firmy dotyczy klucz, kiedy był ostatnio używany, że synchronizacja zatrzyma się
  natychmiast i że trzeba będzie wpisać nowy klucz w connectorze. Nie „Czy na pewno?",
- przycisk potwierdzający jest `.btn--danger` i **nie jest domyślnie ustawionym ogniskiem**;
  obok niego `Anuluj` jako `.btn--ghost`,
- po wykonaniu — **komunikat wyniku** (`.note`) mówiący, co się stało, i wpis w dzienniku zdarzeń.

### 5.7 Pokazanie sekretu — jeden raz, z kopiowaniem

Nowy klucz instalacji widać **jeden raz** (decyzja z 19.09: odczyt jednorazowy, TTL 5 minut).
Skoro tak, to ekran musi dać go przenieść bez przepisywania z palca.

- klucz w polu **tylko do odczytu**, zaznaczalnym, obok przycisk **„Kopiuj"**,
- kopiowanie przez `navigator.clipboard.writeText` w **pliku JS**, podpięte przez
  `addEventListener` (§6 — zero JS w szablonie, CSP bez `unsafe-inline` zostaje nietknięte),
- ⚠ **zapasowa ścieżka**: gdy `navigator.clipboard` nie istnieje albo odmówi, przycisk **zaznacza
  treść pola** i podpowiada skrót — inaczej na części przeglądarek przycisk po prostu nic nie robi,
- potwierdzenie kopiowania **zmianą klasy** (`Skopiowano`), nigdy `alert()`,
- ⚠ **klucz nigdy nie trafia do adresu URL** (§13: adresy lądują w logach serwera i proxy),
  do tytułu strony ani do dziennika zdarzeń,
- obok pola zdanie wprost: **„Zobaczysz go tylko teraz"**, a pod nim, co z nim zrobić
  (wkleić w konfiguracji connectora),
- ekran po zamknięciu tego komunikatu **nie pokazuje klucza ponownie** — także po odświeżeniu.

### 5.8 Zmiana widoku bez przeładowania strony

Zmierzone: przełącznik lat w nieobecnościach to zwykłe odnośniki `?rok=2025`, więc każde kliknięcie
**przeładowuje całą stronę** i przerzuca widok na górę. Przy okazji: **htmx jest wczytany na każdej
stronie, a używa go zero elementów**.

- przełączniki widoku (lata w nieobecnościach, filtry, stronicowanie) wymieniają **fragment**
  przez `hx-get` + `hx-target`, nie przeładowują dokumentu,
- ⚠ **odnośnik zostaje odnośnikiem.** `href` z parametrem jest ścieżką zapasową dla wyłączonego JS
  i dla otwarcia w nowej karcie; htmx tylko przechwytuje kliknięcie. `hx-push-url` utrzymuje adres,
  więc odświeżenie strony pokazuje ten sam widok,
- fragment mieszka w `/templates/fragments`, jeden plik = jeden fragment, renderowany bez layoutu,
  a jego endpoint sprawdza zalogowanie, uprawnienie i własność zasobu **tak samo jak zwykła akcja**
  (§5 `CLAUDE.md` — nie ma czegoś takiego jak „endpoint wewnętrzny, bo używa go tylko htmx"),
- `htmx:responseError` musi pokazywać komunikat; bez tego kliknięcie po prostu nic nie robi,
- ⚠ jeśli po tej zmianie htmx nadal nie ma zastosowania na danym ekranie — **nie ładujemy go tam**.
  Biblioteka wczytywana na każdej stronie i nieużywana to koszt bez pożytku.

### 5.9 Ekran logowania — pola zostały na starym systemie

Zmierzone na `/logowanie` (ekran drugiego rzutu, ale widoczny przy każdym wejściu):

| Element | Stan | Ma być |
|---|---|---|
| pola logowania | klasa `.control`, wysokość **56 px**, promień **16 px**, obrys `rgb(227,235,245)`, tło `rgb(249,251,255)` | `.input`, `--control-h`, `--radius`, `--c-line-strong`, `--c-surface` |
| podświetlenie ogniska | `.control:focus` → **niebieska poświata** `rgba(47,124,246,.08) 0 0 0 4px` ze starego `base.css` | zielony obrys 2 px z `10-base.css`, jak wszędzie |
| reakcja na kliknięcie myszą | `:focus` — niebieska poświata także po kliknięciu | `:focus-visible` |
| przycisk „Zaloguj" | **już przepisany** (`.btn--primary`, 38 px, zielony) | bez zmian |
| pole hasła kontra pole loginu | hasło w `.input-row` dzieli szerokość z przyciskiem „Pokaż" — **węższe od loginu** | oba pola tej samej szerokości; pokazanie hasła jako `.input-group__action` **w środku** pola |

⚠ **Sprostowanie do wersji 1.2:** wymaganie „obrys nie pojawia się po kliknięciu myszą" było
niewykonalne. **Pole tekstowe zawsze spełnia `:focus-visible`**, także przy ognisku z myszy —
tak działają Chrome i Firefox zgodnie ze specyfikacją, bo pole oczekujące wpisywania zawsze
potrzebuje widocznego ogniska. Obowiązuje: pole ma **zielony obrys 2 px i zero `box-shadow`**;
zniknięcie niebieskiej poświaty jest całą oczekiwaną zmianą.

⚠ Stąd wrażenie, które zgłosił Krzysztof: na jednym ekranie stoją dwa różne języki wizualne —
zielony przycisk w nowym stylu pod dwoma niebieskimi polami w starym. Ten ekran wchodzi wyjątkowo
przed drugim rzutem, bo widzi go **każdy użytkownik przy każdym wejściu**.

## 6. Kolejność prac (wiążąca)

1. `00-tokens.css` + `10-base.css` + krój pisma w paczce. **Nic więcej w tym kroku.**
2. `20-layout.css` — powłoka: sidebar z trzema sekcjami, topbar ze ścieżką, `.page`.
   Po tym kroku wszystkie ekrany wyglądają spójnie, choć treść jeszcze stara.
3. `30-components.css` — karta, tabela, przyciski, pola, plakietki, `.stats`, `.defs`, `.segmented`.
4. Przepisanie szablonów trzech ekranów z §5 na te klasy.
5. Usunięcie martwego CSS-u. ⚠ Nie po drodze — dopiero tu, żeby nie zgubić czegoś,
   co jeszcze jest w użyciu.

---

## 7. Pytania otwarte (rozstrzyga Krzysztof)

1. ~~Forma wyświetlania nazwisk.~~ **ROZSTRZYGNIĘTE 20.09** — zamieniamy, zasady w §9.
   Kolejność: `Kowalski Jan` w tabelach (sortowanie po nazwisku), `Jan Kowalski` w nagłówku karty
   pracownika i w wiadomościach e-mail.
2. Kolor interfejsu: zostajemy przy zieleni z logo jako akcencie (przyjęte), czy chcemy zobaczyć
   wariant z grafitem w interfejsie i zielenią wyłącznie w logo.
3. Czy `Nieobecności {rok}` w tabeli firmy liczymy w dniach roboczych, czy w dniach kalendarzowych —
   makieta pokazuje robocze.

---

## 8. Szerokość treści — co z tego wynika w liczbach

| Okno | Dostępne obok paska bocznego | Treść w wersji 1.0 | Treść w wersji 1.1 |
|---|---|---|---|
| 1440 px | 1177 px | 1177 px | 1177 px (bez zmian) |
| 1920 px | 1657 px | **1216 px** | **1560 px** |
| 2560 px | 2297 px | 1216 px | 1560 px |

Limit nadal działa tam, gdzie miał działać — na monitorach 2560+ tabela się nie rozlewa.
Przestał działać tam, gdzie szkodził.

---

## 9. Imię i nazwisko — jedna funkcja, jedno miejsce

R2 przysyła `imie` i `nazwisko` **rozdzielone**, ale zwykle wersalikami (`KOWALSKI`, `JAN`).

**`src/Support/NazwaOsoby.php`** — strefa jawna (§15), funkcja czysta, bez dostępu do bazy:

```php
public static function doWyswietlenia(string $wartosc): string
```

Zasady, w tej kolejności:

1. ⚠ **Jeśli wartość zawiera choć jedną małą literę — zwracamy ją bez zmian.** Reguła działa
   wyłącznie tam, gdzie zapis wersalikami zabrał informację o wielkości liter. Klient, który wpisał
   `von Neumann` poprawnie, nie może tego stracić.
2. Całość na małe litery (`mb_strtolower`, UTF-8 — ⚠ `strtolower` i `ucfirst` psują `Ł`, `Ó`, `Ą`).
3. Podział na człony po **spacji, myślniku i apostrofie** (`'` oraz `’`), z zachowaniem separatorów.
   Każdy człon pierwszą literą wielką. To załatwia `ANNA MARIA`, `NOWAK-JEZIORAŃSKA`, `O'BRIEN`.
4. **Przedrostki zawsze małą literą, chyba że są jedynym członem**: `de`, `del`, `della`, `di`,
   `du`, `da`, `das`, `dos`, `van`, `von`, `der`, `den`, `ten`, `ter`, `la`, `le`, `af`, `av`.
   Daje `de Vries`, `van der Berg`, a samo `DE` → `De`.
   ⚠ Wersja 1.1 mówiła „gdy nie są pierwszym członem", co było **sprzeczne z własnym przypadkiem
   testowym** `DE VRIES` → `de Vries`, gdzie `de` jest pierwsze. Obowiązuje brzmienie powyżej.
5. **`Mc` — tak. `Mac` — NIE.** `MCDONALD` → `McDonald` jest bezpieczne, bo w polskich nazwiskach
   człon zaczynający się od `mc` nie występuje. Reguła dla `mac` zamieniłaby **`MACIEJ` na `MacIej`**
   i jest zakazana.

Zastosowanie:

- funkcja działa na `imie` i `nazwisko` **osobno**, nigdy na sklejonym ciągu,
- ⚠ **formatowanie wyłącznie do wyświetlenia — nigdy zapis do bazy.** Wartość z R2 zostaje nietknięta;
  pomyłka w regule kosztuje poprawkę jednej funkcji, a nie migracji danych,
- ⚠ **sortowanie i wyszukiwanie działają na wartości źródłowej**, nie na sformatowanej — inaczej
  kolejność w tabeli zależałaby od warstwy prezentacji,
- dwie formy podaje warstwa PHP, nie szablon: `nazwiskoImie()` dla tabel i list,
  `imieNazwisko()` dla nagłówka karty, powitania i wiadomości e-mail.

Testy jednostkowe obowiązkowe, minimum te przypadki:
`KOWALSKI`→`Kowalski`, `ANNA MARIA`→`Anna Maria`, `NOWAK-JEZIORAŃSKA`→`Nowak-Jeziorańska`,
`O'BRIEN`→`O'Brien`, `DE VRIES`→`de Vries`, `VAN DER BERG`→`van der Berg`,
`MCDONALD`→`McDonald`, `MACIEJ`→`Maciej`, `ŁUKASZ`→`Łukasz`, `von Neumann`→`von Neumann` (bez zmian),
`Kowalski`→`Kowalski` (bez zmian).


---

## 10. Czas i strefa czasowa

**Zasada z `docs/szkielet-tenanta.md` §5 obowiązuje bez zmian: czas w bazie zawsze w UTC.**
Brakowało drugiej połowy tej zasady — **co robi z tym warstwa widoku.** Zmierzone 20.09.2026:

| Gdzie | Pokazane | Zegar użytkownika w tym momencie |
|---|---|---|
| `/admin/integracja`, stan połączenia | `2026-09-20 17:27:58 UTC` | `20:15` (Europe/Warsaw, UTC+2) |
| `/diagnostyka` | `2026-09-20 14:15:02 UTC` | — |
| karta pracownika, `title` przy „Ostatnia zmiana" | `2026-09-20 14:19:21 UTC` | — |

Kadrowa czytająca „ostatnia synchronizacja 17:27", gdy na jej zegarze jest 20:15, ma prawo
sądzić, że integracja stoi od trzech godzin. Stała w miejscu czterdzieści osiem minut.

### Reguły

1. **Proces PHP pracuje w UTC**: `date_default_timezone_set('UTC')` w bootstrapie, jawnie.
   ⚠ Nie polegamy na `date.timezone` z `php.ini` hostingu — na hostingach współdzielonych bywa
   ustawiona na cokolwiek i zmienia się bez naszej wiedzy.
2. **Strefa prezentacji to ustawienie tenanta** (`tenant_setting.timezone`), domyślnie
   `Europe/Warsaw`, widoczne i edytowalne w Ustawieniach. Instalator podpowiada wartość
   z `date_default_timezone_get()`, jeśli to prawdziwa nazwa strefy, a nie `UTC` z pustego pola.
   ⚠ **Zapisujemy nazwę strefy, nigdy przesunięcia** — przesunięcie zmienia się dwa razy w roku.
3. **Znacznik czasu wyświetla jeden filtr Twiga**, który przelicza UTC → strefa tenanta.
   Żadnego ręcznego formatowania dat w szablonach ani w kontrolerach.
4. ⚠ **Data kalendarzowa to nie znacznik czasu.** Daty z programu kadrowego — początek i koniec
   nieobecności, data zatrudnienia, data zwolnienia — są **datami**, nie momentami.
   Przeliczanie ich przez strefę **przesunęłoby urlop o jeden dzień** i jest zakazane.
   Rozstrzyga typ danych, nie wygląd wartości.
5. **Użytkownik nie ogląda UTC.** Skrót `UTC` może wystąpić wyłącznie w „Szczegółach technicznych",
   w dzienniku zdarzeń i w diagnostyce — zawsze podpisany, nigdy jako jedyna forma.
6. **Czas względny** („3 minuty temu") liczymy z UTC i jest niezależny od strefy — zostaje
   jako pierwsza forma przy stanie połączenia, z czasem bezwzględnym w strefie tenanta obok.
7. **E-maile i eksporty** używają strefy tenanta, z jej nazwą przy dacie — inaczej pracownik
   dostanie powiadomienie z godziną, której nie rozpozna.
8. `/diagnostyka` pokazuje jednym blokiem: **strefa PHP, strefa tenanta, czas serwera w UTC,
   ten sam czas w strefie tenanta.** Rozbieżność zegara serwera ma być widoczna tutaj,
   a nie odkrywana przy reklamacji.

---

## 11. Wariant mobilny — powłoka osobna, komponenty wspólne

⚠ **Stan zastany, zmierzony 20.09.2026 na szerokości 376 px** (sesja zalogowana, `mobile_access = open`).
Wcześniejsza diagnoza w dzienniku decyzji — „wariant mobilny renderuje się bez reguł" — **była błędna**
i wynikała z oglądania jedynego ekranu dostępnego bez sesji. Prawdziwy stan:

- ekrany po zalogowaniu są **w pełni ostylowane starym systemem**: `tokens.css`, `base.css`,
  `components.css`, `layout-mobile.css`,
- ⚠ **klasa istniejąca w obu systemach renderuje się starym wyglądem** (`.card` promień **22**
  zamiast 10, `.btn` waga **800** zamiast 500, pole **56 px** promień **16** zamiast 38/8),
- ⚠ **klasa wprowadzona przy przepisywaniu desktopu renderuje się bez żadnych reguł**
  (`.input`, `.input-group`, `.btn--google` — stąd rozwalony `/m/logowanie`),
- paleta jest **inna, nie brakująca**: tło `#EDF3FA`, pozycje `#0D2A74`, nawigacja `#6F84A1`,
  przycisk `#4A6294` — granatowa rodzina, której nowy system nie zna,
- trzeci zestaw nazw klas: `m-topbar`, `m-content`, `bottom-nav`, `nav-item`, `card--pad`,
  `card__head`, `btn--ghost`, `badge--pending`, `settings-list`.

⚠ **Wniosek wiążący: wpuszczenie `30-components.css` do wariantu mobilnego NIE jest przełącznikiem.**
Oba arkusze definiują te same selektory, więc wpuszczony jako ostatni przewraca cały wygląd mobilny
naraz — razem z dolną belką, która istnieje **wyłącznie** w starym arkuszu. To jest **migracja**
i musi iść w jednym kroku z powłoką opisaną niżej, nigdy przed nią.

### 11.1 Co przejmujemy ze starego systemu, zanim zniknie

⚠ **`env(safe-area-inset-*)` jest dziś używane — w starym `tokens.css`.** Nowy system nie ma go
wcale. Migracja bez przeniesienia tej obsługi **cofnie** telefon z paskiem gestów i wcięciem aparatu
do stanu gorszego niż obecny. To jedyna rzecz, którą stary system robi lepiej, i jedyna,
której nie wolno zgubić.

### 11.2 Powłoka mobilna — `20-layout-mobile.css`

Zasada: **komponenty wspólne, powłoka osobna.** `00-tokens.css`, `10-base.css` i `30-components.css`
są wspólne dla obu wariantów. Różni je **wyłącznie** powłoka: `20-layout.css` dla przeglądarki
na komputerze, `20-layout-mobile.css` dla telefonu. Stary `layout-mobile.css` znika.

Trzy elementy powłoki, wszystkie na tokenach — **ani jednej wartości `#rrggbb` poza `00-tokens.css`**:

| Element | Wartości |
|---|---|
| `.m-topbar` | `position: sticky`, wysokość **56**, tło `--c-surface` (pełne, **nie** półprzezroczyste), dolna linia 1 px `--c-line`, odstęp poziomy `--s-4`, tytuł `--t-h2` / `--w-semibold` / `--c-text` |
| `.m-content` | odstęp poziomy `--s-4`, pionowy `--s-4`, odstęp między kartami `--s-4`, **dół** `calc(56px + var(--s-5) + env(safe-area-inset-bottom, 0px))` |
| `.bottom-nav` | `position: fixed`, tło `--c-surface`, górna linia 1 px `--c-line`, wysokość **56** plus `env(safe-area-inset-bottom, 0px)` jako `padding-bottom`, pięć pozycji równej szerokości |
| `.nav-item` | pole dotykowe **min. 44 × 44**, ikona **20**, etykieta `--t-label` / `--w-medium`, stan spoczynkowy `--c-text-3`, stan aktywny `--c-accent` (⚠ ikona **i** etykieta, bez tła i bez pigułki) |

- ⚠ **Karta na telefonie ma `--radius-card` (10) i padding `--s-4` (16), nie `--s-5` (24).**
  To jedyne odstępstwo komponentu od wariantu komputerowego i jest celowe: 24 px z obu stron
  zjada 13% szerokości ekranu. Zapisujemy je jako nadpisanie w powłoce, nie jako drugi komponent.
- ⚠ **Nagłówek nie może być zdublowany.** Dziś belka górna mówi „Moje wnioski" i nagłówek karty
  mówi „Moje wnioski" — na każdym ekranie. Na telefonie tytuł nosi **belka**, karta nie ma własnego
  nagłówka, chyba że mówi co innego niż belka.
- Ikony w dolnej belce to **ikony z zestawu** (`viewBox 20×20`, jedna grubość), nie znaki
  `⌂ ▦ ＋ ▤ ☺`. Znaki tekstowe renderują się inaczej na każdym systemie i nie dają się ustawić w pionie.

### 11.3 Fundament dotykowy — `10-base.css` i `30-components.css`

⚠ **To nie są poprawki wariantu mobilnego.** Dotyczą laptopa z ekranem dotykowym i tabletu,
więc należą do arkuszy wspólnych i obowiązują w obu wariantach:

1. ⚠ **Pole tekstowe przy wskaźniku zgrubnym ma `font-size: 16px`.** Zmierzone: dziś **14 px**,
   a iOS przy tej wartości **sam przybliża całą stronę** w chwili dotknięcia pola i nie oddala jej
   potem. `@media (pointer: coarse) { .input, .select, textarea { font-size: 16px; height: 44px } }`.
   ⚠ Wariantu komputerowego to nie dotyka — `--control-h` zostaje 38 przy wskaźniku precyzyjnym.
2. **Cel dotykowy minimum 44 × 44** przy wskaźniku zgrubnym: przyciski, pozycje list, ikony w polach,
   odnośniki pomocnicze. Powiększamy **pole dotyku**, nie rysunek.
3. ⚠ **Każda reguła `:hover` idzie w `@media (hover: hover)`.** Zmierzone: **19** reguł `:hover`
   i **0** takich zabezpieczeń. Na telefonie stan najechania **zostaje zapalony po dotknięciu**
   i wygląda jak zaznaczenie, którego nie da się zdjąć.
4. **`dvh` obok `vh`** (6 wystąpień `vh`, 0 `dvh`). `100vh` liczy pasek adresu, więc układ skacze
   przy jego chowaniu.
5. `env(safe-area-inset-*)` — patrz §11.1.

### 11.4 Tabela rekordów przestaje być tabelą, gdy zabraknie jej miejsca

⚠ Zmierzone na `/m/wnioski`: kolumny `116 + 165 + 54 + 142 = 477 px`, sama treść bez odstępów
**317 px**, dostępne **308 px**. Tabela nie mieści się nawet przy zerowym paddingu, a ukryta
jest kolumna **Status** — nikt nie przewija w bok tabeli, o której nie wie, że jest szersza.
Ucinanie najdłuższej kolumny wielokropkiem **odrzucone**: typy urlopu różnią się końcówką,
więc ucięcie zabiera jedyną informację, po którą człowiek tu wchodzi, a pasek i tak zostaje.

### Warunek: szerokość KONTENERA, nie wskaźnik i nie szerokość okna

⚠ **To jest poprawka dwóch błędnych sformułowań tego dokumentu.** Wersja 1.6 mówiła
„przy wskaźniku zgrubnym"; wdrożenie dołożyło do tego `max-width: 700px`. **Oba warunki są złe**,
i widać to na jednym pomiarze: wariant mobilny otwarty w przeglądarce na komputerze ma okno
**1920 px**, wskaźnik precyzyjny — a kolumnę treści **520 px** i kartę **454 px**.
Ani `pointer: coarse`, ani `max-width: 700px` wtedy nie obowiązuje, więc reguła nie działa
i pasek przewijania zostaje. Ta sama pomyłka dotyczyła komórki kalendarza: przy oknie 1200 px
siatka dostaje **64,5 px** szerokości i **90 px** wysokości, czyli pusty prostokąt.

Reguła obowiązująca:

```
.table-scroll { container-type: inline-size; }
@container (max-width: 520px) { .table--rekordy { … lista … } }
```

- ⚠ powodem zamiany jest **brak miejsca**, a miejsce mierzy się tam, gdzie rzecz stoi —
  w jej kontenerze. Okno i rodzaj wskaźnika są **przybliżeniami**, które mylą się dokładnie
  w tych przypadkach, w których na ekran patrzymy,
- to samo dotyczy komórki kalendarza — próg na kontenerze, nie na oknie,
- ⚠ **`pointer: coarse` zostaje tam, gdzie jest prawdziwym powodem** — przy celach dotykowych
  i wielkości pól (§11.3). Palec to argument o **dotykaniu**, nie o szerokości,
- przeglądarka bez `@container` zostawia tabelę z przewijaniem. To **właściwy tryb awarii**:
  widoczny, a nie fałszywy.

#### ⚠ Nazwany wyjątek: oś czasu znaczy nieobecność WYPEŁNIENIEM (22.09.2026)

Reguła „nieobecność znaczymy **krawędzią, nie tłem**" obowiązuje w **siatce miesiąca**, gdzie wpis
jest blokiem wewnątrz dnia, sąsiaduje z numerem i tekstem, a jego długość niczego nie niesie.

⚠ **W osi czasu (wiersze osób, kolumny dni) jest odwrotnie: pasek JEST daną, a jego długość JEST
treścią** — od kiedy do kiedy kogoś nie ma. Odebranie mu wypełnienia odbiera dokładnie tę
informację. Zmierzone na `/kalendarz?widok=zespol`, zanim to poprawiliśmy:

```
pasek nieobecności   tło rgb(252,252,253)   kontrast do wiersza  1.03
tło weekendu         tło rgb(240,242,245)   kontrast do wiersza  1.12
```

Czyli **tło weekendu było czterokrotnie mocniej odznaczone niż sama nieobecność** — szum
głośniejszy od sygnału, na ekranie, po który człowiek przychodzi właśnie po ten sygnał.

Obowiązuje w osi czasu (**przepisane 11.10.2026**, po zatwierdzeniu wzoru pastelowych pasków):

- pasek ma **wypełnienie w kolorze rodzaju** i **nie ma obrysu**; końce są pełnym owalem
  (`border-radius: 999px`). Kolor pochodzi z sześciu tokenów `--c-pasek-{urlop|zwolnienie|kadrowe}-{tlo|tekst}`,
  nigdy z `#rrggbb` w komponencie,
- ⚠ **to jest drugie miejsce, w którym zieleń stoi na danej, nie na akcji** — pastel urlopu
  (`#DCF0E4`) jest zielony. Odstępstwo od §3.1 jest **nazwane i ograniczone do osi czasu**:
  ton jest o rząd słabszy od `--c-accent`, nie da się go kliknąć i nigdy nie stoi obok przycisku.
  Poza osią czasu „zieleń = akcja" nie ustępuje,
- **dni wolne schodzą poniżej paska**: kontrast paska do wiersza > kontrastu dnia wolnego.
  Zmierzone 11.10: pasek 1,191 / 1,167 / 1,206 do bieli wiersza, dzień wolny 1,06.
  To jest kryterium **liczbowe**, sprawdzane w bramce (`paski_nieobecnosci`),
- **tekst na pasku jest nośnikiem, nie ozdobą** — zakres (`17–21.08`) albo, na pasku
  jednodniowym, **numer dnia** (`24`), liczony **po stronie serwera** i wyrównany
  `font-variant-numeric: tabular-nums`. Kontrast tekstu do wypełnienia 5,08–6,60 —
  ⚠ **to on, a nie wypełnienie, spełnia tu próg czytelności**,
- **legenda jest częścią reguły**: próbki legendy biorą te same tokeny, co paski. Zmierzone
  11.10: `legend__mark--urlop` = `rgb(220,240,228)` = `--c-pasek-urlop-tlo`, co do bajtu.

⚠ **Czego ta reguła NIE załatwia — i to jest zmierzone 11.10, nie przeczucie.**
Trzy pastele różnią się **wyłącznie barwą**: wzajemne kontrasty jasności to **1,012 / 1,021 /
1,033**, a do tła dnia wolnego 1,098–1,135. W odcieniach szarości (achromatopsja, wydruk
czarno-biały, tryb wysokiego kontrastu) **rodzaj nieobecności jest nierozpoznawalny**, a sam
pasek na dniu wolnym blisko granicy widoczności. Poprzedni kształt miał na to drugi kanał —
obrys i grubą lewą krawędź, która dodatkowo znaczyła **początek** nieobecności; ten kanał
zniknął razem z obrysem. **Ściemnianie pasteli tego nie naprawia**: przesuwa wszystkie trzy
o tyle samo (propozycja z raportu: 1,246 / 1,232 / 1,254) i nie przekracza żadnego progu —
ani 3:1 z WCAG 1.4.11 dla elementu nieliterowego, ani progu dostrzegalności. Naprawą jest
**drugi, niekolorowy kanał rodzaju** (np. skrót rodzaju w tekście paska, gdzie się mieści)
i to jest **osobna decyzja produktowa** — wzór pasków został zatwierdzony wizualnie i nie
dokładamy do niego nic po cichu. Pozycja otwarta w `docs/architektura.md`.

⚠ Ten wyjątek dotyczy **wyłącznie** osi czasu (`.team-grid__bar`). Siatka miesiąca
(`.month-grid`, `/dane/pracownik/{id}/kalendarz`) zostaje na krawędzi — tam reguła działa,
jest sprawdzona, a rodzaj niesie **tekst wpisu**, nie kolor.

#### ⚠ Nazwany wyjątek: ekran hierarchii zostaje na `@media` (22.09.2026)

Dwie reguły — `.hier` (próg 1100 px, układ dwukolumnowy) i `.hier__tylko-komputer` (próg 760 px,
zdanie „ten ekran jest przeznaczony na komputer") — są **świadomie** na `@media`, nie `@container`.
Trzy powody, wszystkie zmierzone:

1. **Przypadek, dla którego powstała ta reguła, tu nie zachodzi.** `/admin/hierarchia` nie istnieje
   w powłoce mobilnej (zmierzone: `/m/` 200, `/m/kalendarz` 200, `/m/admin/hierarchia` **404** —
   cała Administracja jest webowa). Nie ma więc wąskiej kolumny w szerokim oknie.
2. **Cena kontenera jest realna, nie teoretyczna.** `.hier` leży wprost w `.page`, a kontenery mamy
   tylko na `.card__body` i `.drawer__body`. Postawienie kontenera na `.page` ustanawia **nowy blok
   zawierający** dla potomków `absolute`/`fixed` — a na `.page` wisi kontrolka dostępu do spółek
   (`position: fixed`, pozycja liczona w JavaScripcie **względem okna**) na `/admin/konta`.
   Kosmetyczna poprawka kupiona regresją na innym ekranie to zły interes.
3. **Zdanie o komputerze mówi o urządzeniu, nie o dostępnym miejscu.** Wąska karta w szerokim oknie
   nie znaczy, że ktoś pracuje palcem; wąskie okno — znaczy. To jest ten sam rozdział co
   `pointer: coarse` wyżej: nie każdy próg jest progiem o szerokości.

⚠ **Warunek wygaśnięcia wyjątku:** jeśli `/admin/hierarchia` kiedykolwiek trafi do tras
współdzielonych z powłoką mobilną, **obie** reguły — 760 px i 1100 px — muszą przejść
na `@container`. Nie jedna.

Ciekawostka z pomiaru, warta zapamiętania przy tym progu: przy 1100 px siatka schodzi do jednej
kolumny, więc **karta robi się szersza, nie węższa** (okno 1100 → karta 786 px, okno 1000 → karta
966 px). Próg kontenerowy zachowałby się tu więc inaczej niż okienny — to nie jest zwykłe
przeoczenie, tylko miejsce, w którym dwie miary naprawdę się rozjeżdżają.

### Kształt listy

#### ⚠ Wiersz może być wyższy, dana nie ma prawa być niewidoczna (22.09.2026)

Zmierzone przy zawężaniu pięciu tabel: `table-layout: fixed` usuwa przewijanie w bok i **zamienia
je na ucinanie** — 107 komórek na `/dane/synchronizacja`, w tym **znacznik czasu obcięty w połowie**
(`22.09.2026, 15:51:13`, widok 146 px, treść 175 px). To nie jest naprawa, tylko przeniesienie
problemu: zamiast przewijać, **tabela zaczyna kłamać o treści**.

Reguła: w tabeli danych kolumny dostają **stałe szerokości policzone z najdłuższej treści**,
a komórka **zawija**, nie ucina. Wyższy wiersz jest brzydszy; ucięta dana jest fałszywa,
a fałszu nikt nie zgłosi, bo nie widać, że czegoś brakuje.

⚠ Wyjątek po drugiej stronie: `.chip` **zachowuje `white-space: nowrap`** — plakietka złamana
na dwa wiersze przestaje wyglądać jak plakietka. Dlatego kolumna z plakietkami musi dostać
szerokość z **najdłuższej plakietki**, a nie równy podział.

**Reguła jest włączana znacznikiem `.table--rekordy` na `<table>`, nigdy wyłączana wyjątkiem.**
⚠ Powód jest w trybie awarii: brak znacznika na tabeli rekordów daje **przewijanie w bok**
(brzydkie, widoczne, zgłoszone od razu), a brak wyjątku na tabeli par dałby **odwrócony akcent**
(wygląda dobrze i jest fałszywe, więc nikt tego nie zgłosi).

- ⚠ **Tabela par etykieta/wartość nie jest tabelą rekordów** i nie dostaje tego znacznika.
  Układ dwuliniowy postawiłby nazwę ustawienia głośno, a jego wartość ciszej — czyli odwrotnie,
  niż znaczy ten ekran. Pary idą na `.defs`,
- `<td class="table__lead">` — dana wiodąca, `<td class="table__state">` — plakietka stanu.
  ⚠ Kolejności komórek nie da się użyć jako wskazówki: plakietka bywa w ostatniej komórce
  i w pierwszej, więc ani `:first-child`, ani `:last-child` jej nie znajdzie,
- pozycja ma dwie linie: wiodąca + plakietka w pierwszej, reszta rozdzielona `·` w drugiej,
  ciszej (`--t-caption`, `--c-text-3`). Nagłówki kolumn znikają,
- ⚠ **jednostka wchodzi do komórki** (`5 dni`, nie `5`) w **obu** wariantach. Powód nie jest
  estetyczny: w trybie listy nagłówek kolumny znika, więc liczba bez jednostki traci znaczenie,
  a dwa formaty tej samej tabeli to drugie miejsce do pomyłki,
- wysokość pozycji **nie jest stała** — `--row-h` dotyczy tabeli, nie listy,
- ⚠ **semantyka musi przetrwać**: `display: block` na elementach tabeli kasuje w części czytników
  ekranu rolę tabeli, wiersza i komórki. Jawne `role="table"`, `role="row"`, `role="cell"`.

## 12. Poczta — inne prawa niż strona, i to nie jest odstępstwo

⚠ **Czytaj to, zanim zgłosisz `style=` w `templates/mail/**` jako naruszenie §6.** Wiadomość
renderuje klient poczty, nie przeglądarka: nie ma tam naszego arkusza, nie ma CSP, a Outlook
rysuje treść **silnikiem Worda**. Reguły strony wzięły się z tego, że dzięki nim da się włączyć
CSP bez `unsafe-inline`; w skrzynce odbiorcy nie ma czego chronić CSP, a jest co stracić.

| Reguła strony | W poczcie | Dlaczego |
|---|---|---|
| zakaz `style=` (§6) | ⚠ **style wyłącznie w atrybutach** | część klientów wycina `<style>` z nagłówka, a Gmail usuwa cały arkusz |
| siatki, `flex`, `grid` | ⚠ **tabele układu** | w silniku Worda `flex`, `grid` i promień na `<a>` nie działają; przycisk to komórka z tłem |
| krój z tokenów | **krój systemowy** (`-apple-system, Segoe UI, Arial`) | IBM Plex Sans się nie wczyta, a wymuszony dałby inny wygląd na każdym telefonie |
| ikony i obrazki | ⚠ **zero obrazków**, logo jako **tekst** | klient blokuje obrazki domyślnie; piksela śledzącego w wiadomości od pracodawcy nie da się obronić |
| kolory z tokenów | te same **wartości** wpisane wprost | `var(--…)` w poczcie nie istnieje |
| `class=` | **zabronione** | nasz arkusz nie dociera; klasa bez reguły to atrapa |

**Co jeszcze obowiązuje w poczcie, a nie wynika z reguł strony:**

- ⚠ **wersja tekstowa jest obowiązkowa i powstaje z tego samego źródła co HTML.** Wiadomość
  wyłącznie HTML-owa zbiera punkty spamowe, a spam jest przy tej funkcji naszym głównym ryzykiem.
  `AltBody` pisany drugi raz z ręki rozjeżdża się przy pierwszej poprawce i **nikt tego nie
  zauważy**, bo wersję tekstową czyta ułamek odbiorców — dlatego `MailRenderer` renderuje obie
  z jednego zestawu zmiennych (`templates/mail/*.twig` i `templates/mail/text/*.twig`),
- ⚠ **jeden układ** (`templates/mail/layout.twig`), trzy szablony treści. Kopia układu w trzech
  plikach to ten sam wzorzec, przez który mieliśmy dwie nazwy nagłówka strony,
- ⚠ **nazwa firmy klienta w treści i w stopce** — i to **pełna nazwa z R2**, nie nasza nazwa
  wyświetlana: w wiadomości urzędowej liczy się nazwa z umowy. Bez nazwy pracodawcy zaproszenie
  wygląda jak phishing i tak zostanie potraktowane,
- ⚠ **link także jako tekst do skopiowania.** Gdy przycisk się nie wyrenderuje, zaproszenie
  bez adresu jest bezużyteczne,
- **zieleń tylko na przycisku** — ta sama reguła co w aplikacji (§3.1): kolor znaczy akcję.
  Alert bezpieczeństwa nie ma przycisku, bo nie ma czego kliknąć, więc nie ma w nim zieleni,
- ⚠ **alert nie zawiera adresów ani nazw kont** (CLAUDE.md §14) — w wiadomości są liczby,
  szczegóły zostają w dzienniku zdarzeń,
- **imię w wołaczu tylko wtedy, gdy umiemy odmienić** (`Support\Wolacz`: imiona żeńskie na `-a`).
  W pozostałych przypadkach wiadomość wita bez imienia — forma przekręcona jest gorsza niż brak
  formy, bo wygląda na automat z cudzej listy,
- **bez `Reply-To` na skrzynkę techniczną**; stopka mówi wprost „nie odpowiadaj na ten adres",
- ⚠ stopka używa **myślnika, nie kropki** po nazwie firmy: nazwy z R2 kończą się na `SP. Z O.O.`,
  więc kropka dawała `SP. Z O.O..` (zmierzone na prawdziwej nazwie, nie wymyślone).

⚠ **Bramka pilnuje tego osobnym zestawem reguł.** `bin/check-rules.php` ma dla
`templates/mail/**` własne wzorce (`MAIL_PATTERNS`): zabronione `<style>`, `class=`, `<img>`,
`background=`, `<script>`/`<form>`/`<iframe>`, `flex`/`grid`, `position`, `url(`, `@media`
i `var(--…)`. ⚠ Zmierzone 22.09.2026: przy **jednym** zestawie dla wszystkich szablonów bramka
zgłaszała **40 trafień `style=`** na trzech szablonach wiadomości, czyli karała za jedyny sposób,
w jaki poczta może działać. Przy okazji wyszło, że wzorce znaczników liczyły się także
**w komentarzach** — komentarz wyjaśniający, dlaczego w poczcie nie ma `<style>`, sam był
zgłaszany jako `<style>`; od tej zmiany wzorce znaczników pomijają komentarze Twiga, a wzorce
danych przykładowych nadal obowiązują w całym pliku.

## 11.5 Czego wariant mobilny NIE dostaje

- ekranów administracyjnych: `/m/dane`, `/m/admin/*`, `/m/diagnostyka` zwracają **404** i tak ma zostać.
  Lustro tras obejmuje `$shared`; ekrany administracyjne są w `$webOnly` i to jest decyzja, nie luka,
- ciemnego motywu — nie ma go w wariancie komputerowym,
- własnych komponentów. ⚠ Komponent potrzebny tylko na telefonie jest sygnałem, że ekran robi
  na telefonie co innego niż na komputerze — a to jest decyzja produktowa, nie wizualna, i zgłaszamy ją.

---

## 12. Nazwany wyjątek: panel kontrolki wyboru ma własne przewijanie (1.15, 06.10.2026)

Reguła bramki „zero przewijania w kontenerach i na stronie" **obowiązuje bez zmian dla TREŚCI** —
tabel, kart, siatek. Przewijany kontener z danymi ukrywa dane, i o to w tej regule chodzi.

⚠ **Wyjątek dotyczy wyłącznie panelu kontrolki wyboru** (`details` z listą pozycji do zaznaczenia).
Powód jest zmierzony, nie estetyczny: panel wyboru osób przy zasięgu 53 osób miał
**1408 px wysokości w oknie 855 px**, czyli jego własny przycisk zapisu znajdował się
**poza ekranem**, a zaznaczanie wymagało przewijania strony pod otwartym panelem,
który zasłaniał siatkę.

Warunki wyjątku:

- panel ma **trzy strefy**: nieprzewijalną górę (szukanie), przewijaną listę, nieprzewijalny dół
  (licznik i akcje). **Przewija się wyłącznie lista** — nigdy cały panel;
- wysokość panelu **dopasowana do okna**, tak by dół był zawsze widoczny;
- ⚠ **lista jest kontrolką, nie treścią.** Jeśli kiedykolwiek pojawi się pokusa, żeby tym samym
  wyjątkiem objąć tabelę danych — to znaczy, że tabela jest za szeroka albo za długa,
  i rozwiązaniem jest §11.4, nie przewijanie;
- ⚠ **lista przycięta limitem nie może być filtrowana po stronie przeglądarki.** Przy „30 z 53"
  filtr w przeglądarce przeszukuje trzydzieści i milczy o dwudziestu trzech — człowiek wnioskuje,
  że osoby nie ma. Szukanie w takiej liście idzie **do serwera**, zawsze.

Wyjątek wygasa, jeśli lista przestanie być przycinana limitem i zacznie mieścić cały zasięg —
wtedy wraca reguła ogólna.

---

## 13. Słownictwo: jednostka organizacyjna nazywa się FIRMA (1.16, 11.10.2026)

> **W interfejsie jednostka organizacyjna nazywa się „firma". Słowo „spółka" nie występuje
> w żadnym napisie widzianym przez użytkownika.**

⚠ Powód jest **rzeczowy, nie estetyczny** (rozstrzygnięcie Krzysztofa 11.10.2026):

> „nie każda firma jest spółką"

„Spółka" to **forma prawna** — sp. z o.o., S.A., jawna. Produkt sprzedajemy firmom, z których
część to jednoosobowa działalność, spółdzielnia albo fundacja. Dla nich „Wybierz spółkę" nie jest
niespójne, tylko **nieprawdziwe** — a w systemie kadrowym nieprawdziwa nazwa rzeczy podważa
zaufanie do liczb stojących obok niej.

**Obejmuje** wszystko, co człowiek czyta: etykiety, nagłówki, tytuły stron i okruchy, komunikaty
błędów i potwierdzeń, puste stany, dymki, `title`, `aria-label`, `placeholder`, teksty wiadomości
e-mail, napisy instalatora i diagnostyki oraz **etykiety** zdarzeń w dzienniku.

**Nie obejmuje** (granica, żeby nie było migracji bez zysku):

| zostaje | dlaczego |
|---|---|
| nazwy klas PHP i CSS, zmiennych, kolumn, tabel, pól formularzy (`KontekstSpolki`, `company_id`, `.spolka`, `name="spolka"`) | język kodu, nie człowieka |
| **kody** zdarzeń w `event_log` i **klucze ładunku** (`context.switched`, `z_spolka`) | identyfikatory; zmiana unieważniłaby stare wpisy |
| trasy i nazwy plików (`/kontekst/spolka/{id}`, `znacznik-spolki.twig`) | adres i ścieżka to identyfikator |
| dokumenty w `docs/`, `zlecenia/`, `raporty/` | nasz język roboczy — wyjątkiem jest **ten** dokument, gdzie stoi sama zasada |
| ⚠ **nazwa prawna przysłana przez R2** („BETA SPÓŁKA Z OGRANICZONĄ ODPOWIEDZIALNOŚCIĄ") | **to nie nasze słowo, tylko dana z kadr** — nazwy klienta nie poprawiamy, ta sama zasada co „nie liczymy za kadry" |

⚠ **Zasada ma kryterium w bramce (`bez_slowa_spolka`), bo inaczej słowo wróci przy pierwszym
nowym ekranie** — w kodzie „spolka" zostaje wszędzie. Kryterium przechodzi wszystkie ekrany,
które bramka i tak odwiedza, szuka **rdzenia z ogonkami** (`spółk`, więc wszystkie odmiany)
w `innerText` oraz **osobno** w atrybutach, i wskazuje **ekran i zdanie**. Jedyny wyjątek —
nazwa firmy — jest porównywany z **rzeczywistymi nazwami z bazy** (i ich początkami, bo nazwę
prawną sami przycinamy w polach), nigdy z kształtem napisu: wyjątek oparty na kształcie
przepuściłby nasz własny napis, który akurat wygląda podobnie.
