#!/usr/bin/env python3
"""Bramka ekranowa — mierzy WYRENDEROWANY układ w prawdziwej przeglądarce.

Dlaczego Python, a nie PHP jak reszta narzędzi: sterowanie przeglądarką w PHP oznacza własnego
klienta protokołu CDP, czyli napisanie od zera tego, co tu już działa. Zasada „repozytorium jest
w PHP" dotyczy produktu; `dev/` nie jedzie do klienta (patrz `dev/package-allowlist.txt`).

Co sprawdza:
  1. przewijanie w poziomie WEWNĄTRZ kontenerów (`.table-scroll`, `.card__body`, `.m-content`,
     `.page`) oraz na stronie — przy OBU szerokościach okna. ⚠ Usterka `/m/wnioski` chowała się
     w kontenerze przy szerokim oknie, więc sprawdzenie samej strony przy 376 px jej nie widziało;
  2. NIEZMIENNIK PROGU: dla każdej tabeli z `.table--rekordy` naturalna szerokość <= próg
     z `@container tabela (max-width: …)`. ⚠ Próg jest ODCZYTYWANY z arkusza, nie wpisany tutaj —
     dwie kopie tej samej liczby rozjadą się przy pierwszej zmianie;
  3. błędy konsoli, `pageerror` i naruszenia CSP.

Wymagania i uruchomienie: patrz `dev/README.md`.
"""
from __future__ import annotations

import json
import os
import re
import signal
import struct
import sys
import zlib
from pathlib import Path

KORZEN = Path(__file__).resolve().parent.parent
os.environ.setdefault("PLAYWRIGHT_BROWSERS_PATH", str(Path.home() / ".cache/ms-playwright"))

try:
    from playwright.sync_api import sync_playwright
except ImportError:  # pragma: no cover
    sys.exit("Brak modułu playwright. Instalacja: patrz dev/README.md")

BAZA = os.environ.get("BRAMKA_BAZA", "https://tenant.wezurlop.pl")
HOST = BAZA.split("//", 1)[-1].split("/", 1)[0].split(":")[0]
# ⚠ **Ani loginów, ani haseł nie ma w repozytorium** (audyt Z1 z 17.10.2026). Jeden z adresów
# roboczych był zbudowany z PRAWDZIWEGO NAZWISKA pracownika z R2, a zasada projektu mówi, że
# prawdziwe dane osobowe nie trafiają do repo — więc adresy też schodzą do `~/.wezurlop-dev-konta`
# (poza repo, prawa 0600). Plik ma wiersze `email<TAB>hasło<TAB>rola`; bramka pyta o ROLĘ, bo to
# ona jest stała („administrator", „bez dostępu", „pracownik z danymi"), a adres bywa inny
# na każdej maszynie. Zmienne środowiskowe `BRAMKA_LOGIN*` dalej mają pierwszeństwo.
LOGIN = os.environ.get("BRAMKA_LOGIN", "")
# ⚠ **Żadnych haseł w repozytorium — także domyślnych** (CLAUDE.md §14; audyt Z1 z 17.10.2026
# znalazł tu dwa jawne literały i oba zostały po tym unieważnione). Hasło przychodzi
# ze środowiska albo z `~/.wezurlop-dev-konta` (poza repo, prawa 0600); gdy nie ma go nigdzie,
# bramka mówi wprost, że sprawdzenia nie wykonała, zamiast świecić zielono bez pomiaru.
HASLO = os.environ.get("BRAMKA_HASLO", "")
# ⚠ Drugie konto, NIE-administrator: `kreator@` jest administratorem tenanta, a administrator
# jest z ekranu braku dostępu zwolniony — więc bramka tego ekranu w ogóle nie widziała.
# Konto #7 to pracownik z aktywnym zatrudnieniem i BEZ nadanego dostępu do żadnej spółki;
# ten stan renderuje ekran „brak przydzielonego dostępu" i tylko on go renderuje.
LOGIN2 = os.environ.get("BRAMKA_LOGIN2", "")
HASLO2 = os.environ.get("BRAMKA_HASLO2", "")
# ⚠ Trzecie konto, PRACOWNIK Z DANYMI: `kreator@` jest administratorem BEZ zatrudnienia, więc
# na pulpicie i w kalendarzu widzi zdanie „to konto nie ma zatrudnienia" — czyli bramka chodząca
# po trasach nie widziała ani jednej liczby z kadr. To konto ma zatrudnienie w DWÓCH spółkach,
# limity i nieobecności, i tylko ono renderuje prawdziwy widok pracownika.
LOGIN3 = os.environ.get("BRAMKA_LOGIN3", "")
# ⚠ Od 17.10.2026 **wszystkie trzy konta** czytają hasło tak samo: środowisko albo plik.
# Wcześniej tylko to jedno — i to było dokładnie to miejsce, w którym dwa pozostałe hasła
# leżały jawnie w repozytorium przez cztery tygodnie.
HASLO3 = os.environ.get("BRAMKA_HASLO3", "")


def _konta_dev() -> list[list[str]]:
    """Wiersze `email<TAB>hasło<TAB>rola` z `~/.wezurlop-dev-konta`; rola jest opcjonalna.

    ⚠ Plik leży POZA repozytorium (prawa 0600) i jest jedynym miejscem z hasłami kont roboczych
    — w repozytorium nie ma ani ich, ani adresów (CLAUDE.md §14, audyt z 17.10.2026).
    """
    sciezka = os.path.expanduser("~/.wezurlop-dev-konta")
    try:
        with open(sciezka, encoding="utf-8") as f:
            return [w.rstrip("\n").split("\t") for w in f if w.strip()]
    except OSError:
        return []


def haslo_dev(login: str) -> str:
    """Hasło konta roboczego po adresie."""
    for czesci in _konta_dev():
        if len(czesci) >= 2 and czesci[0] == login:
            return czesci[1]
    return ""


def konto_dev(rola: str) -> str:
    """Adres konta roboczego po ROLI (`admin`, `bezdostepu`, `pracownik`).

    ⚠ Rola, a nie adres, bo rola jest tym, co bramka naprawdę opisuje: „administrator tenanta",
    „pracownik bez nadanego dostępu", „pracownik z danymi z kadr". Adres jest szczegółem
    instalacji i na innej maszynie bywa inny.
    """
    for czesci in _konta_dev():
        if len(czesci) >= 3 and czesci[2] == rola:
            return czesci[0]
    return ""


# ⚠ **Hasła rozstrzygamy JEDEN RAZ, tutaj.** Część sprawdzeń sięga po `HASLO`/`HASLO2` wprost,
# a część po wzorzec `HASLO or haslo_dev(...)`. Odkąd wartości domyślne są puste (17.10.2026,
# po audycie sekretów), te pierwsze logowałyby się pustym hasłem i sypały 422 zamiast powiedzieć,
# czego brakuje. Uzupełnienie stoi więc w jednym miejscu, a nie w kilkunastu wywołaniach.
LOGIN = LOGIN or konto_dev("admin")
LOGIN2 = LOGIN2 or konto_dev("bezdostepu")
LOGIN3 = LOGIN3 or konto_dev("pracownik")
HASLO = HASLO or haslo_dev(LOGIN)
HASLO2 = HASLO2 or haslo_dev(LOGIN2)
HASLO3 = HASLO3 or haslo_dev(LOGIN3)
# Czego oczekujemy na `/` po zalogowaniu drugim kontem: kod i fragment nagłówka.
BEZ_DOSTEPU = (403, "nie ma przydzielonego dostępu")
# ⚠ Wysokości okna dla nakładek. Usterka „dół szuflady nieosiągalny" nie zależała od szerokości,
# tylko od WYSOKOŚCI i od długości treści — a bramka mierzyła wyłącznie szerokości.
# ⚠ Wcięcie pierwszej komórki od krawędzi karty: 1 px obramowania karty + 24 px paddingu komórki.
# Tolerancja 2 px na zaokrąglenia przeglądarki, nie na „prawie tak samo".
WCIECIE_W_KARCIE = 25
WCIECIE_TOLERANCJA = 2

# Dopuszczalna różnica między wysokością kontenera siatki a wysokością dokumentu (zaokrąglenia).
SIATKA_TOLERANCJA = 2

# ⚠ 855 px dołożone 10.10.2026, bo to JEDYNA z tych wysokości, przy której dzisiejsza
# usterka ekranu grup była niewidoczna dla starego kryterium: „Zapisz grupę" stał na
# 820 → 852 px, czyli prostokąt mieścił się w oknie, a przycisk był przycięty do zera.
# Wysokość, przy której miernik świecił na zielono nad zepsutym ekranem, zostaje w zestawie.
# ⚠ Ile najwyżej wolno czekać na odświeżenie wiersza listy po zamknięciu okna grupy.
# Liczba wzięta z pomiaru nadzoru: u niego po `×` wiersz odświeżał się w **1,6 s**, a po
# `Escape` **nie odświeżył się przez 2,5 s**. Budżet 2 s rozdziela te dwa przypadki
# i jest większy od tego, co mierzy bramka u siebie (poniżej 0,5 s) — nie chcę czerwieni
# od wolniejszej maszyny, chcę czerwieni od braku odświeżenia.
ODSWIEZENIE_MS = 2000

WYSOKOSCI_NAKLADEK = (855, 700, 600)
# ⚠ Kryterium to NIE tag, tylko „warstwa o ograniczonej wysokości z własnym obszarem
# przewijanym". Stąd dwa rodzaje wyzwalaczy: `data-modal-open` (okna i szuflada, identyfikator
# w atrybucie) oraz `data-dostep` (kontrolka dostępu — warstwa `position: fixed` o stałym
# identyfikatorze `#dostep-panel`, z własnym `max-height` na liście spółek).
#
# ⚠ Czego tu ŚWIADOMIE nie ma: okna wyniku wysyłki (`#wynik-modal`). Nie ma wyzwalacza —
# otwiera się dopiero po wysłaniu partii zaproszeń, więc żeby je sprawdzić, bramka musiałaby
# WYSŁAĆ ZAPROSZENIA i zmienić dane w bazie roboczej. To nie przeoczenie: ten jeden szablon
# mierzymy ręcznie przy każdej jego zmianie (pomiar opisany w raporcie o szufladzie).
#
# ⚠ **Trzeci rodzaj dołożony 10.10.2026 i to jest DZIURA W POKRYCIU, nie rozszerzenie.**
# Kontrolka wyboru osób (`[data-pickbox]`) jest warstwą o ograniczonej wysokości z własnym
# obszarem przewijanym, czyli dokładnie tym, co to sprawdzenie mierzy — ale otwiera ją
# `<details>`, a nie `data-modal-open`. Skutek: panel, w którym 10.10 nie dało się kliknąć
# „Zapisz grupę", **nie był sprawdzany ani razu**, a raport mówił „16 sprawdzeń nakładek,
# oba przeszły". Przeszły inne nakładki. Ta nie była mierzona.
WYZWALACZE_NAKLADEK = (
    ("[data-modal-open]", None, None),        # identyfikator nakładki w atrybucie wyzwalacza
    # ⚠ Wpis `("[data-dostep]", "dostep-panel", None)` skasowany 20.10.2026 (Z63 cz. A):
    # osobnej warstwy dostępu nie ma od Z61 — panel osoby ma jeden zapis, a jego wyzwalacz
    # to zwykłe `data-modal-open="osoba-panel"`, czyli pierwszy wpis tej listy. Atrybut
    # `data-dostep` nie występuje w żadnym szablonie, więc ten wyzwalacz nie otwierał niczego.
    ("details > summary", None, "[data-pickbox]"),  # panel w `<details>`, identyfikator na warstwie
)
ARKUSZ = KORZEN / "public/assets/css/30-components.css"

KONTENERY = [".table-scroll", ".card__body", ".m-content", ".page"]

# ⚠ **Szerokość „laptopowa" dla kryteriów, które otwierają ekran w jednym oknie** — jedna stała,
# nie liczba wpisana w trzydziestu wywołaniach. Od 20.10.2026 wynosi **1366**, a nie 1440
# (decyzja zarządcy, Z63): bramka i pomiar odbiorowy mają mierzyć te same trzy szerokości,
# bo dwie listy szerokości znaczą dwa różne „sprawdzone". 1366 jest przy tym węższa, więc
# sprawdzenia, które przy 1440 przechodziły „z zapasem", teraz mówią prawdę o laptopie.
SZEROKOSC_LAPTOPA = 1366


def prog_z_arkusza() -> int:
    """Próg zamiany tabeli w listę, ODCZYTANY z arkusza (§11.4)."""
    tekst = ARKUSZ.read_text(encoding="utf-8")
    m = re.search(r"@container\s+tabela\s*\(\s*max-width:\s*(\d+)px\s*\)", tekst)
    if not m:
        sys.exit(f"Nie znalazłem progu `@container tabela (max-width: …)` w {ARKUSZ.name}. "
                 "Reguła zmieniła nazwę albo zniknęła — bramka nie ma czego pilnować.")
    return int(m.group(1))


def sciezki_web() -> list[str]:
    """Ścieżki GET wariantu komputerowego ODCZYTANE z config/routes.php.

    ⚠ Pierwsza wersja tej bramki miała ścieżki wpisane z pamięci („/dane/firmy", „/dane/pracownicy")
    i zgłaszała cztery błędy 404 w konsoli jako usterkę aplikacji. To ta sama poprawka co przy
    liczbie tras: odczytane, nie wpisane.
    """
    import subprocess
    kod = (
        'require "vendor/autoload.php";'
        '$f = require "config/routes.php";'
        'foreach ($f(true) as $r) {'
        '  if ($r[0] !== "GET") continue;'
        '  if (str_starts_with($r[1], "/m") || str_starts_with($r[1], "/api")) continue;'
        '  if (str_contains($r[1], "{")) continue;'
        '  echo $r[1], "\\n";'
        '}'
    )
    wyn = subprocess.run(["php", "-r", kod], cwd=KORZEN, capture_output=True, text=True, check=True)
    trasy = [w for w in wyn.stdout.split() if w]
    # Trasy z parametrem dopisane jawnie, z identyfikatorami z bazy roboczej.
    # ⚠ Panel osoby dopisany jawnie: to ekran zapasowy panelu (ten sam fragment co w szufladzie),
    # a trasa z `{id}` nie wchodzi do wykazu sama. Bez tego nowy ekran nie byłby pilnowany.
    # ⚠ Dziennik z filtrem dopisany jawnie: ten sam szablon renderuje się inaczej (krótsza tabela,
    # dodatkowy przycisk „Wyczyść filtr"), a sam `/admin/dziennik` z wykazu tras tego stanu nie pokryje.
    parametryczne = os.environ.get(
        "BRAMKA_PARAMETRYCZNE",
        "/dane/firma/3,/dane/pracownik/74,/admin/konta/osoba/98,/admin/dziennik?konto=15,"
        # ⚠ Ekran grupy dopisany 10.10.2026: to on miał kontrolkę, w którą nie dało się
        # kliknąć, a przebieg po stronach go nie odwiedzał — trasa ma `{id}`, więc do wykazu
        # nie wchodzi sama. Ekran bez pomiaru to ekran, o którym bramka nic nie mówi.
        "/admin/hierarchia/osoba/73,/admin/grupy/1")
    return trasy + [s for s in parametryczne.split(",") if s]


# ——— selektory znaczników, które bramka klika i mierzy ————————————————————————
# ⚠ **Jedno miejsce na selektor, nie dziesięć** (tura kryteriów Z22, 17.10.2026). Dziesięć
# kryteriów świeciło czerwono nie dlatego, że produkt się popsuł, tylko dlatego, że każde z nich
# miało wklejony własny selektor sprzed przebudowy nagłówka (Z8) i pulpitu (Z15). Gdy znacznik
# zmienia nazwę, ma się to poprawiać TUTAJ, a nie w dziesięciu miejscach — inaczej następna
# przebudowa znów zgasi połowę bramki, a nikt tego nie zauważy, bo „kryteria i tak są czerwone".
#
# ⚠ Strzałki okresu nie mają TEKSTU od Z8 — mają `aria-label` („Poprzedni miesiąc", „Poprzedni
# rok"), bo etykieta zależy od skali. Dlatego selektor idzie po `aria-label`, nie po treści,
# i nie po `.page__actions`, którego w ogóle już nie ma.
# ——— kontrola negatywna ————————————————————————————————————————————————————
# ⚠ **Kryterium, którego nikt nie złamał, jest ozdobą, nie bramką** (tura Z22, 17.10.2026).
# Dziesięć kryteriów świeciło czerwono przez tydzień i nikt tego nie zauważył — bo czerwień
# bez dowodu, że kryterium W OGÓLE potrafi zapalić się z właściwego powodu, niczego nie znaczy.
# Dlatego każde przepięte kryterium ma tu **wstrzykiwaną usterkę**: skrypt wykonywany przed
# kodem strony, który psuje dokładnie tę jedną rzecz, o którą kryterium pyta. Tryb `kontrola`
# uruchamia je po kolei i wymaga, żeby każde kryterium z usterką dało `ok: False`.
#
# ⚠ Usterka wchodzi przez `add_init_script`, a nie przez `<style>` ani atrybut `style=`:
# nagłówek CSP aplikacji nie dopuszcza stylów w znaczniku (§13 CLAUDE.md), więc wstrzyknięcie
# arkusza blokowałaby przeglądarka i „kontrola" mierzyłaby CSP zamiast kryterium. CSSOM
# (`insertRule`) i zmiana klas są poza CSP, bo nie są treścią dokumentu.
USTERKI: dict[str, str] = {}


def zapisz_kod(raport: dict, okno: str, adres: str, kod: int) -> None:
    """Kod odpowiedzi ekranu — **zapisany, zanim przebieg pójdzie dalej** (Z63 cz. A).

    ⚠ Ta funkcja istnieje dlatego, że decyzja „co zrobić z kodem >= 400" była wcześniej jedną
    linią `continue` w pętli i nie zapisywała niczego: ekran w **500 wyglądał w raporcie
    dokładnie jak ekran, którego bramka nie odwiedziła**. Teraz decyzja ma jedno miejsce,
    da się ją sprawdzić bez przeglądarki (tryb `kontrola`) i nie da się jej pominąć przez
    przeoczenie w pętli.

    5xx jest usterką bez wyjątków. 4xx trafia na tę samą listę, a rozdziela je wypis — część
    odwiedzanych adresów odmawia **poprawnie** (410 na wygasłym zaproszeniu, 419 na wygasłym
    formularzu, 404 na kodzie QR spoza parowania).
    """
    if kod >= 400:
        raport["kody"].append({"okno": okno, "adres": adres, "kod": kod})


def nowy_kontekst(b, usterka: str | None = None, **kwargs):
    """Kontekst przeglądarki, opcjonalnie z wstrzykniętą usterką (tryb `kontrola`)."""
    ctx = b.new_context(**kwargs)
    if usterka:
        if usterka not in USTERKI:
            raise KeyError(f"nieznana usterka kontrolna: {usterka}")
        ctx.add_init_script(USTERKI[usterka])
    return ctx


def _usterka(nazwa: str, cialo: str) -> None:
    """Rejestruje usterkę: ciało wykonuje się po zbudowaniu DOM-u i po każdej wymianie fragmentu."""
    USTERKI[nazwa] = (
        "(() => { const psuj = () => { try {" + cialo + "} catch (e) {} };\n"
        "  if (document.readyState !== 'loading') { psuj(); }\n"
        "  document.addEventListener('DOMContentLoaded', psuj);\n"
        "  window.addEventListener('load', psuj);\n"
        "  document.addEventListener('htmx:afterSwap', psuj); })();"
    )


# Znacznik „dziś": drugi dzień dostaje tę samą klasę — „dziś" przestaje być jednym dniem.
_usterka("znacznik_dzis", """
    const kratki = document.querySelectorAll('.msiatka__kratka');
    const dzis = document.querySelector('.msiatka__kratka--dzis');
    if (dzis) {
      const inna = [...kratki].find(k => k !== dzis && !k.className.includes('--poza'));
      if (inna) { inna.classList.add('msiatka__kratka--dzis'); }
    }
    const naglowki = document.querySelectorAll('.team-grid__head');
    const nd = document.querySelector('.team-grid__head--dzis');
    if (nd) {
      const inny = [...naglowki].find(h => h !== nd && !h.className.includes('--person'));
      if (inny) { inny.classList.add('team-grid__head--dzis'); }
    }
""")

# ⚠ **Trzy usterki dopisane 20.10.2026 (Z63 cz. A)** — do kryteriów, które tą turą zostały
# przepięte na dzisiejsze klasy. Każda psuje DOKŁADNIE jedno twierdzenie, bo kryterium łamane
# usterką, która psuje wszystko naraz, nie dowodzi, że zapala się z właściwego powodu.

# Skale czasu: dwunaste pudełko siatki roku usunięte — „dwanaście, nie jedenaście".
_usterka("skale_czasu", """
    const siatki = document.querySelectorAll('.msiatka');
    if (siatki.length === 12) { siatki[11].remove(); }
""")

# Kratka z rodzajem traci podpowiedź — zostaje kolorowy kwadrat bez znaczenia (pozycja 6 z długu).
_usterka("kratka_bez_title", """
    const k = document.querySelector('.msiatka__kratka--urlop, .msiatka__kratka--zasilek,'
      + ' .msiatka__kratka--kadrowe');
    if (k) { k.removeAttribute('title'); }
""")

# Tło pod paskiem: warstwa pod-kratek znika, więc komórka z paskiem znowu maluje się jednym
# tłem z pierwszego dnia zakresu — dokładnie stan sprzed Z68 (kolumna weekendu urywa się
# w połowie wiersza). Usuwamy ELEMENT, nie styl: inline `style` jest w tym projekcie zakazany,
# a usunięcie z DOM-u odtwarza tamten render wierniej niż ukrycie.
_usterka("tlo_dnia_pod_paskiem", """
    document.querySelectorAll('.team-grid__dni').forEach(w => w.remove());
""")

# Przełącznik widoku: wraca pozycja „Zespół" u konta, które widzi całą firmę — czyli stan
# sprzed Z70, w którym przełącznik oferował dwa przyciski pokazujące ten sam zbiór osób.
# ⚠ Wstawiamy element tam, gdzie go rysuje szablon (`.kal-grupa`), a gdy grupy nie ma — bo przy
# jednym widoku przełącznik w ogóle nie powstaje — tworzymy ją obok nawigacji. Inaczej usterka
# nie miałaby się czego zaczepić na tym właśnie ekranie, którego dotyczy.
_usterka("przelacznik_zespolu", """
    const etykieta = (tekst) => {
      const a = document.createElement('a');
      a.className = 'kal-btn';
      a.href = '/kalendarz?widok=zespol';
      const s = document.createElement('span');
      s.className = 'kal-btn__etykieta';
      s.textContent = tekst;
      a.appendChild(s);
      return a;
    };
    let grupa = document.querySelector('.kal-grupa');
    if (!grupa) {
      const nav = document.querySelector('.kal-nav, .card__head, .page');
      if (nav) {
        grupa = document.createElement('div');
        grupa.className = 'kal-grupa';
        nav.appendChild(grupa);
      }
    }
    if (grupa && ![...grupa.querySelectorAll('.kal-btn__etykieta')].some(e => e.textContent.trim() === 'Zespół')) {
      grupa.appendChild(etykieta('Zespół'));
    }
""")

# Przełącznik widoku, usterka DRUGA (Z71): pozycja „Zespół" ZNIKA, choć gałąź jest niepusta —
# czyli odwrotność usterki pierwszej. Łamie stronę dodatnią kryterium: przełożony z trzema
# podwładnymi traci jedyne wejście do widoku swojego zespołu.
# ⚠ Dwie usterki na jedno kryterium, bo ma ono dwa twierdzenia o PRZECIWNYCH stanach: „nie ma
# pozycji, gdy dublowałaby Firmę" i „jest, gdy jest kogo pokazać". Jedna usterka złamałaby
# tylko połowę i druga połowa dalej byłaby nieudowodniona.
_usterka("zespol_schowany", """
    document.querySelectorAll('.kal-btn').forEach(a => {
      const e = a.querySelector('.kal-btn__etykieta') || a;
      if (e.textContent.trim() === 'Zespół') { a.remove(); }
    });
""")

# Prawda kalendarza: nagłówek ekranu mówi „Mój kalendarz" nad widokiem całej spółki — czyli
# dokładnie zgłoszona usterka z 23.09, w której ekran przypisywał oglądającemu cudzy kalendarz.
_usterka("prawda_kalendarza", """
    const h1 = document.querySelector('h1');
    if (h1 && h1.textContent.trim() === 'Kalendarz') { h1.textContent = 'Mój kalendarz'; }
""")

# Jedna wizualizacja, usterka pierwsza: obca krata dni POZA rodziną wspólną. Nazwa klasy jest
# nowa (`kratka-obca`), bo o to właśnie idzie — kryterium nie ma pytać o listę znanych nazw.
_usterka("jedna_wizualizacja_obca_krata", """
    if (document.querySelector('.msiatka, .team-grid')
        && !document.querySelector('.kratka-obca')) {
      const d = document.createElement('div');
      d.className = 'kratka-obca';
      document.querySelector('main').appendChild(d);
    }
""")

# Jedna wizualizacja, usterka druga: oba komponenty rodziny na jednym ekranie — stan, w którym
# „jedna wizualizacja" przestaje być jedna, a poprawka wyglądu dociera tylko do jednego z nich.
_usterka("jedna_wizualizacja_dwie_rodziny", """
    if (document.querySelector('.msiatka') && !document.querySelector('.team-grid')) {
      const d = document.createElement('div');
      d.className = 'team-grid';
      document.querySelector('.msiatka').parentElement.appendChild(d);
    }
""")

# ⚠ Rodzaj ze słownika na pasku **cudzej** osoby — czyli dokładnie to, czego zakazuje zakres
# `bez_szczegolow` (Z53). Podstawiamy etykietę w pierwszym cudzym wierszu osi czasu; własnego
# wiersza („to Ty") nie ruszamy, bo tam rodzaj wolno pokazać — i to jest cała różnica, którą
# te dwa kryteria mają widzieć.
_usterka("rodzaj_u_cudzej_osoby", """
    const siatka = document.querySelector('.team-grid');
    if (!siatka) { return; }
    let cudzy = false;
    for (const el of Array.from(siatka.children)) {
      if (el.classList.contains('team-grid__person')) {
        cudzy = ((el.querySelector('small') || {}).textContent || '').trim() !== 'to Ty';
        continue;
      }
      const pasek = el.querySelector ? el.querySelector('.team-grid__bar') : null;
      if (cudzy && pasek) {
        pasek.setAttribute('aria-label', 'urlop wypoczynkowy, 01.09 \u2013 02.09.2026');
        break;
      }
    }
""")
# Ta sama usterka pod drugą nazwą — dla kryterium mierzącego widok ZESPOŁU. Treść jedna,
# nazwy dwie, bo rejestr kontroli jest słownikiem „usterka → kryterium".
USTERKI["rodzaj_u_cudzej_osoby_zespol"] = USTERKI["rodzaj_u_cudzej_osoby"]

# Pasy tła w szczelinach: zerowy zasięg boczny i zerowa wysokość pasa górnego — dokładnie
# stan sprzed poprawki Z25, w którym w szczelinie widać krawędź przejeżdżającego bloku.
_usterka("pasy_szczelin", """
    const k = document.querySelector('.card--kal-naglowek');
    if (k) { k.style.setProperty('--przyklej-luka', '0px'); }
""")

# ⚠ **Drugi kontroler tego samego kryterium: brak `ResizeObserver`** (Z26). Wpis NIE idzie
# przez `_usterka()`, bo tamto opakowanie czeka na `DOMContentLoaded` — a moduł przyklejenia
# jest `defer`, czyli wykonuje się WCZEŚNIEJ i zdążyłby złapać obserwatora. Tu trzeba usunąć
# go, zanim wystartuje jakikolwiek skrypt strony, czyli w samym `add_init_script`.
USTERKI["pasy_szczelin_bez_obserwatora"] = (
    "try { delete window.ResizeObserver; } catch (e) { window.ResizeObserver = undefined; }"
)

SEL_POPRZEDNI = ".kal-nav a.kal-btn[aria-label^='Poprzedni']"
SEL_NASTEPNY = ".kal-nav a.kal-btn[aria-label^='Następny']"
# Dowolny odnośnik okresu w nagłówku kalendarza (strzałka albo przycisk skali).
SEL_OKRES = ".kal-nav a.kal-btn[href*='miesiac=']"
# Kratka dnia dzisiejszego w siatce miesiąca i roku oraz w nagłówku osi czasu.
SEL_DZIS_KRATKA = ".msiatka__kratka--dzis"
SEL_DZIS_NAGLOWEK = ".team-grid__head--dzis"
# ⚠ **Przełącznik „czyj kalendarz" — selektor przepięty na dzisiejszy kształt** (tura Z22).
# Do 11.10.2026 stał w `.page__actions` jako `.segmented__item`; paska akcji na tym ekranie
# **nie ma wcale** od tamtej pory, a przełącznik jest grupą kwadratów w bloku nagłówka.
# ⚠ Skutek starego selektora był gorszy niż czerwień: lista wychodziła PUSTA, więc twierdzenie
# „«Firma» NIE jest w przełączniku" (poziom 0) przechodziło pusto i nie mierzyło niczego,
# a bliźniacze „«Firma» JEST" (poziom 1) świeciło czerwono. Połowa kryterium udawała zieloną.
# Selektor bierze `aria-label` grupy, a nie kolejność grup: skale i widoki to dwa `.kal-grupa`
# obok siebie i rozróżnia je wyłącznie etykieta.
SEL_WIDOK = '.kal-grupa[aria-label="Czyj kalendarz"] .kal-btn__etykieta'

# ⚠ **Napis ogólny przy zakresie „bez szczegółów" jest DOKŁADNIE JEDEN** —
# `KalendarzPracownika::NIEOBECNY`. Do 20.10.2026 bramka przyjmowała tu zbiór
# {„urlop", „zwolnienie", „inna nieobecność"}, czyli regułę sprzed Z53: wtedy pasek cudzej
# osoby mówił „urlop" albo „zwolnienie" — a „zwolnienie" przy nazwisku jest daną o zdrowiu
# tak samo jak nazwa ze słownika, tylko mniej dokładną. Kryterium było więc **słabsze
# od produktu** i przepuściłoby powrót do tamtego stanu.
OGOLNY_NAPIS = "nieobecny"

# ⚠ **Własny wiersz nie jest przeciekiem.** W widoku firmy i zespołu oglądający widzi też
# SIEBIE, a rodzaj swojej własnej nieobecności wolno mu znać — to jego dana. Oba kryteria
# („zasięg podglądu" i „poziomy") liczyły do tej pory wszystkie paski razem i czerwieniły się
# na dwóch paskach konta pomiarowego (zmierzone 20.10.2026: 39 wierszy w firmie, 37 napisów
# „nieobecny" u innych i dwa „urlop wypoczynkowy" — oba we WŁASNYM wierszu, zakresy
# 01.01–07.01 i 26.01–31.01, zgodne z wykazem własnych nieobecności). Dlatego wiersz
# oglądającego rozpoznajemy po podpisie „to Ty" i mierzymy OSOBNO.
# ⚠ Wiersz oglądającego rozpoznajemy po **podpisie z serwera** („to Ty",
# `KalendarzPracownika::podpisOsoby()`), a nie po porównaniu nazwy z menu konta. Porównanie
# nazw próbowałem pierwsze i było błędne: belka pokazuje „Imię Nazwisko", wiersz „Nazwisko Imię",
# a przy dwóch członach z myślnikiem nie ma reguły, która trafi zawsze. Podpis jest tym samym
# napisem, którego pilnuje `prawda_kalendarza` — jedna nazwa stanu w dwóch kryteriach.
PODPIS_WLASNEGO_WIERSZA = "to Ty"

PASKI_PO_WIERSZACH = """() => {
  const PODPIS_WLASNY = '""" + PODPIS_WLASNEGO_WIERSZA + """';
  const siatka = document.querySelector('.team-grid');
  const wynik = {wlasne: [], obce: [], wierszy: 0, rozpoznano_wlasny: false};
  if (!siatka) { return wynik; }
  let gdzie = null;
  for (const el of Array.from(siatka.children)) {
    if (el.classList.contains('team-grid__person')) {
      wynik.wierszy++;
      const podpis = (el.querySelector('small') || {}).textContent || '';
      const swoj = podpis.trim() === PODPIS_WLASNY;
      wynik.rozpoznano_wlasny = wynik.rozpoznano_wlasny || swoj;
      gdzie = swoj ? 'wlasne' : 'obce';
      continue;
    }
    const pasek = el.querySelector ? el.querySelector('.team-grid__bar') : null;
    if (pasek !== null && gdzie !== null) {
      wynik[gdzie].push((pasek.getAttribute('aria-label') || '').split(',')[0].trim());
    }
  }
  return wynik;
}"""

MOBILNE = ["", "/kalendarz", "/wnioski", "/wniosek/nowy", "/konto", "/zespol", "/ustawienia",
           "/ustawienia/2fa", "/ustawienia/2fa/kody", "/ustawienia/urzadzenia", "/kontekst",
           "/styleguide"]

POMIAR = """(kontenery) => {
  const wyn = [];
  for (const sel of kontenery) {
    document.querySelectorAll(sel).forEach((el, i) => {
      if (el.scrollWidth > el.clientWidth) {
        const t = el.querySelector('table');
        wyn.push({sel, nr: i + 1, clientWidth: el.clientWidth, scrollWidth: el.scrollWidth,
                  nadmiar: el.scrollWidth - el.clientWidth, tabela: t ? t.className : null});
      }
    });
  }
  // Naturalna szerokość każdej tabeli rekordów: klon bez ograniczenia szerokości.
  const rekordy = [];
  document.querySelectorAll('table.table--rekordy').forEach((t, i) => {
    const k = t.cloneNode(true);
    k.style.cssText = 'position:absolute;visibility:hidden;width:auto;table-layout:auto;display:table';
    // ⚠ **Procentowe szerokości komórek trzeba wyzerować w klonie.** Od 11.10.2026 tabela
    // nieobecności ma modyfikator `table--kolumny-prawe` z `width: 1%` na dwóch kolumnach —
    // a procent w tabeli o `table-layout: auto` rozciąga klon do szerokości rodzica, więc
    // „naturalna szerokość" wychodziła równa szerokości okna (zmierzone: 1920 i 1440 px)
    // i niezmiennik progu zapalał się na ośmiu ekranach, choć na stronie nic nie wystawało.
    // Naturalna szerokość ma znaczyć **szerokość treści**, a nie wynik procentu.
    k.querySelectorAll('th, td').forEach(c => { c.style.width = 'auto'; });
    document.body.appendChild(k);
    const naturalna = Math.ceil(k.getBoundingClientRect().width);
    k.remove();
    const opak = t.closest('.table-scroll');
    rekordy.push({nr: i + 1, naturalna, kontener: opak ? opak.clientWidth : null,
                  naglowki: Array.from(t.querySelectorAll('thead th')).map(h => h.textContent.trim())});
  });
  // ⚠ Wcięcie tabeli w karcie. Powód tego sprawdzenia: `/admin/konta` przez trzy tygodnie miało
  // tabelę w opakowaniu z własnym `padding`, więc pierwsza komórka zaczynała się 49 px od krawędzi
  // karty zamiast 25, a tabela była o 50 px węższa niż karta — i dowiedzieliśmy się o tym
  // od użytkownika. To był CZWARTY ekran „zbudowany inaczej niż reszta", a bramka mierzyła
  // wtedy tylko przewijanie. Jedna liczba na tabelę wyłapuje całą tę klasę usterek.
  const wciecia = [];
  document.querySelectorAll('table.table').forEach((t, i) => {
    const karta = t.closest('.card');
    if (!karta) { return; }
    const wiersz = t.querySelector('tbody tr');
    // ⚠ Tabela rekordów w trybie karty (wąski kontener) ma komórki `display: block` i zerowy
    // padding — tam 25 px nie obowiązuje, bo wiersz nie jest wierszem.
    if (wiersz && getComputedStyle(wiersz).display === 'block') { return; }
    const kom = t.querySelector('tbody td') || t.querySelector('thead th');
    if (!kom) { return; }
    const kr = karta.getBoundingClientRect();
    const ramka = parseFloat(getComputedStyle(karta).borderLeftWidth) || 0;
    const padL = parseFloat(getComputedStyle(kom).paddingLeft) || 0;
    wciecia.push({
      nr: i + 1,
      klasa: t.className,
      wciecie: Math.round(kom.getBoundingClientRect().left + padL - kr.left),
      tabela: Math.round(t.getBoundingClientRect().width),
      karta: Math.round(kr.width - 2 * ramka),
      rodzic: t.parentElement.className || t.parentElement.tagName,
    });
  });
  // ⚠ Element z atrybutem `hidden` nie ma prawa mieć pudełka. `[hidden]` z arkusza przeglądarki
  // ma najniższą wagę, więc dowolna nasza reguła ustawiająca `display` na klasie ją bije —
  // a ukryty element jest zwykle PUSTY, więc nikt tego nie widzi. Zmierzone 22.09.2026:
  // `DIV#flash[hidden]` zajmował 1440 × 24 px na każdym ekranie, co odklejało pasek boczny
  // na końcu przewijania, i ukryty przycisk „Wyczyść" 78 × 32 px na liście osób.
  const ukryte = [];
  document.querySelectorAll('[hidden]').forEach(el => {
    const r = el.getBoundingClientRect();
    if (r.width > 0 || r.height > 0) {
      ukryte.push({el: el.tagName + (el.id ? '#' + el.id : '') +
                       (el.className ? '.' + String(el.className).trim().split(' ').filter(Boolean).join('.') : ''),
                   w: Math.round(r.width), h: Math.round(r.height),
                   display: getComputedStyle(el).display});
    }
  });
  // ⚠ **Pojemnik komunikatów (Z47) — przepięte kryterium po pasku `#flash`.** Pasek zniknął,
  // ale pilnowana cecha jest ta sama i ma ten sam powód: rzecz, której NIE WIDAĆ, nie ma prawa
  // zajmować miejsca ani łapać wskaźnika. Pusty pojemnik toastów leży u dołu okna na całą
  // szerokość 620 px — bez `pointer-events: none` przykrywałby przycisk zapisu, a tego nie
  // widać ani na zrzucie, ani w pomiarze prostokątów.
  // ⚠ Odpowiedź FRAGMENTEM nie jest ekranem i nie ma powłoki (`/admin/podlaczenie/stan`,
  // `/kalendarz/osoby`) — pojemnika komunikatów tam nie ma i nie ma go tam być, bo fragment
  // wstawia się do strony, która już go ma. To samo rozpoznanie co w `POMIAR_TYTULU`:
  // brak belki i brak powłoki. (Zmierzone: bez tego warunku kryterium zapalało dwa adresy
  // na dwóch szerokościach — i miało rację co do faktu, a nie co do wniosku.)
  const tk = document.getElementById('toasty');
  const powloka_jest = !!document.querySelector('.app, .app-shell, .auth-shell, .install-shell, .status-card')
    || !!document.querySelector('.topbar, .m-topbar');
  const toasty = (tk === null && !powloka_jest) ? {brak: false, fragment: true, h: 0, dzieci: 0,
      wskaznik: 'none', rola: 'status', live: 'polite'} : tk === null ? {brak: true} : {
    brak: false,
    h: Math.round(tk.getBoundingClientRect().height),
    dzieci: tk.children.length,
    wskaznik: getComputedStyle(tk).pointerEvents,
    rola: tk.getAttribute('role'),
    live: tk.getAttribute('aria-live'),
  };
  const de = document.documentElement;
  // ⚠ Kontener siatki musi być tak wysoki jak dokument. Cokolwiek stanie POD nim, odkleja
  // element `position: sticky` na ostatnich pikselach przewijania — a wygląda to na usterkę
  // menu, nie na usterkę tego, co stoi niżej.
  const app = document.querySelector('.app, .app-shell');
  const siatka = app ? {wysokosc: Math.round(app.getBoundingClientRect().height),
                        dokument: de.scrollHeight,
                        roznica: de.scrollHeight - Math.round(app.getBoundingClientRect().height)}
                     : null;
  return {kontenery: wyn, rekordy, wciecia, ukryte, siatka, toasty,
          strona: {clientWidth: de.clientWidth, scrollWidth: de.scrollWidth,
                   przewija: de.scrollWidth > de.clientWidth}};
}"""


# ⚠ **„Osiągalny" to NIE „prostokąt mieści się w oknie".** Zmierzone 10.10.2026 na
# `/admin/grupy/2`: przycisk „Zapisz grupę" stał na 820 → 852 px w oknie 855 px, czyli
# kryterium prostokąta świeciło na zielono, a przycisku nie dało się ani zobaczyć, ani
# kliknąć — karta-rodzic (`SECTION.card`, `overflow: hidden`) kończyła się na 468 px
# i ucinała cały panel wraz z listą osób. Prostokąt się zgadzał, ekran był nieobsługiwalny.
#
# ⚠ Dlatego pytamy o to samo DWIEMA niezależnymi drogami:
#   1) **geometria** — prostokąt elementu przecięty kolejno z prostokątami WSZYSTKICH
#      przodków, którzy przycinają (`overflow` inny niż `visible`), a na końcu z oknem.
#      Zerowe pole po przecięciu znaczy „nieosiągalny", choćby własny prostokąt mieścił się
#      idealnie,
#   2) **pytanie do przeglądarki** — `document.elementFromPoint()` w środku geometrycznym
#      elementu musi zwrócić ten element albo jego potomka.
# Pierwsza mówi, ile z elementu zostało; druga, czy da się w niego kliknąć. Jedna nie
# wystarcza i to nie jest teoria: punkt 1 przepuściłby element przycięty do jednego piksela,
# a punkt 2 — element zasłonięty przez coś, co nic nie przycina.
#
# ⚠ Przycinanie liczy się tylko wtedy, gdy przodek stoi w **łańcuchu bloków zawierających**
# elementu: `position: absolute` nie jest przycinany przez niepozycjonowanego przodka,
# a `position: fixed` wyłącznie przez przodka tworzącego blok zawierający (`transform`,
# `filter`, `contain`). Bez tego warunku bramka zgłaszałaby jako nieosiągalne okna modalne,
# które stoją nad kartą i są w porządku.
OSIAGALNOSC_JS = r"""
  const __klipujacy = (el) => {
    const out = [];
    let poz = getComputedStyle(el).position;
    for (let p = el.parentElement; p; p = p.parentElement) {
      const cs = getComputedStyle(p);
      const tworzyCB = cs.transform !== 'none' || cs.filter !== 'none'
        || cs.perspective !== 'none' || (cs.willChange || '').indexOf('transform') >= 0
        || (cs.contain || '').indexOf('paint') >= 0 || (cs.contain || '').indexOf('layout') >= 0;
      const wLancuchu = poz === 'fixed' ? tworzyCB
        : (poz === 'absolute' ? (cs.position !== 'static' || tworzyCB) : true);
      if (!wLancuchu) { continue; }
      poz = cs.position;
      const tnieX = cs.overflowX !== 'visible' && cs.overflowX !== '';
      const tnieY = cs.overflowY !== 'visible' && cs.overflowY !== '';
      if (tnieX || tnieY) { out.push({el: p, tnieX: tnieX, tnieY: tnieY, cs: cs}); }
    }
    return out;
  };
  const __opis = (el) => el.tagName
    + (el.id ? '#' + el.id : '')
    + (el.className && typeof el.className === 'string'
        ? '.' + el.className.trim().split(/\s+/).join('.') : '');
  const __osiagalnosc = (el) => {
    if (!el) { return null; }
    const r = el.getBoundingClientRect();
    let x1 = r.left, y1 = r.top, x2 = r.right, y2 = r.bottom;
    const uciely = [];
    for (const k of __klipujacy(el)) {
      const pr = k.el.getBoundingClientRect();
      const przed = Math.max(0, x2 - x1) * Math.max(0, y2 - y1);
      if (k.tnieX) { x1 = Math.max(x1, pr.left); x2 = Math.min(x2, pr.right); }
      if (k.tnieY) { y1 = Math.max(y1, pr.top); y2 = Math.min(y2, pr.bottom); }
      const po = Math.max(0, x2 - x1) * Math.max(0, y2 - y1);
      if (po < przed - 1) {
        uciely.push({przodek: __opis(k.el), overflow: k.cs.overflow,
                     bottom: Math.round(pr.bottom), zabral_px2: Math.round(przed - po)});
      }
    }
    x1 = Math.max(x1, 0); y1 = Math.max(y1, 0);
    x2 = Math.min(x2, window.innerWidth); y2 = Math.min(y2, window.innerHeight);
    const pole = Math.max(0, x2 - x1) * Math.max(0, y2 - y1);
    const wlasne = Math.max(0, r.width) * Math.max(0, r.height);
    const cx = Math.round(r.left + r.width / 2), cy = Math.round(r.top + r.height / 2);
    const traf = (cx >= 0 && cy >= 0 && cx < window.innerWidth && cy < window.innerHeight)
      ? document.elementFromPoint(cx, cy) : null;
    const trafia = traf !== null && (traf === el || el.contains(traf));
    const w_oknie = r.bottom <= window.innerHeight + 1 && r.top >= -1;
    return {
      top: Math.round(r.top), bottom: Math.round(r.bottom), okno: window.innerHeight,
      // Trzy ODDZIELNE warunki — każdy łamie się osobno i każdy mówi o czym innym.
      w_oknie: w_oknie,
      pole: Math.round(pole), pole_wlasne: Math.round(wlasne),
      widoczna_czesc: wlasne > 0 ? Math.round(100 * pole / wlasne) : 0,
      po_przecieciu: {top: Math.round(y1), bottom: Math.round(y2)},
      uciely: uciely,
      punkt: [cx, cy], trafiony: traf ? __opis(traf) : null, trafia: trafia,
      osiagalna: pole > 0 && w_oknie && trafia
    };
  };
"""

POMIAR_NAKLADKI = """(id) => {
""" + OSIAGALNOSC_JS + """
  const d = document.getElementById(id);
  // ⚠ „Otwarta" znaczy co innego dla dialogu (`open`), dla warstwy z `hidden` i dla panelu
  // w `<details>` (tam `hidden` nie ma, a zamknięty panel po prostu nie jest renderowany) —
  // dlatego na końcu i tak pytamy o PUDEŁKO: bez prostokąta nie ma czego mierzyć.
  const szuflada = d ? d.closest('details') : null;
  const otwarta = !!d && (d.tagName === 'DIALOG' ? d.open : (szuflada ? szuflada.open : !d.hidden))
    && d.getClientRects().length > 0;
  if (!otwarta) { return {otwarta: false}; }
  // Obszar przewijany nakładki: pierwszy potomek z własnym przewijaniem w pionie.
  const kandydaci = Array.from(d.querySelectorAll('*')).filter(el => {
    const cs = getComputedStyle(el);
    return cs.overflowY === 'auto' || cs.overflowY === 'scroll';
  });
  const obszar = kandydaci[0] || null;
  let przewija = null, scrollTop_przed = null, scrollTop_po = null;
  if (obszar) {
    scrollTop_przed = obszar.scrollTop;
    obszar.scrollTop = 99999;
    scrollTop_po = obszar.scrollTop;
    // ⚠ „Naprawdę przewija" = treść się nie mieści I scrollTop faktycznie się zmienił.
    // Sam `scrollHeight > clientHeight` bywa prawdą także wtedy, gdy przodek obcina wszystko.
    przewija = obszar.scrollHeight > obszar.clientHeight ? scrollTop_po > scrollTop_przed : null;
  }
  const widoczne = (el) => el.offsetParent !== null || el.getClientRects().length > 0;
  // ⚠ Sprawdzamy DWIE rzeczy, bo warstwy mają dwa kształty: w szufladzie ostatnia akcja stoi
  // w obszarze przewijanym (i to ona uciekała poniżej okna), a w kontrolce dostępu obszar
  // przewijany to sama lista spółek — bez przycisków, które siedzą w stopce warstwy.
  // Pomiar tylko wewnątrz obszaru przepuszczał tę drugą bez żadnego sprawdzenia.
  const ostatniaZ = (zrodlo) => {
    if (!zrodlo) { return null; }
    const akcje = Array.from(zrodlo.querySelectorAll('button, a')).filter(widoczne);
    const el = akcje[akcje.length - 1];
    if (!el) { return null; }
    const opis = {tekst: el.textContent.trim().slice(0, 28), el: __opis(el)};
    const pierwszy = __osiagalnosc(el);
    if (pierwszy.osiagalna) {
      return Object.assign(opis, pierwszy, {przewinieto: false});
    }
    // ⚠ **Warstwa zakotwiczona W STRONIE jest osiągalna, jeśli da się do niej PRZEWINĄĆ.**
    // Rozróżnienie zmierzone 10.10.2026: szuflada (`position: fixed`) stoi w miejscu, więc
    // akcja poniżej okna jest tam nieosiągalna i koniec — ale panel otwierany z `<details>`
    // jedzie razem ze stroną, a strona kalendarza ma 2581 px w oknie 855. Pierwsza wersja
    // tego kryterium traktowała oba przypadki jednakowo i zgłaszała przycisk „Pokaż"
    // (595 → 627 px w oknie 600) jako nieosiągalny, chociaż wystarczy przewinąć o 27 px.
    // ⚠ Przycięcie przodkiem to co innego: przewijanie go nie leczy, więc dowód z `/admin/grupy`
    // (pole 0 px² pod kartą z `overflow: hidden`, strona bez przewijania) zostaje czerwony.
    const wFixed = (() => {
      for (let q = el; q; q = q.parentElement) {
        if (getComputedStyle(q).position === 'fixed') { return true; }
      }
      return false;
    })();
    const de = document.documentElement;
    if (wFixed || de.scrollHeight <= de.clientHeight) {
      return Object.assign(opis, pierwszy, {przewinieto: false, strona_przewija: false});
    }
    // ⚠ **Przewijamy OKNO, nigdy `scrollIntoView`.** Zmierzone 10.10.2026, dowód 14:
    // `el.scrollIntoView()` przewija **także przodków z `overflow: hidden`** (są przewijalne
    // programowo, choć nie dla człowieka) — i wtedy pomiar pokazuje element „osiągalny"
    // dokładnie w sytuacji, którą to kryterium ma łapać. Po wstawieniu panelu w przodka
    // `max-height: 120px; overflow: hidden` kryterium ze `scrollIntoView` dało **zero
    // czerwieni**. Przewinięcie samego okna nie odsłoni niczego, co przycina przodek.
    const y = window.scrollY;
    const r0 = el.getBoundingClientRect();
    const margines = 8;
    const delta = r0.bottom > window.innerHeight ? (r0.bottom - window.innerHeight + margines)
      : (r0.top < 0 ? (r0.top - margines) : 0);
    window.scrollTo(0, y + delta);
    const drugi = __osiagalnosc(el);
    window.scrollTo(0, y);
    return Object.assign(opis, drugi,
      {przewinieto: Math.round(delta), przed_przewinieciem: pierwszy});
  };
  const ostatni_w_obszarze = ostatniaZ(obszar);
  const ostatni = ostatniaZ(d);
  const rd = d.getBoundingClientRect();
  return {
    otwarta: true,
    okno: window.innerHeight,
    dialog: {h: Math.round(rd.height), bottom: Math.round(rd.bottom), scrollH: d.scrollHeight},
    obszar: obszar ? {klasa: obszar.className, h: obszar.clientHeight, scrollH: obszar.scrollHeight,
                      scrollTop_przed, scrollTop_po, przewija} : null,
    ostatni: ostatni,
    ostatni_w_obszarze: ostatni_w_obszarze,
    // ⚠ Nakładka nie może wystawać poniżej okna — ale „poniżej okna" znaczy co innego dla
    // warstwy `position: fixed` (stoi w miejscu, więc jej dół jest nieosiągalny i koniec)
    // i dla panelu zakotwiczonego w stronie (jedzie ze stroną, więc wystarczy, że mieści się
    // w DOKUMENCIE). Jedna reguła na oba przypadki zgłaszała panel kalendarza wystający
    // o 40 px jako usterkę, choć wystarczyło przewinąć stronę.
    dialog_w_oknie: (() => {
      let fixed = false;
      for (let q = d; q; q = q.parentElement) {
        if (getComputedStyle(q).position === 'fixed') { fixed = true; break; }
      }
      if (fixed) { return rd.bottom <= window.innerHeight + 1; }
      const de2 = document.documentElement;
      return rd.bottom + window.scrollY <= de2.scrollHeight + 1;
    })(),
    // ⚠ …a także nie może być PRZYCIĘTA przez przodka: wystawanie poniżej okna to tylko
    // jeden ze sposobów, żeby warstwa była niedostępna. Drugi zmierzyliśmy na `/admin/grupy`.
    dialog_osiagalnosc: __osiagalnosc(d)
  };
}"""


OTWORZ_MENU = """
(sel) => {
  const el = document.querySelector(sel);
  const menu = el ? el.closest('details') : null;
  if (menu) { menu.open = true; }
}
"""


def sprawdz_nakladki(b, sciezki: list[str]) -> list[dict]:
    """⚠ Nakładki otwierane JS-em — bramka patrzy TAM, gdzie mieszkała usterka.

    Przebieg po stronach sprawdza tylko to, co widać bez kliknięcia. Panel osoby miał główną
    akcję („Wyślij zaproszenie", „Zakończ sesje") poniżej okna przy KAŻDEJ wysokości, a bramka
    przechodziła na 51 ekranach: ekran zapasowy panelu (bez JS) jest zdrowy, bo przewija się
    stroną, więc bramka widziała wersję, która działa.
    """
    wyniki = []
    for wys in WYSOKOSCI_NAKLADEK:
        ctx = b.new_context(viewport={"width": SZEROKOSC_LAPTOPA, "height": wys}, ignore_https_errors=True)
        pg = ctx.new_page()
        try:
            pg.goto(BAZA + "/logowanie", wait_until="networkidle")
            pg.fill("#login-email", LOGIN)
            pg.fill("#login-password", HASLO)
            pg.click("button[type=submit]")
            pg.wait_for_load_state("networkidle")
            for s in sciezki:
                odp = pg.goto(BAZA + s, wait_until="domcontentloaded")
                if odp is None or odp.status >= 400:
                    continue
                try:
                    pg.wait_for_load_state("networkidle", timeout=5000)
                except Exception:  # noqa: BLE001
                    pass
                # Jeden wyzwalacz na nakładkę — ten sam panel otwarty z dziesięciu wierszy
                # to dziesięć razy ten sam pomiar.
                # Jeden wyzwalacz na nakładkę — ta sama kontrolka w dziesięciu wierszach
                # to dziesięć razy ten sam pomiar.
                wyzwalacze = []
                for selektor, staly_id, wewnatrz in WYZWALACZE_NAKLADEK:
                    ident = pg.evaluate("""([sel, staly, wewnatrz]) => {
                      const widziane = new Set();
                      const out = [];
                      document.querySelectorAll(sel).forEach(el => {
                        let id = staly || el.getAttribute('data-modal-open');
                        if (wewnatrz) {
                          // Warstwa stoi WEWNĄTRZ wyzwalacza (`<details>` → panel) i sama
                          // nosi identyfikator; bez niego nie ma czego mierzyć.
                          const d = el.closest('details');
                          const w = d ? d.querySelector(wewnatrz) : null;
                          id = w && w.id ? w.id : null;
                        }
                        if (!id || widziane.has(id)) { return; }
                        widziane.add(id);
                        out.push(id);
                      });
                      return out;
                    }""", [selektor, staly_id, wewnatrz])
                    wyzwalacze += [(selektor, i) for i in ident]
                for selektor, okno_id in wyzwalacze:
                    try:
                        if selektor == "[data-dostep]":
                            cel = selektor
                        elif selektor == "details > summary":
                            cel = f'details:has(#{okno_id}) > summary'
                        else:
                            cel = f'[data-modal-open="{okno_id}"]'
                        # ⚠ Wyzwalacz może siedzieć w ZAMKNIĘTYM `<details>` (menu przy karcie
                        # hierarchii) — wtedy nie jest widoczny i klik czeka do timeoutu 30 s.
                        # Bramka otwiera menu, zamiast uznać ekran za zepsuty: to nasza własna
                        # ścieżka bez myszki, a nie usterka układu. Zmierzone 22.09.2026: nowe
                        # okno hierarchii wywróciło dwa z czternastu sprawdzeń nakładek.
                        # ⚠ Dla panelu w `<details>` wyzwalaczem JEST `summary`, więc
                        # wcześniejsze otwarcie menu i kliknięcie dałyby dwa przestawienia
                        # i panel ZAMKNIĘTY — zmierzone: „NIE OTWARŁA SIĘ" na ekranie,
                        # który otwiera się poprawnie.
                        if selektor != "details > summary":
                            pg.evaluate(OTWORZ_MENU, cel)
                        # ⚠ **Nakładka otwarta PRZEZ SERWER nie wymaga kliknięcia — i nie da się
                        # jej kliknąć.** Od Z29 `/ustawienia/spolki` przekierowuje na
                        # `/ustawienia?okno=nazwy`, gdzie okno przychodzi z `<dialog open>`
                        # i zaraz staje się modalne; reszta dokumentu jest wtedy `inert`, więc
                        # klik w wyzwalacz za oknem czeka do timeoutu. Zmierzone: trzy z 45
                        # sprawdzeń padły na „NIE OTWARŁA SIĘ" przy oknie, które było OTWARTE.
                        # Pomiar i tak dotyczy geometrii nakładki, a ta jest już na ekranie.
                        juz_otwarta = pg.evaluate(
                            f"() => {{ const d = document.getElementById('{okno_id}');"
                            " const s = d ? d.closest('details') : null;"
                            " return !!d && (d.tagName === 'DIALOG' ? d.open"
                            "   : (s ? s.open : !d.hidden)); }")
                        # ⚠ **Drugie okno na tym samym ekranie: najpierw zamknij to otwarte.**
                        # Modalny `<dialog>` czyni resztę dokumentu `inert`, więc wyzwalacz
                        # DRUGIEGO okna stoi za nim i klik czeka do timeoutu — nie dlatego, że
                        # ekran jest zepsuty, tylko dlatego, że bramka weszła na niego z oknem
                        # już otwartym. Zmierzone 19.10.2026 po Z49: `/ustawienia/spolki`
                        # przekierowuje na `/ustawienia?okno=nazwy` (okno nazw otwarte przez
                        # serwer), a na tym ekranie stoi teraz także okno skrzynki alertowej —
                        # trzy z 66 sprawdzeń padły na „NIE OTWARŁA SIĘ" przy oknie, które
                        # otwiera się poprawnie.
                        # ⚠ Zamykamy WYŁĄCZNIE okna inne niż mierzone: mierzone otwarte przez
                        # serwer obsługuje `juz_otwarta` wyżej, a zamknięcie go odebrałoby
                        # pomiarowi przedmiot.
                        if not juz_otwarta:
                            pg.evaluate(
                                "(id) => document.querySelectorAll('dialog[open]')"
                                ".forEach(d => { if (d.id !== id) { d.close(); } })",
                                okno_id)
                            pg.click(cel)
                        # ⚠ „Otwarta" znaczy co innego dla dialogu (`open`) i dla warstwy
                        # (`hidden` zdjęte) — bramka sprawdza jedno i drugie, bo kryterium
                        # dotyczy warstwy, nie tagu.
                        pg.wait_for_function(
                            f"() => {{ const d = document.getElementById('{okno_id}');"
                            " const s = d ? d.closest('details') : null;"
                            " return !!d && (d.tagName === 'DIALOG' ? d.open"
                            "   : (s ? s.open : !d.hidden)) && d.getClientRects().length > 0; }",
                            timeout=4000)
                        # Treść nakładki bywa dociągana przez htmx — dajemy jej chwilę.
                        pg.wait_for_timeout(400)
                        m = pg.evaluate(POMIAR_NAKLADKI, okno_id)
                    except Exception as e:  # noqa: BLE001
                        m = {"otwarta": False, "blad": str(e)[:120]}
                    m.update({"adres": s, "okno_id": okno_id, "wysokosc": wys})
                    wyniki.append(m)
                    # ⚠ **Po każdym pomiarze wczytujemy stronę od nowa, zamiast zamykać
                    # warstwę.** Powód zmierzony 10.10.2026: część wyzwalaczy to `<a>` ze
                    # ścieżką zapasową bez JS, więc kliknięcie potrafi przenieść stronę,
                    # a `Escape` nie zamyka `<details>`. Stan po poprzednim pomiarze wywracał
                    # następny z komunikatem „NIE OTWARŁA SIĘ", czyli bramka zgłaszała usterkę
                    # ekranu zamiast własnego bałaganu. Jedno wczytanie na warstwę jest tańsze
                    # niż drugie śledztwo.
                    try:
                        pg.goto(BAZA + s, wait_until="domcontentloaded")
                        pg.wait_for_load_state("networkidle", timeout=5000)
                    except Exception:  # noqa: BLE001
                        pass
        finally:
            ctx.close()
    return wyniki


def sprawdz_bez_dostepu(b) -> dict:
    """Ekran „konto bez przydzielonego dostępu" — pilnowany drugim kontem, nie administratorem.

    ⚠ Sprawdzamy KOD i TREŚĆ, nie samą obecność szablonu: przed rozdzieleniem stanów ten sam
    ekran mówił „nie masz aktywnego zatrudnienia" osobie, która zatrudnienie ma. Gdyby warunek
    w `EmploymentContext` wrócił do jednego stanu, szablon nadal by się renderował, a bramka
    z samą obecnością pliku niczego by nie zauważyła.
    """
    ctx = b.new_context(viewport={"width": 1280, "height": 900}, ignore_https_errors=True)
    pg = ctx.new_page()
    try:
        pg.goto(BAZA + "/logowanie", wait_until="networkidle")
        pg.fill("#login-email", LOGIN2)
        pg.fill("#login-password", HASLO2)
        pg.click("button[type=submit]")
        pg.wait_for_load_state("networkidle")
        odp = pg.goto(BAZA + "/", wait_until="domcontentloaded")
        kod = odp.status if odp is not None else 0
        naglowek = (pg.text_content("h1") or "").strip()
        oczekiwany_kod, fragment = BEZ_DOSTEPU
        return {"login": LOGIN2, "kod": kod, "naglowek": naglowek,
                "ok": kod == oczekiwany_kod and fragment in naglowek}
    except Exception as e:  # noqa: BLE001
        return {"login": LOGIN2, "kod": None, "naglowek": None, "ok": False, "blad": str(e)}
    finally:
        ctx.close()


POST_HIERARCHII = """
async (d) => {
  const body = new URLSearchParams();
  body.set('_csrf', d.csrf);
  body.set('spolka', d.spolka);
  body.set('zmiany[0][employment]', d.employment);
  body.set('zmiany[0][cel]', d.cel);
  if (d.supervisor) { body.set('zmiany[0][supervisor]', d.supervisor); }
  const r = await fetch('/admin/hierarchia/zapisz', {
    method: 'POST', body: body, credentials: 'same-origin',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'}
  });
  return {kod: r.status, tresc: await r.text()};
}
"""


def sprawdz_obca_spolke(b) -> dict:
    """Hierarchia: przełożony z INNEJ spółki musi zostać odrzucony przy zapisie.

    ⚠ Identyfikatory czytane z EKRANU, nie wpisane na sztywno: druga spółka bierze się
    z przełącznika, a zatrudnienia z kart obu drzew. Wiersz wpisany w bramkę to ta sama
    pułapka co ścieżka wpisana z pamięci — przy pierwszym resecie danych bramka pilnowałaby
    nieistniejącego wiersza i świeciła na zielono.

    ⚠ Bez tego sprawdzenia „przełożony musi być z tej samej spółki" jest deklaracją: to jest
    reguła, przez którą dane kadrowe jednej spółki mogłyby wyjść ścieżką akceptacji drugiej.
    """
    ctx = b.new_context(viewport={"width": 1280, "height": 900}, ignore_https_errors=True)
    pg = ctx.new_page()
    try:
        pg.goto(BAZA + "/logowanie", wait_until="networkidle")
        pg.fill("#login-email", LOGIN)
        pg.fill("#login-password", HASLO)
        pg.click("button[type=submit]")
        pg.wait_for_load_state("networkidle")
        pg.goto(BAZA + "/admin/hierarchia", wait_until="networkidle")
        # ⚠ Spółki czytane z **pozycji znacznika w belce** (`.spolka__pozycja`), bo 10.10.2026
        # kontrolka z nagłówka tego ekranu zniknęła na rzecz jednego znacznika (§1 zlecenia
        # o globalnym wyborze spółki). Identyfikator siedzi w `action` formularza pozycji —
        # `/kontekst/spolka/{id}` dla podglądu albo `/kontekst/{zatrudnienie}` dla etatu;
        # bramce wystarczy ten pierwszy kształt, bo konto administracyjne nie ma etatów.
        spolki = pg.evaluate("""() => {
          const b = Array.from(document.querySelectorAll('.spolka__pozycja'));
          return b.map(x => {
            const akcja = (x.closest('form') || {}).action || '';
            const m = akcja.match(/\\/kontekst\\/spolka\\/(\\d+)$/);
            return {id: m ? m[1] : null, wybrana: x.className.indexOf('--on') >= 0};
          }).filter(s => s.id !== null);
        }""")
        if len(spolki) < 2:
            return {"ok": False, "powod": "tenant ma jedna spolke - nie ma czego lamac"}
        biezaca = next((s for s in spolki if s["wybrana"]), spolki[0])
        obca = next(s for s in spolki if s["id"] != biezaca["id"])
        pierwsza = pg.evaluate("""() => {
          const k = document.querySelector('.hier__card');
          const csrf = document.querySelector('input[name="_csrf"]');
          return k && csrf ? {id: k.getAttribute('data-hier-id'), csrf: csrf.value} : null;
        }""")
        pg.goto(BAZA + "/admin/hierarchia?spolka=" + obca["id"], wait_until="networkidle")
        druga = pg.evaluate("""() => {
          const k = document.querySelector('.hier__card');
          return k ? k.getAttribute('data-hier-id') : null;
        }""")
        if pierwsza is None or druga is None:
            return {"ok": False, "powod": "brak kart w jednej ze spolek"}
        pg.goto(BAZA + "/admin/hierarchia?spolka=" + biezaca["id"], wait_until="networkidle")
        dane = {"csrf": pierwsza["csrf"], "spolka": biezaca["id"],
                "employment": pierwsza["id"], "cel": "przelozony", "supervisor": druga}
        wynik = pg.evaluate(POST_HIERARCHII, dane)
        # ⚠ Stan czytany z ekranu WCZYTANEGO PO PRÓBIE, nie ze starego DOM-u: bez tego
        # przeładowania „stan nietknięty" było odczytem sprzed zapisu, czyli niczym.
        pg.goto(BAZA + "/admin/hierarchia?spolka=" + biezaca["id"], wait_until="networkidle")
        po = pg.evaluate("""(id) => {
          const k = document.querySelector('.hier__card[data-hier-id="' + id + '"]');
          return k ? (k.getAttribute('data-hier-przelozony') || '') : null;
        }""", pierwsza["id"])
        # ⚠ Komunikat sprawdzamy SŁOWEM, nie całym zdaniem (ta sama lekcja co przy „Moje firmy":
        # jedno zdanie w kryterium to kolejny egzemplarz napisu, który zapala bramkę przy zmianie
        # słownictwa). Warunek: odmowa **mówi o firmie** i nie wraca do słowa „spółka",
        # którego pilnuje osobno `bez_slowa_spolka`.
        #
        # ⚠ **Czytamy SAM KOMUNIKAT, nie całą stronę** (naprawa 20.10.2026, Z63 cz. A). Do tej
        # pory warunek przeszukiwał całą odpowiedź — a w niej stoi znacznik spółki z nazwami firm,
        # więc firma, której nazwa z umowy brzmi „… SPÓŁKA Z OGRANICZONĄ ODPOWIEDZIALNOŚCIĄ",
        # zapalała to kryterium na **poprawnej odmowie**. Zmierzone: odmowa 422, stan nietknięty,
        # a kryterium czerwone. Komunikat ma jedno miejsce w całej aplikacji (`partials/
        # komunikat.twig`) i własny znacznik `data-komunikat`, więc bierzemy go po nim.
        _m = re.search(r'data-komunikat="blad"[^>]*>(.*?)</p>', wynik["tresc"], re.S)
        komunikat = (_m.group(1) if _m else "").strip()
        komunikat_o_spolce = (
            komunikat != ""
            and SLOWO_UZYTKOWNIKA in komunikat.lower()
            and not ma_slowo_spolka(komunikat)
        )
        return {
            # „Stan nietknięty" znaczy: to zatrudnienie nadal nie ma przełożonego.
            "ok": wynik["kod"] == 422 and komunikat_o_spolce and po == "",
            "kod": wynik["kod"],
            "komunikat_odmowy": komunikat,
            "komunikat_o_spolce": komunikat_o_spolce,
            "przelozony_po_probie": po,
            "para": pierwsza["id"] + " <- " + druga + " (spolka " + obca["id"] + ")",
        }
    except Exception as e:  # noqa: BLE001
        return {"ok": False, "powod": str(e)}
    finally:
        ctx.close()


def ustaw_spolke_w_belce(pg, company_id: int) -> bool:
    """Ustawia KONKRETNĄ spółkę znacznikiem w belce — tak, jak klika ją człowiek.

    ⚠ Potrzebne od 10.10.2026 wszędzie, gdzie pomiar dotąd dopisywał `?spolka=` do adresu:
    ten parametr jest honorowany **jako widok, na jedno żądanie**, a znacznik dalej pokazuje
    wybór konta. Pomiar, który czyta nazwę ze znacznika, musi więc spółkę **ustawić**,
    a nie podać w adresie — inaczej mierzy dwa różne stany naraz.

    Zwraca `False`, gdy tej spółki nie ma w znaczniku (np. ekran tenantowy albo brak dostępu).
    """
    if pg.locator("details.spolka > summary").count() == 0:
        return False
    biezaca = pg.evaluate("() => ((document.querySelector('.spolka') || {}).dataset || {}).spolka || ''")
    if biezaca == str(company_id):
        return True
    pg.locator("details.spolka > summary").first.click()
    pozycja = pg.locator(f"form[action$='/kontekst/spolka/{company_id}'] .spolka__pozycja")
    if pozycja.count() == 0:
        # Konto z etatem w tej spółce przełącza się POZYCJĄ ZATRUDNIENIA — nazwa jest wtedy
        # jedyną wskazówką, bo w adresie stoi identyfikator etatu, nie spółki.
        return False
    pozycja.first.click()
    pg.wait_for_load_state("networkidle")
    return pg.evaluate(
        "() => ((document.querySelector('.spolka') || {}).dataset || {}).spolka || ''") == str(company_id)


def przelacz_spolke_w_belce(pg) -> str | None:
    """Przełącza spółkę **tą samą drogą co człowiek** — klikając pozycję w znaczniku w belce.

    ⚠ Potrzebne od 10.10.2026: ekrany spółkowe biorą spółkę z kontekstu, a nie z `?spolka=`
    w adresie (§1 zlecenia o globalnym wyborze spółki). Pomiar, który chce zmierzyć coś
    w konkretnej spółce, musi ją **ustawić**, a nie dopisać do adresu — bo po pierwszym zapisie
    przekierowanie wróci do spółki z kontekstu i dalszy pomiar mierzy inny ekran.

    Zwraca nazwę spółki po przełączeniu albo `None`, gdy nie ma na co przełączyć.
    """
    if pg.locator("details.spolka > summary").count() == 0:
        return None
    biezaca = pg.evaluate(
        "() => ((document.querySelector('.spolka__nazwa') || {}).textContent || '').trim()")
    pg.locator("details.spolka > summary").first.click()
    pozycje = pg.locator(".spolka__pozycja")
    for i in range(pozycje.count()):
        tekst = (pozycje.nth(i).text_content() or "").strip()
        if tekst and tekst != biezaca:
            pozycje.nth(i).click()
            pg.wait_for_load_state("networkidle")
            return tekst
    return None


def sprawdz_ostrzezenie_wyjscia(b) -> dict:
    """Hierarchia: ostrzeżenie o niezapisanych zmianach **przy wyjściu**, ale NIE przy zapisie.

    ⚠ Powstało po usterce blokującej z 22.09.2026: `beforeunload` strzelał na własnym zapisie
    aplikacji, więc „Zapisz zmiany" otwierało okno „czy chcesz opuścić stronę", a kto kliknął
    „Zostań", **tracił zapis, robiąc dokładnie to, o co ekran prosił.**

    ⚠ I druga lekcja, ważniejsza: w raporcie napisałem, że tego okna **nie da się zmierzyć**,
    bo „Playwright je odrzuca" — i usterka wylądowała dokładnie w miejscu oznaczonym jako
    niesprawdzalne. To było nieprawdziwe: okna `beforeunload` **przychodzą** jako zdarzenie
    `dialog`, tylko trzeba założyć nasłuch. Luka nie była w narzędziu, a w moim pomiarze.

    Dwa przypadki, bo jeden bez drugiego niczego nie dowodzi:
      - `zapis`  — po przeciągnięciu klik „Zapisz zmiany": okna BYĆ NIE MOŻE, a adres ma
        skończyć się na `ok=1`,
      - `wyjscie` — po przeciągnięciu klik w menu boczne: okno MUSI być.
    """
    ctx = b.new_context(viewport={"width": SZEROKOSC_LAPTOPA, "height": 900}, ignore_https_errors=True)
    pg = ctx.new_page()
    okna: list[str] = []
    # ⚠ Przed `try`, nie w środku: `finally` sięga po tę listę także wtedy, gdy wyjątek padnie
    # przed pierwszym przeciągnięciem — a `NameError` w sprzątaniu zamaskowałby prawdziwy powód.
    zapisane: list[str] = []
    # ⚠ Spółka, którą ten pomiar przestawił w belce, wraca w `finally` — kontekst jest TRWAŁY,
    # a zostawiona zmiana czerwieni następne sprawdzenia (zmierzone 10.10.2026).
    przelaczone: list[str] = []
    pg.on("dialog", lambda d: (okna.append(d.type), d.accept()))
    try:
        pg.goto(BAZA + "/logowanie", wait_until="networkidle")
        pg.fill("#login-email", LOGIN)
        pg.fill("#login-password", HASLO)
        pg.click("button[type=submit]")
        pg.wait_for_load_state("networkidle")

        # ⚠ Zapamiętujemy, KTO został przeciągnięty — bez tego nie da się wycofać zapisu,
        # a sprawdzenie ostrzeżenia MUSI zapis dokończyć, żeby cokolwiek mierzyć. Do 22.09.2026
        # bramka zostawiała po sobie przestawioną strukturę przy każdym przebiegu, więc zdanie
        # „struktura z pomiaru stoi do obejrzenia" przestawało być prawdą (§7 zlecenia).
        def przeciagnij() -> bool:
            pg.goto(BAZA + "/admin/hierarchia", wait_until="networkidle")
            zrodlo = pg.locator("#hier-nieustawieni .hier__card").first
            cel = pg.locator("#hier-drzewo .hier__card").first
            if zrodlo.count() == 0 or cel.count() == 0:
                return False
            przeciagany = zrodlo.get_attribute("data-hier-id")
            zrodlo.drag_to(cel)
            pg.wait_for_timeout(300)
            if pg.locator(".hier__bar-item").count() == 0:
                return False
            if przeciagany:
                zapisane.append(przeciagany)
            return True

        if not przeciagnij():
            # ⚠ Ten pomiar potrzebuje spółki, w której JEST co przeciągnąć — a od 10.10.2026
            # ekran bierze spółkę z kontekstu, nie z adresu. Gdy w bieżącej spółce panel
            # „nieustawieni" albo drzewo są puste, przełączamy spółkę **znacznikiem w belce**
            # (tak jak człowiek) i próbujemy jeszcze raz. Która spółka dostarczy materiału,
            # jest dla tego kryterium nieistotne — istotne, że ostrzeżenie pada przy wyjściu.
            pg.goto(BAZA + "/admin/hierarchia", wait_until="networkidle")
            spolka_przed = pg.evaluate(
                "() => ((document.querySelector('.spolka') || {}).dataset || {}).spolka || ''")
            przelaczone.append(spolka_przed)
            if przelacz_spolke_w_belce(pg) is None or not przeciagnij():
                return {"ok": False,
                        "powod": "nie ma z czego zrobic zmiany (pusty panel albo drzewo) "
                                 "w zadnej dostepnej spolce"}
        okna.clear()
        pg.click('#hier-bar button[type="submit"]')
        pg.wait_for_load_state("networkidle")
        zapis_okna = list(okna)
        zapis_adres = pg.url

        if not przeciagnij():
            return {"ok": False, "powod": "nie udalo sie powtorzyc zmiany"}
        okna.clear()
        pg.click('.nav-item[href$="/admin/konta"]')
        pg.wait_for_timeout(1500)
        wyjscie_okna = list(okna)

        return {
            "ok": zapis_okna == [] and "ok=1" in zapis_adres and wyjscie_okna != [],
            "zapis_okien": len(zapis_okna),
            "zapis_adres": zapis_adres.split("hierarchia")[-1],
            "wyjscie_okien": len(wyjscie_okna),
            "wycofane": zapisane,
        }
    except Exception as e:  # noqa: BLE001
        return {"ok": False, "powod": str(e)}
    finally:
        # ⚠ Wycofanie zapisu wykonanego przez to sprawdzenie — tą samą ścieżką aplikacji,
        # którą poszedł zapis. Zdejmujemy przełożonego z KAŻDEGO przeciągniętego zatrudnienia:
        # ten drugi (z próby wyjścia) zapisu nie dokończył, więc `zdejmij` jest dla niego
        # operacją bez skutku — a nie musimy zgadywać, który to był.
        try:
            pg.goto(BAZA + "/admin/hierarchia", wait_until="networkidle")
            dane = pg.evaluate("""() => {
              const c = document.querySelector('input[name="_csrf"]');
              const s = document.querySelector('input[name="spolka"]');
              return c && s ? {csrf: c.value, spolka: s.value} : null;
            }""")
            for kogo in dict.fromkeys(zapisane):
                if dane:
                    pg.evaluate(POST_HIERARCHII, {**dane, "employment": kogo,
                                                  "cel": "zdejmij", "supervisor": ""})
        except Exception:  # noqa: BLE001
            pass
        # ⚠ Wycofanie SPÓŁKI **na końcu**, już po wycofaniu zapisu: wycofanie zapisu czyta
        # identyfikator spółki z ekranu, więc przestawiona wcześniej spółka kazałaby mu
        # zdejmować przełożonego w cudzej spółce — czyli nie zdjąć go wcale.
        for spolka_wracajaca in przelaczone:
            if spolka_wracajaca == "":
                continue
            try:
                pg.goto(BAZA + "/admin/hierarchia", wait_until="networkidle")
                ustaw_spolke_w_belce(pg, int(spolka_wracajaca))
            except Exception:  # noqa: BLE001
                pass
        ctx.close()


def sprawdz_odrzucenie_cyklu(b) -> dict:
    """Hierarchia: żądanie układające PĘTLĘ musi wrócić błędem, a stan zostać NIETKNIĘTY.

    ⚠ To jest dowód przez złamanie, nie sprawdzenie wyglądu (warunek ze zlecenia §11).
    Bramka, która tylko wchodzi na ekran, potwierdza wyłącznie, że drzewo się rysuje —
    a rzecz, na której ten ekran stoi, to odmowa zapisu: cykl `A -> B -> A` zawiesiłby
    eskalację w nieskończonej pętli (znalezisko L2).

    ⚠ **Sprawdzenie samo przygotowuje sobie warunek i samo po sobie sprząta.** Pierwsza wersja
    wymagała gotowej krawędzi w danych roboczych i poszła na czerwono w dniu, w którym Krzysztof
    wyczyścił hierarchię ręcznie — czyli **fałszywy alarm z powodu stanu danych, nie kodu**.
    Bramka krzycząca bez powodu przestaje być czytana dokładnie tak samo jak lista odchyleń
    świecąca przy każdym wierszu. Zapis idzie ścieżką aplikacji i zostaje wycofany na końcu.
    """
    ctx = b.new_context(viewport={"width": 1280, "height": 900}, ignore_https_errors=True)
    pg = ctx.new_page()
    przygotowane = None
    try:
        pg.goto(BAZA + "/logowanie", wait_until="networkidle")
        pg.fill("#login-email", LOGIN)
        pg.fill("#login-password", HASLO)
        pg.click("button[type=submit]")
        pg.wait_for_load_state("networkidle")
        pg.goto(BAZA + "/admin/hierarchia", wait_until="networkidle")
        dane = pg.evaluate("""() => {
          const karty = Array.from(document.querySelectorAll('.hier__card'));
          const csrf = document.querySelector('input[name="_csrf"]');
          const spolka = document.querySelector('input[name="spolka"]');
          if (karty.length < 2 || !csrf || !spolka) { return null; }
          return {a: karty[0].getAttribute('data-hier-id'),
                  b: karty[1].getAttribute('data-hier-id'),
                  csrf: csrf.value, spolka: spolka.value};
        }""")
        if dane is None:
            return {"ok": False, "powod": "mniej niz dwa zatrudnienia w spolce - nie ma z czego zrobic krawedzi"}
        # 1. Warunek: `a` podlega `b` — zwykłą ścieżką zapisu aplikacji.
        krok1 = pg.evaluate(POST_HIERARCHII, {**dane, "employment": dane["a"],
                                              "cel": "przelozony", "supervisor": dane["b"]})
        if krok1["kod"] not in (200, 303):
            return {"ok": False, "powod": "nie udalo sie przygotowac krawedzi", "kod": krok1["kod"]}
        przygotowane = dane
        # 2. Pętla: `b` ma od teraz podlegać swojemu własnemu podwładnemu.
        wynik = pg.evaluate(POST_HIERARCHII, {**dane, "employment": dane["b"],
                                              "cel": "przelozony", "supervisor": dane["a"]})
        # 3. Stan po próbie — z ekranu wczytanego PO niej, nie ze starego DOM-u.
        pg.goto(BAZA + "/admin/hierarchia?spolka=" + dane["spolka"], wait_until="networkidle")
        po = pg.evaluate("""(id) => {
          const k = document.querySelector('.hier__card[data-hier-id="' + id + '"]');
          return k ? (k.getAttribute('data-hier-przelozony') || '') : null;
        }""", dane["b"])
        return {
            "ok": wynik["kod"] == 422 and "pętlę w hierarchii" in wynik["tresc"] and po == "",
            "kod": wynik["kod"],
            "komunikat_o_petli": "pętlę w hierarchii" in wynik["tresc"],
            "przelozony_szefa_po_probie": po,
            "para": dane["b"] + " <- " + dane["a"],
        }
    except Exception as e:  # noqa: BLE001
        return {"ok": False, "powod": str(e)}
    finally:
        # Wycofanie przygotowanej krawędzi — bramka nie zostawia po sobie struktury.
        if przygotowane is not None:
            try:
                pg.evaluate(POST_HIERARCHII, {**przygotowane, "employment": przygotowane["a"],
                                              "cel": "zdejmij", "supervisor": ""})
            except Exception:  # noqa: BLE001
                pass
        ctx.close()


def zatrudnienia_pracownika() -> dict:
    """Identyfikatory do sprawdzenia zasięgu — ODCZYTANE z bazy, nie wpisane w bramkę.

    ⚠ Wiersz wpisany na sztywno to ta sama pułapka co ścieżka wpisana z pamięci: po pierwszym
    resecie danych bramka pilnowałaby nieistniejącego zatrudnienia i świeciła zielono. Czytamy
    więc: zatrudnienie konta pracownika, dwie osoby z tej samej spółki BEZ przełożonego (będą
    podwładnymi na czas pomiaru), jedno zatrudnienie spoza gałęzi w tej samej spółce i jedno
    z DRUGIEJ spółki.
    """
    import subprocess
    kod = (
        'require "vendor/autoload.php";'
        'Dotenv\\Dotenv::createImmutable(__DIR__)->load();'
        '$e = $_ENV;'
        '$pdo = new PDO("mysql:host={$e[\'DB_HOST\']};port={$e[\'DB_PORT\']};dbname={$e[\'DB_NAME\']};charset=utf8mb4",'
        '  $e["DB_USER"], $e["DB_PASS"], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);'
        '$st = $pdo->prepare("SELECT e.id, e.company_id FROM employment e JOIN account a ON a.person_id = e.person_id'
        '  WHERE a.email = :mail AND e.is_active = 1 ORDER BY e.company_id LIMIT 1");'
        '$st->execute(["mail" => $argv[1]]);'
        '$ja = $st->fetch();'
        'if (!$ja) { echo "{}"; exit; }'
        '$st = $pdo->prepare("SELECT id FROM employment WHERE company_id = :c AND is_active = 1'
        '  AND supervisor_employment_id IS NULL AND is_hierarchy_root = 0 AND id <> :ja ORDER BY id LIMIT 2");'
        '$st->execute(["c" => $ja["company_id"], "ja" => $ja["id"]]);'
        '$wolni = $st->fetchAll(PDO::FETCH_COLUMN);'
        '$st = $pdo->prepare("SELECT id FROM employment WHERE company_id = :c AND is_active = 1'
        '  AND supervisor_employment_id IS NOT NULL ORDER BY id LIMIT 1");'
        '$st->execute(["c" => $ja["company_id"]]);'
        '$obcy = $st->fetchColumn();'
        '$st = $pdo->prepare("SELECT id FROM employment WHERE company_id <> :c AND is_active = 1 ORDER BY id LIMIT 1");'
        '$st->execute(["c" => $ja["company_id"]]);'
        '$innaSpolka = $st->fetchColumn();'
        '$trojka = array_merge([(int) $ja["id"]], array_map("intval", $wolni));'
        '$in = implode(",", array_fill(0, count($trojka), "?"));'
        '$st = $pdo->prepare("SELECT DATE_FORMAT(a.date_from, \'%Y-%m\') AS m, COUNT(*) AS ile'
        '  FROM external_absence a JOIN leave_type t ON t.id = a.leave_type_id'
        '  WHERE a.employment_id IN (" . $in . ") AND a.removed_at IS NULL AND t.is_absence = 1'
        '  GROUP BY m ORDER BY ile DESC, m DESC LIMIT 1");'
        '$st->execute($trojka);'
        '$miesiac = $st->fetchColumn();'
        'echo json_encode(["ja" => (int) $ja["id"], "spolka" => (int) $ja["company_id"],'
        '  "wolni" => array_map("intval", $wolni), "obcy" => $obcy === false ? null : (int) $obcy,'
        '  "inna_spolka" => $innaSpolka === false ? null : (int) $innaSpolka,'
        '  "miesiac" => $miesiac === false ? null : (string) $miesiac]);'
    )
    wyn = subprocess.run(["php", "-r", kod, LOGIN3], cwd=KORZEN, capture_output=True, text=True, check=True)
    return json.loads(wyn.stdout or "{}")


# ⚠ **Kryterium kontrastu paska z 06.10 ZASTĄPIONE, nie skasowane** (zlecenie
# `2026-10-11-paski-nieobecnosci.md` §5). Tamto mierzyło wypełnienie neutralne i **obrys**
# w kolorze rodzaju (`obrys_do_wypelnienia`) — a od 11.10.2026 obrysu nie ma wcale, więc
# połowa jego pomiaru nie miała już czego dotyczyć i przechodziła na `undefined`.
# Następca mierzy trzy rzeczy, każdą łamaną osobno (nazwy progów stoją obok komentarzy,
# żeby zmiana progu wymagała świadomej edycji, a nie podmiany cyfry w wyrażeniu).

# Tekst na pasku — próg WCAG dla treści drobnej.
# ⚠ **SŁOWO, nie zdanie** — zgłoszenie Krzysztofa (zlecenie 11.10 §6): „skoro jeden napis miał
# cztery egzemplarze, to przy następnej zmianie słowa będzie ich pięć". Zmierzone na własnej
# skórze w tej samej turze: zmiana „Moje spółki" → „Moje firmy" zapaliła `kontekst_spolki`,
# bo kryterium porównywało CAŁE zdanie z szablonu.
#
# Kryteria pilnują więc **rozstrzygnięcia słownikowego** („to, co widzi użytkownik, nazywa się
# FIRMĄ"), a nie konkretnego brzmienia napisu. Przeredagowanie zdania nie zapala bramki; powrót
# do „spółki" — zapala. ⚠ Czytanie oczekiwanego tekstu z szablonu (druga rozważana droga) odpada:
# kryterium porównujące kod z samym sobą przepuściłoby każde słowo, także „Zupa".
SLOWO_UZYTKOWNIKA = "firm"
# ⚠ **Dwa rdzenie, nie jeden**: „spółk" nie łapie „spółce" (miejscownik, `k` → `c`). Ta sama
# poprawka co w `POMIAR_SLOWA` — i z tego samego powodu: zdanie w widoku zespołu przechodziło.
RDZENIE_ZAKAZANE = ("spółk", "spółc", "spolk", "spolc")


def ma_slowo_spolka(tekst: str | None) -> bool:
    """Czy napis zawiera zakazane słowo w DOWOLNEJ odmianie (także bez ogonków)."""
    maly = (tekst or "").lower()

    return any(r in maly for r in RDZENIE_ZAKAZANE)


KONTRAST_TEKSTU_MIN = 4.5
# Pasek wobec tła wiersza (dzień roboczy) — podłoga z `system-wizualny.md` 1.14: nie mniej niż
# miał kiedyś sam weekend (1,12). ⚠ Poprzednie wypełnienie neutralne dawało 1,227; pastele
# ze wzoru Krzysztofa dają 1,17–1,21, czyli MNIEJ — zgłoszone liczbami w raporcie (§1 pkt 2).
KONTRAST_PASKA_MIN = 1.12
# ⚠ Sufit wykrywa BŁĘDNY POMIAR, nie brzydotę: wypełnienie pastelowe ma kontrast rzędu 1,2,
# więc liczba rzędu kilkunastu znaczy, że policzyliśmy luminancję nieskładanej przezroczystości
# (zmierzone 06.10: 17,778 i kryterium przechodziło pozornie).
KONTRAST_PASKA_MAX = 3.0
# Pasek na komórce dnia WOLNEGO: tło jest tam ciemniejsze, więc ta sama podłoga co na bieli
# byłaby arytmetycznie nieosiągalna (sama komórka dnia wolnego ma 1,06 do wiersza). Warunek jest
# inny i jawny: pasek musi się nadal ODCINAĆ od tego, na czym stoi.
KONTRAST_NA_WOLNYM_MIN = 1.05

POMIAR_PASKOW = """() => {
  const lum = (rgb) => {
    const c = rgb.match(/\\d+/g).slice(0, 3).map(n => {
      const x = Number(n) / 255;
      return x <= 0.03928 ? x / 12.92 : Math.pow((x + 0.055) / 1.055, 2.4);
    });
    return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2];
  };
  const kontrast = (a, b) => {
    const la = lum(a), lb = lum(b);
    return +(((Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05)).toFixed(3));
  };
  // ⚠ Warstwy półprzezroczyste trzeba ZŁOŻYĆ z tym, co pod nimi (ta sama funkcja co w pomiarze
  // z 06.10): przezroczystość policzona jak kolor daje odcień prawie czarny i kontrast 17 —
  // liczbę bez związku z ekranem.
  const skladaj = (fg, a, bg) => fg.map((v, i) => Math.round(v * a + bg[i] * (1 - a)));
  const kanaly = (t) => {
    const m = t.match(/rgba?\\(([^)]+)\\)/);
    if (!m) { return null; }
    const cz = m[1].split(',').map(x => parseFloat(x));
    return {rgb: cz.slice(0, 3), a: cz.length > 3 ? cz[3] : 1};
  };
  const efektywne = (el) => {
    const warstwy = [];
    for (let n = el; n; n = n.parentElement) {
      const c = kanaly(getComputedStyle(n).backgroundColor);
      if (c === null || c.a === 0) { continue; }
      warstwy.push(c);
      if (c.a >= 1) { break; }
    }
    let wynik = [255, 255, 255];
    for (let i = warstwy.length - 1; i >= 0; i--) {
      wynik = skladaj(warstwy[i].rgb, warstwy[i].a, wynik);
    }
    return 'rgb(' + wynik.join(', ') + ')';
  };
  const komorki = Array.from(document.querySelectorAll('.team-grid__cell'));
  const zwykla = komorki.find(c => !c.classList.contains('team-grid__cell--free')
                                   && !c.querySelector('.team-grid__bar'));
  const wolna = komorki.find(c => c.classList.contains('team-grid__cell--free')
                                  && !c.querySelector('.team-grid__bar'));
  if (!zwykla || !wolna) { return {blad: 'brak komórki odniesienia'}; }
  const tlo_wiersza = efektywne(zwykla);
  const tlo_wolnego = efektywne(wolna);

  const opis = (el, rodzaj, na_wolnym) => {
    const st = getComputedStyle(el);
    const tlo = efektywne(el);
    return {rodzaj, tlo, tekst: st.color, waga: st.fontWeight,
            promien: st.borderTopLeftRadius, obrys: st.borderTopWidth,
            cyfry: st.fontVariantNumeric,
            k_tekst: kontrast(st.color, tlo),
            k_do_wiersza: kontrast(tlo, tlo_wiersza),
            k_do_wolnego: kontrast(tlo, tlo_wolnego),
            na_wolnym};
  };

  // Paski, które naprawdę stoją na ekranie.
  const zywe = Array.from(document.querySelectorAll('.team-grid__bar')).map(b => opis(
    b, (b.className.match(/team-grid__bar--(urlop|zasilek|kadrowe)/) || [])[1] || 'nieznany',
    b.parentElement.classList.contains('team-grid__cell--free')));

  // ⚠ **Sonda dla rodzajów, których nie ma w danych roboczych.** Zmierzone: w bazie nie ma ani
  // jednej nieobecności ze źródłem `kadrowe`, więc mierzenie „wszystkich trzech rodzajów" tylko
  // po ekranie dawałoby dwa rodzaje i trzeci zielony na pusto. Sonda to element wstawiony
  // w PRAWDZIWĄ komórkę siatki, więc czyta ten sam arkusz co paski wyżej — i jest usuwana.
  const sondy = {};
  ['urlop', 'zasilek', 'kadrowe'].forEach(r => {
    const s = document.createElement('span');
    s.className = 'team-grid__bar team-grid__bar--' + r;
    zwykla.appendChild(s);
    sondy[r] = opis(s, r, false);
    s.remove();
    const s2 = document.createElement('span');
    s2.className = 'team-grid__bar team-grid__bar--' + r;
    wolna.appendChild(s2);
    sondy[r].k_do_wolnego = opis(s2, r, true).k_do_wolnego;
    s2.remove();
  });

  // ⚠ Numer dnia na pasku jednodniowym musi zgadzać się z KOLUMNĄ, w której pasek stoi —
  // nie wystarczy, że tekst jest niepusty (to ta sama pułapka co „pole szukania istnieje"
  // kontra „szukanie działa"). Kolumnę rozstrzygamy GEOMETRIĄ: szukamy nagłówka dnia, w którego
  // poziomy zakres wpada środek paska. Liczenie po kolejności w DOM nie zadziała, bo komórki
  // z rozpiętością zajmują kilka kolumn.
  // ⚠ Numer czytamy z `.team-grid__numer` (albo z plakietki `.dzien-dzis` w dniu dzisiejszym),
  // a NIE z `textContent` całego nagłówka: od 13.10.2026 w nagłówku stoi też skrót dnia tygodnia,
  // więc całość daje „sob17" i porównanie z napisem paska („17") nie mogło się udać. Kryterium
  // świeciło czerwono na poprawnym ekranie — to był błąd pomiaru, nie produktu.
  const naglowki = Array.from(document.querySelectorAll('.team-grid__head'))
    .filter(h => !h.classList.contains('team-grid__head--person'))
    .map(h => {
      const n = h.querySelector('.team-grid__numer, .dzien-dzis');
      return {numer: (n || h).textContent.trim(), r: h.getBoundingClientRect(),
              wolny: h.classList.contains('team-grid__head--free')};
    });
  const numery = Array.from(document.querySelectorAll('.team-grid__bar--jeden')).map(b => {
    const r = b.getBoundingClientRect();
    const srodek = r.left + r.width / 2;
    const kol = naglowki.find(h => srodek >= h.r.left && srodek <= h.r.right);
    return {napis: b.textContent.trim(), kolumna: kol ? kol.numer : null,
            zgodny: !!kol && kol.numer === b.textContent.trim(),
            szer: Math.round(r.width),
            ucieta: b.scrollWidth > b.clientWidth + 1};
  });

  // ⚠ **Tło POD PASKIEM, dzień po dniu** (zlecenie Z68). Pytanie brzmi „jaki kolor widzi
  // człowiek nad paskiem i pod nim w kolumnie dnia wolnego", więc czytamy kolor W PUNKCIE
  // (`elementFromPoint`), a nie klasy naszej warstwy pod-kratek: kryterium ma mierzyć WYNIK,
  // nie sposób jego osiągnięcia — przy innej implementacji (gradient, pseudoelement) musi
  // działać bez zmiany. Pasek ma `inset: 6px 2px`, więc 2 px nad jego krawędzią i 2 px pod nią
  // leżą jeszcze w komórce.
  // ⚠ Punkt poza oknem zwraca co innego albo nic, a oś czasu przewija się w poziomie — kolumny
  // spoza widoku są więc POMIJANE i policzone osobno, żeby „zero próbek" nie przeszło na zielono.
  const pod_paskiem = [];
  let pominietych = 0;
  Array.from(document.querySelectorAll('.team-grid__bar')).forEach(b => {
    const rk = b.parentElement.getBoundingClientRect();
    const rb = b.getBoundingClientRect();
    const kolumny = naglowki.filter(h => h.r.left >= rk.left - 1 && h.r.right <= rk.right + 1);
    if (kolumny.length < 2) { return; }
    kolumny.forEach(h => {
      const x = (h.r.left + h.r.right) / 2;
      const nad = rb.top - 2, pod = rb.bottom + 2;
      if (x < 0 || x > window.innerWidth || nad < 0 || pod > window.innerHeight) {
        pominietych++;
        return;
      }
      const eNad = document.elementFromPoint(x, nad);
      const ePod = document.elementFromPoint(x, pod);
      pod_paskiem.push({
        numer: h.numer,
        wolny: h.wolny,
        tlo_nad: eNad ? efektywne(eNad) : null,
        tlo_pod: ePod ? efektywne(ePod) : null,
      });
    });
  });

  return {tlo_wiersza, tlo_wolnego, zywe, sondy, numery,
          pod_paskiem, pominietych,
          paskow: zywe.length, jednodniowych: numery.length,
          legenda: Array.from(document.querySelectorAll('.legend__mark')).map(m => {
            const st = getComputedStyle(m);
            return {klasa: m.className, tlo: efektywne(m), promien: st.borderTopLeftRadius};
          }),
          strona_przewija: document.documentElement.scrollWidth > document.documentElement.clientWidth};
}"""


def nazwy_firm() -> list[str]:
    """Rzeczywiste nazwy firm tego tenanta — z bazy, **z nazwą prawną z R2 włącznie**.

    ⚠ Potrzebne do JEDNEGO wyjątku w kryterium `bez_slowa_spolka`: „BETA SPÓŁKA
    Z OGRANICZONĄ ODPOWIEDZIALNOŚCIĄ" to **dana z kadr**, nie nasze słowo (rozstrzygnięcie
    11.10.2026). Wyjątek oparty na KSZTAŁCIE napisu („pomiń, jeśli wielkimi literami")
    przepuściłby nasz własny napis, który akurat będzie wyglądał podobnie — dlatego porównujemy
    z nazwami z bazy.
    """
    import subprocess
    kod = (
        'require "vendor/autoload.php";'
        'Dotenv\\Dotenv::createImmutable(__DIR__)->load();'
        '$e = $_ENV;'
        '$pdo = new PDO("mysql:host={$e[\'DB_HOST\']};port={$e[\'DB_PORT\']};dbname={$e[\'DB_NAME\']};charset=utf8mb4",'
        '  $e["DB_USER"], $e["DB_PASS"], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);'
        '$st = $pdo->query("SELECT name, display_name FROM company");'
        '$n = [];'
        'foreach ($st->fetchAll() as $r) {'
        '  foreach (["name", "display_name"] as $k) {'
        '    if ((string) ($r[$k] ?? "") !== "") { $n[] = (string) $r[$k]; }'
        '  }'
        '}'
        'echo json_encode($n, JSON_UNESCAPED_UNICODE);'
    )
    wyn = subprocess.run(["php", "-r", kod], cwd=KORZEN, capture_output=True, text=True, check=True)
    return json.loads(wyn.stdout or "[]")


# ⚠ **Kryterium `bez_slowa_spolka`** (rozstrzygnięcie Krzysztofa 11.10.2026: „nie każda firma
# jest spółką" — „spółka" to forma prawna, a część klientów to jednoosobowa działalność,
# spółdzielnia albo fundacja; dla nich „Wybierz spółkę" nie jest niespójne, tylko NIEPRAWDZIWE).
#
# ⚠ Dlaczego kryterium, a nie jednorazowy przegląd: w kodzie „spolka" zostaje wszędzie (klasy,
# kolumny, trasy), więc nawrót słowa do napisu jest kwestią czasu — pierwszy nowy ekran wpisze
# je z pamięci. Kryterium idzie przez **wszystkie ekrany, które bramka i tak odwiedza**.
#
# ⚠ Szukamy po RDZENIU (`spó[łl][kc]`), nie po haśle „spółka": odmiany („spółki", „spółce",
# „spółek", „spółką") i złożenia („per spółka", „międzyspółkowy") inaczej przechodzą. Litera `l`
# w klasie znaków łapie zapis bez ogonka.
# ⚠ **`[kc]` dopisane 21.10.2026 i to był prawdziwy błąd, nie dopieszczenie**: do tego dnia rdzeń
# brzmiał `spółk`, a komentarz obok twierdził, że łapie „spółce". Nie łapał — w celowniku
# i miejscowniku `k` przechodzi w `c`. Kryterium świeciło zielono nad zdaniem „… w spółce
# Piaskarz" w widoku zespołu (zgłoszenie Z70). Nauka ogólniejsza: deklarowanego zakresu
# wyrażenia trzeba sprawdzić PRZYKŁADEM każdej wymienionej odmiany, bo komentarz nie jest testem.
#
# ⚠ Tekst widoczny czytamy z `innerText` (nie `innerHTML` — inaczej łapiemy nazwy klas i tras),
# a atrybuty **osobno**: `title`, `aria-label`, `placeholder`, `alt`. Napis w dymku jest napisem.
POMIAR_SLOWA = r"""(nazwy) => {
  // ⚠ Rdzeń z OGONKAMI (`spółk`), nie `spolka`: tak pisze człowiek i tak brzmią wszystkie
  // odmiany (spółki, spółce, spółek, spółką, spółkowy). Zapis bez ogonków występuje wyłącznie
  // w IDENTYFIKATORACH — klucze ładunku zdarzeń w dzienniku (`z_spolka`, `na_spolka`), nazwy
  // klas i tras — a te rozstrzygnięcie z 11.10 zostawia bez zmian. Zmierzone: wariant bez
  // ogonków dawał 22 trafienia na `/admin/dziennik`, wszystkie na kluczach ładunku.
  // ⚠ **`[kc]`, nie samo `k`** — poprawka z 21.10.2026 (Z70). Komentarz wyżej TWIERDZIŁ, że rdzeń
  // „spółk" łapie też „spółce", i to było nieprawdą: w celowniku i miejscowniku `k` przechodzi
  // w `c` („w spółce"), więc kryterium było ślepe dokładnie na tę formę, którą najczęściej pisze
  // się w zdaniu. Zmierzone: zdanie „Widzisz swoją gałąź: 39 osób z 39 w spółce Piaskarz" stało
  // na `/kalendarz?widok=zespol` i przechodziło na zielono.
  const rdzen = /spó[łl][kc]/i;
  // Nazwy firm z bazy wycinamy PRZED szukaniem — „BETA SPÓŁKA Z OGRANICZONĄ
  // ODPOWIEDZIALNOŚCIĄ" to dana z kadr, nie nasze słowo. Najdłuższe najpierw, żeby nazwa
  // zawierająca inną nie zostawiła ogona.
  // ⚠ Wycinamy nazwy firm ORAZ ich początki: nazwę prawną sami przycinamy w kilku miejscach
  // (np. `placeholder="{{ s.nazwa_r2|slice(0, 24) }}"` na ekranie firm), więc w tekście stoi
  // „BETA SPÓŁKA Z OGRANICZ" — kawałek danej z kadr, nie nasze słowo. Warunek zostaje
  // oparty na DANYCH: usuwany napis musi być początkiem prawdziwej nazwy z bazy, i to
  // długim (≥ 12 znaków), żeby wyjątek nie zrobił się workiem.
  const kandydaci = [];
  for (const n of nazwy) {
    if (!n || n.length < 3) { continue; }
    kandydaci.push(n);
    for (let d = n.length - 1; d >= 12; d--) { kandydaci.push(n.slice(0, d)); }
  }
  kandydaci.sort((a, b) => b.length - a.length);
  const bezNazw = (t) => {
    let x = t;
    for (const n of kandydaci) { if (x.includes(n)) { x = x.split(n).join(' '); } }
    return x;
  };
  // ⚠ Rozbicie na zdania: po kropce/wykrzykniku/pytajniku ORAZ na końcach linii
  // (`[\u000a\u000d]`, zapisane kodami, żeby w źródle bramki nie stał prawdziwy znak nowej
  // linii — pierwsza wersja miała go i regex rozjechał się na dwie linijki).
  const zdania = (t) => bezNazw(t).split(/(?<=[.!?])\s+|[\u000a\u000d]+/)
    .map(z => z.trim()).filter(z => rdzen.test(z));
  const znaleziska = [];
  const tekst = document.body ? (document.body.innerText || '') : '';
  zdania(tekst).forEach(z => znaleziska.push({gdzie: 'tekst', zdanie: z.slice(0, 180)}));
  document.querySelectorAll('[title], [aria-label], [placeholder], [alt]').forEach(el => {
    ['title', 'aria-label', 'placeholder', 'alt'].forEach(a => {
      const v = el.getAttribute(a);
      if (v && rdzen.test(bezNazw(v))) {
        znaleziska.push({gdzie: 'atrybut:' + a, zdanie: v.slice(0, 180),
                         element: el.tagName.toLowerCase()
                           + (el.className ? '.' + String(el.className).split(' ')[0] : '')});
      }
    });
  });
  return znaleziska;
}"""


# ⚠ **Kryterium `tytul_ekranu`** (zlecenie `2026-10-11-skale-czasu-i-tytul-ekranu.md` §3b):
# nazwa ekranu występuje RAZ, w górnej belce. Mierzone na wszystkich ekranach przebiegu, bo
# zasada dotyczy całej aplikacji, a nie jednego widoku.
#
# ⚠ **Trzy warunki naraz i to nie jest przezorność**, a wniosek z dwóch zmierzonych stanów:
#   1. sama nieobecność `h1` pod belką przeszłaby przy ekranie, który **stracił nazwę
#      całkowicie** — a tak wyglądały `/zespol`, `/wnioski`, `/wniosek/nowy` i `/konto`,
#      gdzie belka była pusta (dlatego kolejność robót była odwrotna niż brzmi polecenie:
#      najpierw wypełnić belkę, potem zdjąć `h1`),
#   2. sama obecność `h1` w belce przeszłaby przy ekranie, na którym nazwa dalej stoi
#      w **nagłówku karty** — tak było na `/zespol` i `/wniosek/nowy` (słowo trzy razy),
#   3. zgodność z `<title>` pilnuje JEDNEGO ŹRÓDŁA: oba napisy powstają z bloku
#      `nazwa_ekranu`, więc rozjazd znaczy, że ktoś dołożył drugie źródło.
#
# ⚠ Ekran **bez belki** (logowanie, instalator, strona błędu) nie jest wyjątkiem od zasady,
# tylko innym przypadkiem: belki nie ma, więc `h1` zostaje w treści — ale dalej musi być
# **dokładnie jeden**, bo inaczej ekran traci punkt wejścia dla czytnika.
POMIAR_TYTULU = r"""() => {
  const belka = document.querySelector('.topbar, .m-topbar');
  const h1 = Array.from(document.querySelectorAll('h1'));
  const tytul = (document.title || '').replace(/\s*—\s*wezurlop\s*$/, '').trim();
  const nazwa = h1.length === 1 ? h1[0].textContent.trim() : '';
  const naglowki = Array.from(document.querySelectorAll('h2, h3, .card__title'))
    .map(e => e.textContent.trim()).filter(t => t !== '');
  // ⚠ **Trzy rodzaje odpowiedzi, nie dwa.** Bramka odwiedza też adresy, które oddają FRAGMENT
  // (np. `/admin/podlaczenie/stan` → `fragments/connect-wizard.twig`): tam nie ma powłoki,
  // belki, `<title>` ani nazwy ekranu — i nie ma czego nazywać, bo to nie ekran. Powłoka bez
  // belki (logowanie, instalator, strona błędu) jest ekranem, tylko bez paska: wymagamy wtedy
  // DOKŁADNIE JEDNEGO `h1`, ale nie zgodności z `<title>` — na ekranie logowania nagłówek jest
  // pytaniem („Nie pamiętasz hasła?"), a karta przeglądarki mówi „Reset hasła", i to są dwa
  // świadome napisy, nie dwa źródła jednej nazwy.
  const powloka = !!document.querySelector('.app, .app-shell, .auth-shell, .install-shell, .status-card');
  return {
    fragment: !belka && !powloka,
    belka_jest: !!belka,
    h1_ile: h1.length,
    h1_w_belce: !!(belka && h1.length === 1 && belka.contains(h1[0])),
    h1_tekst: nazwa,
    tytul_dokumentu: tytul,
    zgodny_z_tytulem: nazwa !== '' && nazwa === tytul,
    powtorzenia: naglowki.filter(t => t === nazwa && nazwa !== ''),
  };
}"""


def wady_tytulu(m: dict) -> list[str]:
    """Które z trzech twierdzeń nie trzyma na tym ekranie — nazwane, nie zsumowane do `false`."""
    # Odpowiedź fragmentem nie jest ekranem: nie ma belki, powłoki ani tytułu dokumentu.
    if m["fragment"]:
        return []
    wady = []
    if m["h1_ile"] != 1:
        wady.append(f"h1_ile={m['h1_ile']}")
    elif m["belka_jest"] and not m["h1_w_belce"]:
        wady.append("h1_poza_belka")
    # Zgodność z `<title>` dotyczy ekranów Z BELKĄ — tam oba napisy mają jedno źródło
    # (blok `nazwa_ekranu`), więc rozjazd znaczy drugie źródło.
    if m["belka_jest"] and not m["zgodny_z_tytulem"]:
        wady.append(f"h1={m['h1_tekst']!r} != tytul={m['tytul_dokumentu']!r}")
    if m["powtorzenia"]:
        wady.append("powtorzone_w_naglowku: " + ", ".join(m["powtorzenia"][:3]))
    return wady


def poziom_kalendarza(companyId: int, poziom: int | None, email: str = LOGIN3) -> dict:
    """Poziom wglądu w kalendarz firmy dla konta pomiarowego: odczyt albo ustawienie.

    ⚠ **Przepięte 20.10.2026 (Z63 cz. A) na dzisiejszy model.** Do tej pory ta funkcja nadawała
    poziom przez `role_assignment` (`calendar_viewer`, `calendar_viewer_full`) — a od Z53
    (19.10.2026) **te role nie nadają niczego**: widoczność liczą reguły z `calendar_access_rule`,
    a jedyne uprawnienie, o które jeszcze pyta `CalendarVisibility`, to uprawnienie administratora.
    Kryterium `poziomy` mierzyło więc trzy poziomy, z których żaden nie wchodził w życie,
    i świeciło czerwono na poprawnym kodzie (zmierzone: przy „poziomie 1" ekran mówił
    „Nie masz nadanego podglądu kalendarza", przy poziomie 0 to samo).

    ⚠ Zapis idzie **drogą aplikacji** (`CalendarAccessRepository`), w osobnym narzędziu
    `dev/poziom-kalendarza.php` — PHP w jednej linii `php -r` nie dawał się ani przeczytać,
    ani sprawdzić `phpcs`-em. Odpowiedniki poziomów: 0 brak reguły, 1 cała firma
    `bez_szczegolow`, 2 cała firma `ze_szczegolami`.

    `poziom=None` znaczy „tylko odczytaj". Zwraca też nazwy rodzajów ze słownika — potrzebne,
    żeby sprawdzić, że przy poziomie 1 **ani jedna** nie pojawia się w treści odpowiedzi.
    """
    import subprocess
    wyn = subprocess.run(
        ["php", "dev/poziom-kalendarza.php", email, str(companyId),
         "-" if poziom is None else str(poziom)],
        cwd=KORZEN, capture_output=True, text=True, check=True)
    return json.loads(wyn.stdout or "{}")


def sprawdz_poziomy_kalendarza(b, usterka: str | None = None) -> dict:
    """Kalendarz firmy: trzy poziomy widoczności, nadawane jawnie — **dowody przez łamanie**.

    ⚠ Cztery rzeczy naraz (§5 zlecenia `kalendarz-firmy-poziom-widocznosci`):
      1. **poziom 0 nie widzi nic ponad swoje** — przełącznik „Firma" nieobecny, a wejście wprost
         na `?widok=firma` daje własny zasięg, nie cudzy,
      2. **poziom 1 nie przecieka szczegółem** — w TREŚCI odpowiedzi nie ma ani jednej nazwy
         rodzaju ze słownika; pole ukryte stylem to nie brak pola, więc sprawdzamy bajty,
      3. **granica spółki trzyma** — także żądaniem z `HX-Request` i z podmienionym `?spolka=`,
      4. ⚠ **limit wyświetlania NIE zapisuje się jako wyłączenia** — wejście przy zasięgu większym
         niż limit nie tworzy wiersza w `calendar_filter`. Gdyby tworzył, filtr stałby się migawką
         i pracownik przyjęty jutro nie pojawiłby się nikomu.

    Poziom jest ustawiany na czas pomiaru i **przywracany**; zapamiętany filtr też.
    """
    haslo3 = HASLO3 or haslo_dev(LOGIN3)
    if not haslo3:
        return {"ok": False, "powod": f"brak hasła konta {LOGIN3} (BRAMKA_HASLO3 albo ~/.wezurlop-dev-konta)"}
    try:
        ids = zatrudnienia_pracownika()
    except Exception as e:  # noqa: BLE001
        return {"ok": False, "powod": f"nie udalo sie odczytac identyfikatorow: {e}"}
    if not ids or ids.get("spolka") is None or ids.get("inna_spolka") is None:
        return {"ok": False, "powod": "dane robocze nie dają dwóch spółek do pomiaru", "ids": ids}
    spolka = int(ids["spolka"])
    filtr_przed = filtr_kalendarza(spolka, None)
    stan = poziom_kalendarza(spolka, None)
    poziom_przed = stan.get("przed", 0)
    slownik = [n for n in stan.get("slownik", []) if len(n) > 6]

    ctx = nowy_kontekst(b, usterka, viewport={"width": SZEROKOSC_LAPTOPA, "height": 900}, ignore_https_errors=True)
    pg = ctx.new_page()
    try:
        pg.goto(BAZA + "/logowanie", wait_until="networkidle")
        pg.fill("#login-email", LOGIN3)
        pg.fill("#login-password", haslo3)
        pg.click("button[type=submit]")
        pg.wait_for_load_state("networkidle")
        if "/kontekst" in pg.url:
            pg.evaluate("""(id) => {
              const f = Array.from(document.querySelectorAll('.page form'))
                .find(x => x.getAttribute('action').endsWith('/' + id));
              if (f) { f.querySelector('button[type=submit]').click(); }
            }""", str(ids["ja"]))
            pg.wait_for_url(lambda u: "/kontekst" not in u, timeout=15000)

        def ekran(ktora: int | None = None) -> dict:
            # ⚠ **Adres niesie `&spolka=`, czyli firmę, w której nadajemy poziom** (naprawa
            # 20.10.2026, Z63 cz. A — ta sama pułapka, co w `skale_czasu` i `jedna_wizualizacja`).
            # Konto pomiarowe ma etaty w DWÓCH firmach, a bez parametru ekran bierze firmę
            # z kontekstu zatrudnienia — i był to kontekst DRUGIEJ firmy. Zmierzone: po nadaniu
            # poziomu 1 w firmie z `ids["spolka"]` ekran mówił „Nie masz nadanego podglądu
            # kalendarza firmy", bo pytaliśmy o firmę, w której nic nie nadano. Kryterium
            # świeciło czerwono na poprawnym kodzie i na poprawnie nadanym dostępie.
            pg.goto(BAZA + "/kalendarz?widok=firma&miesiac=2026-09&spolka=%d"
                    % (ktora if ktora is not None else spolka), wait_until="networkidle")
            if "/kalendarz" not in pg.url:
                raise RuntimeError("kalendarz oddał przekierowanie na " + pg.url)
            # ⚠ `PASKI_PO_WIERSZACH` wstrzykujemy jako funkcję `POMIAR_WIERSZY`, żeby podział
            # na własny i cudzy wiersz miał **jedno** miejsce w całej bramce.
            return pg.evaluate("""(selWidok) => { const POMIAR_WIERSZY = """ + PASKI_PO_WIERSZACH
                               + """; return ({
              przelacznik: Array.from(document.querySelectorAll(selWidok))
                .map(e => e.textContent.trim()),
              wierszy: document.querySelectorAll('.team-grid__person').length,
              // ⚠ Od 07.10.2026 **musi tu stać ZERO**: siatka miesiąca (`.calendar-grid`)
              // była drugą wizualizacją nieobecności i zniknęła razem z regułami CSS.
              // Liczymy ją dalej właśnie dlatego — żeby powrót drugiej wizualizacji zapalił
              // czerwień, a nie przeszedł niezauważony.
              siatka_stara: document.querySelectorAll('.calendar-grid, .day-cell').length,
              lista: document.querySelectorAll('.table--rekordy tbody tr').length,
              // ⚠ Od 06.10.2026 na pasku stoi ZAKRES DAT, a nazwa rodzaju jest w `aria-label`.
              // Poziom rozstrzyga SZCZEGÓŁOWOŚĆ NAZWY, więc czytamy stamtąd — kryterium
              // („poziom 1 nie oddaje nazwy ze słownika") zostaje dokładnie to samo.
              paski: Array.from(new Set(Array.from(document.querySelectorAll('.team-grid__bar'))
                .map(e => (e.getAttribute('aria-label') || '').split(',')[0].trim())
                .filter(t => t !== ''))),
              // ⚠ **Paski CUDZYCH wierszy osobno** (Z63 cz. A): własnej nieobecności rodzaj
              // wolno oglądającemu znać, więc wrzucona do jednego worka lista zapalała
              // kryterium na jego własnym wierszu. Reguła dotyczy tylko cudzych.
              paski_obce: Array.from(new Set(POMIAR_WIERSZY().obce.filter(t => t !== ''))),
              paski_wlasne: Array.from(new Set(POMIAR_WIERSZY().wlasne.filter(t => t !== ''))),
              // Napis WIDOCZNY na pasku — osobno, żeby było jasne, że to dwie różne dane.
              napisy: Array.from(new Set(Array.from(document.querySelectorAll('.team-grid__bar'))
                .map(e => e.textContent.trim()))),
              w_kontrolce: document.querySelectorAll("input[name='osoby[]']").length,
              zdania: Array.from(document.querySelectorAll('.notice[role=status]'))
                .map(e => e.textContent.trim().slice(0, 30)),
            }); }""", SEL_WIDOK)

        # 1. Poziom 0 — nic ponad swoje.
        poziom_kalendarza(spolka, 0)
        zero = ekran()

        # 2. Poziom 1 — zasięg spółki, ale bez nazw rodzajów. Sprawdzamy TREŚĆ odpowiedzi.
        poziom_kalendarza(spolka, 1)
        jeden = ekran()
        # ⚠ **Skan BAJTÓW odpowiedzi, z wyjęciem WŁASNEGO wiersza** (Z63 cz. A). Bajty, bo pole
        # ukryte stylem to nie brak pola. Własny wiersz wyjęty, bo rodzaj swojej nieobecności
        # oglądający zna z wykazu obok — zmierzone 20.10.2026: w odpowiedzi stały dwa
        # „urlop wypoczynkowy" i oba były jego własne. Wyjmujemy je z KOPII dokumentu
        # (`DOMParser`), nie z żywej strony, i nie przez `innerHTML` na dokumencie.
        tresc = pg.evaluate(
            r"""async (js) => {
          const r = await fetch('/kalendarz?widok=firma&miesiac=2026-09&spolka=' + js.spolka,
            {credentials: 'same-origin',
            headers: {'HX-Request': 'true'}});
          const caly = await r.text();
          const d = new DOMParser().parseFromString(caly, 'text/html');
          const siatka = d.querySelector('.team-grid');
          let wyjeto = 0;
          if (siatka !== null) {
            let swoj = false;
            for (const el of Array.from(siatka.children)) {
              if (el.classList.contains('team-grid__person')) {
                swoj = ((el.querySelector('small') || {}).textContent || '').trim() === js.podpis;
              }
              if (swoj) { el.remove(); wyjeto++; }
            }
          }
          return {kod: r.status, tekst: d.body ? d.body.innerHTML : caly,
                  tekst_caly: caly, wyjeto_komorek: wyjeto};
        }""", {"podpis": PODPIS_WLASNEGO_WIERSZA, "spolka": spolka})
        przeciek = [n for n in slownik if n in tresc["tekst"]]
        # Dla porównania: to samo bez wyjęcia własnego wiersza — żeby w raporcie było widać,
        # że różnicę robi wyłącznie wiersz oglądającego, a nie złagodzone kryterium.
        przeciek_z_wlasnym = [n for n in slownik if n in tresc["tekst_caly"]]

        # 3. Granica spółki: podmieniony `?spolka=` nie daje widoku firmy, w której nie ma reguły.
        # ⚠ **Identyfikator FIRMY, nie zatrudnienia** (naprawa 20.10.2026, Z63 cz. A). Stało tu
        # `ids["inna_spolka"]`, a to jest identyfikator ZATRUDNIENIA z innej firmy (tak go liczy
        # `zatrudnienia_pracownika()`), czyli w `&spolka=` wartość spoza białej listy. Mierzyliśmy
        # więc ścieżkę zapasową dla bzdury, a nie granicę firmy — i porównywaliśmy jej wynik
        # z liczbą wierszy firmy, w której dostęp JEST. Teraz pytamy dwiema osobnymi sondami:
        # o drugą firmę tego konta (etat jest, reguły nie ma) i o numer, którego nie ma wcale.
        druga_firma = next((int(s["id"]) for s in spolki_pracownika() if int(s["id"]) != spolka), None)
        obca = ekran(druga_firma) if druga_firma is not None else None
        bzdura = ekran(999999)

        # 4. Limit nie zapisał się jako wyłączenia — po samych odwiedzinach wiersza nie ma.
        filtr_po = filtr_kalendarza(spolka, None)

        # 5. Poziom 2 — nazwy rodzajów wracają (kontrprób do punktu 2).
        poziom_kalendarza(spolka, 2)
        dwa = ekran()
        pelne = [n for n in dwa["paski_obce"] if n != OGOLNY_NAPIS]

        return {
            "ok": (
                # ⚠ **Poziom 0: odmowa POWIEDZIANA, zero wierszy firmy** — przepięte 17.10.2026
                # (tura Z22). Stało tu `zero["wierszy"] == 1` („widzi wyłącznie siebie"), bo
                # widok własny rysował oś czasu z jednym wierszem. Od Z11/Z14 „Tylko ja" to
                # **siatka miesiąca**, więc wierszy osi jest zero — i kryterium świeciło czerwono
                # na poprawnym ekranie. Twierdzenie zostaje to samo, mierzone tym, co dziś
                # odróżnia brak dostępu od dostępu: **zdanie** o braku nadanego podglądu
                # i zero wierszy firmy, a po nadaniu poziomu — wiersze i brak tego zdania.
                any("Nie masz nadanego podglądu" in z for z in zero["zdania"])
                and zero["wierszy"] == 0
                # ⚠ Przełącznika widoku NIE pytamy tutaj wcale: przy jednej dostępnej pozycji
                # nie renderuje się go z decyzji produktowej (06.10), więc „«Firma» jest
                # w przełączniku" było twierdzeniem o czymś, czego nie ma. Obecność i liczbę
                # pozycji przełącznika pilnuje `prawda_kalendarza` — jedna rzecz, jeden właściciel.
                and zero["siatka_stara"] == 0 and jeden["siatka_stara"] == 0
                and not any("Nie masz nadanego podglądu" in z for z in jeden["zdania"])
                and jeden["wierszy"] > zero["wierszy"]
                and przeciek == [] and tresc["kod"] == 200
                # ⚠ Jeden napis ogólny dla cudzych pasków (`OGOLNY_NAPIS`, patrz komentarz
                # tej stałej) — nie zbiór „urlop / zwolnienie / inna nieobecność", bo tamte
                # też mówiły o zdrowiu. Lista cudzych pasków nie może być pusta.
                and jeden["paski_obce"] != [] and set(jeden["paski_obce"]) == {OGOLNY_NAPIS}
                # ⚠ Druga firma tego konta: etat tam jest, reguły nie ma — więc widok firmy
                # ma zostać ODMÓWIONY (zero wierszy i zdanie o braku podglądu), a nie pokazać
                # kalendarz firmy, dla której nikt nic nie nadał.
                and obca is not None and obca["wierszy"] == 0
                and any("Nie masz nadanego podglądu" in z for z in obca["zdania"])
                # ⚠ Numer spoza białej listy nie jest błędem (CLAUDE.md §11): wraca do firmy
                # dozwolonej. „Dozwolona" znaczy tu jedną z dwóch zmierzonych odpowiedzi —
                # firmę z regułą (tyle wierszy, co przy poziomie 1) albo odmowę (zero).
                and bzdura["wierszy"] in (0, jeden["wierszy"])
                and (filtr_po is None or filtr_po == "")
                and pelne != []
            ),
            "poziom_0": zero,
            "poziom_1": {k: jeden[k] for k in ("przelacznik", "wierszy", "paski_obce", "paski_wlasne",
                                               "napisy", "w_kontrolce", "zdania")},
            "siatka_stara_poziom_0": zero["siatka_stara"],
            "lista_wlasna_poziom_0": zero["lista"],
            "przeciek_slownika_przy_poziomie_1": przeciek,
            "przeciek_gdy_liczyc_wlasny_wiersz": przeciek_z_wlasnym,
            "komorek_wlasnego_wiersza_wyjetych": tresc["wyjeto_komorek"],
            "druga_firma_konta": {"id": druga_firma,
                                  "wierszy": None if obca is None else obca["wierszy"],
                                  "zdania": [] if obca is None else obca["zdania"]},
            "spolka_spoza_listy_wierszy": bzdura["wierszy"],
            "filtr_po_odwiedzinach": filtr_po or "brak wiersza",
            "poziom_2_pelne_nazwy": pelne[:4],
            "slownika_sprawdzono": len(slownik),
        }
    except Exception as e:  # noqa: BLE001
        return {"ok": False, "powod": str(e)}
    finally:
        # ⚠ Przywrócenie poziomu i zapamiętanego filtru — pomiar sprząta po sobie.
        try:
            poziom_kalendarza(spolka, poziom_przed)
            if filtr_przed:
                filtr_kalendarza(spolka, filtr_przed)
            else:
                filtr_kalendarza(spolka, None)
        except Exception:  # noqa: BLE001
            pass
        ctx.close()


def sprawdz_wymiane_fragmentu(b) -> dict:
    """Kalendarz: nawigacja wymienia KARTĘ, nie przeładowuje strony — a adres nadal się zmienia.

    ⚠ Trzy warunki naraz, bo każdy bez pozostałych jest stratą, nie zyskiem:
      1. treść się zmienia i **strona się nie przeładowuje** (znacznik na `<body>` przeżywa klik),
      2. **adres się zmienia** — `?miesiac=` nadal da się wkleić, zakładkować i cofnąć;
         wymiana fragmentu, która psuje przycisk „wstecz", zamienia przeładowanie na usterkę,
      3. **bez JavaScriptu** ten sam odnośnik prowadzi do pełnej strony z powłoką.
    """
    haslo3 = HASLO3 or haslo_dev(LOGIN3)
    if not haslo3:
        return {"ok": False, "powod": f"brak hasła konta {LOGIN3} (BRAMKA_HASLO3 albo ~/.wezurlop-dev-konta)"}

    def zaloguj(ctx):
        pg = ctx.new_page()
        pg.goto(BAZA + "/logowanie", wait_until="networkidle")
        pg.fill("#login-email", LOGIN3)
        pg.fill("#login-password", haslo3)
        pg.click("button[type=submit]")
        pg.wait_for_load_state("networkidle")
        if "/kontekst" in pg.url:
            pg.click(".page form button[type=submit]")
            pg.wait_for_url(lambda u: "/kontekst" not in u, timeout=15000)
        return pg

    ctx = b.new_context(viewport={"width": SZEROKOSC_LAPTOPA, "height": 900}, ignore_https_errors=True)
    bez_js = b.new_context(viewport={"width": SZEROKOSC_LAPTOPA, "height": 900}, ignore_https_errors=True,
                           java_script_enabled=False)
    try:
        pg = zaloguj(ctx)
        pg.goto(BAZA + "/kalendarz?miesiac=2026-09", wait_until="networkidle")
        adres_przed = pg.url
        tytul_przed = pg.locator(".card__title").first.inner_text()
        # Znacznik na `<body>` ginie przy pełnym przeładowaniu, a przeżywa wymianę fragmentu.
        pg.evaluate("() => { document.body.dataset.bramka = 'przed'; }")
        pg.click(SEL_POPRZEDNI)
        pg.wait_for_timeout(800)
        znacznik = pg.evaluate("() => document.body.dataset.bramka || null")
        adres_po = pg.url
        tytul_po = pg.locator(".card__title").first.inner_text()
        # Przycisk „wstecz" ma wrócić do poprzedniego miesiąca, a nie do pustego ekranu.
        pg.go_back()
        pg.wait_for_timeout(800)
        adres_wstecz = pg.url
        tytul_wstecz = pg.locator(".card__title").first.inner_text()

        # Bez JavaScriptu: ten sam odnośnik = pełna strona z powłoką.
        pg2 = bez_js.new_page()
        pg2.goto(BAZA + "/logowanie", wait_until="networkidle")
        pg2.fill("#login-email", LOGIN3)
        pg2.fill("#login-password", haslo3)
        pg2.click("button[type=submit]")
        pg2.wait_for_load_state("networkidle")
        if "/kontekst" in pg2.url:
            pg2.click(".page form button[type=submit]")
            pg2.wait_for_load_state("networkidle")
        pg2.goto(BAZA + "/kalendarz?miesiac=2026-09", wait_until="networkidle")
        pg2.click(SEL_POPRZEDNI)
        pg2.wait_for_load_state("networkidle")
        bez_js_adres = pg2.url
        bez_js_powloka = pg2.query_selector(".sidebar") is not None

        return {
            "ok": (znacznik == "przed" and adres_po != adres_przed and tytul_po != tytul_przed
                   and adres_wstecz == adres_przed and tytul_wstecz == tytul_przed
                   # ⚠ `in`, nie `endswith`: od 07.10.2026 odnośniki miesięcy niosą też
                   # `&spolka=`, żeby wybrana spółka przetrwała przejście na inny miesiąc
                   # (pracownik z dwoma etatami dostał kontrolkę wyboru spółki). Kryterium
                   # mówi „adres bez JS-a prowadzi na TEN miesiąc", a nie „kończy się na nim" —
                   # zapis literalny świecił czerwono na poprawnym zachowaniu.
                   and bez_js_powloka and "miesiac=2026-08" in bez_js_adres),
            "bez_przeladowania": znacznik == "przed",
            "adres": adres_przed.split("/kalendarz")[-1] + " → " + adres_po.split("/kalendarz")[-1],
            "tytul": tytul_przed + " → " + tytul_po,
            "wstecz": adres_wstecz.split("/kalendarz")[-1] + " · " + tytul_wstecz,
            "bez_js": {"adres": bez_js_adres.split("/kalendarz")[-1], "powloka": bez_js_powloka},
        }
    except Exception as e:  # noqa: BLE001
        return {"ok": False, "powod": str(e)}
    finally:
        ctx.close()
        bez_js.close()


def filtr_kalendarza(company_id: int, zapisz: str | None = None, email: str = LOGIN3) -> str | None:
    """Zapamiętany filtr kalendarza konta pracownika: odczyt z usunięciem albo przywrócenie.

    ⚠ Klucz to (konto, SPÓŁKA) — od 23.09.2026, odkąd kalendarz spółki oglądają też konta
    bez zatrudnienia.

    ⚠ Sprawdzenie domyślnego wyboru wymaga **świeżego wejścia**, czyli braku wiersza w tabeli —
    a wiersz mógł zostać po wcześniejszym pomiarze albo po pracy człowieka na tej instancji.
    Bramka zdejmuje go na czas pomiaru i **przywraca po nim**: zapamiętany filtr to cudzy stan
    interfejsu, nie nasz śmieć do sprzątnięcia.
    """
    import subprocess
    kod = (
        'require "vendor/autoload.php";'
        'Dotenv\\Dotenv::createImmutable(__DIR__)->load();'
        '$e = $_ENV;'
        '$pdo = new PDO("mysql:host={$e[\'DB_HOST\']};port={$e[\'DB_PORT\']};dbname={$e[\'DB_NAME\']};charset=utf8mb4",'
        '  $e["DB_USER"], $e["DB_PASS"], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);'
        '$st = $pdo->prepare("SELECT id FROM account WHERE email = :mail");'
        '$st->execute(["mail" => $argv[1]]);'
        '$konto = $st->fetchColumn();'
        'if ($konto === false) { echo ""; exit; }'
        '$zatr = (int) $argv[2];'
        'if ($argv[3] === "-") {'
        '  $st = $pdo->prepare("SELECT excluded_ids FROM calendar_filter WHERE account_id = :k AND company_id = :z");'
        '  $st->execute(["k" => $konto, "z" => $zatr]);'
        '  $byl = $st->fetchColumn();'
        '  $pdo->prepare("DELETE FROM calendar_filter WHERE account_id = :k AND company_id = :z")'
        '    ->execute(["k" => $konto, "z" => $zatr]);'
        '  echo $byl === false ? "" : (string) $byl;'
        '  exit;'
        '}'
        '$st = $pdo->prepare("INSERT INTO calendar_filter (account_id, company_id, excluded_ids, updated_at)'
        '  VALUES (:k, :z, :v, UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE excluded_ids = :v2, updated_at = UTC_TIMESTAMP()");'
        '$st->execute(["k" => $konto, "z" => $zatr, "v" => $argv[3], "v2" => $argv[3]]);'
        'echo "przywrocone";'
    )
    wyn = subprocess.run(["php", "-r", kod, email, str(company_id), zapisz if zapisz is not None else "-"],
                         cwd=KORZEN, capture_output=True, text=True, check=True)
    return wyn.stdout or None


def sprawdz_zasieg_podgladu(b, usterka: str | None = None) -> dict:
    """Kalendarz zespołu: identyfikator SPOZA gałęzi i z INNEJ spółki nie ma prawa nic dodać.

    ⚠ To najważniejsze sprawdzenie tego ekranu i jedyne, które mierzy coś innego niż wygląd.
    „Wybór osób to filtr, nie uprawnienie" jest deklaracją do chwili, w której ktoś poda cudzy
    identyfikator w adresie. Zmierzone na wstrzykniętej usterce (usunięty `przefiltruj()`):
    jedno żądanie pokazało **27 osób całej spółki** zamiast czterech z gałęzi.

    ⚠ **Założenie wypowiedziane wprost**: mierzymy przy DOMYŚLNYM zakresie przełożonych
    (`ZakresWidocznosci::DOMYSLNY_PRZELOZONYCH` = `bez_szczegolow`), czyli przy braku reguły
    „przełożeni" w tej firmie. Gdyby tenant nadał przełożonym `ze_szczegolami`, nazwa rodzaju
    na cudzym pasku byłaby POPRAWNA, a to kryterium zapaliłoby się na poprawnym ekranie —
    i wtedy trzeba je zapytać o regułę firmy, a nie o napis. Dziś takiej reguły nie ma
    i sprawdzenie tego nie zgaduje: liczy napisy, które widzi.

    ⚠ Sprawdzenie samo przygotowuje sobie gałąź (ścieżką aplikacji, jako administrator)
    i **samo ją wycofuje** — dane robocze po przebiegu wyglądają tak jak przed nim.
    Dwa poziomy, nie jeden: `wnuk` pod `dziecko`, żeby zasięg „cała gałąź w dół" był naprawdę
    sprawdzony w głąb, a nie tylko na bezpośrednich podwładnych.
    """
    haslo3 = HASLO3 or haslo_dev(LOGIN3)
    if not haslo3:
        return {"ok": False, "powod": f"brak hasła konta {LOGIN3} (BRAMKA_HASLO3 albo ~/.wezurlop-dev-konta)"}
    try:
        ids = zatrudnienia_pracownika()
    except Exception as e:  # noqa: BLE001
        return {"ok": False, "powod": f"nie udalo sie odczytac identyfikatorow: {e}"}
    if not ids or len(ids.get("wolni", [])) < 2 or ids.get("obcy") is None:
        return {"ok": False, "powod": "dane robocze nie dają gałęzi do pomiaru", "ids": ids}
    ja, dziecko, wnuk = ids["ja"], ids["wolni"][0], ids["wolni"][1]

    ctx_adm = b.new_context(viewport={"width": SZEROKOSC_LAPTOPA, "height": 900}, ignore_https_errors=True)
    adm = ctx_adm.new_page()
    przygotowane = False
    # Przed `try`, bo `finally` przywraca ten wiersz także po wyjątku.
    filtr_przed: str | None = None
    try:
        adm.goto(BAZA + "/logowanie", wait_until="networkidle")
        adm.fill("#login-email", LOGIN)
        adm.fill("#login-password", HASLO)
        adm.click("button[type=submit]")
        adm.wait_for_load_state("networkidle")
        adm.goto(BAZA + "/admin/hierarchia?spolka=" + str(ids["spolka"]), wait_until="networkidle")
        csrf = adm.evaluate("""() => {
          const c = document.querySelector('input[name="_csrf"]');
          return c ? c.value : null;
        }""")
        if csrf is None:
            return {"ok": False, "powod": "brak tokenu CSRF na ekranie hierarchii"}
        wspolne = {"csrf": csrf, "spolka": str(ids["spolka"])}
        for podwladny, szef in ((dziecko, ja), (wnuk, dziecko)):
            w = adm.evaluate(POST_HIERARCHII, {**wspolne, "employment": str(podwladny),
                                               "cel": "przelozony", "supervisor": str(szef)})
            if w["kod"] not in (200, 303):
                return {"ok": False, "powod": "nie udalo sie przygotowac galezi", "kod": w["kod"]}
        przygotowane = True

        ctx = nowy_kontekst(b, usterka, viewport={"width": SZEROKOSC_LAPTOPA, "height": 900}, ignore_https_errors=True)
        pg = ctx.new_page()
        try:
            pg.goto(BAZA + "/logowanie", wait_until="networkidle")
            pg.fill("#login-email", LOGIN3)
            pg.fill("#login-password", haslo3)
            pg.click("button[type=submit]")
            pg.wait_for_load_state("networkidle")
            if "/kontekst" in pg.url:
                # Osoba w dwóch spółkach wybiera kontekst; bierzemy ten, w którym stoi gałąź.
                pg.evaluate("""(id) => {
                  const f = Array.from(document.querySelectorAll('.page form'))
                    .find(x => x.getAttribute('action').endsWith('/' + id));
                  if (f) { f.querySelector('button[type=submit]').click(); }
                }""", str(ja))
                # ⚠ Czekamy na ADRES, nie na `networkidle`: ten drugi wrócił natychmiast ze starej
                # strony, więc pierwsze żądanie kalendarza wychodziło PRZED zapisaniem kontekstu
                # w sesji i wracało przekierowaniem na wybór firmy. Zmierzone: pierwszy pomiar
                # dawał 0 wierszy, kolejne 1 — czyli fałszywa czerwień z powodu mojego pomiaru,
                # nie kodu aplikacji.
                pg.wait_for_url(lambda u: "/kontekst" not in u, timeout=15000)

            # ⚠ ŚWIEŻE WEJŚCIE: bez wiersza w `calendar_filter` widok „Zespół" musi pokazać
            # CAŁY zasięg. To jest usterka zgłoszona 22.09.2026 („nie widzę swoich pracowników"):
            # domyślnie zaznaczony był tylko właściciel, więc „Zespół" wyglądał identycznie jak
            # „Tylko ja" i przełącznik czytał się jako zepsuty. Bramka mierzy teraz stan POCZĄTKOWY,
            # bo to on decyduje, czy funkcja dla użytkownika istnieje.
            filtr_przed = filtr_kalendarza(ids['spolka'], None)

            # ⚠ **Każdy adres niesie `&spolka=`, czyli firmę mierzonej gałęzi.** Konto pomiarowe
            # ma dwa etaty, a kontekst firmy jest **stanem sesji**, który zostawia poprzednie
            # sprawdzenie: `sprawdz_jedna_wizualizacje` przełącza firmę świadomie (to jego
            # przedmiot pomiaru). Zmierzone 17.10.2026 w pełnym przebiegu: to kryterium
            # zwracało **0 wierszy**, a to samo w izolacji — **3**. Czerwień pochodziła
            # z kolejności kryteriów, nie z kodu aplikacji; `?spolka=` jest widokiem
            # na jedno żądanie, więc sam niczego nie zostawia.
            OGON_SP = f"&spolka={ids['spolka']}"

            def wiersze(param: str) -> list:
                pg.goto(BAZA + "/kalendarz?widok=zespol" + param + OGON_SP,
                        wait_until="networkidle")
                # ⚠ Gdyby żądanie wróciło przekierowaniem (wybór firmy, wygaszona sesja), zero
                # wierszy znaczyłoby „nie ma czego pokazać", a nie „zakres zadziałał". Pomiar
                # na innym ekranie niż mierzony to nie pomiar.
                if "/kalendarz" not in pg.url:
                    raise RuntimeError("kalendarz oddał przekierowanie na " + pg.url)
                return pg.evaluate("""() => Array.from(document.querySelectorAll('.team-grid__person'))
                    .map(e => e.textContent.trim().replace(/\\s+/g, ' '))""")

            domyslne = wiersze("")
            domyslny_komunikat = pg.evaluate(
                "() => document.querySelectorAll('.notice[role=status]').length")
            # ⚠ Kryterium to REGUŁA („domyślnie widać cały zasięg"), nie liczba 3. Liczba złapałaby
            # też przypadek, w którym ktoś równolegle dołożył komuś przełożonego w danych roboczych —
            # i bramka świeciłaby czerwono z powodu cudzego pomiaru, nie usterki. Zmierzone dwa razy
            # tego samego dnia: przebieg w tle i zrzuty ekranu ruszały tę samą gałąź.
            w_kontrolce = pg.evaluate(
                """() => document.querySelectorAll("input[name='osoby[]']").length""")
            dozwoleni = wiersze("&osoby=%d,%d,%d" % (ja, dziecko, wnuk))
            # ⚠ Szerokości siatki zespołu mierzymy TUTAJ, bo tylko tu gałąź istnieje — przebieg
            # po trasach zobaczyłby widok „Tylko ja". Kryterium jest inne niż dla tabel i to jest
            # świadome: trzydzieści jeden kolumn nie zmieści się na 374 px przy żadnej typografii,
            # więc przy wąskim ekranie siatka MOŻE jechać w bok we własnym opakowaniu — ale
            # `.card__body` przewijać się nie może, a kolumna dnia musi zostać czytelna.
            miary = {}
            # ⚠ Te same trzy szerokości, co w przebiegu po ekranach (Z63): 1920 / 1366 / 374.
            for etykieta, szer in (("1920", 1920), ("1366", 1366), ("374", 374)):
                pg.set_viewport_size({"width": szer, "height": 900})
                pg.goto(BAZA + "/kalendarz?widok=zespol&osoby=%d,%d,%d" % (ja, dziecko, wnuk)
                        + OGON_SP, wait_until="networkidle")
                miary[etykieta] = pg.evaluate("""() => {
                  const g = document.querySelector('.team-grid');
                  const o = document.querySelector('.team-scroll');
                  const karta = document.querySelector('.card__body--flush');
                  const dzien = document.querySelectorAll('.team-grid__head')[1];
                  const osoba = document.querySelector('.team-grid__person');
                  if (!g || !o || !karta || !dzien || !osoba) { return null; }
                  return {
                    siatka_przewija: o.scrollWidth > o.clientWidth,
                    karta_przewija: karta.scrollWidth > karta.clientWidth,
                    kolumna_dnia: Math.round(dzien.getBoundingClientRect().width),
                    kolumna_osoby: Math.round(osoba.getBoundingClientRect().width),
                    nazwa_ucieta: osoba.scrollWidth > osoba.clientWidth + 1,
                  };
                }""")
            pg.set_viewport_size({"width": SZEROKOSC_LAPTOPA, "height": 900})
            spoza = wiersze("&osoby=%d" % ids["obcy"])
            inna = wiersze("&osoby=%d" % ids["inna_spolka"]) if ids["inna_spolka"] else []
            mieszane = wiersze("&osoby=%d,%d" % (dziecko, ids["obcy"]))
            # ⚠ TA SAMA próba, ale żądaniem htmx: fragment jest pełnoprawnym endpointem
            # (CLAUDE.md §5), a nagłówek `HX-Request` wybiera wyłącznie FORMĘ odpowiedzi.
            # Bez tego pomiaru „fragment sprawdza zasięg tak samo" jest deklaracją — a to
            # dokładnie ta klasa założeń, przez którą powstają endpointy „wewnętrzne".
            fragment = pg.evaluate(
                """async (adres) => {
                  const r = await fetch(adres, {credentials: 'same-origin',
                    headers: {'HX-Request': 'true'}});
                  const tekst = await r.text();
                  const box = document.createElement('div');
                  box.innerHTML = tekst;
                  return {kod: r.status, powloka: /class="sidebar"/.test(tekst),
                          osoby: Array.from(box.querySelectorAll('.team-grid__person span:first-child'))
                            .map(e => e.textContent.trim())};
                }""",
                "/kalendarz?widok=zespol&osoby=%d" % ids["obcy"])
            # Rodzaj na pasku musi być OGÓLNY — nigdy nazwa ze słownika (dana o zdrowiu).
            # ⚠ Miesiąc dobrany ZAPYTANIEM do tej trójki, a nie bieżący: w miesiącu bez
            # nieobecności lista pasków jest pusta, a sprawdzenie „wszystkie są ogólne"
            # przechodzi na pusto i nie dowodzi niczego. Pierwszy przebieg tak właśnie zrobił.
            # ⚠ **Kontrast paska przeniesiony do `sprawdz_paski_nieobecnosci`** (zlecenie
            # 11.10 §5): tamto kryterium mierzyło wypełnienie neutralne i OBRYS w kolorze
            # rodzaju, a obrysu od 11.10.2026 nie ma. Tu zostaje to, co dotyczy ZASIĘGU:
            # rodzaj ogólny na pasku, szerokości siatki, lista osób. Jedna rzecz, jedno
            # kryterium — dwa liczniki tej samej rzeczy rozjechałyby się na pierwszej zmianie.
            paski: list[str] = []
            paski_wlasne: list[str] = []
            wiersze_paskow: dict = {"wlasne": [], "obce": [], "wierszy": 0, "rozpoznano_wlasny": False}
            if ids.get("miesiac"):
                pg.goto(BAZA + "/kalendarz?widok=zespol&miesiac=%s&osoby=%d,%d,%d"
                        % (ids["miesiac"], ja, dziecko, wnuk) + OGON_SP, wait_until="networkidle")
                # ⚠ **Rodzaj czytamy z `aria-label`, nie z napisu na pasku** — od 06.10.2026
                # na pasku stoi ZAKRES DAT (zlecenie `kontrolki-kalendarza-i-paski` §3),
                # a nazwa rodzaju przeniosła się do treści dostępnej. Reguła jest bez zmian:
                # rodzaj ma być OGÓLNY, nigdy kodem ze słownika. Zmieniło się miejsce, w którym
                # ta dana mieszka, więc zmienia się pomiar — nie kryterium.
                wiersze_paskow = pg.evaluate(PASKI_PO_WIERSZACH)
                paski = [x for x in wiersze_paskow["obce"] if x != ""]
                paski_wlasne = [x for x in wiersze_paskow["wlasne"] if x != ""]
            przelacznik = pg.evaluate(
                "() => Array.from(document.querySelectorAll('" + SEL_WIDOK + "'))"
                ".map(e => e.textContent.trim())")
            szerokosci_ok = all(
                m is not None and not m["karta_przewija"] and m["kolumna_dnia"] >= 16
                and m["kolumna_osoby"] >= 120 and not m["nazwa_ucieta"]
                for m in miary.values()
            ) and miary["1920"] is not None and not miary["1920"]["siatka_przewija"] \
                and miary["1366"] is not None and not miary["1366"]["siatka_przewija"]
            return {
                # ⚠ `domyslne` musi mieć TRZY wiersze (cała przygotowana gałąź) i ZERO zdań
                # o zawężeniu: przy pełnym widoku nie ma czego tłumaczyć, a przy zawężonym
                # zdanie jest obowiązkowe (pusto z filtra i pusto z braku danych nie mogą
                # wyglądać tak samo).
                # ⚠ Paski CUDZYCH osób muszą mieć jeden napis ogólny (`OGOLNY_NAPIS`), a lista
                # nie może być pusta — zero pasków przeszłoby każdy warunek o ich treści.
                # Własny wiersz jest wyłączony świadomie i mierzony osobno (patrz komentarz
                # przy `PASKI_PO_WIERSZACH`).
                "ok": (len(domyslne) == w_kontrolce and w_kontrolce >= 3 and domyslny_komunikat == 0
                       and len(dozwoleni) == 3 and spoza == [] and inna == []
                       and len(mieszane) == 1 and paski != []
                       and set(paski) == {OGOLNY_NAPIS}
                       and "Zespół" in przelacznik and szerokosci_ok
                       and fragment["kod"] == 200 and fragment["osoby"] == [] and not fragment["powloka"]),
                "fragment_spoza_galezi": fragment,
                "miesiac_z_danymi": ids.get("miesiac"),
                "domyslnie_wierszy": len(domyslne),
                "w_kontrolce": w_kontrolce,
                "domyslnie_zdan_o_filtrze": domyslny_komunikat,
                "szerokosci": miary,
                "szerokosci_ok": szerokosci_ok,
                "galaz": "%d -> %d -> %d" % (ja, dziecko, wnuk),
                "wierszy_dozwolonych": len(dozwoleni),
                "spoza_galezi": {"id": ids["obcy"], "wierszy": len(spoza)},
                "inna_spolka": {"id": ids["inna_spolka"], "wierszy": len(inna)},
                "mieszane": {"wierszy": len(mieszane)},
                "rodzaje_na_paskach_cudzych": sorted(set(paski)),
                "rodzaje_na_paskach_wlasnych": sorted(set(paski_wlasne)),
                "wlasny_wiersz_rozpoznany": wiersze_paskow["rozpoznano_wlasny"],
                "przelacznik": przelacznik,
            }
        finally:
            ctx.close()
    except Exception as e:  # noqa: BLE001
        return {"ok": False, "powod": str(e)}
    finally:
        # ⚠ Przywrócenie zapamiętanego filtru, jeśli jakiś był — patrz `filtr_kalendarza()`.
        try:
            if przygotowane and filtr_przed:
                filtr_kalendarza(ids['spolka'], filtr_przed)
        except Exception:  # noqa: BLE001
            pass
        # ⚠ Wycofanie gałęzi — bramka nie zostawia po sobie zmienionej struktury (§7 zlecenia).
        if przygotowane:
            for podwladny in (wnuk, dziecko):
                try:
                    adm.evaluate(POST_HIERARCHII, {**wspolne, "employment": str(podwladny),
                                                   "cel": "zdejmij", "supervisor": ""})
                except Exception:  # noqa: BLE001
                    pass
        ctx_adm.close()


def spolki_dla_admina() -> dict:
    """Dwie spółki z największą liczbą czynnych zatrudnień i liczby osób w nich — **z bazy**.

    ⚠ Potrzebne do pomiaru kontem BEZ ZATRUDNIENIA (administracyjnym): tylko takie konto pokazuje
    usterkę z §1 zlecenia 23.09 („przełożony: Ty" przy osobach, które przełożonego nie mają)
    i tylko ono dostaje listę rozwijaną spółek. Identyfikatorów nie wpisujemy w bramkę:
    po pierwszym resecie danych pilnowałaby nieistniejącej spółki i świeciła zielono.
    """
    import subprocess
    kod = (
        'require "vendor/autoload.php";'
        'Dotenv\\Dotenv::createImmutable(__DIR__)->load();'
        '$e = $_ENV;'
        '$pdo = new PDO("mysql:host={$e[\'DB_HOST\']};port={$e[\'DB_PORT\']};dbname={$e[\'DB_NAME\']};charset=utf8mb4",'
        '  $e["DB_USER"], $e["DB_PASS"], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);'
        '$st = $pdo->query("SELECT e.company_id AS id, COUNT(*) AS ile,'
        '    SUM(e.supervisor_employment_id IS NULL) AS bez_szefa,'
        '    SUM(e.is_hierarchy_root = 1) AS szczytow'
        '  FROM employment e WHERE e.is_active = 1 GROUP BY e.company_id ORDER BY ile DESC LIMIT 2");'
        '$w = $st->fetchAll();'
        '$st = $pdo->query("SELECT DATE_FORMAT(a.date_from, \'%Y-%m\') AS m, COUNT(*) AS ile'
        '  FROM external_absence a JOIN leave_type t ON t.id = a.leave_type_id'
        '  WHERE a.removed_at IS NULL AND t.is_absence = 1 GROUP BY m ORDER BY ile DESC LIMIT 1");'
        '$m = $st->fetchColumn();'
        'echo json_encode(["spolki" => array_map(static fn (array $r): array => ['
        '  "id" => (int) $r["id"], "ile" => (int) $r["ile"], "bez_szefa" => (int) $r["bez_szefa"],'
        '  "szczytow" => (int) $r["szczytow"]], $w),'
        '  "miesiac" => $m === false ? null : (string) $m]);'
    )
    wyn = subprocess.run(["php", "-r", kod], cwd=KORZEN, capture_output=True, text=True, check=True)
    return json.loads(wyn.stdout or "{}")


def sprawdz_rozdzial_rol(b) -> dict:
    """Rozdział „administrator tenanta ≠ pracownik" — **TRZY dowody przez łamanie** (§4.4 zlecenia).

    ⚠ Trzeci jest najważniejszy, bo jest **jedyny, którego nikt nie wywoła ręcznie**: konto wisi
    na osobie i zatrudnienie też wisi na osobie, więc gdy kadry zatrudnią administratora, regułę
    łamie connector przy synchronizacji, nie człowiek. Warunek przy nadawaniu roli tego nie widzi.

      a) nadanie `tenant_admin` koncie z aktywnym zatrudnieniem → odrzucone,
      b) nadanie dostępu pracowniczego koncie administracyjnemu → odrzucone,
      c) **wstrzyknięte zatrudnienie** osoby z konta administracyjnego → pozycja BŁĄD
         na `/diagnostyka`, nie cisza.

    ⚠ Pomiar sprząta po sobie: wstrzyknięte zatrudnienie jest usuwane w tym samym przebiegu,
    także gdy pomiar padnie w połowie (`finally`).
    """
    import subprocess

    def probka(tryb: str = "proby") -> dict:
        # ⚠ Bez `check=True`: gdy próbka padnie, bramka ma **powiedzieć dlaczego**, a nie rzucić
        # suchym „non-zero exit status". Czerwona bramka bez powodu to drugie śledztwo.
        wyn = subprocess.run(["php", "dev/probka-rozdzialu-rol.php", tryb],
                             cwd=KORZEN, capture_output=True, text=True)
        if wyn.returncode != 0 or not wyn.stdout.strip():
            raise RuntimeError(f"probka-rozdzialu-rol.php {tryb} kod={wyn.returncode}: "
                               f"{(wyn.stderr or wyn.stdout).strip()[:400]}")
        return json.loads(wyn.stdout)

    haslo = HASLO or haslo_dev(LOGIN)
    wstawione = False
    ctx = b.new_context(viewport={"width": SZEROKOSC_LAPTOPA, "height": 900}, ignore_https_errors=True)
    try:
        proby = probka()
        # Stan wyjściowy: pozycja na diagnostyce ma być CZYSTA, inaczej dowód (c) nic nie znaczy.
        pg = ctx.new_page()
        pg.goto(BAZA + "/logowanie", wait_until="networkidle")
        pg.fill("#login-email", LOGIN)
        pg.fill("#login-password", haslo)
        pg.click("button[type=submit]")
        pg.wait_for_load_state("networkidle")

        def pozycja() -> dict:
            pg.goto(BAZA + "/diagnostyka", wait_until="networkidle")
            # ⚠ **Selektor przepięty na dzisiejszy kształt** (Z63 cz. A): `/diagnostyka` nie jest
            # tabelą od przebudowy ekranu — pozycje to `.diag-wiersz` z `.diag-stan--ok|uwaga|blad`,
            # a stan stoi w KLASIE, nie w pierwszej komórce. Stare szukanie `tr` zwracało `null`,
            # więc kryterium było czerwone, choć oba nadania odrzucało poprawnie, a pozycja
            # na ekranie stała na miejscu (zmierzone 20.10.2026: `c_diagnostyka_przed: None`).
            return pg.evaluate("""() => {
              const w = Array.from(document.querySelectorAll('.diag-wiersz')).find(e => {
                const n = e.querySelector('.diag-wiersz__nazwa');
                return n && n.textContent.indexOf('Rozdział ról') >= 0;
              });
              if (!w) { return null; }
              const znak = w.querySelector('.diag-stan');
              const klasa = znak ? znak.className : '';
              const m = klasa.match(/diag-stan--([a-z]+)/);
              const nazwa = w.querySelector('.diag-wiersz__nazwa');
              const wartosc = w.querySelector('.diag-wiersz__wartosc');
              return {
                // Nazwa stanu zostaje taka, jaką mówi kryterium: „OK" albo kod z klasy.
                stan: m === null ? 'nieznany' : (m[1] === 'ok' ? 'OK' : m[1]),
                nazwa: nazwa ? nazwa.textContent.trim() : '',
                wartosc: wartosc ? wartosc.textContent.trim() : '',
              };
            }""")

        przed = pozycja()
        wstaw = probka("wstaw-etat")
        wstawione = True
        po = pozycja()
        naruszenia_php = probka()["naruszenia_teraz"]

        return {
            "ok": (
                proby["a_admin_dla_etatu"].startswith("odrzucone")
                and proby["b_pracownik_dla_admina"].startswith("odrzucone")
                and przed is not None and przed["stan"] == "OK"
                and po is not None and po["stan"] != "OK"
                and naruszenia_php == 1
            ),
            "a_admin_dla_etatu": proby["a_admin_dla_etatu"],
            "b_pracownik_dla_admina": proby["b_pracownik_dla_admina"],
            "c_diagnostyka_przed": przed,
            "c_diagnostyka_po_wstrzyknieciu": po,
            "wstrzyknieto": wstaw,
        }
    except Exception as e:  # noqa: BLE001
        return {"ok": False, "powod": str(e)}
    finally:
        if wstawione:
            try:
                subprocess.run(["php", "dev/probka-rozdzialu-rol.php", "usun-etat"],
                               cwd=KORZEN, capture_output=True, text=True, check=True)
            except Exception:  # noqa: BLE001
                pass
        ctx.close()


def sprawdz_prawde_kalendarza(b, usterka: str | None = None) -> dict:
    """Kalendarz firmy kontem BEZ ZATRUDNIENIA — **czy ekran mówi prawdę** (zlecenie 23.09 §1–§3, §4a).

    ⚠ Tylko konto administracyjne pokazuje te usterki, bo wszystkie wynikają z jednego stanu:
    oglądający nie ma zatrudnienia, więc po jego stronie porównania stoi `NULL`.

      1. **Podpisy przełożonego — LICZONE, nie szukane.** Warunek zawsze-prawdziwy i warunek
         zawsze-fałszywy wyglądają na ekranie identycznie, dopóki się nie policzy; dlatego
         usterka „przełożony: Ty" przy 25 osobach bez przełożonego przeżyła dwa tygodnie.
         Konto bez zatrudnienia nie ma prawa podpisać **ani jednej** osoby „przełożony: Ty",
         a korzeń hierarchii nie ma prawa mieć tego podpisu u nikogo.
      2. **Nagłówek ekranu** (`h1`, tytuł dokumentu, okruch) nie mówi „Mój kalendarz" nad
         widokiem całej spółki.
      3. **Zdanie o dniach wolnych** nie przypisuje oglądającemu rozkładu, którego nie ma.
      4. **Odmiana po przyimku**: „z N osób", nigdy „z N osoby".
      5. **Liczba nieobecności w miesiącu** zgadza się z liczbą pasków na ekranie (§4a.2).
      6. **Nagłówek dni zostaje WIDOCZNY po przewinięciu o ekran** (§4a.1) — przyklejenie
         przy przewijaniu STRONY, nie pudełko z własnym paskiem. ⚠ „Widoczny" znaczy: prostokąt
         w oknie, nieprzycięty przez przodków **i** `elementFromPoint` w jego środku zwraca ten
         nagłówek. Bez trzeciego warunku kryterium przechodziło nad nagłówkiem schowanym pod
         paskiem górnym (zmierzone 10.10.2026, naprawione tego samego dnia).

    Poziom widoczności jest nadawany na czas pomiaru i **przywracany**; filtr też.
    """
    haslo = HASLO or haslo_dev(LOGIN)
    dane = spolki_dla_admina()
    spolki = dane.get("spolki", [])
    if len(spolki) < 2:
        return {"ok": False, "powod": "dane robocze nie dają dwóch spółek z zatrudnieniami", "dane": dane}
    miesiac = dane.get("miesiac") or "2026-08"
    # ⚠ Spółka z NAJWIĘKSZĄ liczbą osób, które MAJĄ przełożonego — nie największa w ogóle.
    # Powód jest dowodowy: w spółce, w której nikt nie ma przełożonego, zero podpisów „Ty"
    # wychodzi także przy warunku zawsze-fałszywym. Dopiero obecność OBU podpisów naraz
    # („bez przełożonego" i „przełożony: <nazwisko>") pokazuje, że kod faktycznie rozróżnia.
    kolejnosc = sorted(spolki, key=lambda x: int(x["ile"]) - int(x["bez_szefa"]), reverse=True)
    a, c = int(kolejnosc[0]["id"]), int(kolejnosc[1]["id"])
    poziom_a = poziom_kalendarza(a, None, LOGIN).get("przed", 0)
    poziom_c = poziom_kalendarza(c, None, LOGIN).get("przed", 0)
    filtr_a = filtr_kalendarza(a, None, LOGIN)

    ctx = nowy_kontekst(b, usterka, viewport={"width": SZEROKOSC_LAPTOPA, "height": 855}, ignore_https_errors=True)
    try:
        poziom_kalendarza(a, 2, LOGIN)
        poziom_kalendarza(c, 2, LOGIN)
        pg = ctx.new_page()
        pg.goto(BAZA + "/logowanie", wait_until="networkidle")
        pg.fill("#login-email", LOGIN)
        pg.fill("#login-password", haslo)
        pg.click("button[type=submit]")
        pg.wait_for_load_state("networkidle")

        pg.goto(BAZA + f"/kalendarz?widok=firma&miesiac={miesiac}&spolka={a}", wait_until="networkidle")
        ekran = pg.evaluate("""() => {
          const podpisy = {};
          document.querySelectorAll('.team-grid__person small').forEach(s => {
            const t = s.textContent.trim(); podpisy[t] = (podpisy[t] || 0) + 1;
          });
          const paski = Array.from(document.querySelectorAll('.team-grid__bar'));
          return {
            tytul_dokumentu: document.title,
            // ⚠ `h1` stoi od 11.10.2026 **w belce** i jest bieżącą pozycją okruchów
            // (zlecenie `skale-czasu-i-tytul-ekranu` §2). Czytamy więc jedyny `h1` dokumentu,
            // a nie `.page__title h1`, którego już nie ma. Trzy warunki niżej dotyczą teraz
            // jednego elementu — to celowe: położenia i jedyności pilnuje `tytul_ekranu`.
            h1: (document.querySelector('h1') || {}).textContent,
            okruch: (document.querySelector('.crumbs__now') || {}).textContent,
            // ⚠ Podtytuł z liczbą osób USUNIĘTY jako powtórzenie tego, co stoi na przycisku
            // kontrolki — sprawdzamy więc, że go NIE MA, a odmianę po przyimku „z" mierzymy
            // tam, gdzie ta liczba teraz mieszka.
            sub: document.querySelector('.page__sub') === null ? null
              : document.querySelector('.page__sub').textContent,
            przycisk_kontrolki: (document.querySelector('summary.menu__trigger') || {}).textContent,
            spolka_w_karcie: (document.querySelector('.card__title') || {}).textContent,
            card_sub: (document.querySelector('.card__sub') || {}).textContent,
            podpisy,
            paskow: paski.length,
            paski_uciete: paski.filter(e => e.scrollWidth > e.clientWidth + 1).length,
            paski_tekstow: Array.from(new Set(paski.map(e => e.textContent.trim()))).length,
            zdania: Array.from(document.querySelectorAll('.card__meta')).map(e => e.textContent.trim()),
            dokument: document.documentElement.scrollHeight,
            okno: window.innerHeight,
            combo: Array.from(document.querySelectorAll('.spolka__pozycja')).map(e => ({
              on: e.classList.contains('spolka__pozycja--on'),
              wersalik: getComputedStyle(e).textTransform,
            })),
            segmented_spolek: document.querySelectorAll('.page__actions .segmented').length,
          };
        }""")
        # ⚠ **Nazwisko przełożonego musi działać, gdy JEGO WIERSZA nie ma na liście.** Dowód idzie
        # przez kontrolkę zawężoną szukaniem do JEDNEJ osoby, która ma przełożonego: jego wiersza
        # wtedy na pewno nie ma, a podpis i tak ma podać nazwisko. Zmierzone przed poprawką:
        # po odznaczeniu przełożonego 5 wierszy miało „przełożony: inna osoba w gałęzi" zamiast
        # jego nazwiska — nazwy brały się z pokazanej listy, nie z zasięgu.
        podwladny = pg.evaluate("""() => {
          const w = Array.from(document.querySelectorAll('.team-grid__person')).find(
            e => (e.querySelector('small') || {textContent: ''}).textContent.startsWith('przełożony: '));
          if (!w) { return null; }
          return {osoba: w.querySelector('span').textContent.trim(),
                  podpis: w.querySelector('small').textContent.trim()};
        }""")
        podpis_bez_szefa = None
        if podwladny is not None:
            nazwisko = podwladny["osoba"].split(" ")[0]
            pg.goto(BAZA + f"/kalendarz?widok=firma&miesiac={miesiac}&spolka={a}&szukaj={nazwisko}",
                    wait_until="networkidle")
            pg.click("summary.menu__trigger")
            pg.wait_for_timeout(250)
            podpis_bez_szefa = pg.evaluate("""(osoba) => {
              const it = Array.from(document.querySelectorAll('.pickbox__item')).find(
                l => l.querySelector('.pickbox__name').textContent.trim() === osoba);
              return it ? it.querySelector('.pickbox__tag').textContent.trim() : null;
            }""", podwladny["osoba"])
            pg.goto(BAZA + f"/kalendarz?widok=firma&miesiac={miesiac}&spolka={a}", wait_until="networkidle")

        # 6. Przyklejenie nagłówka dni — po przewinięciu o PEŁNY ekran.
        pg.evaluate("window.scrollTo(0, window.innerHeight)")
        pg.wait_for_timeout(150)
        # ⚠ **Drugie kryterium z tej samej rodziny, znalezione przy §1 zlecenia z 10.10:**
        # „nagłówek dni jest widoczny po przewinięciu" było liczone z samego prostokąta
        # (`r.top` w oknie). Nagłówek stoi w siatce, a siatka ma własne przewijanie — czyli
        # przodka, który przycina. Dokładamy to samo przecięcie i to samo pytanie do
        # przeglądarki, żeby „przyklejony" znaczyło „widać go", a nie „ma współrzędne".
        naglowek = pg.evaluate("""() => {
""" + OSIAGALNOSC_JS + """
          const n = document.querySelectorAll('.team-grid__head')[1];
          const o = document.querySelector('.team-grid__head--person');
          if (!n) { return null; }
          const r = n.getBoundingClientRect();
          const os = __osiagalnosc(n);
          // ⚠ **Kryterium znowu mierzy WIDOCZNOŚĆ, nie prostokąt** (aneks 10.10.2026 §6.1,
          // po naprawie `.team-grid__head { top: var(--topbar-h) }`). Historia jest tu
          // ostrzeżeniem: przez cztery dni stało `r.top >= -1 && r.top < innerHeight`, czyli
          // sam prostokąt — i świeciło zielono nad nagłówkiem, którego **nie było widać**,
          // bo przyklejał się pod paskiem górnym (`HEADER.topbar`, `z-index: 10`, też `top: 0`).
          // Przez te cztery dni kryterium nazywało się `w_kadrze`, bo tylko to mierzyło;
          // `widoczny` wraca razem z hit-testem, nie samą nazwą.
          return {top: Math.round(r.top), osiagalnosc: os,
                  widoczny: r.top >= -1 && r.top < window.innerHeight && os.pole > 0
                    && os.trafia === true,
                  zaslaniany_przez: os.trafia ? null : os.trafiony,
                  pozycja: getComputedStyle(n).position,
                  osoba_pozycja: o ? getComputedStyle(o).position : null,
                  przewinieto: Math.round(window.scrollY)};
        }""")

        # Liczba nieobecności w zdaniu MUSI zgadzać się z liczbą pasków na ekranie.
        # (`_re` używane także w kryterium odmiany niżej.)
        import re as _re
        m = _re.match(r"^(\d+) nieobecno", (ekran["card_sub"] or "").strip())
        zdanie_liczba = int(m.group(1)) if m else (0 if "Brak nieobecności" in (ekran["card_sub"] or "") else None)

        szczyty = int(kolejnosc[0]["szczytow"])
        return {
            "ok": (
                ekran["podpisy"].get("przełożony: Ty", 0) == 0
                and ekran["podpisy"].get("bez przełożonego", 0) > 0
                # Trzeci podpis MUSI wystąpić: bez niego zero „Ty" nie dowodzi niczego,
                # bo tak samo wygląda warunek zawsze-fałszywy.
                and any(k.startswith("przełożony: ") for k in ekran["podpisy"])
                and (ekran["h1"] or "").strip() == "Kalendarz"
                and (ekran["h1"] or "").strip() == (ekran["okruch"] or "").strip()
                and (ekran["h1"] or "").strip() in (ekran["tytul_dokumentu"] or "")
                # ⚠ Kryterium jest REGUŁĄ, nie zakazem słowa: po przyimku „z" ma stać
                # dopełniacz, a „z 1 osoby" jest POPRAWNE. Szukanie samego „ osoby"
                # świeciłoby czerwono od stanu danych (spółka z jedną osobą w zasięgu),
                # a bramka czerwona od danych przestaje być bramką.
                and _re.search(r"z \d+ osoby", ekran["przycisk_kontrolki"] or "") is None
                # ⚠ Podtytuł z liczbą osób NIE ISTNIEJE — ta liczba stoi na przycisku kontrolki
                # i tylko tam. Dwa miejsca z jedną liczbą to dwa miejsca do rozjechania się.
                and ekran["sub"] is None
                and _re.search(r"\d+", ekran["przycisk_kontrolki"] or "") is not None
                and (ekran["spolka_w_karcie"] or "") != ""
                # ⚠ **Zdania „N nieobecności" JUŻ NIE MA** (decyzja Krzysztofa, Z8, 17.10.2026),
                # więc porównanie go z liczbą pasków straciło przedmiot — i przez to kryterium
                # świeciło czerwono na ekranie, który jest poprawny. Zostają dwa twierdzenia,
                # które dalej coś znaczą: **zdanie nie wróciło** (liczba stoi raz, na przycisku
                # kontrolki) i **paski są** — bez tego drugiego pusty ekran przechodziłby
                # wszystkie warunki o kształcie.
                and zdanie_liczba is None and (ekran["card_sub"] or "") == ""
                and ekran["paskow"] > 0
                and ekran["paski_uciete"] == 0
                and any("tylko weekendy" in z and "nie ma zatrudnienia" in z for z in ekran["zdania"])
                and not any("Twojego rozkładu czasu pracy" in z for z in ekran["zdania"])
                and naglowek is not None and naglowek["widoczny"] and naglowek["przewinieto"] > 0
                # ⚠ Konto administracyjne ma tylko „Firma", czyli JEDNĄ pozycję — więc
                # przełącznika widoku nie ma wcale (§3). W pasku zostaje zero `.segmented`.
                and ekran["segmented_spolek"] == 0
                # ⚠ Nazwisko przełożonego to samo na liście i bez jego wiersza — i NIGDY zastępnik,
                # dopóki przełożony jest w zasięgu oglądającego.
                and (podwladny is None or podpis_bez_szefa == podwladny["podpis"])
                and (podpis_bez_szefa is None or "zasięgu" not in podpis_bez_szefa)
                and len([k for k in ekran["combo"] if k["on"]]) == 1
                and all(k["wersalik"] == "none" for k in ekran["combo"])
            ),
            "podpisy": ekran["podpisy"],
            "szczytow_hierarchii_w_spolce": szczyty,
            "naglowek": {k: ekran[k] for k in ("h1", "okruch", "tytul_dokumentu")},
            "podtytul_istnieje": ekran["sub"] is not None,
            "przycisk_kontrolki": ekran["przycisk_kontrolki"],
            "naglowek_karty": ekran["spolka_w_karcie"],
            "zdanie_nieobecnosci": ekran["card_sub"],
            "paskow_na_ekranie": ekran["paskow"],
            "paskow_roznych_tekstow": ekran["paski_tekstow"],
            "paskow_ucietych": ekran["paski_uciete"],
            "zdania_pod_siatka": ekran["zdania"],
            "dokument_px": ekran["dokument"],
            "okno_px": ekran["okno"],
            "naglowek_po_przewinieciu": naglowek,
            "podpis_na_siatce": podwladny,
            "podpis_gdy_szefa_nie_ma_na_liscie": podpis_bez_szefa,
            "przelacznikow_segmented": ekran["segmented_spolek"],
            "pozycji_w_liscie_spolek": len(ekran["combo"]),
        }
    except Exception as e:  # noqa: BLE001
        return {"ok": False, "powod": str(e)}
    finally:
        try:
            poziom_kalendarza(a, poziom_a, LOGIN)
            poziom_kalendarza(c, poziom_c, LOGIN)
            filtr_kalendarza(a, filtr_a if filtr_a else None, LOGIN)
        except Exception:  # noqa: BLE001
            pass
        ctx.close()


def sprawdz_panel_wyboru(b) -> dict:
    """Panel kontrolki wyboru osób — **nazwany wyjątek na przewijanie** i arytmetyka zbiorów.

    ⚠ Wyjątek z `docs/system-wizualny.md` 12 jest tu zapisany jako REGUŁA, nie jako zgoda:
    przewija się **wyłącznie lista**, panel i formularz nie, a przycisk zapisu jest w całości
    na ekranie. Powód zmierzony: przy zasięgu 53 osób panel miał 1364 px w oknie 855 px,
    a przycisk „Pokaż wybrane" kończył się na 1639 px, czyli poza ekranem.

    ⚠ **„Odznacz wszystkich" przy przyciętej liście** nie ma prawa wykluczyć osób, których
    człowiek nie widział: po kliknięciu w `calendar_filter` ma być DOKŁADNIE tyle wyłączeń,
    ile pozycji było pokazanych (zlecenie 06.10 §2b).

    ⚠ **Po przełączeniu miesiąca i spółki kontrolka musi działać DALEJ** (§4): karta jest
    wymieniana przez htmx, a prototyp nadzoru po pierwszym przełączeniu spółki przestawał
    działać bez błędu i bez komunikatu.
    """
    haslo = HASLO or haslo_dev(LOGIN)
    dane = spolki_dla_admina()
    spolki = dane.get("spolki", [])
    if len(spolki) < 2:
        return {"ok": False, "powod": "dane robocze nie dają dwóch spółek z zatrudnieniami", "dane": dane}
    miesiac = dane.get("miesiac") or "2026-08"
    # Większa spółka na pomiar panelu: przycięta lista jest warunkiem dowodu z §2b.
    duza = max(spolki, key=lambda x: int(x["ile"]))
    a = int(duza["id"])
    druga = int([x for x in spolki if int(x["id"]) != a][0]["id"])
    poziom_a = poziom_kalendarza(a, None, LOGIN).get("przed", 0)
    poziom_b = poziom_kalendarza(druga, None, LOGIN).get("przed", 0)
    filtr_a = filtr_kalendarza(a, None, LOGIN)

    ctx = b.new_context(viewport={"width": SZEROKOSC_LAPTOPA, "height": 855}, ignore_https_errors=True)
    try:
        poziom_kalendarza(a, 2, LOGIN)
        poziom_kalendarza(druga, 2, LOGIN)
        pg = ctx.new_page()
        pg.goto(BAZA + "/logowanie", wait_until="networkidle")
        pg.fill("#login-email", LOGIN)
        pg.fill("#login-password", haslo)
        pg.click("button[type=submit]")
        pg.wait_for_load_state("networkidle")

        def geometria() -> dict:
            pg.click("summary.menu__trigger")
            pg.wait_for_timeout(250)
            return pomiar_panelu()

        def pomiar_panelu() -> dict:
            return pg.evaluate("""() => {
""" + OSIAGALNOSC_JS + """
              const p = document.querySelector('.pickbox');
              if (!p) { return null; }
              const lista = p.querySelector('.pickbox__list');
              const form = p.querySelector('.pickbox__form');
              // ⚠ `--primary`, nie pierwszy `.btn`: w stopce stoją teraz także dwa
              // przyciski pomocnicze z klasą `.btn`, a mierzymy przycisk ZAPISU.
              const btn = p.querySelector('.pickbox__foot .btn--primary');
              const rp = p.getBoundingClientRect(); const rb = btn.getBoundingClientRect();
              return {
                panel_h: Math.round(rp.height), panel_bottom: Math.round(rp.bottom),
                okno: window.innerHeight,
                lista_h: Math.round(lista.getBoundingClientRect().height),
                lista_przewija: lista.scrollHeight > lista.clientHeight,
                panel_przewija: p.scrollHeight > p.clientHeight + 1,
                form_przewija: form.scrollHeight > form.clientHeight + 1,
                // ⚠ **Nie prostokąt, a OSIĄGALNOŚĆ** (10.10.2026): `rb.bottom <= innerHeight`
                // było tu zielone nad przyciskiem przyciętym do zera przez kartę-rodzica.
                // Ten sam pomiar co w sprawdzeniu nakładek — przecięcie z przodkami
                // plus `elementFromPoint`.
                zapis_osiagalnosc: __osiagalnosc(btn),
                przycisk_w_calosci_na_ekranie: __osiagalnosc(btn).osiagalna,
                pozycji: p.querySelectorAll("input[name='osoby[]']").length,
                wierszy_zlamanych: Array.from(p.querySelectorAll('.pickbox__item'))
                  .filter(e => e.getBoundingClientRect().height > 48).length,
                licznik: (p.querySelector('[data-pick-licznik]') || {}).textContent,
                // ⚠ `style.maxHeight` MUSI być puste: wysokość panelu należy do arkusza
                // (funkcja okna), a nie do JavaScriptu (funkcja położenia) — patrz §1.
                inline_max: p.style.maxHeight || '',
                scrollY: Math.round(window.scrollY),
                etykiety: Array.from(p.querySelectorAll('.pickbox__akcja')).map(b => b.textContent.trim()),
              };
            }""")

        pg.goto(BAZA + f"/kalendarz?widok=firma&miesiac={miesiac}&spolka={a}", wait_until="networkidle")
        # ⚠ Liczbę osób w zasięgu czytamy z PRZYCISKU kontrolki („Widać 7 z 36 osób" albo
        # „Wszystkie osoby: 36"), bo podtytuł z tą samą liczbą został usunięty jako powtórzenie.
        # ⚠ To nie jest obejście: przycisk jest teraz JEDYNYM miejscem, w którym ta liczba stoi,
        # więc bramka czyta ją tam, gdzie człowiek. Dwa wzorce, bo napis zależy od stanu filtru.
        w_zasiegu = pg.evaluate("""() => {
          const m = (document.querySelector('summary.menu__trigger') || {}).textContent || '';
          const x = m.match(/z (\\d+) os/) || m.match(/osoby:\\s*(\\d+)/);
          return x ? parseInt(x[1], 10) : null;
        }""")
        pierwsze = geometria()

        # ⚠ **DOWÓD Z PRZEWIJANIA, NIE Z OTWARCIA** (§1 zlecenia z wieczora 06.10). Pomiar
        # w chwili otwarcia pokazywał panel poprawny — i właśnie dlatego nie wyłapał usterki:
        # wysokość liczyła się z POŁOŻENIA panelu w oknie, więc rosła przy przewijaniu
        # (zmierzone 513 → 935 px) i po powrocie na górę nie wracała. Kontrolkę mierzy się
        # w użyciu, a stan otwarcia jest jednym ze stanów, nie wynikiem.
        pg.evaluate("window.scrollTo(0, 400)")
        pg.wait_for_timeout(250)
        przewiniete = pomiar_panelu()
        pg.evaluate("window.scrollTo(0, 0)")
        pg.wait_for_timeout(250)
        z_powrotem = pomiar_panelu()

        # ⚠ Po WYMIANIE FRAGMENTU (miesiąc, potem spółka) kontrolka musi dalej się dopasowywać.
        pg.click(SEL_OKRES)
        pg.wait_for_timeout(600)
        po_miesiacu = geometria()
        # ⚠ **Zmiana spółki przestała być wymianą fragmentu** (10.10.2026, §1 zlecenia
        # o globalnym wyborze spółki): kontrolka w nagłówku ekranu zniknęła, a znacznik w belce
        # przełącza `POST`-em z przekierowaniem. Mierzymy więc stan po pełnym przejściu — i tak
        # musi on wyjść identyczny, bo kontrolka wyboru osób dopasowuje wysokość przy każdym
        # starcie, nie tylko po wymianie. Wymianę fragmentu nadal mierzy krok z miesiącem wyżej.
        pg.goto(BAZA + f"/kalendarz?widok=firma&miesiac={miesiac}&spolka={druga}",
                wait_until="networkidle")
        pg.wait_for_timeout(300)
        # ⚠ `geometria()` SAMA otwiera panel — drugie kliknięcie by go zamknęło i pomiar
        # opisywałby przycisk schowany za siatką (zmierzone: `przycisk_w_calosci_na_ekranie`
        # czerwone przy poprawnym ekranie, bo panel był zamknięty).
        po_spolce = geometria()

        # ⚠ §2: **dwa działania, DWA różne zasięgi** — i to jest cała poprawka z wieczora 06.10.
        #   „Odznacz pokazanych" rusza wyłącznie listę z ekranu → wyłączeń dokładnie tyle,
        #   ile było pozycji, ani jednego więcej (zawężenie o niewidzianych ukrywa ludzi po cichu);
        #   „Zaznacz wszystkich" czyści wyłączenia W CAŁOŚCI → zero (poszerzanie jest bezpieczne,
        #   nikt nie znika). Asymetria musi też być WIDOCZNA: liczby stoją w etykietach.
        # ⚠ **Lista ZAWĘŻONA SZUKANIEM, nie limitem** — i to jest zmiana z 06.10 wieczorem.
        # Dowód „działanie rusza tylko to, co człowiek widział" wymaga listy krótszej niż zasięg.
        # Dotąd dawał ją próg wyświetlania (30 z 53), ale próg podniesiono do 100, bo obcinał
        # każdą spółkę w danych. Szukanie daje tę samą sytuację i **nie zależy od wartości progu**,
        # więc dowód przestaje się psuć przy każdej zmianie konfiguracji.
        pg.goto(BAZA + f"/kalendarz?widok=firma&miesiac={miesiac}&spolka={a}", wait_until="networkidle")
        pg.click("summary.menu__trigger")
        pg.wait_for_timeout(200)
        pelna_lista = pg.evaluate("""() => document.querySelectorAll("input[name='widoczne[]']").length""")
        # Nazwisko z pierwszej pozycji — szukana fraza bierze się Z DANYCH, nie z bramki.
        fraza = pg.evaluate(
            """() => (document.querySelector('.pickbox__name') || {textContent: ''})
                 .textContent.trim().split(' ')[0]""")
        ukladStopki = pg.evaluate("""() => {
          const r = document.querySelector('.pickbox__row');
          const b = Array.from(r.querySelectorAll('.pickbox__akcja'));
          const l = document.querySelector('.pickbox__licznik');
          return {dzialan: b.length,
                  // ⚠ Oba działania W JEDNEJ LINII: rząd zawijał się w różnych miejscach,
                  // bo punkt zawinięcia zależał od LICZB w etykietach, a te zmieniają się
                  // przy każdym kliknięciu — kontrolka skakała pod kursorem.
                  w_jednej_linii: b.length === 2 &&
                    Math.abs(b[0].getBoundingClientRect().top - b[1].getBoundingClientRect().top) < 2,
                  licznik_nad_dzialaniami: l.getBoundingClientRect().bottom
                    <= b[0].getBoundingClientRect().top + 1,
                  rzad_przewija: r.scrollWidth > r.clientWidth + 1,
                  // ⚠ Typ `button`, nie `submit`: te działania przestawiają pola, nie zapisują.
                  typy: b.map(x => x.getAttribute('type')),
                  panel_w: Math.round(document.querySelector('.pickbox').getBoundingClientRect().width),
                  nazwisk: document.querySelectorAll('.pickbox__name').length,
                  nazwisk_ucietych: Array.from(document.querySelectorAll('.pickbox__name'))
                    .filter(n => n.scrollWidth > n.clientWidth + 1).length,
                  zapis: (document.querySelector('.pickbox__foot .btn--primary') || {}).textContent};
        }""")

        pg.goto(BAZA + f"/kalendarz?widok=firma&miesiac={miesiac}&spolka={a}&szukaj={fraza}",
                wait_until="networkidle")
        pg.click("summary.menu__trigger")
        pg.wait_for_timeout(200)
        pokazanych = pg.evaluate("""() => document.querySelectorAll("input[name='widoczne[]']").length""")
        etykiety = pg.evaluate(
            """() => Array.from(document.querySelectorAll('.pickbox__akcja')).map(b => b.textContent.trim())""")
        # ⚠ **Dwa kroki, i pierwszy NIE MA PRAWA ZAPISAĆ.** „Odznacz pokazanych" przestawia pola
        # wyboru; zapisuje dopiero „Pokaż". Bramka sprawdza oba momenty, bo gdyby działanie
        # zapisywało od razu, panel zamykałby się po kliknięciu i nie dałoby się zaznaczyć
        # wszystkich, a potem jeszcze kogoś odznaczyć (zgłoszenie z 06.10 wieczorem).
        pg.click("[data-pick='zadne']")
        pg.wait_for_timeout(400)
        przed_pokaz = filtr_kalendarza(a, None, LOGIN)
        bez_zapisu = przed_pokaz is None or przed_pokaz == ""
        panel_zostal_otwarty = pg.evaluate(
            """() => !!document.querySelector('details.menu--wybor') &&
                 document.querySelector('details.menu--wybor').open""")
        # ⚠ Jeśli działanie zapisało od razu, panel jest już zamknięty i nie ma czego kliknąć.
        # Wychodzimy z **czytelnym powodem**, a nie z limitem czasu na szukaniu przycisku:
        # czerwona bramka bez powodu to drugie śledztwo (ta sama zasada co przy próbce ról).
        if not bez_zapisu or not panel_zostal_otwarty:
            return {"ok": False,
                    "powod": 'działanie w kontrolce zapisało od razu — „Pokaż” ma być osobnym krokiem',
                    "w_bazie_przed_pokaz": przed_pokaz,
                    "panel_zostal_otwarty": panel_zostal_otwarty,
                    "uklad_stopki": ukladStopki}
        pg.click(".pickbox__foot .btn--primary")
        pg.wait_for_timeout(800)
        zapisane = filtr_kalendarza(a, None, LOGIN)
        wylaczen = len(json.loads(zapisane)) if zapisane else 0
        # „Zaznacz wszystkich" na PEŁNEJ liście — ma wyczyścić wszystko, także wyłączenia
        # osób, których przy zawężonym szukaniu nie było na ekranie.
        pg.goto(BAZA + f"/kalendarz?widok=firma&miesiac={miesiac}&spolka={a}", wait_until="networkidle")
        pg.click("summary.menu__trigger")
        pg.wait_for_timeout(200)
        pg.click("[data-pick='wszystkie']")
        pg.wait_for_timeout(300)
        pg.click(".pickbox__foot .btn--primary")
        pg.wait_for_timeout(800)
        po_zaznaczeniu = filtr_kalendarza(a, None, LOGIN)
        wylaczen_po_zaznaczeniu = len(json.loads(po_zaznaczeniu)) if po_zaznaczeniu else 0

        # ⚠ **„Zaznacz wszystkich", a potem ręczne odznaczenie jednej osoby** ma dać dokładnie
        # jedno wyłączenie, a nie zero. Przeglądarka wyraża „cały zasięg" stanem — zaznaczeniem
        # pokazanych i wyrzuceniem ukrytych `wyl[]` — więc po odznaczeniu pola decyduje znowu to,
        # co widać. Gdyby zamiar siedział w osobnej flasze, flaga przeżyłaby odznaczenie i zapis
        # powiedziałby „widzę wszystkich", gubiąc ostatnią decyzję człowieka bez śladu.
        pg.goto(BAZA + f"/kalendarz?widok=firma&miesiac={miesiac}&spolka={a}&szukaj={fraza}",
                wait_until="networkidle")
        pg.click("summary.menu__trigger")
        pg.wait_for_timeout(200)
        pg.click("[data-pick='wszystkie']")
        pg.wait_for_timeout(150)
        pg.evaluate("""() => {
          const p = document.querySelector("input[name='osoby[]']");
          p.checked = false;
          p.dispatchEvent(new Event('change', {bubbles: true}));
        }""")
        pg.wait_for_timeout(150)
        pg.click(".pickbox__foot .btn--primary")
        pg.wait_for_timeout(800)
        po_odkliku = filtr_kalendarza(a, None, LOGIN)
        wylaczen_po_odkliku = len(json.loads(po_odkliku)) if po_odkliku else 0

        # ⚠ **SZUKANIE NIE MOŻE KASOWAĆ NIEZAPISANYCH DECYZJI.** Zmierzone przed poprawką:
        # „Zaznaczono 31 z 36" przed wpisaniem czegokolwiek i „9 z 9" po — pięć odznaczeń
        # przepadało bez żadnego sygnału, więc wyglądało to na samoczynne zaznaczenie wszystkich.
        # Dowód idzie przez osobę, która **wypada z wyniku szukania**: jej decyzja musi wrócić,
        # kiedy szukanie zostanie wyczyszczone, a potem trafić do bazy.
        # ⚠ **Ognisko klawiatury** sprawdzamy w tym samym przebiegu, bo to ta sama usterka:
        # wymiana całego panelu niszczy pole tekstowe. Przed poprawką `activeElement` po
        # przeszukaniu był `BODY`, więc drugi `Backspace` nie robił nic i **nie dało się cofnąć
        # wpisanego tekstu** — to nie niedogodność, tylko zablokowana funkcja.
        filtr_kalendarza(a, None, LOGIN)
        pg.goto(BAZA + f"/kalendarz?widok=firma&miesiac={miesiac}&spolka={a}", wait_until="networkidle")
        pg.click("summary.menu__trigger")
        pg.wait_for_timeout(200)
        poza = pg.evaluate("""(f) => {
          const it = Array.from(document.querySelectorAll('.pickbox__item')).find(
            l => !l.querySelector('.pickbox__name').textContent.toLowerCase().includes(f.toLowerCase()));
          if (!it) { return null; }
          const p = it.querySelector("input[name='osoby[]']");
          p.checked = false;
          p.dispatchEvent(new Event('change', {bubbles: true}));
          return p.value;
        }""", fraza)
        pg.fill("input[name=szukaj]", fraza)
        pg.wait_for_timeout(1100)
        po_szukaniu = pg.evaluate("""() => ({
          wyl: Array.from(document.querySelectorAll('input[name=\"wyl[]\"]')).map(p => p.value),
          fokus_w_szukajce: document.activeElement === document.querySelector('input[name=szukaj]'),
        })""")
        # Cofnięcie tekstu klawiszem — bez ogniska drugi `Backspace` nie zrobi NIC.
        pg.keyboard.press("Backspace")
        pg.wait_for_timeout(450)
        pg.keyboard.press("Backspace")
        pg.wait_for_timeout(450)
        cofanie = pg.evaluate("""(f) => ({
          wartosc: document.querySelector('input[name=szukaj]').value,
          oczekiwana: f.slice(0, -2),
          fokus_w_szukajce: document.activeElement === document.querySelector('input[name=szukaj]'),
        })""", fraza)
        pg.fill("input[name=szukaj]", "")
        pg.wait_for_timeout(1100)
        decyzja_przezyla = pg.evaluate("""(id) => {
          const p = document.querySelector("input[name='osoby[]'][value='" + id + "']");
          return p ? p.checked : null;
        }""", poza)
        pg.click(".pickbox__foot .btn--primary")
        pg.wait_for_timeout(800)
        po_przezyciu = filtr_kalendarza(a, None, LOGIN)
        wylaczen_po_przezyciu = len(json.loads(po_przezyciu)) if po_przezyciu else 0

        # ⚠ §4: **szerokość przycisku spółki i położenie nawigacji identyczne dla OBU nazw.**
        # Pasek akcji jest wyrównany do prawej, więc przycisk o zmiennej szerokości przesuwał
        # wszystko, co stoi przed nim (zmierzony skok lewej krawędzi: 111 px przy nazwie prawnej).
        pasek = {}
        for spolka_id in (a, druga):
            pg.goto(BAZA + f"/kalendarz?widok=firma&miesiac={miesiac}&spolka={spolka_id}",
                    wait_until="networkidle")
            pasek[str(spolka_id)] = pg.evaluate("""() => {
              // ⚠ Od 10.10.2026 mierzymy **znacznik w belce** (`.spolka__trigger`), bo
              // kontrolka z nagłówka ekranu zniknęła. Pytanie zostaje to samo: czy nazwa prawna
              // („BETA SPÓŁKA Z OGRANICZONĄ ODPOWIEDZIALNOŚCIĄ") rozpycha kontrolkę i przesuwa
              // to, co stoi obok. Teraz obok stoi nawigacja miesiąca w pasku akcji — i ona
              // nie ma prawa drgnąć, bo znacznik jest w innej warstwie.
              const t = document.querySelector('.spolka__trigger');
              const prev = document.querySelector('.kal-nav a.kal-btn[href*="miesiac"]');
              const n = t ? t.querySelector('.spolka__nazwa') : null;
              if (!t || !prev) { return null; }
              const a = t.querySelector('.spolka__strzalka');
              return {przycisk_px: Math.round(t.getBoundingClientRect().width),
                      poprzedni_left: Math.round(prev.getBoundingClientRect().left),
                      nazwa_ucieta: n.scrollWidth > n.clientWidth,
                      // ⚠ Strzałka stoi na PRAWEJ krawędzi przycisku niezależnie od długości
                      // nazwy. Przy stałej szerokości, ale nazwie bez `flex: 1 1 auto`, krótka
                      // nazwa zbijała napis i strzałkę do lewej, a po prawej zostawała pustka:
                      // zmierzone 124 px przy „ALFA" i 13 px przy nazwie prawnej, czyli
                      // ten sam skok 111 px, tylko w środku kontrolki.
                      strzalka_od_prawej: Math.round(
                        t.getBoundingClientRect().right - a.getBoundingClientRect().right),
                      // ⚠ §3: przełącznik widoku istnieje od DWÓCH pozycji — nigdy z jedną.
                      // ⚠ Od Z8 przełączniki kalendarza to `.kal-grupa` z `.kal-btn`, a nie
                      // `.segmented` w pasku akcji — `.segmented` został na pulpicie i w wykazie,
                      // gdzie przełącza ROK, więc szukanie go tutaj mierzyło inny ekran.
                      pozycji_przelacznika: Array.from(
                        document.querySelectorAll('.kal-nav__prawa .kal-grupa')).map(
                          s => s.children.length),
                      podtytul_istnieje: document.querySelector('.page__sub') !== null,
                      // ⚠ Jedyny `h1` dokumentu: od 11.10.2026 stoi w belce, nie w `.page__title`
                      // (tamten odczyt wywracał całe sprawdzenie na `null`).
                      h1: (document.querySelector('h1') || {}).textContent.trim()};
            }""")
        szer = {k: v["przycisk_px"] for k, v in pasek.items() if v}
        lewe = {k: v["poprzedni_left"] for k, v in pasek.items() if v}
        strzalki = {k: v["strzalka_od_prawej"] for k, v in pasek.items() if v}

        wszystkie_panele = (pierwsze, przewiniete, z_powrotem, po_miesiacu, po_spolce)
        return {
            "ok": (
                all(g is not None for g in wszystkie_panele)
                and all(g["panel_bottom"] <= g["okno"] for g in wszystkie_panele)
                and all(g["przycisk_w_calosci_na_ekranie"] for g in wszystkie_panele)
                and all(not g["panel_przewija"] and not g["form_przewija"] for g in wszystkie_panele)
                and pierwsze["lista_przewija"]
                and all(g["wierszy_zlamanych"] == 0 for g in wszystkie_panele)
                # ⚠ §1: wysokość panelu RÓWNA w trzech momentach przewijania i ani jednego
                # `style.maxHeight` z JavaScriptu — to on zamieniał ją w funkcję położenia.
                and pierwsze["panel_h"] == przewiniete["panel_h"] == z_powrotem["panel_h"]
                and przewiniete["scrollY"] > 0 and z_powrotem["scrollY"] == 0
                and all(g["inline_max"] == "" for g in wszystkie_panele)
                # ⚠ §2: dwa zasięgi, dwie liczby — i liczby widoczne w etykietach.
                # Przy liście zawężonej szukaniem te liczby są RÓŻNE, więc warunek na etykiety
                # nie przechodzi przypadkiem (na pełnej liście oba działania obejmują ten sam zbiór).
                and pokazanych > 0 and wylaczen == pokazanych and wylaczen_po_zaznaczeniu == 0
                # ⚠ Po „Zaznacz wszystkich" i ręcznym odznaczeniu: dokładnie tyle wyłączeń,
                # ile odznaczono — nie zero.
                and wylaczen_po_odkliku == pokazanych
                # ⚠ Szukanie nie kasuje niezapisanych decyzji: osoba spoza wyniku wraca
                # odznaczona, a jej wyłączenie trafia do bazy jako jedyne.
                and poza is not None and po_szukaniu["wyl"] == [poza]
                and decyzja_przezyla is False and wylaczen_po_przezyciu == 1
                # ⚠ Ognisko zostaje w szukajce po wymianie i tekst da się cofnąć klawiszem.
                and po_szukaniu["fokus_w_szukajce"] and cofanie["fokus_w_szukajce"]
                and cofanie["wartosc"] == cofanie["oczekiwana"]
                and pokazanych < pelna_lista
                and any(f"({pelna_lista})" in e for e in etykiety)
                and any(f"({pokazanych})" in e for e in etykiety)
                # ⚠ Stopka: oba działania w jednej linii, licznik nad nimi, rząd się nie przewija.
                and ukladStopki["w_jednej_linii"] and ukladStopki["licznik_nad_dzialaniami"]
                and not ukladStopki["rzad_przewija"]
                # ⚠ Działanie samo NIE zapisuje i panel zostaje otwarty — zapisuje „Pokaż".
                and bez_zapisu and panel_zostal_otwarty
                # ⚠ Panel szerszy: nazwiska nie są ucinane wielokropkiem.
                and ukladStopki["nazwisk_ucietych"] == 0
                # ⚠ Próg wyświetlania NIE MOŻE obcinać spółki z danych: „Zaznacz wszystkich (N)"
                # ma dawać N wierszy, nie mniej (zgłoszenie z 06.10: 36 rekordów, 30 zaznaczonych).
                and (w_zasiegu is None or pelna_lista == w_zasiegu)
                # ⚠ §4: przycisk spółki i nawigacja co do piksela takie same dla obu nazw.
                and len(set(szer.values())) == 1 and len(set(lewe.values())) == 1
                and len(set(strzalki.values())) == 1
                # ⚠ §3: przełącznik widoku nigdy z JEDNĄ pozycją.
                and all(1 not in (v or {}).get("pozycji_przelacznika", []) for v in pasek.values())
                # ⚠ §5 i §6: podtytuł bez spółki i bez miesiąca, nagłówek krótki.
                and all((v or {})["h1"] == "Kalendarz" for v in pasek.values())
                and all((v or {})["podtytul_istnieje"] is False for v in pasek.values())
            ),
            "w_zasiegu": w_zasiegu,
            "panel_1_po_otwarciu": pierwsze,
            "panel_2_po_przewinieciu": przewiniete,
            "panel_3_po_powrocie": z_powrotem,
            "panel_po_zmianie_miesiaca": po_miesiacu,
            "panel_po_zmianie_spolki": po_spolce,
            "pozycji_bez_szukania": pelna_lista,
            "szukana_fraza": fraza,
            "pokazanych_po_zawezeniu": pokazanych,
            "etykiety_przyciskow": etykiety,
            "uklad_stopki": ukladStopki,
            "dzialanie_nie_zapisuje": bez_zapisu,
            "panel_zostal_otwarty": panel_zostal_otwarty,
            "wylaczen_po_odznacz_pokazanych": wylaczen,
            "wylaczen_po_zaznacz_wszystkich": wylaczen_po_zaznaczeniu,
            "wylaczen_po_recznym_odkliku": wylaczen_po_odkliku,
            "odznaczony_spoza_wyniku": poza,
            "wyl_przeniesione_przez_szukanie": po_szukaniu["wyl"],
            "fokus_po_szukaniu": po_szukaniu["fokus_w_szukajce"],
            "cofanie_tekstu": cofanie,
            "decyzja_po_wyczyszczeniu_szukania_zaznaczona": decyzja_przezyla,
            "wylaczen_po_zapisie_decyzji": wylaczen_po_przezyciu,
            "pasek_akcji": pasek,
        }
    except Exception as e:  # noqa: BLE001
        return {"ok": False, "powod": str(e)}
    finally:
        try:
            poziom_kalendarza(a, poziom_a, LOGIN)
            poziom_kalendarza(druga, poziom_b, LOGIN)
            filtr_kalendarza(a, filtr_a if filtr_a else None, LOGIN)
        except Exception:  # noqa: BLE001
            pass
        ctx.close()


def sprawdz_kasowanie_klucza(b) -> dict:
    """Kasowanie unieważnionych kluczy instalacji na `/admin/integracja` — **dowody przez łamanie**.

    ⚠ **Bramka zakłada WŁASNY klucz i nie dotyka czynnego klucza instalacji.** Dowód „czynnego
    nie da się skasować" przy zepsutym warunku skasowałby klucz, z którego korzysta connector
    u klienta — czyli pomiar wyrządziłby dokładnie tę szkodę, przed którą ma chronić.

    Cztery przeszkody, każda w innym miejscu i żadna nie zastępuje pozostałych:
      1. **klucz CZYNNY** — `POST …/usun` z pełnym żądaniem → 404, klucz zostaje,
      2. **brak potwierdzenia** w formularzu → 422, klucz zostaje,
      3. **brak tokenu CSRF** → 419, klucz zostaje,
      4. poprawne żądanie → klucz **znika z wykazu** i zostaje wpis w dzienniku.

    ⚠ Ekran potwierdzenia sprawdzamy osobno, bo pierwsza wersja wołała funkcję zwracającą
    **tylko czynne** klucze i dawała 404 — przycisk „Skasuj" prowadził w pustkę, a samo kasowanie
    działało wyłącznie przy żądaniu zbudowanym ręcznie. Sprawdzenie endpointu bez sprawdzenia
    ekranu tego nie wyłapało.
    """
    import subprocess

    def probka(*args: str) -> dict:
        wyn = subprocess.run(["php", "dev/probka-klucza.php", *args],
                             cwd=KORZEN, capture_output=True, text=True)
        if wyn.returncode != 0 or not wyn.stdout.strip():
            raise RuntimeError(f"probka-klucza.php {' '.join(args)} kod={wyn.returncode}: "
                               f"{(wyn.stderr or wyn.stdout).strip()[:300]}")
        return json.loads(wyn.stdout)

    haslo = HASLO or haslo_dev(LOGIN)
    klucz = None
    ctx = b.new_context(viewport={"width": SZEROKOSC_LAPTOPA, "height": 900}, ignore_https_errors=True)
    try:
        klucz = probka("wydaj")
        kid = int(klucz["id"])
        pg = ctx.new_page()
        pg.goto(BAZA + "/logowanie", wait_until="networkidle")
        pg.fill("#login-email", LOGIN)
        pg.fill("#login-password", haslo)
        pg.click("button[type=submit]")
        pg.wait_for_load_state("networkidle")
        pg.goto(BAZA + "/admin/integracja", wait_until="networkidle")
        token = pg.evaluate("""() => (document.querySelector('input[name=_csrf]') || {}).value || ''""")

        def usun(pola: dict) -> int:
            return pg.evaluate("""async (a) => {
              const r = await fetch(a.url, {method: 'POST', credentials: 'same-origin',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: new URLSearchParams(a.pola).toString()});
              return r.status;
            }""", {"url": f"/admin/integracja/klucze/{kid}/usun", "pola": pola})

        # 1. Klucz CZYNNY — pełne, poprawne żądanie i mimo to odmowa.
        kod_czynny = usun({"_csrf": token, "potwierdzam": "1"})
        po_czynnym = probka("stan", str(kid))
        # ⚠ **Druga warstwa sprawdzana OSOBNO.** Odmowę wyżej daje już warunek w usłudze, więc
        # zdjęcie warunku z ZAPYTANIA przechodziło na zielono — dwie przeszkody mają sens tylko
        # wtedy, gdy każda jest zmierzona. Tu wołamy repozytorium wprost, mijając usługę.
        repo_czynny = probka("repo-czynny", str(kid))

        probka("uniewaznij", str(kid))
        # 2. i 3. — brak potwierdzenia, brak tokenu.
        kod_bez_potw = usun({"_csrf": token})
        kod_bez_csrf = usun({"potwierdzam": "1"})
        po_probach = probka("stan", str(kid))

        # ⚠ **Wyrównanie kolumny „Działania" mierzone przy OBU rodzajach wiersza naraz** — a to
        # da się zrobić tylko teraz, kiedy w wykazie stoi i klucz czynny (trzy przyciski),
        # i unieważniony (jeden). Reguła: wszystkie dociśnięte do prawej krawędzi komórki tym
        # samym odstępem i mieszczące się w niej.
        #
        # ⚠ **Przepięte po Z35 (18.10.2026), bo zmienił się przedmiot pomiaru.** Dawna reguła
        # brzmiała „każdy rząd w jednej linii" i była policzona dla DWÓCH przycisków (158 px
        # komórki wobec 231 px potrzebnych, rząd 72 px zamiast 32). Po Z35 przyciski są trzy
        # i potrzebują 361 px, a kolumna daje 287 px przy oknie 1440 — zlecenie na to pozwala
        # wprost („zawijane, jeśli się nie mieszczą"). Kryterium mówi więc to, co ma znaczyć:
        # **przy 1920 px jedna linia, przy 1440 najwyżej dwie, nigdy trzy**, zawsze w granicach
        # komórki i zawsze z tym samym odstępem od prawej.
        #
        # ⚠ **Druga zmiana: liczymy WSZYSTKIE przyciski, nie dwa pierwsze.** Dawny warunek
        # porównywał `bs[0]` z `bs[1]`, więc trzeci mógł zawinąć się niezauważony — zmierzone:
        # przy 1440 px dwa pierwsze stały na 404, trzeci na 444, a kryterium świeciło na zielono.
        pg.goto(BAZA + "/admin/integracja", wait_until="networkidle")
        POMIAR_DZIALAN = """() => Array.from(document.querySelectorAll('.btn-row--jedna-linia'))
            .map(r => {
              const td = r.closest('td');
              const bs = Array.from(r.querySelectorAll('a.btn'));
              if (!td || bs.length === 0) { return null; }
              // ⚠ Czy rząd MIEŚCI SIĘ w komórce — kryterium dołożone po dowodzie przez łamanie.
              // Bez niego „jedna linia" przechodziła także przy kolumnie za wąskiej: rząd
              // z `nowrap` nie zawijał się, tylko wychodził poza komórkę. Jedna linia, która
              // wystaje, nie jest spełnieniem reguły — jest inną usterką.
              const st = getComputedStyle(td);
              const wnetrze = td.getBoundingClientRect().width
                - parseFloat(st.paddingLeft) - parseFloat(st.paddingRight);
              return {przyciskow: bs.length,
                      miesci_sie: Math.round(r.getBoundingClientRect().width) <= Math.ceil(wnetrze) + 1,
                      od_prawej: Math.round(td.getBoundingClientRect().right - bs[bs.length - 1].getBoundingClientRect().right),
                      wysokosc: Math.round(r.getBoundingClientRect().height),
                      w_jednej_linii: bs.every(x =>
                        Math.abs(x.getBoundingClientRect().top - bs[0].getBoundingClientRect().top) < 2)};
            }).filter(x => x !== null)"""
        wyrownanie = pg.evaluate(POMIAR_DZIALAN)
        # ⚠ Drugi pomiar przy 1920 px: to jest szerokość, przy której reguła „jedna linia"
        # ma obowiązywać. Bez niej kryterium przepuściłoby układ, w którym przyciski zawijają
        # się ZAWSZE — a wtedy kolumna działań byłaby po prostu za wąska.
        pg.set_viewport_size({"width": 1920, "height": 900})
        pg.goto(BAZA + "/admin/integracja", wait_until="networkidle")
        wyrownanie_1920 = pg.evaluate(POMIAR_DZIALAN)
        pg.set_viewport_size({"width": SZEROKOSC_LAPTOPA, "height": 900})

        # ⚠ Ekran potwierdzenia MUSI istnieć dla klucza unieważnionego.
        pg.goto(BAZA + f"/admin/integracja/klucze/{kid}/usun", wait_until="networkidle")
        ekran = pg.evaluate("""() => ({
          kod: document.body.innerText.indexOf('404') === 0,
          tytul: (document.querySelector('h1, h2') || {textContent: ''}).textContent.trim(),
          mowi_o_przebiegach: document.body.innerText.indexOf('Przebiegi synchronizacji') >= 0
            || document.body.innerText.indexOf('ani jednego przebiegu') >= 0,
          mowi_o_dzienniku: document.body.innerText.indexOf('dzienniku zdarzeń') >= 0,
        })""")

        # 4. Poprawne żądanie — klucz znika z wykazu.
        pg.goto(BAZA + "/admin/integracja", wait_until="networkidle")
        token = pg.evaluate("""() => (document.querySelector('input[name=_csrf]') || {}).value || ''""")
        kod_ok = usun({"_csrf": token, "potwierdzam": "1"})
        po_usunieciu = probka("stan", str(kid))
        pg.goto(BAZA + "/admin/integracja", wait_until="networkidle")
        w_wykazie = pg.evaluate("""(p) => document.body.innerText.indexOf(p) >= 0""", klucz["prefiks"])

        return {
            "ok": (
                kod_czynny == 404 and po_czynnym["status"] == "active"
                and repo_czynny["repozytorium_skasowalo"] is False and repo_czynny["istnieje_po"]
                and kod_bez_potw == 422 and kod_bez_csrf == 419
                and po_probach["istnieje"] and po_probach["status"] == "revoked"
                and not ekran["kod"] and ekran["tytul"] != ""
                and ekran["mowi_o_przebiegach"] and ekran["mowi_o_dzienniku"]
                and not po_usunieciu["istnieje"] and not w_wykazie
                # ⚠ Kolumna „Działania": oba rodzaje wiersza (jeden przycisk i trzy) dociśnięte
                # do prawej TYM SAMYM odstępem i mieszczące się w komórce.
                and len(wyrownanie) >= 2
                and len({w["od_prawej"] for w in wyrownanie}) == 1
                and all(w["miesci_sie"] for w in wyrownanie)
                and {w["przyciskow"] for w in wyrownanie} == {1, 3}
                # Przy 1440 px wolno zawinąć się na dwie linie (32 + odstęp + 32 = 72), nigdy na trzy.
                and max(w["wysokosc"] for w in wyrownanie) <= 72
                # Przy 1920 px jedna linia i ani piksela więcej niż wysokość przycisku.
                and len(wyrownanie_1920) >= 2
                and all(w["w_jednej_linii"] for w in wyrownanie_1920)
                and all(w["miesci_sie"] for w in wyrownanie_1920)
                and max(w["wysokosc"] for w in wyrownanie_1920) <= 40
            ),
            "klucz_bramki": klucz,
            "czynny_odmowa": kod_czynny,
            "stan_po_probie_na_czynnym": po_czynnym,
            "repozytorium_na_czynnym": repo_czynny,
            "bez_potwierdzenia": kod_bez_potw,
            "bez_csrf": kod_bez_csrf,
            "stan_po_probach": po_probach,
            "wyrownanie_kolumny_dzialanie": wyrownanie,
            "wyrownanie_przy_1920": wyrownanie_1920,
            "ekran_potwierdzenia": ekran,
            "po_skasowaniu": po_usunieciu,
            "prefiks_nadal_w_wykazie": w_wykazie,
        }
    except Exception as e:  # noqa: BLE001
        return {"ok": False, "powod": str(e), "klucz_bramki": klucz}
    finally:
        # ⚠ Pomiar sprząta po sobie także wtedy, gdy padł w połowie — i kasuje WYŁĄCZNIE
        # swój klucz (próbka dopasowuje nazwę).
        if klucz is not None:
            try:
                subprocess.run(["php", "dev/probka-klucza.php", "usun", str(klucz["id"])],
                               cwd=KORZEN, capture_output=True, text=True)
            except Exception:  # noqa: BLE001
                pass
        ctx.close()


def spolki_z_etatem(login: str) -> list[int]:
    """Identyfikatory spółek, w których konto ma **czynny etat** — z bazy, nie z ekranu.

    ⚠ Potrzebne do twierdzenia `jedna_droga_na_spolke`: warunek Krzysztofa z 11.10.2026 brzmi
    „konto z etatem przełącza zatrudnieniem, konto bez etatu spółką, i **nigdy dwie drogi dla
    tego samego konta**". Żeby to zmierzyć, a nie zadeklarować, bramka musi wiedzieć z bazy,
    ile spółek z etatem konto ma — inaczej liczyłaby zgodność panelu z samym panelem.
    """
    import subprocess
    kod = (
        'require "vendor/autoload.php";'
        'Dotenv\\Dotenv::createImmutable(__DIR__)->load();'
        '$e = $_ENV;'
        '$pdo = new PDO("mysql:host={$e[\'DB_HOST\']};port={$e[\'DB_PORT\']};dbname={$e[\'DB_NAME\']};charset=utf8mb4",'
        '  $e["DB_USER"], $e["DB_PASS"], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);'
        '$st = $pdo->prepare("SELECT DISTINCT c.id FROM company c'
        '  JOIN employment e ON e.company_id = c.id'
        '  JOIN account a ON a.person_id = e.person_id'
        '  WHERE a.email = :mail AND e.is_active = 1 AND c.is_active = 1 ORDER BY c.id");'
        '$st->execute(["mail" => $argv[1]]);'
        'echo json_encode(array_map("intval", array_column($st->fetchAll(), "id")));'
    )
    wyn = subprocess.run(["php", "-r", kod, login], cwd=KORZEN, capture_output=True, text=True, check=True)
    return json.loads(wyn.stdout or "[]")


def spolki_pracownika() -> list[dict]:
    """Spółki konta pracownika (LOGIN3) z nazwą z R2 i nazwą wyświetlaną — **z bazy**.

    ⚠ Kolejność po nazwie z R2, bo o kolejność toczy się pół pomiaru: żeby udowodnić sortowanie
    po nazwie WYŚWIETLANEJ, trzeba nadać nazwę spółce, która po nazwie z R2 stoi OSTATNIA,
    i zobaczyć, że przeskoczyła na początek. Identyfikatorów nie wpisujemy w bramkę — po resecie
    danych pilnowałaby nieistniejącej spółki.
    """
    import subprocess
    kod = (
        'require "vendor/autoload.php";'
        'Dotenv\\Dotenv::createImmutable(__DIR__)->load();'
        '$e = $_ENV;'
        '$pdo = new PDO("mysql:host={$e[\'DB_HOST\']};port={$e[\'DB_PORT\']};dbname={$e[\'DB_NAME\']};charset=utf8mb4",'
        '  $e["DB_USER"], $e["DB_PASS"], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);'
        '$st = $pdo->prepare("SELECT DISTINCT c.id, c.name, c.display_name FROM company c'
        '  JOIN employment e ON e.company_id = c.id'
        '  JOIN account a ON a.person_id = e.person_id'
        '  WHERE a.email = :mail AND e.is_active = 1 AND c.is_active = 1 ORDER BY c.name");'
        '$st->execute(["mail" => $argv[1]]);'
        'echo json_encode($st->fetchAll(), JSON_UNESCAPED_UNICODE);'
    )
    wyn = subprocess.run(["php", "-r", kod, LOGIN3], cwd=KORZEN, capture_output=True, text=True, check=True)
    return json.loads(wyn.stdout or "[]")


def sprawdz_nazwe_wyswietlana(b, usterka: str | None = None) -> dict:
    """Nazwa wyświetlana spółki podstawiona w zakładkach — i **sortowanie po niej** (06.10.2026).

    Prośba Krzysztofa: „jak jest wpisana nazwa spółki, która ma być wyświetlana, to będzie ona
    zastąpiona w poszczególnych zakładkach tam, gdzie teraz występuje nazwa pobrana przez
    integrację" + „tak, po wyświetlanej, aby było spójnie".

    ⚠ **Nazwę nadajemy EKRANEM, nie SQL-em** (`/ustawienia/spolki`). Wpis do bazy sprawdziłby
    tylko zapytania odczytu; przejście przez formularz sprawdza całą drogę, w tym trasę zapisu
    i jednoznaczność nazw. Gdyby zapis padł, pomiar ma być czerwony tutaj, a nie zielony dalej.

    ⚠ **Trzy kryteria, każde do złamania OSOBNO** — bo dwa potrafią się wzajemnie wyręczyć:
      1. `podstawienie` — ekran pokazuje nazwę wyświetlaną i NIE pokazuje nazwy z R2,
      2. `kolejnosc` — spółka z nazwą sortującą się pierwsza faktycznie stoi pierwsza, choć
         po nazwie z R2 była ostatnia. `SELECT` z nazwą wyświetlaną przy `ORDER BY c.name`
         przechodzi kryterium 1 i oblewa to — i dokładnie taki był stan przed tą zmianą,
      3. `wyjatek_kadry` — `/dane` pokazuje nazwę WYŚWIETLANĄ w treści wiersza, a nazwę z R2
         w **podpowiedzi** tej nazwy. ⚠ Tak brzmi decyzja Krzysztofa z Z76 (22.10.2026)
         i ZASTĘPUJE ona dawne „na `/dane` zostaje nazwa z R2 w treści": powód tamtej reguły
         się nie zmienił — nazwa z kadr jest tu treścią do porównania z programem kadrowym,
         więc zniknąć nie może — ale 46 znaków wersalikami w kolumnie wiersza rozpychało
         tabelę wobec sześcioznakowej nazwy własnej (zmierzone). Dlatego zostaje, tylko
         w `title`. Kryterium pilnuje OBU połówek: nazwa z R2 jest osiągalna i nie stoi
         w treści, a w treści stoi nazwa wyświetlana. Pominięcie którejkolwiek przepuściłoby
         albo „podmień wszędzie", albo powrót do rozpychania.

    ⚠ Dwa ekrany, dwie różne kontrolki i dwa różne repozytoria: `/kontekst` (wybór firmy przy
    logowaniu, `EmploymentRepository`) i kontrolka spółki w `/kalendarz`
    (`PermissionRepository::companiesWithPermission`). Jeden ekran dowodziłby jednego zapytania.

    ⚠ Pomiar sprząta po sobie: nazwa wyświetlana i nadane poziomy kalendarza wracają do stanu
    sprzed pomiaru, także gdy pomiar padnie w połowie (`finally`).
    """
    spolki = spolki_pracownika()
    if len(spolki) < 2:
        return {"ok": False, "powod": f"konto {LOGIN3} ma {len(spolki)} spółek — za mało, "
                                      "żeby zmierzyć kolejność; pomiaru NIE wykonano"}

    cel = spolki[-1]           # ostatnia po nazwie z R2
    inna = spolki[0]
    nazwa_r2 = str(cel["name"])
    # ⚠ Prefiks „Aaa-” nie jest ozdobą: nowa nazwa musi sortować się PRZED nazwą drugiej spółki,
    # inaczej kryterium kolejności przeszłoby także przy sortowaniu po nazwie z R2.
    nadana = "Aaa-proba-bramki"
    # ⚠ Wartość do oddania bierzemy z ekranu w `stan_przed`, nie tutaj: między odczytem bazy
    # a zapisem mieści się całe logowanie, a to dość czasu, żeby ktoś zmienił nazwę ręcznie.
    poprzednia_z_bazy = cel["display_name"]
    haslo = HASLO or haslo_dev(LOGIN)
    haslo_prac = HASLO3 or haslo_dev(LOGIN3)
    if not haslo_prac:
        return {"ok": False, "powod": f"brak hasła konta {LOGIN3} — ekranu wyboru firmy "
                                      "NIE zmierzono (hasła nie ma w repozytorium, §14)"}

    # ⚠ Usterkę kontrolną wstrzykujemy do OBU kontekstów: pomiar chodzi dwoma kontami
    # (administrator nadaje nazwę, pracownik ją widzi), a twierdzenie o `/dane` mierzy się
    # w drugim z nich.
    ctx = nowy_kontekst(b, usterka, viewport={"width": SZEROKOSC_LAPTOPA, "height": 900},
                        ignore_https_errors=True)
    ctx2 = nowy_kontekst(b, usterka, viewport={"width": SZEROKOSC_LAPTOPA, "height": 900},
                         ignore_https_errors=True)
    nadane = False
    poziomy: list[tuple[int, int]] = []
    try:
        # Kontrolka spółki w kalendarzu pokazuje się tylko kontu, które ma podgląd w WIĘCEJ niż
        # jednej spółce — więc nadajemy poziom w obu i oddajemy go w `finally`.
        for sp in spolki:
            stan = poziom_kalendarza(int(sp["id"]), 2, LOGIN)
            poziomy.append((int(sp["id"]), int(stan["przed"])))

        pg = ctx.new_page()
        pg.goto(BAZA + "/logowanie", wait_until="networkidle")
        pg.fill("#login-email", LOGIN)
        pg.fill("#login-password", haslo)
        pg.click("button[type=submit]")
        pg.wait_for_load_state("networkidle")

        def zapisz(wartosc: str) -> str:
            # ⚠ **Od Z29 nazwy zapisuje OKNO, a nie wiersz tabeli** (`/ustawienia/spolki`
            # przekierowuje na `/ustawienia?okno=nazwy`, gdzie serwer renderuje `<dialog open>`).
            # Pole zostało z tym samym `aria-label`, więc selektor jest ten sam; zmienił się
            # przycisk: jeden „Zapisz nazwy" dla całego zestawu zamiast guzika przy polu.
            pg.goto(BAZA + "/ustawienia/spolki", wait_until="networkidle")
            pole = pg.locator(f'input[aria-label="Nazwa wyświetlana dla {nazwa_r2}"]')
            pole.fill(wartosc)
            pg.click("#okno-nazwy button[type=submit]")
            pg.wait_for_load_state("networkidle")
            # ⚠ **Komunikat odmowy stoi PRZY POLU, nie w pasku strony** (od Z29): okno zapisuje
            # kilka firm naraz, więc pasek może powiedzieć tylko „nic nie zapisano", a to, CO
            # jest nie tak z konkretną nazwą, mówi zdanie pod jej polem. Kryterium pyta o drugie,
            # bo to jego czyta człowiek, który właśnie wpisał kolidującą nazwę.
            return pg.evaluate("""() => {
              const przy_polu = Array.from(document.querySelectorAll('.field--error .field__hint'))
                .map(x => x.textContent.trim()).filter(t => t !== '');
              if (przy_polu.length > 0) { return przy_polu[0]; }
              const n = Array.from(document.querySelectorAll('.notice'))
                .map(x => x.textContent.trim()).filter(t => t !== '');
              return n.length > 0 ? n[0] : '';
            }""")

        def wartosc_pola() -> str:
            pg.goto(BAZA + "/ustawienia/spolki", wait_until="networkidle")
            return pg.locator(f'input[aria-label="Nazwa wyświetlana dla {nazwa_r2}"]').input_value()

        def przywroc() -> str:
            """Oddanie nazwy — ale **tylko jeśli w polu stoi nadal nasza próbna nazwa**.

            ⚠ To nie jest ostrożność teoretyczna. 06.10.2026 pomiar prowadzony skryptem nadpisał
            nazwę wyświetlaną wpisaną ręcznie przez człowieka: skrypt zapisał „stan przed" PO
            swoim zapisie, więc przywrócił wartość, która nigdy nie była stanem wyjściowym.
            Dziennik nie zapisuje „z czego na co", więc straty nie dało się nawet odtworzyć.
            Dlatego sprzątanie **sprawdza**, co w polu stoi, i cudzej wartości nie rusza.
            """
            teraz = wartosc_pola()
            if teraz != nadana:
                return (f"WSTRZYMANE — w polu stoi {teraz!r}, nie nasza nazwa próbna; "
                        "ktoś zmienił ją w trakcie pomiaru i NIE nadpisujemy tego")
            zapisz(stan_przed)
            return "przywrócono: " + (stan_przed if stan_przed != "" else "(puste)")

        # ⚠ Stan wyjściowy czytany **przed** jakimkolwiek zapisem — i z EKRANU, nie z pamięci.
        stan_przed = wartosc_pola()
        komunikat = zapisz(nadana)
        nadane = True

        # Ekran wyboru firmy — kontem PRACOWNIKA, bo tylko ono ma zatrudnienie w dwóch spółkach.
        pg2 = ctx2.new_page()
        pg2.goto(BAZA + "/logowanie", wait_until="networkidle")
        pg2.fill("#login-email", LOGIN3)
        pg2.fill("#login-password", haslo_prac)
        pg2.click("button[type=submit]")
        pg2.wait_for_load_state("networkidle")
        pg2.goto(BAZA + "/kontekst", wait_until="networkidle")
        kontekst = pg2.evaluate("""() => ({
          pozycje: Array.from(document.querySelectorAll('.settings-list__copy strong'))
            .map(e => e.textContent.trim()),
          tekst: document.querySelector('main').innerText
        })""")
        # ⚠ Pulpit mierzymy PO wybraniu kontekstu, bo nagłówek pulpitu bierze nazwę z INNEGO
        # zapytania (`PracownikRepository::mojeZatrudnienie`) niż lista wyboru firmy. Zmierzone
        # 06.10.2026: z pominiętym pulpitem bramka przeszła na zielono przy nazwie z R2
        # wstawionej z powrotem do tego jednego zapytania — jeden ekran mniej to jedno
        # zapytanie bez dowodu.
        # ⚠ Wiersz wybieramy po NAZWIE, nie po identyfikatorze w `action`: tam stoi identyfikator
        # ZATRUDNIENIA, nie spółki — pomyłka dawała pomiar, który nigdy nie kliknął.
        pg2.locator("button.settings-list__row", has_text=nadana).first.click()
        pg2.wait_for_load_state("networkidle")
        pg2.goto(BAZA + "/", wait_until="networkidle")
        pulpit_tekst = pg2.evaluate("() => document.querySelector('main').innerText")

        # Kontrolka spółki w kalendarzu — inne repozytorium, ta sama reguła.
        # ⚠ Spółkę **ustawiamy znacznikiem**, a nie `?spolka=` w adresie: ten parametr jest
        # widokiem na jedno żądanie i znacznik pokazuje wtedy dalej wybór konta (§1 zlecenia
        # z 10.10.2026). Pomiar czyta nazwę ze znacznika, więc musi mierzyć stan po wyborze.
        pg.goto(BAZA + "/kalendarz?widok=firma", wait_until="networkidle")
        spolka_przed_pomiarem = pg.evaluate(
            "() => ((document.querySelector('.spolka') || {}).dataset || {}).spolka || ''")
        ustaw_spolke_w_belce(pg, int(cel["id"]))
        kal = pg.evaluate("""() => ({
          przycisk: ((document.querySelector('.spolka__nazwa') || {}).textContent || '').trim(),
          lista: Array.from(document.querySelectorAll('.spolka__pozycja')).map(a => a.textContent.trim()),
          tekst: document.querySelector('main').innerText
        })""")

        # `/dane` to podgląd tego, co przysłał connector — nazwa z R2 ZOSTAJE, ale od Z76
        # w podpowiedzi nazwy, nie w treści wiersza.
        pg.goto(BAZA + "/dane", wait_until="networkidle")
        dane_tekst = pg.evaluate("() => document.querySelector('main').innerText")
        dane_nazwy = pg.evaluate(
            "() => [...document.querySelectorAll('.dane-firma__nazwa')].map((e) => ({"
            " tekst: (e.textContent || '').trim(), title: e.getAttribute('title') || ''}))")

        podstawienie = (
            nadana in kontekst["tekst"] and nazwa_r2 not in kontekst["tekst"]
            and nadana in pulpit_tekst and nazwa_r2 not in pulpit_tekst
            and kal["przycisk"] == nadana
            and nadana in kal["tekst"] and nazwa_r2 not in kal["tekst"]
        )
        # ⚠ Kolejność liczona z PIERWSZEJ pozycji, nie z obecności na liście: lista zawiera obie
        # spółki przy każdym sortowaniu, więc „jest na liście" nie mierzy niczego.
        kolejnosc = (
            len(kontekst["pozycje"]) >= 2 and kontekst["pozycje"][0] == nadana
            and len(kal["lista"]) >= 2 and kal["lista"][0] == nadana
        )
        # ⚠ Szukamy WIERSZA TEJ spółki, a nie „czy nazwa gdzieś jest": na liście stoją wszystkie
        # firmy tenanta, więc obecność dowolnej nazwy nie mówi nic o tej jednej.
        wiersz_celu = next((w for w in dane_nazwy if w["tekst"] == nadana), None)
        wyjatek_kadry = (wiersz_celu is not None
                         and wiersz_celu["title"] == nazwa_r2
                         and nazwa_r2 not in dane_tekst)

        # Sprzątanie w ścieżce normalnej, żeby jego WYNIK trafił do raportu; `finally` zostaje
        # jako ścieżka awaryjna (pomiar padł w połowie) — tam wyniku nie ma już gdzie zapisać.
        sprzatanie = przywroc()
        nadane = False
        # ⚠ **Pomiar sprząta także po WYBORZE SPÓŁKI** — od 10.10.2026 kontekst jest trwały
        # (`account.context_company_id`), więc zostawiona zmiana wędruje do następnych
        # sprawdzeń. Zmierzone: `grupy` i dwa sprawdzenia kalendarza czerwone, bo mierzyły
        # spółkę, którą ten pomiar im ustawił.
        if spolka_przed_pomiarem != "":
            pg.goto(BAZA + "/kalendarz?widok=firma", wait_until="networkidle")
            ustaw_spolke_w_belce(pg, int(spolka_przed_pomiarem))

        return {
            "ok": podstawienie and kolejnosc and wyjatek_kadry,
            "podstawienie": podstawienie,
            "kolejnosc": kolejnosc,
            "wyjatek_kadry": wyjatek_kadry,
            "spolka": {"id": int(cel["id"]), "nazwa_r2": nazwa_r2, "nadana": nadana,
                       "pierwsza_po_nazwie_r2": str(inna["name"])},
            "komunikat_zapisu": komunikat,
            "kontekst_pozycje": kontekst["pozycje"],
            "pulpit_naglowek": pulpit_tekst.split("\n")[2] if len(pulpit_tekst.split("\n")) > 2 else "",
            "kalendarz_przycisk": kal["przycisk"],
            "kalendarz_lista": kal["lista"],
            # ⚠ Dwa pola, nie jedno: „nazwa z R2 w treści" (ma być `false` od Z76) i wiersz
            # tej spółki z `/dane` razem z podpowiedzią (tam ma stać nazwa z R2).
            "dane_nazwa_r2_w_tresci": nazwa_r2 in dane_tekst,
            "dane_wiersz_celu": wiersz_celu,
            "stan_przed_pomiarem": stan_przed,
            # ⚠ Pokazane obok siebie świadomie: różnica znaczy, że ktoś zmienił nazwę MIĘDZY
            # odczytem bazy a wejściem na ekran — i że to ekran, a nie baza, jest tu prawdą.
            "stan_przed_w_bazie": "" if poprzednia_z_bazy is None else str(poprzednia_z_bazy),
            "sprzatanie": sprzatanie,
        }
    except Exception as e:  # noqa: BLE001
        # ⚠ Czerwona bramka musi powiedzieć DLACZEGO. Najczęstszy powód upadku tego pomiaru nie
        # jest usterką produktu, tylko tym, że ktoś zmienił nazwę wyświetlaną w trakcie przebiegu —
        # a wtedy komunikat „nie znalazłem Aaa-proba-bramki" prowadzi na całkiem złe śledztwo.
        # Dlatego do powodu dokładamy to, co w polu FAKTYCZNIE stoi.
        try:
            w_polu = wartosc_pola()
        except Exception:  # noqa: BLE001
            w_polu = "(nie udało się odczytać)"
        kontekst = ("" if w_polu == nadana
                    else f" ⚠ W polu nazwy wyświetlanej stoi {w_polu!r}, a nie nasza nazwa próbna "
                         f"{nadana!r} — ktoś zmienił ją w trakcie pomiaru. To nie jest usterka "
                         "produktu; powtórz przebieg bez równoległej pracy na tym ekranie.")
        return {"ok": False, "powod": str(e) + kontekst, "w_polu_nazwy": w_polu}
    finally:
        if nadane:
            try:
                przywroc()
            except Exception:  # noqa: BLE001
                pass
        for spolka_id, przed in poziomy:
            try:
                poziom_kalendarza(spolka_id, przed, LOGIN)
            except Exception:  # noqa: BLE001
                pass
        ctx2.close()
        ctx.close()


def sprawdz_wybor_spolki(b) -> dict:
    """**Jeden globalny wybór spółki** (zlecenie `2026-10-10-globalny-wybor-spolki.md` §1).

    ⚠ **Zastępuje kryterium `kontrolka_spolki`** z 06.10.2026 („ta sama kontrolka w nagłówku
    kalendarza i hierarchii"). Tamto pilnowało stanu, który to zlecenie **cofnęło**: kontrolki
    z nagłówków ekranów znikły, a wybór spółki stoi w jednym znaczniku w górnej belce. Dlatego
    kryterium jest **wymienione, nie usunięte** — gdyby zniknęło bez następcy, nikt by nie
    zauważył powrotu drugiej kontrolki na ekran.

    ⚠ **Pięć twierdzeń, każde do złamania osobno:**
      1. `jeden_znacznik` — na każdym mierzonym ekranie stoi **dokładnie jeden** znacznik
         spółki, w belce (nie w `.page__head`), i **zero** innych kontrolek wyboru spółki
         (`.combo`, `select[name=spolka]`). To jest cel zmiany, nie efekt uboczny,
      2. `bezwladny_na_tenancie` — na ekranie o zakresie `tenant` znacznik **nie rozwija się**
         i mówi „Wszystkie firmy". Bez tego kłamałby na Licencji i Integracji, sugerując dane
         jednej spółki,
      3. `przelacza` — wybór pozycji **zmienia widoczną treść** ekranu, nie tylko napis
         w znaczniku. Sam napis to jedno przypisanie do sesji: wystarczy, że ekran dalej czyta
         spółkę po staremu, i znacznik pokazuje jedno, a lista drugie,
      4. `trwa` — wybór przeżywa **zmianę ekranu** i **ponowne zalogowanie** (kolumna
         `account.context_company_id`, nie sama sesja),
      5. `zapomniana_spolka` — spółka zapamiętana, do której konto **straciło dostęp**, nie jest
         pokazywana, a ekran **mówi o tym** (`.spolka__uwaga`). Stan tworzy
         `dev/probka-spolki.php` na własnych obiektach (własna osoba, własne konto, własne
         uprawnienia) i sam je kasuje — bramka nie odbiera dostępu nikomu, kto pracuje,
      6. `jedna_droga_na_spolke` — ⚠ **warunek dopisany przez Krzysztofa 11.10.2026** przy
         dopuszczeniu drugiego kształtu przełączenia: „nigdy dwie drogi dla tego samego konta —
         konto z etatem przełącza zatrudnieniem, konto bez etatu spółką, i bramka ma tego
         pilnować". Mierzone na DWÓCH kontach, bo jedno nie pokazałoby obu kształtów:
         administrator tenanta (zero etatów → wszystkie pozycje przez `POST /kontekst/spolka/{id}`)
         i konto próbne z etatem w jednej spółce i nadanym podglądem w drugiej (dokładnie
         **jedna** pozycja przez `POST /kontekst/{employmentId}`). Warunek jest podwójny:
         **żadna spółka nie występuje w panelu dwa razy** ani w dwóch drogach naraz, a liczba
         pozycji „przez zatrudnienie" **równa się liczbie spółek z czynnym etatem w bazie** —
         bez tej drugiej połowy panel zgadzałby się sam z sobą.
    """
    import subprocess

    EKRANY_SPOLKOWE = ["/admin/grupy", "/admin/hierarchia", "/admin/konta", "/kalendarz"]
    EKRANY_TENANTOWE = ["/licencja", "/admin/integracja", "/dane", "/diagnostyka",
                        "/admin/dziennik", "/ustawienia"]
    POMIAR = r"""() => {
      const z = document.querySelector('.spolka');
      const glowa = document.querySelector('.page__head');
      const belka = document.querySelector('.topbar, .m-topbar');
      return {
        znacznikow: document.querySelectorAll('.spolka').length,
        w_belce: !!(z && belka && belka.contains(z)),
        w_naglowku_strony: !!(z && glowa && glowa.contains(z)),
        rozwijalny: z ? z.tagName === 'DETAILS' : null,
        nazwa: z ? ((z.querySelector('.spolka__nazwa') || {}).textContent || '').trim() : null,
        uwaga: !!document.querySelector('.spolka__uwaga'),
        stare: document.querySelectorAll('.combo, select[name=spolka]').length,
        tresc: ((document.querySelector('.page') || document.querySelector('main') || {})
                 .textContent || '').replace(/\s+/g, ' ').trim().slice(0, 600),
      };
    }"""

    # ⚠ Czytamy **akcję formularza**, nie wygląd pozycji: dwie drogi do tej samej spółki wyglądają
    # identycznie, a różnią się adresem. `d.open` przywracamy, żeby pomiar nie zostawiał panelu
    # otwartego następnemu krokowi (ten sam nawyk co przy stanie trwałym).
    DROGI = r"""() => {
      const d = document.querySelector('details.spolka');
      if (!d) { return null; }
      const bylo = d.open;
      d.open = true;
      const poz = Array.from(document.querySelectorAll('.spolka__pozycja')).map(b => {
        const f = b.closest('form');
        const akcja = f ? (f.getAttribute('action') || '') : '';
        return {nazwa: b.textContent.trim(), akcja,
                droga: /\/kontekst\/spolka\/\d+$/.test(akcja) ? 'spolka'
                     : (/\/kontekst\/\d+$/.test(akcja) ? 'zatrudnienie' : 'inna')};
      });
      d.open = bylo;
      return poz;
    }"""

    def jedna_droga(poz, etatow_w_bazie):
        """Jedna spółka = jedna droga, a liczba dróg „przez zatrudnienie" = prawda z bazy."""
        if not poz:
            return False, {"powod": "panel bez pozycji"}
        nazwy = [x["nazwa"] for x in poz]
        drogi_spolki: dict[str, set[str]] = {}
        for x in poz:
            drogi_spolki.setdefault(x["nazwa"], set()).add(x["droga"])
        przez_etat = sum(1 for x in poz if x["droga"] == "zatrudnienie")
        wynik = (
            len(nazwy) == len(set(nazwy))
            and all(x["droga"] in ("spolka", "zatrudnienie") for x in poz)
            and all(len(v) == 1 for v in drogi_spolki.values())
            and przez_etat == etatow_w_bazie
        )
        return wynik, {"pozycji": len(poz), "nazw_roznych": len(set(nazwy)),
                       "przez_zatrudnienie": przez_etat, "etatow_w_bazie": etatow_w_bazie,
                       "drogi": {k: sorted(v) for k, v in drogi_spolki.items()}}

    def zaloguj(pg, login, haslo):
        pg.goto(BAZA + "/logowanie", wait_until="networkidle")
        pg.fill("#login-email", login)
        pg.fill("#login-password", haslo)
        pg.click("button[type=submit]")
        pg.wait_for_load_state("networkidle")
        if "/kontekst" in pg.url:
            pg.locator("button.settings-list__row").first.click()
            pg.wait_for_load_state("networkidle")

    def wyloguj(pg):
        # ⚠ Wylogowanie to POST (CLAUDE.md §3) — klikamy w interfejsie, nie wchodzimy adresem.
        menu = pg.locator("details:has(form[action$='/wyloguj']) > summary")
        if menu.count() > 0:
            menu.first.click()
        pg.locator("form[action$='/wyloguj'] button[type=submit]").first.click()
        pg.wait_for_load_state("networkidle")

    def probka(tryb, *reszta):
        wyn = subprocess.run(["php", "dev/probka-spolki.php", tryb, *reszta],
                             cwd=KORZEN, capture_output=True, text=True)
        if wyn.returncode != 0 or not wyn.stdout.strip():
            raise RuntimeError(f"probka-spolki.php {tryb} kod={wyn.returncode}: "
                               f"{(wyn.stderr or wyn.stdout).strip()[:300]}")
        return json.loads(wyn.stdout)

    haslo_admin = HASLO or haslo_dev(LOGIN)
    if not haslo_admin:
        return {"ok": False, "powod": f"brak hasła konta {LOGIN} — pomiaru NIE wykonano"}
    ctx = None
    ctx2 = None
    dane_probki = None
    try:
        ctx = b.new_context(viewport={"width": SZEROKOSC_LAPTOPA, "height": 1000}, ignore_https_errors=True)
        pg = ctx.new_page()
        zaloguj(pg, LOGIN, haslo_admin)

        ekrany = {}
        for adres in EKRANY_SPOLKOWE + EKRANY_TENANTOWE:
            r = pg.goto(BAZA + adres, wait_until="networkidle")
            ekrany[adres] = {"kod": r.status, **pg.evaluate(POMIAR)}

        jeden_znacznik = all(
            e["kod"] == 200 and e["znacznikow"] == 1 and e["stare"] == 0
            and e["w_belce"] and not e["w_naglowku_strony"]
            for e in ekrany.values()
        )
        # ⚠ Napis pilnowany SŁOWEM (patrz `SLOWO_UZYTKOWNIKA`): musi mówić „wszystkie" i „firm",
        # i nie wolno mu wrócić do „spółki". Całe zdanie w kryterium znaczyło piąty egzemplarz
        # tego samego napisu — a zmiana słownictwa zapalała bramkę na poprawnym kodzie.
        bezwladny_na_tenancie = all(
            ekrany[a]["rozwijalny"] is False
            and "wszystkie" in (ekrany[a]["nazwa"] or "").lower()
            and SLOWO_UZYTKOWNIKA in (ekrany[a]["nazwa"] or "").lower()
            and not ma_slowo_spolka(ekrany[a]["nazwa"])
            for a in EKRANY_TENANTOWE
        ) and all(ekrany[a]["rozwijalny"] is True for a in EKRANY_SPOLKOWE)

        # 3. Przełączenie zmienia TREŚĆ — mierzone na grupach, bo tam skład list różni się
        #    między spółkami w sposób widoczny bez żadnych uprawnień dodatkowych.
        ADRES = "/admin/grupy"
        pg.goto(BAZA + ADRES, wait_until="networkidle")
        przed = pg.evaluate(POMIAR)
        # ⚠ Drogi przełączenia czytamy PRZED kliknięciem w pozycję: po przełączeniu panel
        # i tak pokazuje to samo, ale pomiar ma stać tam, gdzie stan jest zastany.
        drogi_admin = pg.evaluate(DROGI)
        ok_admin, opis_admin = jedna_droga(drogi_admin, len(spolki_z_etatem(LOGIN)))
        pg.click(".spolka__trigger")
        pozycje = pg.locator(".spolka__pozycja")
        cel = None
        for i in range(pozycje.count()):
            t = pozycje.nth(i).text_content().strip()
            if t and t != przed["nazwa"]:
                cel = (i, t)
                break
        if cel is None:
            return {"ok": False, "powod": f"konto {LOGIN} widzi jedną spółkę — nie ma czego przełączać"}
        pozycje.nth(cel[0]).click()
        pg.wait_for_load_state("networkidle")
        po = pg.evaluate(POMIAR)
        przelacza = (po["nazwa"] == cel[1] and po["nazwa"] != przed["nazwa"]
                     and po["tresc"] != przed["tresc"]
                     # ⚠ Przełączenie wraca na TEN SAM ekran, nie na pulpit: inaczej kontrolka
                     # „działa", a człowiek traci miejsce pracy przy każdym użyciu.
                     and pg.url.replace(BAZA, "").startswith(ADRES))

        # 4. Trwałość: inny ekran, potem ponowne zalogowanie.
        pg.goto(BAZA + "/diagnostyka", wait_until="networkidle")
        pg.goto(BAZA + ADRES, wait_until="networkidle")
        po_ekranie = pg.evaluate(POMIAR)["nazwa"]
        wyloguj(pg)
        zaloguj(pg, LOGIN, haslo_admin)
        pg.goto(BAZA + ADRES, wait_until="networkidle")
        po_logowaniu = pg.evaluate(POMIAR)["nazwa"]
        trwa = po_ekranie == cel[1] and po_logowaniu == cel[1]
        # ⚠ Pomiar sprząta po sobie: wybór wraca na spółkę, którą konto miało przed bramką.
        pg.click(".spolka__trigger")
        pg.locator(".spolka__pozycja", has_text=przed["nazwa"]).first.click()
        pg.wait_for_load_state("networkidle")
        ctx.close()
        ctx = None

        # 5. Zapamiętana spółka, do której konto straciło dostęp — na obiektach bramki.
        dane_probki = probka("utworz")
        ctx2 = b.new_context(viewport={"width": SZEROKOSC_LAPTOPA, "height": 1000}, ignore_https_errors=True)
        p2 = ctx2.new_page()
        zaloguj(p2, dane_probki["login"], dane_probki["haslo"])
        p2.goto(BAZA + "/kalendarz", wait_until="networkidle")
        pr_przed = p2.evaluate(POMIAR)
        poz = p2.locator(".spolka__pozycja")
        cel2 = None
        for i in range(poz.count()):
            t = poz.nth(i).text_content().strip()
            if t and t != pr_przed["nazwa"]:
                cel2 = t
                break
        if cel2 is None:
            return {"ok": False, "powod": "konto próbne widzi jedną spółkę — dowodu NIE wykonano"}
        p2.click(".spolka__trigger")
        p2.locator(".spolka__pozycja", has_text=cel2).first.click()
        p2.wait_for_load_state("networkidle")
        p2.goto(BAZA + "/kalendarz", wait_until="networkidle")
        pr_wybrana = p2.evaluate(POMIAR)
        # ⚠ Konto próbne to jedyne miejsce, gdzie panel pokazuje OBA kształty naraz (etat
        # w jednej spółce, nadany podgląd w drugiej) — czyli jedyne, na którym da się złamać
        # warunek „nigdy dwie drogi dla tego samego konta".
        drogi_probki = p2.evaluate(DROGI)
        ok_probka, opis_probka = jedna_droga(drogi_probki, len(spolki_z_etatem(dane_probki["login"])))
        jedna_droga_na_spolke = ok_admin and ok_probka
        wyloguj(p2)
        odebrane = probka("odbierz", str(dane_probki["spolka_podgladu"]))
        zaloguj(p2, dane_probki["login"], dane_probki["haslo"])
        p2.goto(BAZA + "/kalendarz", wait_until="networkidle")
        pr_po = p2.evaluate(POMIAR)
        zapomniana_spolka = (
            pr_wybrana["nazwa"] == cel2
            and pr_po["nazwa"] != cel2
            # ⚠ Milczenie jest tu usterką: człowiek wybrał spółkę B, widzi A i musi wiedzieć,
            # dlaczego. Bez tego warunku „po cichu wróciliśmy do innej spółki" jest zielone.
            and pr_po["uwaga"] is True
            and pr_wybrana["uwaga"] is False
        )

        return {
            "ok": jeden_znacznik and bezwladny_na_tenancie and przelacza and trwa
                  and zapomniana_spolka and jedna_droga_na_spolke,
            "jeden_znacznik": jeden_znacznik,
            "bezwladny_na_tenancie": bezwladny_na_tenancie,
            "przelacza": przelacza,
            "trwa": trwa,
            "zapomniana_spolka": zapomniana_spolka,
            "jedna_droga_na_spolke": jedna_droga_na_spolke,
            "drogi_przelaczenia": {"administrator": {"ok": ok_admin, **opis_admin,
                                                     "pozycje": drogi_admin},
                                   "konto_probne": {"ok": ok_probka, **opis_probka,
                                                    "pozycje": drogi_probki}},
            "ekrany": {a: {k: e[k] for k in ("kod", "znacznikow", "stare", "w_belce",
                                             "w_naglowku_strony", "rozwijalny", "nazwa")}
                       for a, e in ekrany.items()},
            "przelaczenie": {"przed": przed["nazwa"], "po": po["nazwa"], "cel": cel[1],
                             "adres_po": pg.url.replace(BAZA, "") if ctx is not None else None,
                             "tresc_sie_zmienila": po["tresc"] != przed["tresc"]},
            "trwalosc": {"po_zmianie_ekranu": po_ekranie, "po_zalogowaniu": po_logowaniu},
            # ⚠ **Hasło próbki NIE wchodzi do raportu** (Z63 cz. A). Jest losowe i konto żyje
            # jeden przebieg, ale raport z bramki jedzie do drugiej sesji i do pliku — a hasło
            # w pliku jest hasłem w pliku (CLAUDE.md §14). Zostaje w pamięci przebiegu, bo
            # bramka musi się nim zalogować.
            "utracona": {"probka": {k: v for k, v in (dane_probki or {}).items() if k != "haslo"},
                         "wybrana": pr_wybrana["nazwa"],
                         "po_odebraniu": pr_po["nazwa"], "uwaga": pr_po["uwaga"],
                         "spolki_po_odebraniu": odebrane.get("spolki_po")},
        }
    except Exception as e:  # noqa: BLE001
        return {"ok": False, "powod": str(e)}
    finally:
        if dane_probki is not None:
            try:
                subprocess.run(["php", "dev/probka-spolki.php", "sprzataj"],
                               cwd=KORZEN, capture_output=True, text=True)
            except Exception:  # noqa: BLE001
                pass
        for k in (ctx, ctx2):
            if k is not None:
                try:
                    k.close()
                except Exception:  # noqa: BLE001
                    pass


def dane_paskow() -> dict:
    """Spółka i miesiąc z **największą liczbą nieobecności**, w tym jednodniowych — z bazy.

    ⚠ Miesiąc dobrany zapytaniem, nie wpisany: w miesiącu bez nieobecności pasków jest zero,
    a „wszystkie paski spełniają warunek" przechodzi wtedy na pusto (ta sama pułapka, którą
    złapaliśmy 06.10 przy rodzajach ogólnych). Wymagamy też przynajmniej jednego paska
    JEDNODNIOWEGO, bo na nim stoi twierdzenie o numerze dnia.
    """
    import subprocess
    kod = (
        'require "vendor/autoload.php";'
        'Dotenv\\Dotenv::createImmutable(__DIR__)->load();'
        '$e = $_ENV;'
        '$pdo = new PDO("mysql:host={$e[\'DB_HOST\']};port={$e[\'DB_PORT\']};dbname={$e[\'DB_NAME\']};charset=utf8mb4",'
        '  $e["DB_USER"], $e["DB_PASS"], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);'
        '$st = $pdo->query("SELECT em.company_id spolka, LEFT(a.date_from, 7) miesiac,'
        '    COUNT(*) ile, SUM(a.date_from = a.date_to) jednodniowych'
        '  FROM external_absence a JOIN leave_type t ON t.id = a.leave_type_id'
        '    JOIN employment em ON em.id = a.employment_id'
        '  WHERE a.removed_at IS NULL AND t.is_absence = 1'
        '  GROUP BY em.company_id, miesiac HAVING jednodniowych > 0'
        '  ORDER BY ile DESC LIMIT 1");'
        'echo json_encode($st->fetch() ?: []);'
    )
    wyn = subprocess.run(["php", "-r", kod], cwd=KORZEN, capture_output=True, text=True, check=True)
    return json.loads(wyn.stdout or "{}")


def sprawdz_paski_nieobecnosci(b, usterka: str | None = None) -> dict:
    """**Wygląd paska nieobecności** (zlecenie `2026-10-11-paski-nieobecnosci.md` §5).

    ⚠ **Następca kryterium kontrastu z 06.10**, które mierzyło obrys w kolorze rodzaju
    (`kontrast.paski[*].obrys_do_wypelnienia` w `sprawdz_zasieg_podgladu`). Obrysu od 11.10.2026
    nie ma — kolor niesie wypełnienie — więc tamten pomiar straciłby przedmiot i przechodziłby
    na pusto. Zastąpione, nie skasowane: ta sama kolejność co `stan_z_listy` → `jedno_zadanie`.

    ⚠ **Trzy twierdzenia, każde do złamania osobno:**
      1. `kontrast_tekstu` — napis na pasku ma kontrast ≥ 4,5 do wypełnienia, dla **każdego
         z trzech rodzajów**. Rodzaj `kadrowe` nie występuje w danych roboczych (zmierzone:
         zero wpisów), więc jego kontrast czyta sonda wstawiona w prawdziwą komórkę siatki —
         ten sam arkusz, bez udawania, że dana jest na ekranie,
      2. `kontrast_paska` — wypełnienie wobec tła wiersza na dniu **roboczym** (podłoga 1,12
         z reguły 1.14, sufit 3,0 jako wykrywacz błędnego pomiaru) **i** wobec tła dnia
         **wolnego** (podłoga 1,05, bo tam tło jest ciemniejsze), plus warunek strukturalny
         z 06.10: pasek musi być mocniejszym znakiem niż samo wygaszenie weekendu,
      3. `tlo_dni_pod_paskiem` — pod paskiem WIELODNIOWYM każdy dzień ma tło swojej kolumny:
         dzień wolny barwę dnia wolnego, dzień roboczy barwę zwykłej komórki, i tak samo **nad**
         paskiem, jak **pod** nim. ⚠ Twierdzenie dodane w Z68, bo komórka z paskiem obejmuje
         kilka kolumn i brała tło z PIERWSZEGO dnia zakresu: kolumna weekendu urywała się
         w połowie wiersza (nieobecność od dnia roboczego) albo wygaszała dni robocze
         (nieobecność od weekendu). Mierzone kolorem W PUNKCIE, nie klasą — przy innej
         implementacji tła ma działać bez zmiany. Barwy odniesienia to zmierzone tła zwykłej
         i wolnej komórki BEZ paska, więc kryterium nie zna żadnego kodu koloru,
      4. `numer_dnia_zgodny` — pasek jednodniowy niesie numer dnia **zgodny z kolumną**,
         w której stoi (rozstrzygane geometrią, nie kolejnością w DOM), i numer **nie jest
         ucięty** — ani przy 1440 px, ani na wąskim ekranie (374 i 320 px), gdzie dodatkowo
         pilnujemy **0 przewijania strony w poziomie**.
    """
    haslo_admin = HASLO or haslo_dev(LOGIN)
    if not haslo_admin:
        return {"ok": False, "powod": f"brak hasła konta {LOGIN} — pomiaru NIE wykonano"}
    dane = dane_paskow()
    if dane.get("spolka") is None or dane.get("miesiac") is None:
        return {"ok": False, "powod": "brak miesiąca z nieobecnościami — pomiaru NIE wykonano"}
    spolka, miesiac = int(dane["spolka"]), str(dane["miesiac"])
    # ⚠ Stan wyjściowy odczytany PRZED pierwszym zapisem (lekcja z 06.10) — klucz `przed`.
    poziom_przed = poziom_kalendarza(spolka, None, LOGIN).get("przed", 0)
    ctx = None
    try:
        # ⚠ Poziom 2 (ze szczegółem) nadajemy, bo bez niego konto administracyjne nie widzi
        # siatki firmy, a pasków na ekranie jest wtedy zero. Stan wyjściowy odczytany PRZED
        # nadaniem i przywracany w `finally` — kontekst i role są trwałe (lekcja z 11.10).
        poziom_kalendarza(spolka, 2, LOGIN)
        # ⚠ Kontekst przez `nowy_kontekst`, nie `b.new_context`: inaczej kryterium nie da się
        # uruchomić w trybie `kontrola` (zmierzone 21.10.2026 — kontrola przerwała się
        # na `TypeError`, bo funkcja nie przyjmowała nazwy usterki, a trzy kryteria dalej
        # w rejestrze nie zostały sprawdzone wcale).
        ctx = nowy_kontekst(b, usterka,
                            viewport={"width": SZEROKOSC_LAPTOPA, "height": 1000},
                            ignore_https_errors=True)
        pg = ctx.new_page()
        pg.goto(BAZA + "/logowanie", wait_until="networkidle")
        pg.fill("#login-email", LOGIN)
        pg.fill("#login-password", haslo_admin)
        pg.click("button[type=submit]")
        pg.wait_for_load_state("networkidle")
        # ⚠ `?spolka=` jako WIDOK na jedno żądanie (rozstrzygnięcie 10.10) — pomiar nie przestawia
        # kontekstu trwałego, więc nie zostawia stanu następnym sprawdzeniom.
        adres = f"/kalendarz?widok=firma&miesiac={miesiac}&spolka={spolka}"
        pg.goto(BAZA + adres, wait_until="networkidle")
        szeroki = pg.evaluate(POMIAR_PASKOW)

        # ⚠ Wąski ekran mierzony na **obu powłokach**: zwężonym widoku webowym i na `/m/kalendarz`,
        # bo zlecenie mówi wprost „`/m/*`, 374 px i 320 px". Powłoka mobilna dołącza TEN SAM
        # partial osi czasu, ale ma inną kartę i inne odstępy — a różnica kilku pikseli decyduje,
        # czy numer dnia się mieści. Mierzenie tylko zwężonego widoku webowego byłoby pomiarem
        # innego ekranu niż ten, o który pytało zlecenie.
        waskie = {}
        for etykieta, szer, prefiks in (("374", 374, ""), ("320", 320, ""),
                                        ("374-m", 374, "/m"), ("320-m", 320, "/m")):
            pg.set_viewport_size({"width": szer, "height": 812})
            pg.goto(BAZA + prefiks + adres, wait_until="networkidle")
            m = pg.evaluate(POMIAR_PASKOW)
            waskie[etykieta] = {"jednodniowych": m.get("jednodniowych"),
                                "ucietych": sum(1 for n in m.get("numery", []) if n["ucieta"]),
                                "zgodnych": sum(1 for n in m.get("numery", []) if n["zgodny"]),
                                "szerokosc_paska": sorted({n["szer"] for n in m.get("numery", [])}),
                                "strona_przewija": m.get("strona_przewija")}

        sondy = szeroki.get("sondy", {})
        zywe = szeroki.get("zywe", [])
        numery = szeroki.get("numery", [])
        wolny_do_wiersza = None
        if szeroki.get("tlo_wolnego") and szeroki.get("tlo_wiersza"):
            wolny_do_wiersza = pg.evaluate(
                """([a, b]) => {
                  const lum = (rgb) => {
                    const c = rgb.match(/\\d+/g).slice(0, 3).map(n => {
                      const x = Number(n) / 255;
                      return x <= 0.03928 ? x / 12.92 : Math.pow((x + 0.055) / 1.055, 2.4);
                    });
                    return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2];
                  };
                  const la = lum(a), lb = lum(b);
                  return +(((Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05)).toFixed(3));
                }""", [szeroki["tlo_wolnego"], szeroki["tlo_wiersza"]])

        trzy = all(r in sondy for r in ("urlop", "zasilek", "kadrowe"))
        kontrast_tekstu = (
            trzy
            and all(sondy[r]["k_tekst"] >= KONTRAST_TEKSTU_MIN for r in sondy)
            # ⚠ Paski ŻYWE muszą mieć te same liczby co sonda — inaczej sonda mierzyłaby arkusz,
            # a ekran rysował co innego (np. reguła nadpisana wyżej w kaskadzie).
            and zywe != []
            and all(p["k_tekst"] >= KONTRAST_TEKSTU_MIN for p in zywe)
            and all(p["waga"] in ("600", "bold") for p in zywe)
        )
        kontrast_paska = (
            trzy
            and all(KONTRAST_PASKA_MIN <= sondy[r]["k_do_wiersza"] <= KONTRAST_PASKA_MAX for r in sondy)
            and all(sondy[r]["k_do_wolnego"] >= KONTRAST_NA_WOLNYM_MIN for r in sondy)
            and wolny_do_wiersza is not None
            # Warunek strukturalny z 06.10: pasek jest mocniejszym znakiem niż wygaszenie weekendu.
            and all(sondy[r]["k_do_wiersza"] > wolny_do_wiersza for r in sondy)
            and zywe != []
            and all(KONTRAST_PASKA_MIN <= p["k_do_wiersza"] <= KONTRAST_PASKA_MAX for p in zywe)
        )
        # ⚠ **Tło dzień po dniu pod paskiem** (Z68). Porównanie z barwami ODNIESIENIA
        # zmierzonymi na komórkach bez paska — nie z kodem koloru, żeby zmiana tokena nie
        # wymagała ruszania bramki. Wymagamy obu rodzajów dni w próbkach: gdyby na ekranie
        # wypadły same dni robocze, twierdzenie przechodziłoby na pusto.
        dni_pod = [d for d in szeroki.get("pod_paskiem") or []
                   if d["tlo_nad"] is not None and d["tlo_pod"] is not None]
        tlo_dni_pod_paskiem = (
            dni_pod != []
            and any(d["wolny"] for d in dni_pod)
            and any(not d["wolny"] for d in dni_pod)
            and all(
                (d["tlo_nad"] == szeroki["tlo_wolnego"] and d["tlo_pod"] == szeroki["tlo_wolnego"])
                if d["wolny"]
                else (d["tlo_nad"] == szeroki["tlo_wiersza"] and d["tlo_pod"] == szeroki["tlo_wiersza"])
                for d in dni_pod)
        )
        numer_dnia_zgodny = (
            numery != []
            and all(n["zgodny"] for n in numery)
            and all(not n["ucieta"] for n in numery)
            and all(w["jednodniowych"] == len(numery) and w["zgodnych"] == len(numery)
                    and w["ucietych"] == 0 and w["strona_przewija"] is False
                    for w in waskie.values())
        )
        # ⚠ Legenda tymi samymi tokenami co paski (§3): próbka rodzaju ma mieć DOKŁADNIE to tło,
        # które ma pasek tego rodzaju. Inaczej legenda opisuje kolor, którego na ekranie nie ma.
        legenda = {m["klasa"].split("--")[-1]: m["tlo"] for m in szeroki.get("legenda", [])
                   if "--" in m["klasa"]}
        legenda_zgodna = trzy and all(
            legenda.get(r) == sondy[r]["tlo"] for r in ("urlop", "zasilek", "kadrowe"))
        return {
            "ok": (kontrast_tekstu and kontrast_paska and tlo_dni_pod_paskiem
                   and numer_dnia_zgodny and legenda_zgodna),
            "kontrast_tekstu": kontrast_tekstu,
            "kontrast_paska": kontrast_paska,
            "tlo_dni_pod_paskiem": tlo_dni_pod_paskiem,
            "numer_dnia_zgodny": numer_dnia_zgodny,
            "legenda_zgodna": legenda_zgodna,
            "ekran": adres,
            "tlo_wiersza": szeroki.get("tlo_wiersza"),
            "tlo_wolnego": szeroki.get("tlo_wolnego"),
            # Próbki tła pod paskiem: ile zgodnych, ile nie i pierwsze niezgodne z nazwą dnia.
            "dni_pod_paskiem": {
                "probek": len(dni_pod),
                "wolnych": sum(1 for d in dni_pod if d["wolny"]),
                "pominietych_poza_oknem": szeroki.get("pominietych"),
                "niezgodne": [d for d in dni_pod if (
                    (d["tlo_nad"], d["tlo_pod"]) != (szeroki["tlo_wolnego"], szeroki["tlo_wolnego"])
                    if d["wolny"]
                    else (d["tlo_nad"], d["tlo_pod"]) != (szeroki["tlo_wiersza"], szeroki["tlo_wiersza"])
                )][:6],
            },
            "dzien_wolny_do_wiersza": wolny_do_wiersza,
            "sondy": sondy,
            "paskow_na_ekranie": szeroki.get("paskow"),
            "rodzaje_na_ekranie": sorted({p["rodzaj"] for p in zywe}),
            "jednodniowych": len(numery),
            "numery": numery[:14],
            "waskie_ekrany": waskie,
            "legenda": legenda,
        }
    except Exception as e:  # noqa: BLE001
        return {"ok": False, "powod": str(e)}
    finally:
        try:
            poziom_kalendarza(spolka, poziom_przed, LOGIN)
        except Exception:  # noqa: BLE001
            pass
        if ctx is not None:
            try:
                ctx.close()
            except Exception:  # noqa: BLE001
                pass


def nieobecnosci_pracownika(employmentId: int, od: str, do: str) -> dict:
    """Nieobecności jednego zatrudnienia w zakresie dat — **z bazy, nie z ekranu**.

    ⚠ Twierdzenie „dane, nie pustka" (zlecenie §3a.2) ma sens tylko wtedy, gdy liczba, z którą
    porównujemy, pochodzi **spoza** mierzonego szablonu. Skala roku pokazująca zero kratek
    przejdzie każdy test na kształt — i dokładnie to jest usterka, której szukamy.

    `wpisow` to pozycje z kadr (odpowiednik wiersza `external_absence`), `dni` to liczba dni
    KALENDARZOWYCH wpadających w zakres (po przycięciu wpisu do zakresu) — czyli tyle kratek
    ma być pokolorowanych.
    """
    import subprocess
    kod = (
        'require "vendor/autoload.php";'
        'Dotenv\\Dotenv::createImmutable(__DIR__)->load();'
        '$e = $_ENV;'
        '$pdo = new PDO("mysql:host={$e[\'DB_HOST\']};port={$e[\'DB_PORT\']};dbname={$e[\'DB_NAME\']};charset=utf8mb4",'
        '  $e["DB_USER"], $e["DB_PASS"], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);'
        '$st = $pdo->prepare("SELECT COUNT(*) wpisow,'
        '    COALESCE(SUM(DATEDIFF(LEAST(a.date_to, :do2), GREATEST(a.date_from, :od2)) + 1), 0) dni,'
        '    COALESCE(SUM(CASE WHEN a.date_from >= :od3 AND a.date_from <= :do3 THEN a.days ELSE 0 END), 0) dni_kadr'
        '  FROM external_absence a JOIN leave_type t ON t.id = a.leave_type_id'
        '  WHERE a.employment_id = :z AND a.removed_at IS NULL AND t.is_absence = 1'
        '    AND a.date_from <= :do AND a.date_to >= :od");'
        '$st->execute(["z" => (int) $argv[1], "od" => $argv[2], "do" => $argv[3],'
        '  "od2" => $argv[2], "do2" => $argv[3], "od3" => $argv[2], "do3" => $argv[3]]);'
        'echo json_encode($st->fetch());'
    )
    wyn = subprocess.run(["php", "-r", kod, str(employmentId), od, do],
                         cwd=KORZEN, capture_output=True, text=True, check=True)
    dane = json.loads(wyn.stdout or "{}")
    return {k: int(v) for k, v in dane.items()}


# ⚠ Pomiar skal: kształt, dane i geometria w jednym odczycie — ale **osobno dla każdej skali**
# (zlecenie §3a: „nigdy »sprawdzamy Rok, reszta pewnie też działa«").
POMIAR_SKALI = r"""() => {
  // ⚠ Przełącznik skal rozpoznajemy po **nazwie dostępnej** (`aria-label="Skala czasu"`), a nie
  // po klasie ani po kolejności grup. Od Z8 (17.10.2026) kontrolki kalendarza to `.kal-grupa`
  // z `.kal-btn`, a `data-skala` zniknęło razem z `.segmented` — kryterium szukające tamtego
  // atrybutu mierzyło ekran, którego nie ma. Nazwa dostępna jest kontraktem z czytnikiem ekranu,
  // więc jest stabilniejsza od klasy: zmiana nazwy to zmiana zachowania, a nie kosmetyka.
  const grupaSkal = document.querySelector('.kal-nav__prawa .kal-grupa[aria-label="Skala czasu"]');
  const przyciski = grupaSkal ? Array.from(grupaSkal.children) : [];
  const etykieta = (e) => (e.querySelector('.kal-btn__etykieta') || e).textContent.trim();
  const wybrana = przyciski.filter(e => e.classList.contains('kal-btn--on')).map(etykieta);
  // Skala bieżąca z `aria-current`, czyli z tego samego źródła, z którego czyta ją czytnik ekranu.
  const biezacy = przyciski.find(e => e.getAttribute('aria-current') === 'true') || null;
  const skalaZAdresu = biezacy
    ? (new URL(biezacy.href, location.href).searchParams.get('skala') || 'miesiac')
    : null;
  const pozycje = grupaSkal ? [przyciski.map(etykieta)] : [];

  // ⚠ Osiągalność = **przecięcie z każdym PRZYCINAJĄCYM PRZODKIEM**, plus warunek, że nic nie
  // wychodzi za prawą krawędź strony. Celowo **nie** przecinamy z wysokością okna: dwanaście
  // siatek roku stoi jedna pod drugą, więc dalsze są poniżej zgięcia — to normalne przewijanie
  // w pionie, nie niedostępność.
  const widac = (el) => {
    const r = el.getBoundingClientRect();
    if (r.width <= 0 || r.height <= 0) { return false; }
    let x1 = r.left, y1 = r.top, x2 = r.right, y2 = r.bottom;
    for (let n = el.parentElement; n; n = n.parentElement) {
      const st = getComputedStyle(n);
      if (st.overflowX !== 'visible' || st.overflowY !== 'visible') {
        const p = n.getBoundingClientRect();
        x1 = Math.max(x1, p.left); y1 = Math.max(y1, p.top);
        x2 = Math.min(x2, p.right); y2 = Math.min(y2, p.bottom);
      }
      if (st.display === 'none' || st.visibility === 'hidden' || st.opacity === '0') { return false; }
    }
    const szerStrony = document.documentElement.clientWidth;
    return (x2 - x1) > 2 && (y2 - y1) > 2 && x1 >= -1 && x2 <= szerStrony + 1;
  };

  // ⚠ Rok i Miesiąc stoją na TYM SAMYM komponencie siatki (`.msiatka`) — tak zostało
  // rozstrzygnięte 13.10.2026 i zapisane w §4c systemu wizualnego. Rok to dwanaście pudełek
  // z nazwą miesiąca, Miesiąc „Tylko ja" to jedno pudełko obok wykazu wpisów.
  const siatki = Array.from(document.querySelectorAll('.msiatka'));
  const kratki = Array.from(document.querySelectorAll('.msiatka__kratka'));
  const nieobecne = kratki.filter(
    e => /msiatka__kratka--(urlop|zasilek|kadrowe)/.test(e.className));
  // ⚠ **Podpowiedź kratki z rodzajem** (pozycja 6 z `do-sprawdzenia.md`, dopisane w Z66):
  // kratka niesie rodzaj WYPEŁNIENIEM, więc jedyne miejsce, w którym człowiek czyta „co to
  // było i kiedy", to `title`. Pusty albo bez daty znaczy kolorowy kwadrat bez znaczenia.
  const tytulyNieobecnych = nieobecne.map(e => (e.getAttribute('title') || '').trim());

  // ⚠ **Nagłówek dnia tygodnia nad swoją kolumną — geometrią, nie indeksem w pętli.** To jest
  // następca sprawdzenia „numer nad kolumną" ze starej siatki roku (`.rok__naglowek`), której
  // już nie ma: tam numery dni szły w jednym rzędzie 1–31, tu siatka jest tygodniowa i kolumna
  // znaczy dzień tygodnia. Pytanie zostaje to samo — czy nagłówek stoi nad tym, co opisuje.
  const pierwsza = siatki[0];
  const odchylenia = [];
  if (pierwsza) {
    const naglowki = Array.from(pierwsza.querySelectorAll('.msiatka__dzien-tygodnia'));
    const komorki = Array.from(pierwsza.querySelectorAll('.msiatka__kratka'));
    naglowki.forEach((h, i) => {
      // Kratka z tej samej kolumny: pierwszy wiersz siatki ma tyle komórek, ile nagłówków.
      const k = komorki[i];
      if (!k) { odchylenia.push({kolumna: h.textContent.trim(), odchylenie: null}); return; }
      const a = h.getBoundingClientRect(), bb = k.getBoundingClientRect();
      odchylenia.push({kolumna: h.textContent.trim(),
                       odchylenie: Math.round((a.left + a.right) / 2 - (bb.left + bb.right) / 2)});
    });
  }

  // ⚠ Wiersze czytamy z `.table--rekordy` (tabela jest tam zawsze), a wyrównanie z obecności
  // modyfikatora — inaczej zdjęcie modyfikatora wywracało pomiar liczby wierszy, czyli jedna
  // usterka łamała dwa twierdzenia i nie było widać, które naprawdę.
  const tabela = document.querySelector('.table--rekordy');
  let lista = null;
  if (tabela) {
    const karta = tabela.closest('.card').getBoundingClientRect();
    const naglowki = Array.from(tabela.querySelectorAll('thead th'));
    const ostatnia = tabela.querySelector('tbody tr td:last-child');
    lista = {
      wierszy: tabela.querySelectorAll('tbody tr').length,
      ma_modyfikator: tabela.classList.contains('table--kolumny-prawe'),
      kolumny: naglowki.map(th => Math.round(th.getBoundingClientRect().width)),
      naglowki: naglowki.map(th => th.textContent.trim()),
      od_prawej: ostatnia ? Math.round(karta.right - ostatnia.getBoundingClientRect().right) : null,
      kolumnowa: naglowki.length > 0 && naglowki[0].getBoundingClientRect().width > 0,
    };
  }

  return {
    skala_wybrana: wybrana,
    skala_z_adresu: skalaZAdresu,
    pozycje_przelacznikow: pozycje,
    siatka: {
      pudelek: siatki.length,
      kratek: kratki.length,
      nieobecnych: nieobecne.length,
      tytuly_nieobecnych: tytulyNieobecnych,
      nazwy: Array.from(document.querySelectorAll('.msiatka__nazwa')).map(e => e.textContent.trim()),
      sumy: Array.from(document.querySelectorAll('.msiatka__suma')).map(e => e.textContent.trim()),
      odchylenia,
      widac: siatki.length > 0 && widac(siatki[0]),
    },
    // Miesiąc „Tylko ja": siatka PO LEWEJ i wykaz wpisów PO PRAWEJ — jeden blok `.mies`.
    miesiac_ja: {
      blokow: document.querySelectorAll('.mies').length,
      wpisow: document.querySelectorAll('.wpisy__poz').length,
      ma_odnosnik: document.querySelector('.mies__wpisy .card__meta a') !== null,
    },
    lista,
    strona_przewija: document.documentElement.scrollWidth > document.documentElement.clientWidth,
    miesiac_os: document.querySelectorAll('.team-grid').length,
  };
}"""


def sprawdz_skale_czasu(b, usterka: str | None = None) -> dict:
    """**Trzy nowe skale czasu w „Tylko ja"** (zlecenie `2026-10-11-skale-czasu…` §1 i §3a).

    ⚠ **Trzy stany ekranu, nie jeden.** Krzysztof wybrał wszystkie trzy skale naraz, więc
    sprawdzenie idzie **pętlą po skalach** — „zmierzyliśmy Rok, reszta pewnie też działa" jest
    dokładnie tym, czego to kryterium ma nie przepuścić.

    ⚠ **Pięć twierdzeń, każde łamane osobno:**
      1. `stan_z_adresu` — wejście na adres ze skalą daje TĘ skalę, a przełącznik pokazuje ją
         jako wybraną; wartość bzdurna wraca do miesiąca (biała lista, nie błąd),
      2. `dane_nie_pustka` — liczba nieobecności widocznych w skali **zgadza się z bazą**
         dla tego zakresu dat. ⚠ Z **sufitem**: więcej niż w bazie to też usterka (duplikaty
         z pętli po miesiącach), nie tylko mniej. To twierdzenie wyłapuje „warunek wygląda
         poprawnie i po cichu nie łapie niczego",
      3. `rok_numer_nad_kolumna` — w skali roku numer dnia stoi nad swoją kolumną, sprawdzone
         **geometrią** (środek nagłówka vs środek kratki), nie indeksem w pętli,
      4. `lista_wyrownanie` — prawa krawędź ostatniej kolumny leży w granicach kilku pikseli
         od prawej krawędzi karty, a „Rodzaj" jest szersza od „Dni robocze",
      5. `kwartal_waski` — trzy siatki są **widoczne** (przecięciem z przycinającymi przodkami,
         nie „są w HTML"), a strona **nie przewija się w poziomie** na wąskim ekranie,
      6. `kratki_z_podpowiedzia` — **każda kratka z rodzajem ma `title` z nazwą i DATĄ**
         (pozycja 6 z `do-sprawdzenia.md`, dopisane w Z66). Kratka niesie rodzaj wypełnieniem,
         więc `title` jest jedynym miejscem, w którym człowiek czyta „co to było i kiedy";
         pusty albo bez daty znaczy kolorowy kwadrat bez znaczenia. Wzorzec pyta o datę
         po separatorze, nie o samą obecność atrybutu — i mierzy w DWÓCH szablonach tej samej
         kratki (skala roku i miesiąc), bo to dwa różne pliki.
    """
    haslo = HASLO3 or haslo_dev(LOGIN3)
    if not haslo:
        return {"ok": False, "powod": f"brak hasła konta {LOGIN3} — pomiaru NIE wykonano"}
    try:
        ids = zatrudnienia_pracownika()
    except Exception as e:  # noqa: BLE001
        return {"ok": False, "powod": f"nie udało się odczytać zatrudnień: {e}"}
    jaId = int(ids["ja"])
    # ⚠ **Adresy niosą `&spolka=`, czyli spółkę TEGO zatrudnienia.** Bez tego pomiar zależy od
    # kontekstu firmy, który mogło zostawić inne sprawdzenie: konto pomiarowe ma dwa etaty
    # (76 w ALFA, 146 w BETA), więc `/kalendarz?widok=ja` pokazuje nieobecności tego etatu,
    # który należy do firmy z belki — i porównanie z bazą dla etatu 76 wychodziło wtedy fałszywie
    # czerwone. Zmierzone w pełnym przebiegu bramki: `dane_nie_pustka` czerwone, a to samo
    # sprawdzenie w izolacji zielone (to objaw z pamięci: „czerwienieją sprawdzenia, których kod
    # nie dotykałeś"). `?spolka=` jest honorowane jako widok na jedno żądanie, więc nie zostawia
    # stanu następnym.
    spolka_ja = int(ids.get("spolka") or 0)
    OGON = f"&spolka={spolka_ja}" if spolka_ja > 0 else ""
    MIESIAC = "2026-08"
    ROK = MIESIAC[:4]
    # ⚠ **Dwie skale, nie trzy** (tura kryteriów Z22): „Kwartał" zniknął z produktu przy
    # przebudowie nagłówka (Z8, 17.10.2026) — zostały Miesiąc, Rok i Lista. Kryterium pytające
    # o kwartał mierzyło ekran, którego nie ma, i czerwieniło się na poprawnym kodzie.
    zakresy = {
        "rok": (f"{ROK}-01-01", f"{ROK}-12-31"),
        "lista": (f"{ROK}-01-01", f"{ROK}-12-31"),
    }
    z_bazy = {k: nieobecnosci_pracownika(jaId, *v) for k, v in zakresy.items()}
    ctx = None
    try:
        ctx = nowy_kontekst(b, usterka, viewport={"width": SZEROKOSC_LAPTOPA, "height": 1000}, ignore_https_errors=True)
        pg = ctx.new_page()
        pg.goto(BAZA + "/logowanie", wait_until="networkidle")
        pg.fill("#login-email", LOGIN3)
        pg.fill("#login-password", haslo)
        pg.click("button[type=submit]")
        pg.wait_for_load_state("networkidle")
        if "/kontekst" in pg.url:
            pg.locator(".page form button[type=submit]").first.click()
            pg.wait_for_load_state("networkidle")

        pomiary = {}
        for skala in ("rok", "lista"):
            pg.goto(BAZA + f"/kalendarz?widok=ja&miesiac={MIESIAC}&skala={skala}{OGON}",
                    wait_until="networkidle")
            pomiary[skala] = pg.evaluate(POMIAR_SKALI)
        # Wartość spoza białej listy i brak parametru: oba mają dać miesiąc, czyli oś czasu.
        pg.goto(BAZA + f"/kalendarz?widok=ja&miesiac={MIESIAC}&skala=bzdura{OGON}", wait_until="networkidle")
        pomiary["bzdura"] = pg.evaluate(POMIAR_SKALI)
        pg.goto(BAZA + f"/kalendarz?widok=zespol&miesiac={MIESIAC}&skala=rok{OGON}", wait_until="networkidle")
        pomiary["zespol_rok"] = pg.evaluate(POMIAR_SKALI)

        # ⚠ **Skala poza „Tylko ja" musi wrócić do miesiąca** (zlecenie §4) — mierzone na koncie
        # administracyjnym Z NADANYM poziomem, bo tylko ono naprawdę dostaje widok firmy.
        # Poziom czytamy przed nadaniem i oddajemy w `finally`: role są trwałe.
        spolka_adm = int(dane_paskow().get("spolka") or 0)
        haslo_adm = HASLO or haslo_dev(LOGIN)
        poza_ja = None
        if spolka_adm > 0 and haslo_adm:
            poziom_przed_adm = poziom_kalendarza(spolka_adm, None, LOGIN).get("przed", 0)
            przestawiony = True
            try:
                poziom_kalendarza(spolka_adm, 2, LOGIN)
                ctx_adm = nowy_kontekst(b, usterka, viewport={"width": SZEROKOSC_LAPTOPA, "height": 1000}, ignore_https_errors=True)
                adm = ctx_adm.new_page()
                adm.goto(BAZA + "/logowanie", wait_until="networkidle")
                adm.fill("#login-email", LOGIN)
                adm.fill("#login-password", haslo_adm)
                adm.click("button[type=submit]")
                adm.wait_for_load_state("networkidle")
                adm.goto(BAZA + f"/kalendarz?widok=firma&miesiac={MIESIAC}&skala=rok&spolka={spolka_adm}",
                         wait_until="networkidle")
                poza_ja = adm.evaluate(POMIAR_SKALI)
                ctx_adm.close()
            finally:
                if przestawiony:
                    try:
                        poziom_kalendarza(spolka_adm, poziom_przed_adm, LOGIN)
                    except Exception:  # noqa: BLE001
                        pass

        # ⚠ **Każda kratka z rodzajem ma `title` z nazwą i DATĄ** (pozycja 6 z długu, Z66).
        # Mierzymy w skali roku i w miesiącu, bo to dwa różne szablony tej samej kratki.
        # Wzorzec pyta o datę po separatorze — „urlop wypoczynkowy · 17.08.2026" — a nie o samą
        # obecność atrybutu: `title="urlop wypoczynkowy"` mówi CO, ale nie KIEDY, a kratka stoi
        # w siatce dwunastu miesięcy, gdzie „kiedy" jest całą resztą informacji.
        tytuly = []
        for _skala in ("rok", "bzdura"):
            tytuly += pomiary[_skala]["siatka"]["tytuly_nieobecnych"]
        kratki_z_podpowiedzia = tytuly != [] and all(
            re.search(r" · \d{1,2}\.\d{2}", _t) is not None for _t in tytuly)

        # 5. wąski ekran — dwanaście siatek roku widocznych, zero przewijania strony w poziomie
        # ⚠ Mierzymy też POWŁOKĘ MOBILNĄ (`/m/`), nie tylko wąskie okno powłoki webowej: to dwa
        # różne układy i tylko jeden z nich ma dolną nawigację.
        waskie = {}
        for etyk, szer in (("374", 374), ("320", 320)):
            pg.set_viewport_size({"width": szer, "height": 812})
            for adres, klucz in ((f"/kalendarz?widok=ja&miesiac={MIESIAC}&skala=rok{OGON}", "rok"),
                                 (f"/m/kalendarz?widok=ja&miesiac={MIESIAC}&skala=rok{OGON}", "rok_m"),
                                 (f"/kalendarz?widok=ja&miesiac={MIESIAC}{OGON}", "miesiac")):
                pg.goto(BAZA + adres, wait_until="networkidle")
                waskie[f"{etyk}-{klucz}"] = pg.evaluate(POMIAR_SKALI)
        pg.set_viewport_size({"width": SZEROKOSC_LAPTOPA, "height": 1000})

        # ⚠ Mierzymy WYŁĄCZNIE przełącznik skal (po nazwie dostępnej „Skala czasu"). Konto
        # pracownika bez podwładnych i bez nadanego poziomu ma JEDNĄ pozycję widoku, więc obok
        # nie ma drugiej grupy — „pierwsza z dwóch" byłaby pomiarem na kształcie, który nie
        # zawsze istnieje.
        stan_z_adresu = (
            pomiary["rok"]["skala_wybrana"] == ["Rok"]
            and pomiary["rok"]["skala_z_adresu"] == "rok"
            and pomiary["lista"]["skala_wybrana"] == ["Lista"]
            and pomiary["lista"]["skala_z_adresu"] == "lista"
            # Wartość spoza białej listy wraca do miesiąca — cicho, bez błędu.
            and pomiary["bzdura"]["skala_wybrana"] == ["Miesiąc"]
            and pomiary["bzdura"]["skala_z_adresu"] == "miesiac"
            # Kształt ekranu idzie za skalą, nie tylko podświetlenie przycisku.
            and pomiary["rok"]["siatka"]["pudelek"] == 12
            and len(pomiary["rok"]["siatka"]["nazwy"]) == 12
            and pomiary["lista"]["lista"] is not None
            and pomiary["lista"]["lista"]["wierszy"] > 0
            and pomiary["lista"]["siatka"]["pudelek"] == 0
            # ⚠ Miesiąc „Tylko ja" to JEDNA siatka obok wykazu wpisów (13.10.2026), a nie oś
            # czasu: oś została przy widokach zespołu i firmy, gdzie wiersz znaczy osobę.
            and pomiary["bzdura"]["miesiac_ja"]["blokow"] == 1
            and pomiary["bzdura"]["siatka"]["pudelek"] == 1
            and pomiary["bzdura"]["miesiac_os"] == 0
            and all(p["pozycje_przelacznikow"][0] == ["Miesiąc", "Rok", "Lista"]
                    for k, p in pomiary.items() if k in ("rok", "lista", "bzdura"))
            # Na widoku firmy (konto z nadanym poziomem): żadnej siatki miesięcznej i **żadnego
            # przełącznika skal** — bo poza „Tylko ja" nie ma czym przełączać.
            and poza_ja is not None
            and poza_ja["siatka"]["pudelek"] == 0
            and poza_ja["skala_z_adresu"] is None
            and poza_ja["miesiac_os"] == 1
        )
        # ⚠ Porównanie z bazą: równość, nie „co najmniej". Sufit wyłapie duplikaty z pętli.
        dane_nie_pustka = (
            z_bazy["rok"]["wpisow"] > 0
            and pomiary["rok"]["siatka"]["nieobecnych"] == z_bazy["rok"]["dni"]
            # Lista scala ciągi kadrowe, więc wierszy może być MNIEJ niż wpisów — nigdy więcej.
            and pomiary["lista"]["lista"] is not None
            and 0 < pomiary["lista"]["lista"]["wierszy"] <= z_bazy["lista"]["wpisow"]
        )
        # ⚠ Następca sprawdzenia „numer nad kolumną": w siatce tygodniowej kolumna znaczy dzień
        # tygodnia, więc mierzymy, czy nagłówek (`pon`, `wt`, …) stoi nad swoją kolumną. Pytanie
        # jest to samo, co przy starej siatce 1–31: czy podpis opisuje to, co pod nim.
        odchylenia = pomiary["rok"]["siatka"]["odchylenia"]
        naglowek_nad_kolumna = (
            len(odchylenia) == 7
            and all(o["odchylenie"] is not None and abs(o["odchylenie"]) <= 2 for o in odchylenia)
            and pomiary["rok"]["siatka"]["widac"]
        )
        lst = pomiary["lista"]["lista"]
        lista_wyrownanie = (
            lst is not None and lst["kolumnowa"] and lst["ma_modyfikator"]
            and lst["naglowki"] == ["Rodzaj", "Kiedy", "Dni robocze", "Skąd"]
            and lst["od_prawej"] is not None and lst["od_prawej"] <= 4
            and lst["kolumny"][0] > lst["kolumny"][2]
        )
        # ⚠ Wąski ekran mierzy **tryb awarii**, nie tylko brak przewijania: dwanaście siatek
        # zawsze się „zmieści", bo mogą się ścisnąć do nieczytelności — więc obok przewijania
        # sprawdzamy, że wszystkie dwanaście jest na miejscu i że pierwsza jest osiągalna.
        rok_waski = all(
            p["siatka"]["pudelek"] == 12 and p["siatka"]["widac"] and p["strona_przewija"] is False
            for k, p in waskie.items() if k.endswith("-rok") or k.endswith("-rok_m")
        ) and all(p["strona_przewija"] is False for p in waskie.values())

        return {
            "ok": stan_z_adresu and dane_nie_pustka and naglowek_nad_kolumna
                  and lista_wyrownanie and rok_waski and kratki_z_podpowiedzia,
            "stan_z_adresu": stan_z_adresu,
            "dane_nie_pustka": dane_nie_pustka,
            "naglowek_nad_kolumna": naglowek_nad_kolumna,
            "lista_wyrownanie": lista_wyrownanie,
            "rok_waski": rok_waski,
            "kratki_z_podpowiedzia": kratki_z_podpowiedzia,
            "podpowiedzi_kratek": {"sprawdzonych": len(tytuly), "przyklad": tytuly[0] if tytuly else None,
                                   "bez_daty": [x for x in tytuly
                                                if re.search(r" · \d{1,2}\.\d{2}", x) is None][:3]},
            "zatrudnienie": jaId,
            "z_bazy": z_bazy,
            "skale": {k: {"wybrana": p["skala_wybrana"],
                          "pudelek": p["siatka"]["pudelek"], "kratek": p["siatka"]["kratek"],
                          "nieobecnych": p["siatka"]["nieobecnych"],
                          "wpisow_obok": p["miesiac_ja"]["wpisow"],
                          "lista_wierszy": (p["lista"] or {}).get("wierszy"),
                          "os_czasu": p["miesiac_os"]}
                      for k, p in pomiary.items()},
            "odchylenia_naglowkow": odchylenia,
            "sumy_miesiecy": pomiary["rok"]["siatka"]["sumy"],
            "lista_kolumny": lst,
            "poza_ja": None if poza_ja is None else {
                "pudelek": poza_ja["siatka"]["pudelek"],
                "przelacznik_skal": poza_ja["skala_z_adresu"],
                "os_czasu": poza_ja["miesiac_os"]},
            "waskie": {k: {"pudelek": p["siatka"]["pudelek"],
                           "widac": p["siatka"]["widac"],
                           "strona_przewija": p["strona_przewija"]}
                       for k, p in waskie.items()},
        }
    except Exception as e:  # noqa: BLE001
        return {"ok": False, "powod": str(e)}
    finally:
        if ctx is not None:
            try:
                ctx.close()
            except Exception:  # noqa: BLE001
                pass


# ⚠ Pasmo proporcji kratki dnia. Wzorcem jest dzień z widoku Miesiąc, ale **wzorzec jest
# przedziałem, nie jedną liczbą**: zmierzone, że sam dzień Miesiąca ma 0,79 przy oknie 1920 px,
# 0,54 przy 1440 i 0,47 przy 374 (kolumna dnia ściska się razem z kartą). Pasmo musi więc
# obejmować wzorzec i odrzucać OBA błędy: kratkę płaską (2,64 w Kwartale i 3,07 w Roku przed
# poprawką) oraz kratkę zbyt wysoką — kryterium z jedną granicą przepuściłoby drugą wersję
# tego samego błędu.
KRATKA_PR_MIN = 0.45
KRATKA_PR_MAX = 1.30

POMIAR_DZIS = r"""() => {
  const lum = (rgb) => {
    const c = rgb.match(/\d+/g).slice(0, 3).map(n => {
      const x = Number(n) / 255;
      return x <= 0.03928 ? x / 12.92 : Math.pow((x + 0.055) / 1.055, 2.4);
    });
    return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2];
  };
  const kontrast = (a, b) => {
    const la = lum(a), lb = lum(b);
    return +(((Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05)).toFixed(3));
  };
  const skladaj = (fg, a, bg) => fg.map((v, i) => Math.round(v * a + bg[i] * (1 - a)));
  const kanaly = (t) => {
    const m = t.match(/rgba?\(([^)]+)\)/);
    if (!m) { return null; }
    const cz = m[1].split(',').map(x => parseFloat(x));
    return {rgb: cz.slice(0, 3), a: cz.length > 3 ? cz[3] : 1};
  };
  const efektywne = (el) => {
    const warstwy = [];
    for (let n = el; n; n = n.parentElement) {
      const c = kanaly(getComputedStyle(n).backgroundColor);
      if (c === null || c.a === 0) { continue; }
      warstwy.push(c);
      if (c.a >= 1) { break; }
    }
    let wynik = [255, 255, 255];
    for (let i = warstwy.length - 1; i >= 0; i--) {
      wynik = skladaj(warstwy[i].rgb, warstwy[i].a, wynik);
    }
    return 'rgb(' + wynik.join(', ') + ')';
  };
  // ⚠ Kolor znacznika czytamy z `box-shadow`, bo znacznikiem jest **obwódka**, nie tło.
  const kolorCienia = (el) => {
    const c = kanaly(getComputedStyle(el).boxShadow || '');
    return c === null ? null : 'rgb(' + c.rgb.join(', ') + ')';
  };
  const pros = (el) => {
    const r = el.getBoundingClientRect();
    return {w: Math.round(r.width * 10) / 10, h: Math.round(r.height * 10) / 10,
            pr: r.height > 0 ? Math.round(r.width / r.height * 100) / 100 : null};
  };

  // ⚠ Tło odniesienia: kratka BEZ rodzaju i BEZ znacznika dnia — to wobec niej mierzymy,
  // czy „dziś" w ogóle widać. W osi czasu rolę kratki pełni komórka dnia.
  const czysta = [...document.querySelectorAll('.msiatka__kratka, .team-grid__cell')]
    .find(e => !/--(urlop|zasilek|kadrowe|dzis|wolny|poza)/.test(e.className));
  const tlo = czysta ? efektywne(czysta) : 'rgb(255, 255, 255)';

  // ⚠ **Znacznik „dziś" to WYPEŁNIENIE kratki** (Z23, 17.10.2026) — wcześniej była obwódka,
  // więc kolor czytano z `box-shadow`. Teraz czytamy OBA: tło niesie rozpoznanie, a ramka
  // (`box-shadow: inset`) obrysowuje je i przejmuje całą robotę na dniu z nieobecnością.
  const kratkiDzis = [...document.querySelectorAll('.msiatka__kratka--dzis')];
  const naglowkiDzis = [...document.querySelectorAll('.team-grid__head--dzis')];
  const znacznik = kratkiDzis[0] || naglowkiDzis[0] || null;
  const opis = (el) => {
    if (!el) { return null; }
    const st = getComputedStyle(el);
    const n = el.querySelector('.dzien-dzis');
    const ramka = kolorCienia(el);
    const tloEl = efektywne(el);
    return {
      tlo: tloEl,
      ramka,
      numer_kolor: n ? getComputedStyle(n).color : st.color,
      // Numer czytamy NA JEGO WŁASNYM tle (wypełnienie kratki), bo tam go widać.
      kontrast_numeru: kontrast(n ? getComputedStyle(n).color : st.color, tloEl),
      // Ramka wobec tła kratki — to ona oddziela „dziś" od rodzaju pod spodem.
      kontrast_ramki: ramka ? kontrast(ramka, tloEl) : null,
      // Wypełnienie wobec kratki zwykłej — czy „dziś" w ogóle odróżnia się od sąsiadów.
      kontrast_wypelnienia: kontrast(tloEl, tlo),
    };
  };

  // ⚠ **Pierwszeństwo rodzaju** (Z23): gdy dziś wypada w dzień z nieobecnością albo wolny,
  // wypełnienie ma zostać RODZAJU, a „dziś" niesie sama ramka. Sprawdzamy to podstawieniem klasy
  // na żywej kratce — tego nie da się zmierzyć czekaniem, aż kalendarz sam taki dzień pokaże.
  const naRodzaju = {};
  if (kratkiDzis[0]) {
    const k = kratkiDzis[0];
    const byly = k.className;
    ['urlop', 'zasilek', 'kadrowe', 'wolny'].forEach(r => {
      k.className = byly + ' msiatka__kratka--' + r;
      const wzor = document.createElement('div');
      wzor.className = 'msiatka__kratka msiatka__kratka--' + r;
      k.parentElement.appendChild(wzor);
      const tloRodzaju = efektywne(wzor);
      wzor.remove();
      const o = opis(k);
      naRodzaju[r] = {
        tlo: o.tlo,
        tlo_rodzaju: tloRodzaju,
        // Reguła: tło kratki „dziś" z rodzajem = tło samego rodzaju (rodzaj wygrywa).
        rodzaj_wygrywa: o.tlo === tloRodzaju,
        ramka: o.ramka,
        numer_kolor: o.numer_kolor,
        kontrast_ramki: o.kontrast_ramki,
        kontrast_numeru: o.kontrast_numeru,
      };
    });
    k.className = byly;
  }

  const naglowek = naglowkiDzis[0] || null;
  const inneNaglowki = [...document.querySelectorAll('.team-grid__head')]
    .filter(e => !e.classList.contains('team-grid__head--dzis')
                 && !e.classList.contains('team-grid__head--person'));

  // Kratka do pomiaru kształtu: pierwsza prawdziwa (nie „poza miesiącem").
  const kratka = document.querySelector('.msiatka__kratka:not(.msiatka__kratka--poza)')
              || document.querySelector('.team-grid__cell');

  return {
    tlo_odniesienia: tlo,
    // ⚠ **Dokładnie jeden znacznik na widok** (tura kryteriów Z22): dwa znaczniki znaczą, że
    // „dziś" przestało być jednym dniem — a to najczęstszy objaw błędu w wyliczaniu zakresu.
    znacznikow_w_siatce: kratkiDzis.length,
    znacznikow_w_naglowku: naglowkiDzis.length,
    znacznik: opis(znacznik),
    na_rodzaju: naRodzaju,
    naglowek_dzis: !!naglowek,
    // ⚠ Nie wystarczy, że klasa jest: nagłówek musi się **różnić** od pozostałych nagłówków —
    // klasa bez reguły w arkuszu przeszłaby sprawdzenie na samej obecności.
    naglowek_rozni_sie: !!naglowek && inneNaglowki.length > 0 && (
      efektywne(naglowek) !== efektywne(inneNaglowki[0])
      || getComputedStyle(naglowek).boxShadow !== getComputedStyle(inneNaglowki[0]).boxShadow),
    kratka: kratka ? pros(kratka) : null,
    kratka_font: kratka ? getComputedStyle(kratka).fontSize : null,
    przewija_w_poziomie: document.documentElement.scrollWidth > document.documentElement.clientWidth,
  };
}"""


def nazwy_znacznika_dzis() -> dict:
    """Ile razy w `/templates` i `/public/assets/css` występuje każdy z dwóch rdzeni nazwy.

    ⚠ **Liczone w całych katalogach, nie na liście plików, które znam** (zlecenie §3.2): lista
    plików starzeje się cicho, a nowy ekran dopisze starą nazwę i nikt tego nie zobaczy.
    Warunek: jeden z rdzeni ma **zero** wystąpień.
    """
    import re
    rdzenie = {"--today": 0, "--dzis": 0}
    for katalog, wzor in (("templates", "*.twig"), ("public/assets/css", "*.css"),
                          ("public/assets/js", "*.js")):
        for sciezka in (KORZEN / katalog).rglob(wzor):
            tekst = sciezka.read_text(encoding="utf-8")
            # komentarze Twiga i CSS pomijamy: opis historii („dawniej `--today`") nie jest użyciem
            tekst = re.sub(r"\{#.*?#\}", "", tekst, flags=re.S)
            tekst = re.sub(r"/\*.*?\*/", "", tekst, flags=re.S)
            for rdzen in rdzenie:
                rdzenie[rdzen] += len(re.findall(re.escape(rdzen) + r"\b", tekst))
    return rdzenie


def _png_barwy(dane: bytes) -> dict:
    """Ile razy występuje każda barwa w zrzucie — własny czytnik PNG (8 bitów, typ 2/6).

    ⚠ Dlaczego własny: na maszynie deweloperskiej nie ma Pillow, a dokładanie zależności
    do `dev/` dla pięćdziesięciu linii dekodera to supply chain bez powodu (CLAUDE.md §0).
    PNG z Playwrighta jest zawsze 8-bitowy i bez przeplotu, więc obsługujemy dokładnie to.
    """
    if dane[:8] != b"\x89PNG\r\n\x1a\n":
        raise ValueError("to nie PNG")
    i, idat, ihdr = 8, [], None
    while i < len(dane):
        dl = struct.unpack(">I", dane[i:i + 4])[0]
        typ, tresc = dane[i + 4:i + 8], dane[i + 8:i + 8 + dl]
        if typ == b"IHDR":
            ihdr = struct.unpack(">IIBBBBB", tresc)
        elif typ == b"IDAT":
            idat.append(tresc)
        elif typ == b"IEND":
            break
        i += 12 + dl
    w, h, glebia, typ_k, _, _, przeplot = ihdr
    if glebia != 8 or przeplot != 0 or typ_k not in (2, 6):
        raise ValueError(f"nieobsługiwany PNG: {ihdr}")
    kan = 3 if typ_k == 2 else 4
    sur, szer_b = zlib.decompress(b"".join(idat)), w * kan
    poprz, p, barwy = bytearray(szer_b), 0, {}
    for _ in range(h):
        f = sur[p]
        p += 1
        linia = bytearray(sur[p:p + szer_b])
        p += szer_b
        for x in range(szer_b):
            a = linia[x - kan] if x >= kan else 0
            bb = poprz[x]
            c = poprz[x - kan] if x >= kan else 0
            if f == 1:
                linia[x] = (linia[x] + a) & 255
            elif f == 2:
                linia[x] = (linia[x] + bb) & 255
            elif f == 3:
                linia[x] = (linia[x] + (a + bb) // 2) & 255
            elif f == 4:
                pa, pb, pc = abs(bb - c), abs(a - c), abs(a + bb - 2 * c)
                pr = a if (pa <= pb and pa <= pc) else (bb if pb <= pc else c)
                linia[x] = (linia[x] + pr) & 255
        for x in range(0, szer_b, kan):
            k = (linia[x], linia[x + 1], linia[x + 2])
            barwy[k] = barwy.get(k, 0) + 1
        poprz = linia
    return barwy


def sprawdz_pasy_szczelin(b, usterka: str | None = None) -> dict:
    """**Szczeliny wokół przyklejonego bloku nagłówka mają WYŁĄCZNIE kolor tła strony, a wiersz
    dni stoi dokładnie pod kartą — na każdym powiększeniu i po zmianie wysokości karty** (Z25 + Z26).

    ⚠ **Kryterium mierzy PIKSELE i GEOMETRIĘ, nie reguły arkusza, i to jest sedno.** Zgłoszenie
    Z25 brzmiało „w szczelinie widać cienką linię i fragment lewej krawędzi karty, która
    przejeżdża pod spodem", a pierwszy pomiar — hit-test `elementFromPoint` w trzech punktach
    pasa — świecił zielono, bo pytał 3 px od krawędzi, a usterką był 1 px ramki i cień przy samej
    krawędzi. Stąd dwie zasady: pas mierzy się **na całej szerokości kolumny treści**
    i **barwą piksela**.
      1. `pas_nad` — między dolną krawędzią belki a górą bloku nagłówka,
      2. `pas_pod` — od dołu bloku nagłówka **do góry przyklejonego wiersza dni** (a nie do
         następnego bloku, jak liczył pomiar Z25). To jest poprawka z Z26 i bez niej kryterium
         nie widziało zgłoszonej usterki: pas tła pod kartą jedzie razem z kartą, więc 16 px
         bezpośrednio pod nią było zakryte ZAWSZE, a odsłonięta szczelina powstawała NIŻEJ —
         między końcem tego pasa a wierszem dni, który został na starym `top`.

    ⚠ **Trzecie twierdzenie, czysto geometryczne: `wiersz_dni.top - karta.bottom == odstęp bloków`**
    (16 px) na każdym położeniu suwaka. Ono łapie obie strony usterki Z26 jednym pomiarem:
    wiersz **schowany pod kartą** (odstęp ujemny, tak było przy powiększeniu przeglądarki)
    i wiersz **oderwany od karty** (odstęp większy, czyli szczelina z przewijanymi wierszami).
    Pod progiem szerokości bloku wiersz przykleja się do PUDEŁKA, nie do strony (patrz
    `.team-scroll`), więc tam twierdzenie brzmi inaczej: `top` wiersza musi wynosić `0px`.

    ⚠ **Pięć okien, w tym trzy z powiększeniem przeglądarki** (110 / 125 / 150 %, czyli
    `device_scale_factor` 1,1 / 1,25 / 1,5 przy odpowiadających im szerokościach CSS 1745 / 1536
    / 1280 px). Powiększenie było w zgłoszeniu Z26 wprost i nie jest tym samym, co węższe okno:
    zmienia się liczba pikseli urządzenia na piksel CSS, więc krawędzie 1 px wypadają na pół
    piksela i są wygładzane. Dlatego „obcy piksel" to różnica **większa niż 2/255 na kanale**,
    a nie każda różnica: przy 110 % samo tło wychodzi ze zrzutu jako (245, 245, 248) wobec
    (245, 246, 248). Usterki, o które kryterium pyta, są dalej: ramka `--c-line` to 15/255,
    tło wiersza osoby 10/255, wyciszone tło nagłówka 3/255, napis ponad 80/255.

    ⚠ **Próba zmiany wysokości karty w trakcie** (okno 1920 i 1536 @ 1,25): karcie nagłówka
    dokładamy `padding-bottom: 30px`, mierzymy, zdejmujemy i mierzymy znowu. To dokładnie ta
    próba, którą Krzysztof wykonał na żywo w swojej przeglądarce; bez `ResizeObserver`
    w `kalendarz-przyklej.js` wiersz dni zostaje na starym `top` (zmierzone: odstęp −14 px
    zamiast 16 px, czyli wiersz chowa się pod kartą).
    ⚠ Krok suwaka: 8 px przy powiększeniu 100 % i 16 px wyżej. Dowód do zlecenia Z25 szedł
    co 4 px, ale usterka powtarza się z okresem wiersza (56 px), więc krok dwa razy większy
    niczego nie przepuszcza, a trzyma czas przebiegu bramki w ryzach.
    """
    haslo = HASLO or haslo_dev(LOGIN)
    if not haslo:
        return {"ok": False, "powod": f"brak hasła konta {LOGIN} — pomiaru NIE wykonano"}
    TLO = (245, 246, 248)   # --c-bg
    TOLERANCJA = 2          # patrz docstring: wygładzanie krawędzi przy powiększeniu ułamkowym
    # ⚠ Wcięcie pasa: 1 px przy powiększeniu całkowitym, 3 px przy ułamkowym. Trzy, bo tyle
    # sięga cień bloku (`--shadow-card` to `0 1px 2px`), a przy ułamkowym powiększeniu krawędź
    # pasa zakrywającego ten cień nie wypada już na granicy piksela urządzenia i cień przecieka
    # szwem przy zaokrąglonych narożnikach (zmierzone przy 110 %: 15 pikseli barwy
    # (248, 248, 249) na każde położenie suwaka).
    def wciecie(zoom: float) -> int:
        return 1 if float(zoom).is_integer() else 3
    # (szerokość CSS, wysokość CSS, powiększenie, krok suwaka, czy robić próbę zmiany wysokości)
    OKNA = ((1920, 1080, 1, 8, True), (1366, 768, 1, 8, False), (1745, 982, 1.1, 16, False),
            (1536, 864, 1.25, 16, True), (1280, 720, 1.5, 16, False))
    GEO = """() => {
      const q = s => document.querySelector(s);
      const karta = q('.card--kal-naglowek'), page = q('.page'), belka = q('.topbar');
      const wiersz = q('.team-grid__head--person'), pudlo = q('.team-scroll');
      if (!karta || !page || !belka || !wiersz || !pudlo) { return null; }
      const k = karta.getBoundingClientRect(), p = page.getBoundingClientRect();
      const bl = belka.getBoundingClientRect(), w = wiersz.getBoundingClientRect();
      const luka = parseFloat(getComputedStyle(q('.bloki')).getPropertyValue('--bloki-luka'));
      return {x: Math.round(p.left), szer: Math.round(p.width),
              nad_y: Math.round(bl.bottom), nad_h: Math.round(k.top - bl.bottom),
              karta_b: Math.round(k.bottom), wiersz_t: Math.round(w.top),
              luka: Math.round(luka),
              tryb: getComputedStyle(pudlo).overflowY === 'visible' ? 'strona' : 'pudelko',
              top_wiersza: getComputedStyle(wiersz).top,
              przyklejona: getComputedStyle(karta).position};
    }"""

    def obce(pg, g, WCIECIE):
        """Obce piksele w obu pasach na jednym położeniu suwaka.

        ⚠ **Pas mierzy się bez skrajnego piksela z góry i z dołu** (`WCIECIE`), bo ten wiersz
        należy do SĄSIADA: przy powiększeniu ułamkowym krawędź 1 px wypada na pół piksela
        urządzenia i przeglądarka wmiesza ją w wycinek. Zmierzone przy 110 %: 1646 pikseli
        barwy (242, 243, 245) w jednym wierszu pasa, wyłącznie z dolnej krawędzi belki.
        Usterki, o które kryterium pyta, leżą we WNĘTRZU pasa (ramki i cień bloku przejeżdżają
        przez cały jego przekrój, przewijane wiersze wypełniają go w całości), a dryf wiersza
        dni o 1 px łapie twierdzenie geometryczne — nie ten pomiar.
        ⚠ Wcięcie idzie **w obu osiach**, bo szerokość wycinka też nie dzieli się przy
        powiększeniu ułamkowym: zmierzone przy 110 % — 1497 px CSS to 1646,7 px urządzenia,
        więc skrajna kolumna zrzutu była wypełniona w 70 % i wychodziła jako (248, 248, 249)
        na każdym położeniu suwaka. Usterka Z25 leżała 32 px w głąb kolumny treści (tam stoi
        ramka bloku), więc trzy piksele z brzegu nie mają do niej żadnego stosunku.
        """
        wyn = {}
        for nazwa, py, ph in (("nad", g["nad_y"], g["nad_h"]),
                              ("pod", g["karta_b"], max(g["luka"], g["wiersz_t"] - g["karta_b"]))):
            py, ph = py + WCIECIE, ph - 2 * WCIECIE
            px, pw = g["x"] + WCIECIE, g["szer"] - 2 * WCIECIE
            if ph <= 0:
                wyn[nazwa] = (0, None)
                continue
            png = pg.screenshot(clip={"x": px, "y": py, "width": pw, "height": ph})
            inne = {k: v for k, v in _png_barwy(png).items()
                    if max(abs(k[0] - TLO[0]), abs(k[1] - TLO[1]), abs(k[2] - TLO[2])) > TOLERANCJA}
            wyn[nazwa] = (sum(inne.values()),
                          max(inne.items(), key=lambda kv: kv[1])[0] if inne else None)
        return wyn

    def przebieg(pg, krok, WCIECIE):
        """Suwak 0–240 px: obce piksele w pasach i odstęp wiersza dni od karty."""
        suma = {"nad": 0, "pod": 0}
        odstepy, topy, przyklady = set(), set(), []
        for y in range(0, 241, krok):
            pg.evaluate("v => window.scrollTo(0, v)", y)
            pg.wait_for_timeout(25)
            g = pg.evaluate(GEO)
            odstepy.add(g["wiersz_t"] - g["karta_b"])
            topy.add(g["top_wiersza"])
            for nazwa, (ile, barwa) in obce(pg, g, WCIECIE).items():
                suma[nazwa] += ile
                if ile and len(przyklady) < 5:
                    przyklady.append({"pas": nazwa, "scroll": y, "piksele": ile, "barwa": barwa})
        return {"obce_piksele": suma, "odstepy": sorted(odstepy),
                "topy": sorted(topy), "przyklady": przyklady}

    wynik: dict = {"okna": {}}
    ctx = None
    try:
        for szer, wys, zoom, krok, proba in OKNA:
            ctx = nowy_kontekst(b, usterka, viewport={"width": szer, "height": wys},
                                device_scale_factor=zoom, ignore_https_errors=True)
            pg = ctx.new_page()
            pg.goto(BAZA + "/logowanie", wait_until="networkidle")
            pg.fill("#login-email", LOGIN)
            pg.fill("#login-password", haslo)
            pg.click("button[type=submit]")
            pg.wait_for_load_state("networkidle")
            pg.goto(BAZA + "/kalendarz?widok=firma", wait_until="networkidle")
            klucz = f"{szer}x{wys}@{zoom}"
            g = pg.evaluate(GEO)
            if g is None:
                wynik["okna"][klucz] = {"blad": "brak bloku nagłówka kalendarza"}
                ctx.close()
                ctx = None
                continue
            o = {"przyklejona": g["przyklejona"], "tryb": g["tryb"],
                 "pas_nad_h": g["nad_h"], "luka": g["luka"], "wciecie": wciecie(zoom),
                 "suwak": przebieg(pg, krok, wciecie(zoom))}
            if proba:
                # ⚠ Zmiana wysokości karty w trakcie — CSSOM, nie `<style>`: nagłówek CSP
                # aplikacji nie dopuszcza stylów w znaczniku (ta sama zasada, co w usterkach).
                pg.evaluate("document.querySelector('.card--kal-naglowek')"
                            ".style.paddingBottom = '30px'")
                pg.wait_for_timeout(250)
                o["po_zmianie_wysokosci"] = przebieg(pg, 24, wciecie(zoom))
                pg.evaluate("document.querySelector('.card--kal-naglowek')"
                            ".style.paddingBottom = ''")
                pg.wait_for_timeout(250)
                o["po_powrocie"] = przebieg(pg, 24, wciecie(zoom))
            wynik["okna"][klucz] = o
            ctx.close()
            ctx = None
    except Exception as e:  # noqa: BLE001
        wynik["ok"] = False
        wynik["powod"] = str(e)
        return wynik
    finally:
        if ctx is not None:
            ctx.close()

    def dobry(o: dict, p: dict) -> bool:
        """Jeden przebieg suwaka: pasy czyste i wiersz dni na swoim miejscu."""
        if p["obce_piksele"]["nad"] or p["obce_piksele"]["pod"]:
            return False
        if o["tryb"] == "strona":
            return p["odstepy"] == [o["luka"]]
        return p["topy"] == ["0px"]

    okna = list(wynik["okna"].values())
    wynik["ok"] = bool(okna) and all(
        "blad" not in o and o["przyklejona"] == "sticky" and o["pas_nad_h"] > 0
        and all(dobry(o, p) for k, p in o.items() if isinstance(p, dict) and "obce_piksele" in p)
        for o in okna)
    return wynik


# ⚠ **Jeden pomiar dla OBU stron kryterium `przelacznik_zespolu`** (Z70 + Z71): etykiety
# przełącznika, liczba wierszy osi czasu i zdania pod kalendarzem. Dwie kopie tego kodu
# rozjechałyby się przy pierwszej zmianie klasy — a wtedy jedna strona kryterium mierzyłaby
# coś innego niż druga i nikt by tego nie zauważył, bo obie byłyby zielone.
# ⚠ Nazwisk z wierszy NIE czytamy: to dane osobowe, a kryterium pyta o LICZBĘ wierszy.
POMIAR_PRZELACZNIKA = r"""() => {
  const etykieta = (e) => (e.querySelector('.kal-btn__etykieta') || e).textContent.trim();
  const pozycje = [...document.querySelectorAll('.kal-btn')].map(etykieta)
    .filter(t => ['Tylko ja', 'Zespół', 'Firma'].includes(t));
  const wybrany = [...document.querySelectorAll('.kal-btn[aria-current="true"]')].map(etykieta);
  const wierszy = document.querySelectorAll('.team-grid__person').length;
  const zdania = [...document.querySelectorAll('.card__meta, .notice')]
    .map(e => e.innerText.trim()).filter(t => t !== '');
  return {pozycje, wybrany, wierszy, zdania};
}"""


def probka_zespolu(tryb: str) -> dict:
    """Struktura próbna z gałęzią (`dev/probka-zespolu.php`) — tworzenie, sprzątanie, liczniki.

    ⚠ Tryb `sprzataj` wołamy także PRZED pomiarem i w `finally`: po przerwanym przebiegu
    (Ctrl+C, zabity proces) w bazie zostają wiersze próbki, a adres konta jest unikalny —
    bez tego następne uruchomienie wywróciłoby się na duplikacie zamiast zmierzyć cokolwiek.
    """
    import subprocess
    wyn = subprocess.run(["php", "dev/probka-zespolu.php", tryb],
                         cwd=KORZEN, capture_output=True, text=True, check=True)

    return json.loads(wyn.stdout or "{}")


def sprawdz_przelacznik_zespolu(b, usterka: str | None = None) -> dict:
    """**Pozycja „Zespół" istnieje tylko wtedy, gdy pokazuje coś innego niż „Firma"**
    (zlecenie Z70, 21.10.2026 — zgłoszenie Krzysztofa „u administratora oba widoki pokazują
    to samo").

    ⚠ Kryterium mierzy REGUŁĘ, nie liczbę: nie „u tego konta mają być dwie pozycje", tylko
    „pozycja, która dublowałaby inną, nie powstaje, a adres jej nie przywraca". Cztery
    twierdzenia:
      1. `bez_pozycji_zespolu` — konto z wglądem w całą firmę i bez gałęzi NIE ma pozycji
         „Zespół" w przełączniku,
      2. `adres_nie_przywraca` — `?widok=zespol` renderuje widok FIRMY, a nie zespołu: inaczej
         ekran miałby stan osiągalny adresem, którego przełącznik nie oferuje (i dokładnie tam
         stało nieprawdziwe zdanie o gałęzi),
      3. `zdanie_bez_spolki` — zdanie o zasięgu nie używa słowa „spółka" w żadnej odmianie.
         ⚠ To twierdzenie jest tu osobno, bo kryterium `bez_slowa_spolka` **przegapiło ten
         napis**: szukało rdzenia „spółk", a w zdaniu stało „w spółce" (miejscownik, `k` → `c`).
         Rdzeń poprawiony, a to twierdzenie pilnuje konkretnego zdania, które wtedy przeszło,
      4. `zdanie_bez_galezi` — przy koncie bez podwładnych zdanie NIE mówi „Widzisz swoją
         gałąź": gałąź to hierarchia, a nie reguła dostępu, i mówienie inaczej przypisuje
         człowiekowi podwładnych, których nie ma.

    ⚠ **Strona DODATNIA mierzona na własnej strukturze próbnej** (zlecenie Z71): pozycja
    „Zespół" MUSI być u kogoś, kto kogoś prowadzi, i musi pokazywać dokładnie jego gałąź.
    Do tego dnia kryterium mierzyło tylko stronę ujemną, bo jedyny przełożony z niepustą gałęzią
    w bazie roboczej to prawdziwa osoba — a hasła do jej konta nie wolno trzymać w pliku
    deweloperskim (rozstrzygnięcie Krzysztofa z 21.10.2026). Dlatego `dev/probka-zespolu.php`
    zakłada przełożonego z kontem i trzech podwładnych na czas pomiaru i kasuje ich po nim;
    liczniki tabel sprawdzamy przed i po, więc „posprzątane" jest twierdzeniem, nie nadzieją.
      5. `pozycja_przy_galezi` — konto z gałęzią MA pozycję „Zespół",
      6. `zespol_to_galaz` — widok „Zespół" pokazuje dokładnie tyle wierszy, ile liczy gałąź
         (przełożony i jego trzy osoby), ani mniej, ani więcej,
      7. `zdanie_o_galezi` — zdanie mówi „Widzisz swoją gałąź…" i nie używa słowa „spółka",
      8. `posprzatane` — liczniki osób, etatów, kont, krawędzi hierarchii i ról wracają
         do stanu z przed pomiaru.

    ⚠ „Zespół" i „Firma" NARAZ u tego konta nie wystąpią i nie jest to brak w pomiarze: żeby
    mieć widok firmy, konto musi mieć wgląd w całą firmę — a wtedy zespół równa się firmie
    i reguła z Z70 słusznie chowa pozycję. Obie pozycje obok siebie wymagają reguły WĘŻSZEJ
    niż cała firma (np. na grupy), co jest osobnym stanem i osobnym pomiarem.

    ⚠ Samą regułę we wszystkich kombinacjach (gałąź mniejsza, równa, brak firmy) sprawdza
    `tests/przelacznik-widoku.php` — tam, gdzie nie trzeba ani przeglądarki, ani konta.
    """
    haslo = HASLO or haslo_dev(LOGIN)
    if not haslo:
        return {"ok": False, "powod": f"brak hasła konta {LOGIN} — pomiaru NIE wykonano"}
    ctx = None
    try:
        ctx = nowy_kontekst(b, usterka, viewport={"width": SZEROKOSC_LAPTOPA, "height": 900},
                            ignore_https_errors=True)
        pg = ctx.new_page()
        pg.goto(BAZA + "/logowanie", wait_until="networkidle")
        pg.fill("#login-email", LOGIN)
        pg.fill("#login-password", haslo)
        pg.click("button[type=submit]")
        pg.wait_for_load_state("networkidle")

        wyniki = {}
        for adres in ("/kalendarz", "/kalendarz?widok=zespol"):
            pg.goto(BAZA + adres, wait_until="networkidle")
            wyniki[adres] = pg.evaluate(POMIAR_PRZELACZNIKA)

        glowny = wyniki["/kalendarz"]
        zAdresu = wyniki["/kalendarz?widok=zespol"]
        wszystkie_zdania = glowny["zdania"] + zAdresu["zdania"]

        bez_pozycji_zespolu = "Zespół" not in glowny["pozycje"] and "Zespół" not in zAdresu["pozycje"]
        # ⚠ Brak pozycji „Zespół" to jedno, a drugie — że adres jej nie przywraca. Przy jednym
        # widoku przełącznika nie ma wcale, więc o widoku rozstrzyga ZDANIE o zasięgu firmy.
        adres_nie_przywraca = any("całej firmy" in z for z in zAdresu["zdania"])
        zdanie_bez_spolki = not any(ma_slowo_spolka(z) for z in wszystkie_zdania)
        zdanie_bez_galezi = not any("swoją gałąź" in z for z in wszystkie_zdania)

        # ── Strona DODATNIA: własna gałąź próbna (Z71) ──────────────────────────────────
        probka = None
        dodatnia: dict = {"pozycja_przy_galezi": False, "zespol_to_galaz": False,
                          "zdanie_o_galezi": False, "posprzatane": False}
        try:
            probka = probka_zespolu("utworz")
            ctx2 = nowy_kontekst(b, usterka, viewport={"width": SZEROKOSC_LAPTOPA, "height": 900},
                                 ignore_https_errors=True)
            pg2 = ctx2.new_page()
            pg2.goto(BAZA + "/logowanie", wait_until="networkidle")
            pg2.fill("#login-email", probka["login"])
            pg2.fill("#login-password", probka["haslo"])
            pg2.click("button[type=submit]")
            pg2.wait_for_load_state("networkidle")
            pg2.goto(BAZA + "/kalendarz?widok=zespol", wait_until="networkidle")
            m = pg2.evaluate(POMIAR_PRZELACZNIKA)
            ctx2.close()
            dodatnia["pozycja_przy_galezi"] = "Zespół" in m["pozycje"]
            dodatnia["zespol_to_galaz"] = m["wierszy"] == probka["galaz"]
            dodatnia["zdanie_o_galezi"] = (
                any("swoją gałąź" in z for z in m["zdania"])
                and not any(ma_slowo_spolka(z) for z in m["zdania"])
            )
            dodatnia["pozycje_probki"] = m["pozycje"]
            dodatnia["wierszy_w_zespole"] = m["wierszy"]
            dodatnia["galaz_probki"] = probka["galaz"]
            dodatnia["zdania_probki"] = [z[:120] for z in m["zdania"]][:3]
        finally:
            # ⚠ Sprzątanie ZAWSZE, także po wyjątku i po przerwaniu — i od razu sprawdzone
            # licznikami, nie „wywołaliśmy, więc pewnie zadziałało".
            po = probka_zespolu("sprzataj")
            if probka is not None:
                dodatnia["posprzatane"] = po.get("liczniki") == probka.get("liczniki_przed")
                dodatnia["liczniki_przed"] = probka.get("liczniki_przed")
                dodatnia["liczniki_po"] = po.get("liczniki")

        return {
            "ok": bez_pozycji_zespolu and adres_nie_przywraca and zdanie_bez_spolki
                  and zdanie_bez_galezi and dodatnia["pozycja_przy_galezi"]
                  and dodatnia["zespol_to_galaz"] and dodatnia["zdanie_o_galezi"]
                  and dodatnia["posprzatane"],
            "galaz_probna": dodatnia,
            "bez_pozycji_zespolu": bez_pozycji_zespolu,
            "adres_nie_przywraca": adres_nie_przywraca,
            "zdanie_bez_spolki": zdanie_bez_spolki,
            "zdanie_bez_galezi": zdanie_bez_galezi,
            "pozycje": glowny["pozycje"],
            "pozycje_z_adresu": zAdresu["pozycje"],
            "zdania": [z[:120] for z in wszystkie_zdania][:6],
        }
    except Exception as e:  # noqa: BLE001 — wynik czerwony z powodem, nie wywrócony przebieg
        return {"ok": False, "powod": f"{type(e).__name__}: {e}"}
    finally:
        if ctx is not None:
            ctx.close()


def sprawdz_znacznik_dzis(b, usterka: str | None = None) -> dict:
    """**Znacznik „dziś": jeden na widok, czytelny i ustępujący rodzajowi** (Z23 + tura Z22).

    ⚠ **Sześć twierdzeń, każde łamane osobno.** Kryterium mierzy REGUŁĘ, nie liczbę z dnia:
    nie „tło ma być #E3EEFB", tylko „numer da się przeczytać, ramka widać, rodzaj wygrywa
    z błękitem, a znacznik jest dokładnie jeden".
      1. `numer_czytelny` — kontrast numeru do jego własnego tła ≥ 4,5 (próg tekstu) w KAŻDYM
         z trzech widoków i na każdym tle rodzaju,
      2. `ramka_widoczna` — kontrast ramki „dziś" do tła, na którym leży, ≥ 3,0 (WCAG 1.4.11,
         ten sam próg co przy paskach w Z6),
      3. `rodzaj_wygrywa` — gdy dziś wypada w dzień z nieobecnością albo wolny, **wypełnienie
         zostaje rodzaju**, a „dziś" niesie sama ramka. Mierzone podstawieniem klasy na żywej
         kratce: inaczej trzeba by czekać, aż kalendarz sam taki dzień pokaże,
      4. `jeden_znacznik` — dokładnie JEDEN znacznik na widok. Dwa znaczą, że „dziś" przestało
         być jednym dniem, a to najczęstszy objaw błędu w wyliczaniu zakresu dat,
      5. `jedna_nazwa` — w szablonach i arkuszach jedna nazwa klasy (`--dzis`), zero `--today`,
      6. `w_naglowku` — na osi czasu dzień dzisiejszy jest wyróżniony w nagłówku i **różni się**
         od pozostałych nagłówków, nie tylko ma klasę.

    ⚠ `rok_bez_przewijania` **skasowane** (tura Z22): przewijanie w poziomie mierzy teraz
    `sprawdz_skale_czasu` na czterech szerokościach, a dwa kryteria pilnujące tej samej rzeczy
    kompensują się — jedno świeci czerwono, drugie zielono i nie wiadomo, które mówi prawdę.
    """
    haslo = HASLO3 or haslo_dev(LOGIN3)
    if not haslo:
        return {"ok": False, "powod": f"brak hasła konta {LOGIN3} — pomiaru NIE wykonano"}
    nazwy = nazwy_znacznika_dzis()
    # ⚠ Miesiąc BIEŻĄCY: znacznik „dziś" istnieje tylko w nim.
    from datetime import date
    biezacy = date.today().strftime("%Y-%m")
    try:
        spolka_ja = int(zatrudnienia_pracownika().get("spolka") or 0)
    except Exception:  # noqa: BLE001
        spolka_ja = 0
    ogon_spolki = f"&spolka={spolka_ja}" if spolka_ja > 0 else ""
    ctx = None
    try:
        ctx = nowy_kontekst(b, usterka, viewport={"width": 1920, "height": 1080},
                            ignore_https_errors=True)
        pg = ctx.new_page()
        pg.goto(BAZA + "/logowanie", wait_until="networkidle")
        pg.fill("#login-email", LOGIN3)
        pg.fill("#login-password", haslo)
        pg.click("button[type=submit]")
        pg.wait_for_load_state("networkidle")
        if "/kontekst" in pg.url:
            pg.locator(".page form button[type=submit]").first.click()
            pg.wait_for_load_state("networkidle")

        pomiary = {}
        # ⚠ Te same trzy szerokości, co w przebiegu po ekranach (Z63): 1920 / 1366 / 374.
        for etyk, szer, wys in (("1920", 1920, 1080), ("1366", 1366, 900), ("374", 374, 812)):
            pg.set_viewport_size({"width": szer, "height": wys})
            for skala in ("miesiac", "rok"):
                ogon = "" if skala == "miesiac" else "&skala=rok"
                pg.goto(BAZA + f"/kalendarz?widok=ja&miesiac={biezacy}{ogon}{ogon_spolki}",
                        wait_until="networkidle")
                pomiary[f"{etyk}-{skala}"] = pg.evaluate(POMIAR_DZIS)

        # ⚠ Oś czasu stoi na koncie Z NADANYM poziomem — konto pracownika bez podwładnych
        # widoku firmy nie dostaje, więc nagłówka „dziś" nie byłoby czym zmierzyć.
        os_czasu = None
        spolka_adm = int(dane_paskow().get("spolka") or 0)
        haslo_adm = HASLO or haslo_dev(LOGIN)
        if spolka_adm > 0 and haslo_adm:
            poziom_przed = poziom_kalendarza(spolka_adm, None, LOGIN).get("przed", 0)
            try:
                poziom_kalendarza(spolka_adm, 2, LOGIN)
                ctx_adm = nowy_kontekst(b, usterka, viewport={"width": 1920, "height": 1080},
                                        ignore_https_errors=True)
                adm = ctx_adm.new_page()
                adm.goto(BAZA + "/logowanie", wait_until="networkidle")
                adm.fill("#login-email", LOGIN)
                adm.fill("#login-password", haslo_adm)
                adm.click("button[type=submit]")
                adm.wait_for_load_state("networkidle")
                adm.goto(BAZA + f"/kalendarz?widok=firma&miesiac={biezacy}&spolka={spolka_adm}",
                         wait_until="networkidle")
                os_czasu = adm.evaluate(POMIAR_DZIS)
                ctx_adm.close()
            finally:
                try:
                    poziom_kalendarza(spolka_adm, poziom_przed, LOGIN)
                except Exception:  # noqa: BLE001
                    pass

        wszystkie = list(pomiary.values()) + ([os_czasu] if os_czasu else [])
        zeZnacznikiem = [p for p in wszystkie if p["znacznik"] is not None]
        numery = [p["znacznik"]["kontrast_numeru"] for p in zeZnacznikiem]
        numery += [r["kontrast_numeru"] for p in zeZnacznikiem for r in p["na_rodzaju"].values()]
        ramki = [p["znacznik"]["kontrast_ramki"] for p in zeZnacznikiem
                 if p["znacznik"]["kontrast_ramki"] is not None]
        ramki_rodzaj = [r["kontrast_ramki"] for p in zeZnacznikiem
                        for r in p["na_rodzaju"].values() if r["kontrast_ramki"] is not None]

        numer_czytelny = zeZnacznikiem != [] and all(k >= 4.5 for k in numery)
        # ⚠ Ramka na własnym, błękitnym tle jest SŁABA z założenia (to obrys wypełnienia, nie
        # znak sam w sobie) — próg 3:1 obowiązuje tam, gdzie ramka JEST znacznikiem, czyli
        # na dniu z rodzajem. Na kratce zwykłej wymagamy tylko, żeby wypełnienie się odróżniało.
        ramka_widoczna = ramki_rodzaj != [] and all(k >= 3.0 for k in ramki_rodzaj)
        wypelnienie_widac = all(p["znacznik"]["kontrast_wypelnienia"] > 1.03 for p in zeZnacznikiem)
        rodzaj_wygrywa = zeZnacznikiem != [] and all(
            r["rodzaj_wygrywa"] and r["numer_kolor"] == p["znacznik"]["numer_kolor"]
            for p in zeZnacznikiem for r in p["na_rodzaju"].values()
        ) and all(p["na_rodzaju"] != {} for p in pomiary.values())
        jeden_znacznik = all(p["znacznikow_w_siatce"] == 1 for p in pomiary.values()) and (
            os_czasu is None or os_czasu["znacznikow_w_naglowku"] == 1)
        jedna_nazwa = nazwy["--today"] == 0 and nazwy["--dzis"] > 0
        w_naglowku = os_czasu is not None and os_czasu["naglowek_dzis"] and os_czasu["naglowek_rozni_sie"]
        kratki = {k: v["kratka"] for k, v in pomiary.items()
                  if k.endswith("-rok") and v["kratka"] is not None}
        kratka_ksztalt = kratki != {} and all(
            KRATKA_PR_MIN <= v["pr"] <= KRATKA_PR_MAX for v in kratki.values())

        return {
            "ok": (numer_czytelny and ramka_widoczna and wypelnienie_widac and rodzaj_wygrywa
                   and jeden_znacznik and jedna_nazwa and w_naglowku and kratka_ksztalt),
            "numer_czytelny": numer_czytelny,
            "ramka_widoczna": ramka_widoczna,
            "wypelnienie_widac": wypelnienie_widac,
            "rodzaj_wygrywa": rodzaj_wygrywa,
            "jeden_znacznik": jeden_znacznik,
            "jedna_nazwa": jedna_nazwa,
            "w_naglowku": w_naglowku,
            "kratka_ksztalt": kratka_ksztalt,
            "nazwy_klas": nazwy,
            "miesiac_pomiaru": biezacy,
            "kontrast_numeru": {"min": min(numery) if numery else None, "wszystkie": sorted(set(numery))},
            "kontrast_ramki_na_rodzaju": {"min": min(ramki_rodzaj) if ramki_rodzaj else None,
                                          "wszystkie": sorted(set(ramki_rodzaj))},
            "kontrast_ramki_na_blekicie": sorted(set(ramki)),
            "na_rodzaju": (zeZnacznikiem[0]["na_rodzaju"] if zeZnacznikiem else {}),
            "znaczniki": {k: {"w_siatce": v["znacznikow_w_siatce"],
                              "w_naglowku": v["znacznikow_w_naglowku"],
                              "tlo": (v["znacznik"] or {}).get("tlo"),
                              "ramka": (v["znacznik"] or {}).get("ramka"),
                              "numer": (v["znacznik"] or {}).get("numer_kolor")}
                          for k, v in pomiary.items()},
            "os_czasu": None if os_czasu is None else {
                "naglowkow_dzis": os_czasu["znacznikow_w_naglowku"],
                "rozni_sie": os_czasu["naglowek_rozni_sie"],
                "tlo": (os_czasu["znacznik"] or {}).get("tlo")},
            "kratki": kratki,
        }
    except Exception as e:  # noqa: BLE001
        return {"ok": False, "powod": str(e)}
    finally:
        if ctx is not None:
            try:
                ctx.close()
            except Exception:  # noqa: BLE001
                pass


def sprawdz_jedna_wizualizacje(b, usterka: str | None = None) -> dict:
    """**Jedna wizualizacja kalendarza dla wszystkich kont** (zgłoszenie Krzysztofa 07.10.2026).

    „Wizualizacja kalendarza ma być trzymana w jednym miejscu, aby zmiana szła na wszystkich
    użytkowników". Do 07.10 było odwrotnie: „Tylko ja" miało własną siatkę miesiąca, a „Zespół"
    i „Firma" oś czasu z paskami. Zmierzone przed zmianą na koncie pracownika: `.calendar-grid`
    obecne, `.team-grid__bar` **zero** — na wszystkich trzech adresach widoków. Ponieważ zwykły
    pracownik (bez podwładnych i bez nadanego poziomu) ma dostęp wyłącznie do „Tylko ja",
    żadna poprawka wyglądu nieobecności do niego nie dotarła.

    ⚠ **Trzy kryteria, każde do złamania osobno:**
      1. `jedna_wizualizacja` — na KAŻDYM koncie i w każdym widoku rysuje się oś czasu
         (`.team-grid`), a stara siatka (`.calendar-grid`, `.day-cell`) nie istnieje. Liczymy
         oba, nie jedno: sama obecność osi czasu nie wyklucza, że obok wróciła druga siatka,
      2. `wlasna_lista` — pod osią czasu w widoku własnym stoi wykaz nieobecności z kadr
         i **zgadza się z paskami**: tyle wierszy, ile ciągów nieobecności w tym miesiącu.
         Bez tego oś czasu bez listy przechodziłaby jako „scalone",
      3. `znacznik_pracownika` — pracownik z etatami w dwóch spółkach ma znacznik spółki w belce
         i przełączenie DZIAŁA (zmienia spółkę na ekranie), a kontekst pulpitu zostaje
         nietknięty, bo `?spolka=` jest parametrem widoku, nie zmianą stanu (CLAUDE.md §3).
    """
    haslo_prac = HASLO3 or haslo_dev(LOGIN3)
    if not haslo_prac:
        return {"ok": False, "powod": f"brak hasła konta {LOGIN3} — pomiaru NIE wykonano"}
    spolki = spolki_pracownika()
    if len(spolki) < 2:
        return {"ok": False, "powod": f"konto {LOGIN3} ma {len(spolki)} spółek — kontrolki "
                                      "wyboru spółki nie da się zmierzyć"}
    # ⚠ **Komponenty WSPÓLNE, nie jeden komponent** — tak brzmi dzisiejsza postać tego samego
    # wymagania. Do 13.10.2026 każdy widok rysował oś czasu i kryterium pytało wprost o `.team-grid`.
    # Po rozstrzygnięciu z 13.10 (§4c systemu wizualnego) „Tylko ja" rysuje SIATKĘ (`.msiatka` —
    # ten sam komponent, co skala Roku) z wykazem wpisów obok, a Zespół i Firma zostają przy osi
    # czasu, bo tam wiersz znaczy OSOBĘ. Pytanie zostaje to samo i jest sednem zgłoszenia
    # Krzysztofa: czy pracownik widzi te same komponenty, co administrator, czy własny, martwy
    # ekran, do którego nie dochodzi żadna poprawka. Dlatego liczymy **rodzinę wspólną**
    # (`.msiatka` albo `.team-grid`) i **zero klas zniesionych**.
    STAN = """() => ({
      os_czasu: document.querySelectorAll('.team-grid').length,
      siatka: document.querySelectorAll('.msiatka').length,
      // ⚠ **Obca krata liczona STRUKTURALNIE, nie z listy zniesionych nazw** (Z63 cz. A).
      // Do 20.10.2026 stała tu lista klas (`.calendar-grid`, `.day-cell`, `.calendar-weekdays`,
      // `.month-grid`, `.rok__dzien`, `.kwartal__kratka`) — żadnej z nich nie ma już ani
      // w szablonach, ani w arkuszu, więc warunek „zero klas zniesionych" **nie mógł zapalić
      // się nigdy** i był ozdobą, nie bramką. Pytanie ze zgłoszenia Krzysztofa zostaje to samo:
      // czy obok komponentu wspólnego nie stoi druga, własna krata dni. Liczymy więc pudełka
      // dnia leżące POZA rodziną wspólną — niezależnie od tego, jak ktoś je nazwie.
      obce_kraty: [...document.querySelectorAll('[class*="kratka"], [class*="day-cell"],'
        + ' [class*="day-entry"], [class*="calendar"], [class*="month-grid"]')]
        .filter(e => !e.closest('.msiatka, .team-grid')).length,
      wierszy: document.querySelectorAll('.team-grid__person').length,
      paskow: document.querySelectorAll('.team-grid__bar').length,
      wpisow: document.querySelectorAll('.wpisy__poz').length,
      ma_odnosnik: document.querySelector('.mies__wpisy .card__meta a') !== null,
      lista: document.querySelectorAll('.table--rekordy tbody tr').length,
      lista_pusta: document.querySelectorAll('.table--rekordy tbody .empty-state').length,
      pusty_stan: document.querySelectorAll('.empty-state').length,
      znacznik: ((document.querySelector('.spolka__nazwa') || {}).textContent || '').trim(),
      tytul: ((document.querySelector('.card__title') || {}).textContent || '').trim()
    })"""
    poziomy: list[tuple[int, int]] = []
    try:
        _ids_prac = zatrudnienia_pracownika()
        _spolka_prac = int(_ids_prac.get("spolka") or 0)
    except Exception:  # noqa: BLE001
        _ids_prac, _spolka_prac = {}, 0
    OGON_PRAC = f"&spolka={_spolka_prac}" if _spolka_prac > 0 else ""
    ctx = None
    try:
        wynik: dict = {}
        # Pracownik — konto, które przed zmianą widziało WYŁĄCZNIE starą siatkę.
        ctx = nowy_kontekst(b, usterka, viewport={"width": SZEROKOSC_LAPTOPA, "height": 1000}, ignore_https_errors=True)
        pg = ctx.new_page()
        pg.goto(BAZA + "/logowanie", wait_until="networkidle")
        pg.fill("#login-email", LOGIN3)
        pg.fill("#login-password", haslo_prac)
        pg.click("button[type=submit]")
        pg.wait_for_load_state("networkidle")
        if "/kontekst" in pg.url:
            pg.locator("button.settings-list__row").first.click()
            pg.wait_for_load_state("networkidle")
        # ⚠ **Adres niesie `&spolka=`, czyli firmę TEGO etatu, z którym porównujemy bazę**
        # (ta sama poprawka, co w `sprawdz_skale_czasu` — i ta sama pułapka). Konto pomiarowe
        # ma dwa etaty, a domyślny kontekst wybiera ten drugi: zmierzone 17.10.2026 w pełnym
        # przebiegu — ekran stał w firmie z **jedną** nieobecnością w sierpniu, a `wlasna_lista`
        # porównywała go z etatem 76 w drugiej firmie, gdzie są **dwie**. Kryterium świeciło
        # czerwono na poprawnym ekranie, czyli mierzyło kontekst, a nie wykaz.
        for widok in ("ja", "zespol", "firma"):
            pg.goto(BAZA + f"/kalendarz?widok={widok}&miesiac=2026-08{OGON_PRAC}",
                    wait_until="networkidle")
            wynik["pracownik_" + widok] = pg.evaluate(STAN)
        # Przełączenie spółki kontrolką — i sprawdzenie, że kontekst pulpitu ZOSTAJE.
        pg.goto(BAZA + "/", wait_until="networkidle")
        kontekst_przed = pg.evaluate("() => document.querySelector('main').innerText.split('\\n')[2]")
        pg.goto(BAZA + "/kalendarz?miesiac=2026-08", wait_until="networkidle")
        combo_przed = pg.evaluate("() => ((document.querySelector('.spolka__nazwa')||{}).textContent||'').trim()")
        pozycje = pg.evaluate("() => Array.from(document.querySelectorAll('.spolka__pozycja')).map(a => a.textContent.trim())")
        inny = next((i for i, t in enumerate(pozycje) if t != combo_przed), None)
        if inny is not None:
            pg.locator("details.spolka > summary").click()
            pg.locator(".spolka__pozycja").nth(inny).click()
            pg.wait_for_timeout(1500)
        combo_po = pg.evaluate("() => ((document.querySelector('.spolka__nazwa')||{}).textContent||'').trim()")
        pg.goto(BAZA + "/", wait_until="networkidle")
        kontekst_po = pg.evaluate("() => document.querySelector('main').innerText.split('\\n')[2]")
        ctx.close()

        # Administrator z nadanym poziomem — konto, które widziało NOWY wygląd.
        for sp in spolki:
            stan = poziom_kalendarza(int(sp["id"]), 2, LOGIN)
            poziomy.append((int(sp["id"]), int(stan["przed"])))
        ctx = nowy_kontekst(b, usterka, viewport={"width": SZEROKOSC_LAPTOPA, "height": 1000}, ignore_https_errors=True)
        pg = ctx.new_page()
        pg.goto(BAZA + "/logowanie", wait_until="networkidle")
        pg.fill("#login-email", LOGIN)
        pg.fill("#login-password", HASLO or haslo_dev(LOGIN))
        pg.click("button[type=submit]")
        pg.wait_for_load_state("networkidle")
        pg.goto(BAZA + "/kalendarz?widok=firma&miesiac=2026-08", wait_until="networkidle")
        wynik["admin_firma"] = pg.evaluate(STAN)

        # ⚠ Każdy ekran rysuje DOKŁADNIE JEDNĄ wizualizację z rodziny wspólnej i zero klas
        # zniesionych. Liczymy oba warunki, nie jeden: sama obecność siatki nie wyklucza, że obok
        # wróciła druga, stara.
        # ⚠ Trzy warunki, nie jeden: na każdym ekranie jest komponent z rodziny wspólnej,
        # **tylko jeden z dwóch** (siatka albo oś czasu, nigdy oba naraz — to jest „jedna
        # wizualizacja" w dosłownym sensie) i zero obcych krat dnia poza rodziną.
        jedna_wizualizacja = all(
            (w["siatka"] + w["os_czasu"]) >= 1
            and (w["siatka"] == 0) != (w["os_czasu"] == 0)
            and w["obce_kraty"] == 0
            for w in wynik.values()
        ) and wynik["pracownik_ja"]["siatka"] == 1 and wynik["pracownik_ja"]["os_czasu"] == 0 \
          and wynik["admin_firma"]["os_czasu"] == 1 and wynik["admin_firma"]["siatka"] == 0
        wlasny = wynik["pracownik_ja"]
        # ⚠ Wykaz obok siatki liczy to samo zjawisko, co kratki, drugą drogą: wiersz wykazu to
        # CIĄG nieobecności z kadr. Od Z14 (17.10.2026) wykaz pokazuje **najwyżej pięć** pozycji
        # i odnośnik „wszystkie (N)", więc kryterium porównuje z BAZĄ, nie z liczbą kratek:
        # „tyle, ile jest, ale nie więcej niż pięć — a jeśli więcej, to z odnośnikiem".
        ciagow = None
        try:
            # ⚠ Identyfikator zatrudnienia CZYTAMY Z BAZY (`zatrudnienia_pracownika`), nie
            # wpisujemy: po pierwszym resecie danych wpisany numer pilnowałby nieistniejącego
            # zatrudnienia i kryterium świeciłoby zielono bez pomiaru.
            ciagow = nieobecnosci_pracownika(
                int(zatrudnienia_pracownika()["ja"]), "2026-08-01", "2026-08-31")
        except Exception:  # noqa: BLE001
            ciagow = None
        wlasna_lista = (
            wlasny["wpisow"] > 0 and wlasny["pusty_stan"] == 0
            and wlasny["wpisow"] <= 5
            and (ciagow is None or (
                wlasny["wpisow"] == min(5, ciagow["wpisow"])
                and (wlasny["ma_odnosnik"] == (ciagow["wpisow"] > 5))))
            # Na widoku firmy wykazu nie ma — tam wiersz znaczy osobę, nie nieobecność.
            and wynik["admin_firma"]["wpisow"] == 0
        )
        # ⚠ **Kryterium zawężone 07.10.2026 (wariant C) — i to NIE jest rozluźnienie.**
        # Do wariantu C stało tu `kontekst_po == kontekst_przed`: kontrolka była parametrem
        # widoku, więc kontekst pulpitu MIAŁ zostać nietknięty. Po C pozycja spółki z etatem
        # przełącza kontekst celowo, więc ten warunek opisywał już stan sprzed naprawy —
        # bramka zapaliła się na poprawnym zachowaniu (zmierzone: pulpit „Piaskarz · 0,50"
        # → „Beta · 0,25").
        # ⚠ Pytanie „czy kontekst poszedł za kalendarzem" ma teraz **jednego właściciela**:
        # `sprawdz_kontekst_spolki`, razem z kierunkiem odwrotnym i dziennikiem. Tutaj zostaje
        # to, czego tamto sprawdzenie nie pyta: że pracownik **ma** kontrolkę i że przełączenie
        # zmienia spółkę pokazaną w kalendarzu. Dopisanie warunku o kontekście z powrotem
        # oznaczałoby dwa miejsca pilnujące jednej rzeczy — i jedno z nich nieaktualne.
        # ⚠ Od 10.10.2026 mierzy **znacznik w belce**, nie kontrolkę w nagłówku ekranu
        # (zlecenie o globalnym wyborze spółki §1). Pytanie zostaje: pracownik z dwoma etatami
        # musi mieć czym przełączyć i przełączenie musi zmienić spółkę pokazaną w kalendarzu.
        znacznik_pracownika = (
            combo_przed != "" and inny is not None and combo_po == pozycje[inny]
            and combo_po != combo_przed
        )

        return {
            "ok": jedna_wizualizacja and wlasna_lista and znacznik_pracownika,
            "jedna_wizualizacja": jedna_wizualizacja,
            "wlasna_lista": wlasna_lista,
            "znacznik_pracownika": znacznik_pracownika,
            "ekrany": wynik,
            "ciagow_w_bazie": ciagow,
            "znacznik": {"przed": combo_przed, "po": combo_po, "pozycje": pozycje},
            "kontekst_pulpitu": {"przed": kontekst_przed, "po": kontekst_po},
        }
    except Exception as e:  # noqa: BLE001
        return {"ok": False, "powod": str(e)}
    finally:
        for spolka_id, przed in poziomy:
            try:
                poziom_kalendarza(spolka_id, przed, LOGIN)
            except Exception:  # noqa: BLE001
                pass
        if ctx is not None:
            try:
                ctx.close()
            except Exception:  # noqa: BLE001
                pass


def sprawdz_kontekst_spolki(b) -> dict:
    """**Przełączenie spółki pracy z kalendarza** — wariant C (zlecenie `2026-10-07-kontekst-…`).

    Do 07.10.2026 kontrolka w kalendarzu zmieniała tylko WIDOK, a kontekst pracy (pulpit, limity,
    wnioski) zostawał tam, gdzie go ustawiono. Zmierzone: kalendarz „Beta", pulpit
    „Piaskarz · etat 0,50". ⚠ Pracownik na dwóch połówkach etatu ma DWA różne limity, więc
    patrząc na kalendarz jednej spółki z limitem drugiej planował urlop na cudzej liczbie —
    i to jest nieprawda niezależnie od tego, czy da się na niej kliknąć „Wyślij".

    ⚠ **Pięć kryteriów, każde o czymś innym** (lista odbioru z §5 zlecenia):
      1. `przelacza_kontekst` — przełączenie W KALENDARZU zmienia spółkę **w obu miejscach**
         (kalendarz i pulpit) i wraca na ten sam miesiąc,
      2. `naglowek_wiodacy` — przełączenie W NAGŁÓWKU (`/kontekst`) ciągnie za sobą kalendarz;
         jeden właściciel decyzji znaczy, że działa w obie strony,
      3. `obce_odrzucone` — `POST /kontekst/{cudze zatrudnienie}` odrzucone (404), kontekst
         nietknięty. Kontrolka przestała być „tylko widokiem", więc musi mieć sprawdzenie
         właściciela zasobu jak każda inna akcja (§10),
      4. `dwa_rodzaje` — pozycje o dwóch znaczeniach są widoczne jako dwa rodzaje PRZED
         kliknięciem (nagłówki sekcji), a przy jednym rodzaju nagłówków nie ma,
      5. `bez_js` — formularz działa bez JavaScriptu i wraca na ten sam miesiąc.

    ⚠ Kryterium 4 mierzy **szablon**, nie ekran, i to jest świadome: przypadku mieszanego
    (etat w jednej spółce, podgląd w drugiej) nie da się ustawić na danych tego tenanta bez
    zmieniania komuś danych kadrowych. Pomiar idzie przez `dev/probka-kontrolki-spolek.php`,
    który renderuje **ten sam plik** z `strict_variables` jak aplikacja.
    """
    import subprocess
    haslo_prac = HASLO3 or haslo_dev(LOGIN3)
    if not haslo_prac:
        return {"ok": False, "powod": f"brak hasła konta {LOGIN3} — pomiaru NIE wykonano"}

    kod = (
        'require "vendor/autoload.php";'
        'Dotenv\\Dotenv::createImmutable(__DIR__)->load();'
        '$e = $_ENV;'
        '$p = new PDO("mysql:host={$e[\'DB_HOST\']};port={$e[\'DB_PORT\']};dbname={$e[\'DB_NAME\']};charset=utf8mb4",'
        '  $e["DB_USER"], $e["DB_PASS"], [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);'
        '$st = $p->prepare("SELECT e.id FROM employment e WHERE e.is_active = 1'
        '  AND e.person_id <> (SELECT person_id FROM account WHERE email = :m) ORDER BY e.id LIMIT 1");'
        '$st->execute(["m" => $argv[1]]);'
        '$obce = $st->fetchColumn();'
        '$st = $p->prepare("SELECT company_id, subject_id FROM event_log WHERE event_code = \'context.switched\''
        '  ORDER BY id DESC LIMIT 1");'
        '$st->execute();'
        '$ost = $st->fetch();'
        'echo json_encode(["obce" => $obce === false ? null : (int) $obce,'
        '  "ostatni_wpis" => $ost === false ? null : $ost]);'
    )

    def z_bazy() -> dict:
        wyn = subprocess.run(["php", "-r", kod, LOGIN3], cwd=KORZEN, capture_output=True, text=True, check=True)
        return json.loads(wyn.stdout or "{}")

    def probka_szablonu() -> dict:
        wyn = subprocess.run(["php", "dev/probka-kontrolki-spolek.php"],
                             cwd=KORZEN, capture_output=True, text=True)
        if wyn.returncode != 0 or not wyn.stdout.strip():
            raise RuntimeError(f"probka-kontrolki-spolek.php kod={wyn.returncode}: "
                               f"{(wyn.stderr or wyn.stdout).strip()[:300]}")
        return json.loads(wyn.stdout)

    ADRES = "/kalendarz?widok=ja&miesiac=2026-08"
    ctx = None
    ctx2 = None
    try:
        przed_baza = z_bazy()
        szablon = probka_szablonu()

        def zaloguj_pracownika(kontekst):
            pg = kontekst.new_page()
            pg.goto(BAZA + "/logowanie", wait_until="networkidle")
            pg.fill("#login-email", LOGIN3)
            pg.fill("#login-password", haslo_prac)
            pg.click("button[type=submit]")
            pg.wait_for_load_state("networkidle")
            if "/kontekst" in pg.url:
                pg.locator("button.settings-list__row").first.click()
                pg.wait_for_load_state("networkidle")
            return pg

        ctx = b.new_context(viewport={"width": SZEROKOSC_LAPTOPA, "height": 1000}, ignore_https_errors=True)
        pg = zaloguj_pracownika(ctx)

        def spolka_pulpitu() -> str:
            """Nazwa spółki, w której kontekście stoi PULPIT.

            ⚠ Czytana z **zaznaczonej pozycji przełącznika spółek**, a nie ze zdania pod tytułem:
            `.page__sub` zszedł z pulpitu 17.10.2026 razem z całym podtytułem (decyzja Krzysztofa),
            więc odczyt zwracał pusty napis i kryterium nie mogło przejść, choć produkt działał.
            ⚠ Przełącznik pokazuje „NAZWA · etat 1/1", więc bierzemy człon przed kropką — nazwa
            jest tym, co ma się zgadzać z kalendarzem, a etat to osobna dana.
            """
            pg.goto(BAZA + "/", wait_until="networkidle")
            return pg.evaluate(
                "() => ((document.querySelector('.page__head .segmented__item--on') || {})"
                ".textContent || '').split('\u00b7')[0].trim()")

        def spolka_kalendarza() -> str:
            pg.goto(BAZA + ADRES, wait_until="networkidle")
            return pg.evaluate("() => ((document.querySelector('.spolka__nazwa')||{}).textContent||'').trim()")

        # 1. Przełączenie w kalendarzu.
        kal_przed = spolka_kalendarza()
        pul_przed = spolka_pulpitu()
        pg.goto(BAZA + ADRES, wait_until="networkidle")
        pg.evaluate("() => { document.querySelector('details.spolka').open = true; }")
        pozycje = pg.evaluate(
            "() => Array.from(document.querySelectorAll('.spolka__pozycja')).map(e => e.textContent.trim())")
        cel = next((t for t in pozycje if t != kal_przed), None)
        if cel is None:
            return {"ok": False, "powod": f"konto {LOGIN3} ma jedną pozycję z etatem ({pozycje}) "
                                          "— nie ma czego przełączać; pomiaru NIE wykonano"}
        pg.locator(".spolka__pozycja", has_text=cel).click()
        pg.wait_for_load_state("networkidle")
        adres_po = pg.url.replace(BAZA, "")
        kal_po = pg.evaluate("() => ((document.querySelector('.spolka__nazwa')||{}).textContent||'').trim()")
        pul_po = spolka_pulpitu()

        # 2. Przełączenie w nagłówku — kalendarz ma pójść za kontekstem.
        pg.goto(BAZA + "/kontekst", wait_until="networkidle")
        pg.locator("button.settings-list__row", has_text=kal_przed).click()
        pg.wait_for_load_state("networkidle")
        kal_po_naglowku = spolka_kalendarza()
        pul_po_naglowku = spolka_pulpitu()

        # 3. Cudze zatrudnienie — odrzucone.
        pg.goto(BAZA + ADRES, wait_until="networkidle")
        csrf = pg.evaluate("() => (document.querySelector('input[name=_csrf]') || {}).value || ''")
        obce = przed_baza.get("obce")
        proba = pg.evaluate("""async ({id, csrf}) => {
          const r = await fetch('/kontekst/' + id, {method: 'POST', redirect: 'follow',
            body: new URLSearchParams({_csrf: csrf, powrot: 'kalendarz', widok: 'ja', miesiac: '2026-08'})});
          return {kod: r.status};
        }""", {"id": obce, "csrf": csrf})
        kal_po_probie = spolka_kalendarza()
        ctx.close()
        ctx = None

        # 5. Bez JavaScriptu.
        ctx2 = b.new_context(viewport={"width": SZEROKOSC_LAPTOPA, "height": 1000}, ignore_https_errors=True,
                             java_script_enabled=False)
        p2 = ctx2.new_page()
        p2.goto(BAZA + "/logowanie", wait_until="networkidle")
        p2.fill("#login-email", LOGIN3)
        p2.fill("#login-password", haslo_prac)
        p2.click("button[type=submit]")
        p2.wait_for_load_state("networkidle")
        if "/kontekst" in p2.url:
            p2.locator("button.settings-list__row").first.click()
            p2.wait_for_load_state("networkidle")
        p2.goto(BAZA + ADRES, wait_until="networkidle")
        bez_js_przed = p2.locator(".spolka__nazwa").first.inner_text().strip()
        p2.locator("details.spolka > summary").click()
        teksty = [t.strip() for t in p2.locator(".spolka__pozycja").all_inner_texts()]
        cel2 = next((t for t in teksty if t != bez_js_przed), None)
        bez_js_po = bez_js_przed
        bez_js_adres = ""
        if cel2 is not None:
            p2.locator(".spolka__pozycja", has_text=cel2).click()
            p2.wait_for_load_state("networkidle")
            bez_js_po = p2.locator(".spolka__nazwa").first.inner_text().strip()
            bez_js_adres = p2.url.replace(BAZA, "")
            # ⚠ **Pomiar sprząta po sobie** — i od 10.10.2026 musi, bo kontekst jest TRWAŁY
            # (kolumna `account.context_company_id`). Krok „bez JavaScriptu" przełącza spółkę
            # pracy konta pracowniczego; zostawiona zmiana wędrowała do następnych sprawdzeń
            # i czerwieniła `zasieg` oraz `poziomy`, bo mierzyły kalendarz **innej spółki**,
            # niż ta, w której nadały poziom. Zmierzone: dwa sprawdzenia czerwone z powodu
            # zmiany zrobionej przez trzecie.
            p2.locator("details.spolka > summary").first.click()
            p2.locator(".spolka__pozycja", has_text=bez_js_przed).first.click()
            p2.wait_for_load_state("networkidle")

        po_baza = z_bazy()

        przelacza_kontekst = (
            kal_po == cel and cel in pul_po and kal_przed in pul_przed
            and kal_po != kal_przed and "miesiac=2026-08" in adres_po
        )
        naglowek_wiodacy = kal_po_naglowku == kal_przed and kal_przed in pul_po_naglowku
        obce_odrzucone = (
            obce is not None and proba["kod"] == 404 and kal_po_probie == kal_przed
            # ⚠ Odrzucona próba NIE zapisuje `context.switched` — inaczej dziennik mówiłby,
            # że kontekst się zmienił, a nie zmienił się.
            and po_baza["ostatni_wpis"] is not None
            and int(po_baza["ostatni_wpis"]["company_id"]) != 0
        )
        # ⚠ Kontrakt znacznika (`partials/znacznik-spolki.twig`), nie zniesionej `.combo`:
        #  • dwa rodzaje pozycji widać jako dwa rodzaje PRZED kliknięciem (nagłówki sekcji),
        #  • każda pozycja to `POST` z tokenem — zero odnośników, zero `hx-*` (§3 i §7 reguł),
        #  • jedna dostępna spółka i ekran tenantowy dają NAPIS, nie kontrolkę,
        #  • utracona spółka zapamiętana jest NAZWANA, nie przemilczana.
        m = szablon["mieszany"]
        naglowki_slownik = (
            len(m["naglowki"]) == 2
            # ⚠ Pierwszy nagłówek to sekcja „moich" firm — pilnujemy SŁOWA, nie zdania.
            and SLOWO_UZYTKOWNIKA in m["naglowki"][0].lower()
            and all(not ma_slowo_spolka(n) for n in m["naglowki"])
            # Drugi nagłówek musi mówić, że tam się tylko patrzy — bez tego oba rodzaje
            # wyglądałyby jak jeden z dwoma podtytułami.
            and "podgląd" in m["naglowki"][1].lower()
        )
        dwa_rodzaje = (
            naglowki_slownik
            and m["kontrolka"] is True and m["stopka"] is True
            and m["pozycji"] == 2 and m["formularzy"] == 2 and m["csrf_w_formularzu"] == 2
            and m["post_do_zatrudnienia"] == 1 and m["post_do_spolki"] == 1
            and m["odnosnikow"] == 0 and m["hx_na_pozycji"] is False
            and m["stan_w_formularzu"] == 2
            and szablon["same_etaty"]["naglowki"] == []
            and szablon["sam_podglad"]["naglowki"] == []
            and szablon["jedna_spolka"]["kontrolka"] is False
            and szablon["jedna_spolka"]["napis"] is True
            and szablon["tenant"]["kontrolka"] is False and szablon["tenant"]["napis"] is True
            and szablon["utracona"]["uwaga_o_utraconej"] is True
            and szablon["mieszany"]["uwaga_o_utraconej"] is False
        )
        bez_js = cel2 is not None and bez_js_po == cel2 and "miesiac=2026-08" in bez_js_adres

        return {
            "ok": przelacza_kontekst and naglowek_wiodacy and obce_odrzucone and dwa_rodzaje and bez_js,
            "przelacza_kontekst": przelacza_kontekst,
            "naglowek_wiodacy": naglowek_wiodacy,
            "obce_odrzucone": obce_odrzucone,
            "dwa_rodzaje": dwa_rodzaje,
            "naglowki_slownik": naglowki_slownik,
            "bez_js": bez_js,
            "kalendarz": {"przed": kal_przed, "po": kal_po, "po_naglowku": kal_po_naglowku},
            "pulpit": {"przed": pul_przed.strip()[:60], "po": pul_po.strip()[:60]},
            "adres_po_przelaczeniu": adres_po,
            "proba_obcego": {"zatrudnienie": obce, **proba, "kalendarz_po": kal_po_probie},
            "bez_js_szczegoly": {"przed": bez_js_przed, "po": bez_js_po, "adres": bez_js_adres},
            "szablon": szablon,
        }
    except Exception as e:  # noqa: BLE001
        return {"ok": False, "powod": str(e)}
    finally:
        for k in (ctx, ctx2):
            if k is not None:
                try:
                    k.close()
                except Exception:  # noqa: BLE001
                    pass


def sprawdz_dziennik_i_zdania(b) -> dict:
    """Tura porządkowa 08.10.2026: **dziennik „z czego na co", zdania zamiast ciszy, kolizja nazw.**

    ⚠ Trzy kryteria, każde o innej usterce z `raporty/2026-10-07-otwarte-zgloszenia.md`:
      1. `dziennik_z_na` — wpis `tenant.company_display_name` niesie **wartość przed i po**.
         To ta usterka, która kosztowała dane: 06.10 skrypt nadpisał nazwę wpisaną ręcznie,
         a dziennik mówił tylko „ustawiono", więc odtworzyć nie było z czego,
      2. `zdania_zamiast_ciszy` — `?widok=zespol` i `?widok=firma` u konta, które na nie
         nie ma prawa, **mówią dlaczego**, a `?widok=ja` milczy (bo nie ma o czym mówić).
         Zmierzone przed naprawą: oba adresy **i wartość bzdurna** dawały identyczny ekran
         i identyczną ciszę,
      3. `kolizja_nazw` — nazwa wyświetlana równa nazwie z kadr innej spółki jest odrzucona,
         a sprawdzenie **serializuje się blokadą**: pod wyścigiem zapis czeka, zamiast przejść
         obok. Mierzy to `dev/probka-kolizji-nazwy.php` na dwóch połączeniach.

    ⚠ Pomiar sprząta po sobie i **nie nadpisuje cudzej wartości**: stan nazwy czytany z ekranu
    przed zapisem, a przy oddawaniu sprawdzany (ta sama reguła co w `sprawdz_nazwe_wyswietlana`).
    """
    import subprocess
    haslo_prac = HASLO3 or haslo_dev(LOGIN3)
    if not haslo_prac:
        return {"ok": False, "powod": f"brak hasła konta {LOGIN3} — zdań o widoku NIE zmierzono"}
    spolki = spolki_pracownika()
    if len(spolki) < 2:
        return {"ok": False, "powod": "tenant ma mniej niż dwie spółki — kolizji nazw nie da się zmierzyć"}

    def wpis_dziennika(kod: str) -> dict | None:
        php = (
            'require "vendor/autoload.php";'
            'Dotenv\\Dotenv::createImmutable(__DIR__)->load();'
            '$e = $_ENV;'
            '$p = new PDO("mysql:host={$e[\'DB_HOST\']};port={$e[\'DB_PORT\']};dbname={$e[\'DB_NAME\']};charset=utf8mb4",'
            '  $e["DB_USER"], $e["DB_PASS"], [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);'
            '$st = $p->prepare("SELECT detail FROM event_log WHERE event_code = :k ORDER BY id DESC LIMIT 1");'
            '$st->execute(["k" => $argv[1]]);'
            '$r = $st->fetch();'
            'echo $r === false ? "null" : ($r["detail"] ?? "null");'
        )
        wyn = subprocess.run(["php", "-r", php, kod], cwd=KORZEN, capture_output=True, text=True, check=True)
        tresc = wyn.stdout.strip()
        return None if tresc in ("", "null") else json.loads(tresc)

    def probka(tryb: str, *reszta: str) -> dict:
        wyn = subprocess.run(["php", "dev/probka-kolizji-nazwy.php", tryb, *reszta],
                             cwd=KORZEN, capture_output=True, text=True)
        linie = [w for w in wyn.stdout.splitlines() if w.strip().startswith("{")]
        if not linie:
            raise RuntimeError(f"probka-kolizji-nazwy.php {tryb} kod={wyn.returncode}: "
                               f"{(wyn.stderr or wyn.stdout).strip()[:300]}")
        return json.loads(linie[-1])

    cel = spolki[-1]
    nazwa_r2 = str(cel["name"])
    nadana = "Aaa-proba-dziennika"
    ctx = None
    ctx2 = None
    nadane = False
    try:
        ctx = b.new_context(viewport={"width": SZEROKOSC_LAPTOPA, "height": 900}, ignore_https_errors=True)
        pg = ctx.new_page()
        pg.goto(BAZA + "/logowanie", wait_until="networkidle")
        pg.fill("#login-email", LOGIN)
        pg.fill("#login-password", HASLO or haslo_dev(LOGIN))
        pg.click("button[type=submit]")
        pg.wait_for_load_state("networkidle")

        def pole_nazwy():
            # ⚠ Od Z29 ten adres przekierowuje do OKNA na `/ustawienia` (patrz `prawda_spolki`).
            pg.goto(BAZA + "/ustawienia/spolki", wait_until="networkidle")
            return pg.locator(f'input[aria-label="Nazwa wyświetlana dla {nazwa_r2}"]')

        stan_przed = pole_nazwy().input_value()

        def zapisz(wartosc: str) -> str:
            pole = pole_nazwy()
            pole.fill(wartosc)
            pg.click("#okno-nazwy button[type=submit]")
            pg.wait_for_load_state("networkidle")
            # ⚠ **Komunikat odmowy stoi PRZY POLU, nie w pasku strony** (od Z29): okno zapisuje
            # kilka firm naraz, więc pasek może powiedzieć tylko „nic nie zapisano", a to, CO
            # jest nie tak z konkretną nazwą, mówi zdanie pod jej polem. Kryterium pyta o drugie,
            # bo to jego czyta człowiek, który właśnie wpisał kolidującą nazwę.
            return pg.evaluate("""() => {
              const przy_polu = Array.from(document.querySelectorAll('.field--error .field__hint'))
                .map(x => x.textContent.trim()).filter(t => t !== '');
              if (przy_polu.length > 0) { return przy_polu[0]; }
              const n = Array.from(document.querySelectorAll('.notice'))
                .map(x => x.textContent.trim()).filter(t => t !== '');
              return n.length > 0 ? n[0] : '';
            }""")

        zapisz(nadana)
        nadane = True
        wpis = wpis_dziennika("tenant.company_display_name")

        # Kolizja z nazwą z kadr INNEJ spółki — przez ekran, komunikat ma to nazwać.
        inna_r2 = str(spolki[0]["name"])
        komunikat_kolizji = zapisz(inna_r2)
        po_kolizji = pole_nazwy().input_value()

        # Oddajemy stan i dopiero potem mierzymy wyścig (próbka pracuje na innej spółce).
        teraz = pole_nazwy().input_value()
        if teraz in (nadana, inna_r2):
            zapisz(stan_przed)
        nadane = False
        ctx.close()
        ctx = None

        # Wyścig: jedno połączenie trzyma kolidujący wiersz, drugie próbuje zapisać.
        import threading
        trzyma = {}

        def blokuj():
            try:
                trzyma["wynik"] = probka("blokuj", "4")
            except Exception as e:  # noqa: BLE001
                trzyma["blad"] = str(e)

        w = threading.Thread(target=blokuj)
        w.start()
        import time as _t
        _t.sleep(1.0)
        wyscig = probka("zapisz")
        w.join(timeout=15)

        # Zdania zamiast ciszy — kontem pracownika bez podwładnych i bez poziomu.
        ctx2 = b.new_context(viewport={"width": SZEROKOSC_LAPTOPA, "height": 900}, ignore_https_errors=True)
        p2 = ctx2.new_page()
        p2.goto(BAZA + "/logowanie", wait_until="networkidle")
        p2.fill("#login-email", LOGIN3)
        p2.fill("#login-password", haslo_prac)
        p2.click("button[type=submit]")
        p2.wait_for_load_state("networkidle")
        if "/kontekst" in p2.url:
            p2.locator("button.settings-list__row").first.click()
            p2.wait_for_load_state("networkidle")
        zdania = {}
        for widok in ("ja", "zespol", "firma", "bzdura"):
            p2.goto(BAZA + f"/kalendarz?widok={widok}&miesiac=2026-08", wait_until="networkidle")
            zdania[widok] = p2.evaluate(
                "() => Array.from(document.querySelectorAll('.notice')).map(x => x.textContent.trim()).filter(Boolean)")

        dziennik_z_na = (
            wpis is not None and "z" in wpis and "na" in wpis
            and wpis["na"] == nadana and wpis["z"] == (stan_przed if stan_przed != "" else None)
        )
        # ⚠ „Milczy przy `ja`" jest częścią kryterium: zdanie przy widoku, o który poproszono
        # i który dostaliśmy, byłoby szumem — a szum uczy ludzi pomijać komunikaty.
        zdania_zamiast_ciszy = (
            zdania["ja"] == [] and zdania["bzdura"] == []
            and len(zdania["zespol"]) == 1 and len(zdania["firma"]) == 1
            and zdania["zespol"] != zdania["firma"]
        )
        kolizja_nazw = (
            # ⚠ Znów SŁOWO, nie zdanie: komunikat musi powiedzieć, że nazwa należy do innej
            # FIRMY z programu kadrowego — brzmienie zdania wolno przeredagować bez bramki.
            "nazwą innej " + SLOWO_UZYTKOWNIKA in komunikat_kolizji.lower()
            and not ma_slowo_spolka(komunikat_kolizji)
            and po_kolizji == nadana
            and wyscig["powod"] == "kolizja_kadry"
            # ⚠ Czekanie jest dowodem BLOKADY: bez `FOR UPDATE` ten sam wyścig daje 0 s
            # (zmierzone przez wstrzyknięcie usterki). Dlatego progiem jest czas, nie powód.
            and wyscig["czekal_s"] >= 1
        )

        return {
            "ok": dziennik_z_na and zdania_zamiast_ciszy and kolizja_nazw,
            "dziennik_z_na": dziennik_z_na,
            "zdania_zamiast_ciszy": zdania_zamiast_ciszy,
            "kolizja_nazw": kolizja_nazw,
            "wpis_dziennika": wpis,
            "stan_przed": stan_przed,
            "komunikat_kolizji": komunikat_kolizji[:90],
            "nazwa_po_kolizji": po_kolizji,
            "wyscig": wyscig,
            "zdania": zdania,
        }
    except Exception as e:  # noqa: BLE001
        return {"ok": False, "powod": str(e)}
    finally:
        if nadane and ctx is not None:
            try:
                pole = pole_nazwy()
                if pole.input_value() in (nadana, str(spolki[0]["name"])):
                    pole.fill(stan_przed)
                    pole.locator("xpath=following-sibling::button[1]").click()
                    pg.wait_for_load_state("networkidle")
            except Exception:  # noqa: BLE001
                pass
        for k in (ctx, ctx2):
            if k is not None:
                try:
                    k.close()
                except Exception:  # noqa: BLE001
                    pass


def osoba_z_wycofanymi() -> dict:
    """Osoba, która ma **wycofane** nieobecności — identyfikatory czytane z bazy, nie wpisane."""
    import subprocess
    kod = (
        'require "vendor/autoload.php";'
        'Dotenv\\Dotenv::createImmutable(__DIR__)->load();'
        '$e = $_ENV;'
        '$pdo = new PDO("mysql:host={$e[\'DB_HOST\']};port={$e[\'DB_PORT\']};dbname={$e[\'DB_NAME\']};charset=utf8mb4",'
        '  $e["DB_USER"], $e["DB_PASS"], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);'
        '$rok = (int) date("Y");'
        '$st = $pdo->prepare("SELECT e.person_id, e.company_id, p.last_name,'
        '    SUM(CASE WHEN a.removed_at IS NULL THEN a.days ELSE 0 END) AS dni,'
        '    SUM(CASE WHEN a.removed_at IS NULL THEN 0 ELSE 1 END) AS wycofanych'
        '  FROM external_absence a JOIN employment e ON e.id = a.employment_id'
        '  JOIN person p ON p.id = e.person_id'
        '  WHERE YEAR(a.date_from) = :rok GROUP BY e.person_id, e.company_id, p.last_name'
        '  HAVING wycofanych > 0 ORDER BY wycofanych DESC LIMIT 1");'
        '$st->execute(["rok" => $rok]);'
        '$w = $st->fetch();'
        'echo json_encode(["osoba" => $w === false ? null : $w, "rok" => $rok]);'
    )
    wyn = subprocess.run(["php", "-r", kod], cwd=KORZEN, capture_output=True, text=True, check=True)

    return json.loads(wyn.stdout or "{}")


def sprawdz_liczniki_nieobecnosci(b) -> dict:
    """**Dwa liczniki tej samej rzeczy muszą się zgadzać** — i muszą mówić, czego nie liczą.

    ⚠ Powstało z pomiaru Krzysztofa 10.10.2026: lista `/dane/firma/3` pokazywała dla jednej
    osoby **0 dni**, a jej karta `/dane/pracownik/89` — **44 dni**. Oba napisy sumowały to samo
    pole `external_absence.days`; różnica była w jednym warunku: lista miała `removed_at IS NULL`,
    karta nie miała go wcale. Zmierzone w bazie: 19 nieobecności tego zatrudnienia, **wszystkie
    wycofane**, 44 dni w roku bieżącym — czyli karta pokazywała dni, których w kadrach nie ma.

    ⚠ Kryterium **tylko czyta** (zero zapisów) i bierze osobę z danych, nie wpisaną: szuka
    takiej, która ma w tym roku choć jedną wycofaną nieobecność. Gdy takiej nie ma, mówi to
    wprost i jest **czerwone** — bo kryterium „z braku okazji" nie jest kryterium (ta sama
    zasada co przy liczniku nieaktywnych w grupach).

    Trzy rzeczy naraz, bo to trzy różne twierdzenia:
      1. liczba z **listy firmy** i liczba z **karty osoby** są identyczne,
      2. karta **nazywa** wycofane osobno (`wycofanych: N`), zamiast je przemilczeć,
      3. tabela pod spodem pokazuje tyle wierszeń z plakietką `wycofana`, ile mówi stopka —
         licznik niezgodny z tabelą pod nim jest tą samą chorobą co licznik, którego nikt
         nie przelicza.
    """
    dane = osoba_z_wycofanymi()
    if dane.get("osoba") is None:
        return {"ok": False, "powod": "w danych nie ma osoby z wycofaną nieobecnością w tym roku "
                                      "— NIE zmierzono"}
    o = dane["osoba"]
    haslo = HASLO or haslo_dev(LOGIN)
    ctx = b.new_context(viewport={"width": 1920, "height": 1080}, ignore_https_errors=True)
    try:
        pg = ctx.new_page()
        pg.goto(BAZA + "/logowanie", wait_until="networkidle")
        pg.fill("#login-email", LOGIN)
        pg.fill("#login-password", haslo)
        pg.click("button[type=submit]")
        pg.wait_for_load_state("networkidle")
        pg.goto(BAZA + "/dane/firma/" + str(o["company_id"]) + "?szukaj=" + str(o["last_name"]),
                wait_until="networkidle")
        z_listy = pg.evaluate("""(nazwisko) => {
          const w = Array.from(document.querySelectorAll('table tbody tr'))
            .find(r => r.innerText.toUpperCase().indexOf(nazwisko.toUpperCase()) >= 0);
          if (!w) { return null; }
          const m = w.innerText.match(/(\\d+)\\s*dni/);
          return {wiersz: w.innerText.replace(/\\s+/g, ' ').trim(), dni: m ? parseInt(m[1], 10) : null};
        }""", str(o["last_name"]))
        pg.goto(BAZA + "/dane/pracownik/" + str(o["person_id"]), wait_until="networkidle")
        z_karty = pg.evaluate("""() => {
          // ⚠ **Selektor przepięty 19.10.2026 (Z46)**: karta liczb (`.card.stats`) ustąpiła paskowi
          // kafli (`.kafel`), ten sam co na pozostałych ekranach panelu. Kryterium mierzy to samo
          // twierdzenie — „liczba dni i liczba wycofanych zgadzają się z bazą i z listą firmy" —
          // tylko element, w którym stoją, jest inny. Stary selektor nie znajdował nic, więc
          // kryterium zapalało się na pustce zamiast na rozjeździe liczb.
          const kafel = Array.from(document.querySelectorAll('.kafel'))
            .find(s => /Nieobecno\u015bci w/.test(s.textContent));
          // ⚠ Czytamy WARTOŚĆ i PODPIS kafelka, nie cały kafelek: etykieta „Nieobecności w 2026"
          // i wartość „0 dni" stoją w dwóch elementach bez spacji między nimi, więc
          // `textContent` całości dawał „20260 dni" i wyrażenie wyłuskiwało rok, nie dni.
          // Liczba wycofanych jest w podpisie (`.kafel__opis`), a nie przy wartości.
          const tekst = kafel
            ? ((kafel.querySelector('.kafel__wartosc') || {}).textContent || '').replace(/\\s+/g, ' ').trim()
              + ' ' + ((kafel.querySelector('.kafel__opis') || {}).textContent || '').replace(/\\s+/g, ' ').trim()
            : '';
          const dni = tekst.match(/(\\d+)\\s*dni/);
          const wyc = tekst.match(/wycofanych:\\s*(\\d+)/);
          const stopka = (document.querySelector('#nieobecnosci .card__foot span') || {}).textContent || '';
          const wycStopka = stopka.match(/wycofanych:\\s*(\\d+)/);
          return {
            kafel: tekst,
            dni: dni ? parseInt(dni[1], 10) : null,
            wycofanych_w_kaflu: wyc ? parseInt(wyc[1], 10) : 0,
            stopka: stopka.replace(/\\s+/g, ' ').trim(),
            wycofanych_w_stopce: wycStopka ? parseInt(wycStopka[1], 10) : 0,
            wierszy: document.querySelectorAll('#nieobecnosci table tbody tr').length,
            plakietek_wycofana: Array.from(document.querySelectorAll('#nieobecnosci table tbody tr'))
              .filter(r => /wycofana/.test(r.textContent)).length,
          };
        }""")

        return {
            "ok": z_listy is not None
                and z_listy["dni"] == z_karty["dni"]
                and z_karty["dni"] == int(o["dni"])
                and z_karty["wycofanych_w_kaflu"] > 0
                and z_karty["wycofanych_w_stopce"] == z_karty["wycofanych_w_kaflu"]
                and z_karty["plakietek_wycofana"] == z_karty["wycofanych_w_stopce"],
            "osoba": o, "rok": dane["rok"], "z_listy": z_listy, "z_karty": z_karty,
        }
    except Exception as e:  # noqa: BLE001
        return {"ok": False, "powod": str(e)[:200]}
    finally:
        ctx.close()


def dane_grup(spolka_ekranu: int = 0) -> dict:
    """Migawka **całej** tabeli przynależności i dane pomocnicze — **z bazy**.

    ⚠ Migawka jest z CAŁEJ tabeli, nie z jednej grupy. Dowód „jedno żądanie = jedna zmiana"
    liczony w granicach grupy próbnej nie zobaczyłby usterki, która rusza przynależność gdzie
    indziej — a to jest dokładnie ta usterka, przez którą 10.10.2026 wypadł z Produkcji ktoś,
    kogo nikt nie zmieniał.
    """
    import subprocess
    kod = (
        'require "vendor/autoload.php";'
        'Dotenv\\Dotenv::createImmutable(__DIR__)->load();'
        '$e = $_ENV;'
        '$pdo = new PDO("mysql:host={$e[\'DB_HOST\']};port={$e[\'DB_PORT\']};dbname={$e[\'DB_NAME\']};charset=utf8mb4",'
        '  $e["DB_USER"], $e["DB_PASS"], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);'
        '$pary = $pdo->query("SELECT CONCAT(employment_id, \'-\', group_id) AS p FROM employment_group ORDER BY p")'
        '  ->fetchAll(PDO::FETCH_COLUMN);'
        '$pelne = $pdo->query("SELECT employment_id e, group_id g FROM employment_group")->fetchAll();'
        # ⚠ „Obcy" znaczy **z innej spółki niż ekran**, więc spółka ekranu jedzie do zapytania
        # jako parametr. Dotąd brało się po prostu przypisanie z najwyższym `company_id` —
        # i od 10.10.2026, gdy spółka ekranu bierze się z kontekstu (a nie z `?spolka=`
        # w adresie bramki), ten wybór potrafił trafić w TĘ SAMĄ spółkę. Skutek: krok
        # „cudze zatrudnienie w pojedynczym żądaniu" w ogóle się nie wykonywał, a kryterium
        # `granica_po_http` czerwieniło się z braku pomiaru, nie z usterki.
        '$st = $pdo->prepare("SELECT eg.employment_id AS e, eg.group_id AS g, c.company_id AS c'
        '   FROM employment_group eg JOIN company_group c ON c.id = eg.group_id'
        '  WHERE (:ekran = 0 OR c.company_id <> :ekran) ORDER BY c.company_id DESC LIMIT 1");'
        '$st->execute(["ekran" => (int) ($argv[1] ?? 0)]);'
        '$obcy = $st->fetch();'
        '$nieczynni = $pdo->query("SELECT e.id, e.company_id FROM employment e WHERE e.is_active = 0 ORDER BY e.id")'
        '  ->fetchAll();'
        'echo json_encode(["pary" => $pary, "pary_pelne" => $pelne,'
        '  "obcy" => $obcy === false ? null : $obcy,'
        '  "nieczynni" => $nieczynni]);'
    )
    # ⚠ Identyfikator spółki ekranu jedzie ARGUMENTEM, nie w treści kodu: `0` znaczy
    # „bez zawężenia" i daje dawne zachowanie tam, gdzie wołający nie zna jeszcze spółki.
    wyn = subprocess.run(["php", "-r", kod, str(spolka_ekranu)],
                         cwd=KORZEN, capture_output=True, text=True, check=True)

    return json.loads(wyn.stdout or "{}")


POMIAR_EKRANU_GRUPY = """() => {
""" + OSIAGALNOSC_JS + """
  const de = document.documentElement;
  const a = document.querySelector('.assign');
  const kolumny = Array.from(document.querySelectorAll('.assign__col'));
  const przodkowie = [];
  if (a) {
    for (let p = a.parentElement; p; p = p.parentElement) {
      const cs = getComputedStyle(p);
      if (cs.overflowX !== 'visible' || cs.overflowY !== 'visible') {
        przodkowie.push(__opis(p) + '{overflow: ' + cs.overflow + '}');
      }
    }
  }
  return {
    jest: !!a,
    kolumn: kolumny.length,
    // ⚠ Nad kolumnami NIE MA prawa stać przodek przycinający — to przez niego kontrolka
    // była nieosiągalna przy zgadzającym się prostokącie.
    przodkowie_przycinajacy: przodkowie,
    // ⚠ Zero nakładek: `position: absolute`/`fixed` w tej rodzinie znaczyłoby powrót
    // do wzorca, który te usterki wyprodukował. Pasek „Cofnij" (`.toast`) nie jest
    // częścią rodziny i jego pozycja jest celowa, więc go tu nie liczymy.
    pozycjonowane: Array.from(document.querySelectorAll('[class*="assign"]'))
      .filter(e => ['absolute', 'fixed'].indexOf(getComputedStyle(e).position) >= 0)
      .map(e => __opis(e)),
    strona_przewija: de.scrollHeight > de.clientHeight,
    strona: {scrollHeight: de.scrollHeight, clientHeight: de.clientHeight},
    pol_wyboru: document.querySelectorAll('.assign input[type=checkbox]').length,
    przyciskow_zapisu: Array.from(document.querySelectorAll('.assign button'))
      .filter(b => /Zapisz/.test(b.textContent)).length,
    // ⚠ Pasek „Cofnij" ma NIE ISTNIEĆ (aneks §3a). Kryterium `akcja_odwrotna` dowodzi,
    // że jest czym zastąpiony; to liczy, czy nie został obok — dwie drogi do jednego stanu.
    paskow_cofnij: document.querySelectorAll('[data-toast], .toast').length,
    // Symetria kolumn (aneks §3): po obu stronach wyszukiwarka i akcja zbiorcza.
    szukajek: document.querySelectorAll('.assign__col .assign__search input[type=search]').length,
    // ⚠ Oba przyciski zbiorcze **ciche** (`btn--ghost`), nie zielone: akcja masowa nie ma
    // przeważać nad pojedynczymi `+` (aneks §3b). Zbieramy klasy, nie werdykt.
    zbiorcze: Array.from(document.querySelectorAll('.assign__foot button[type=submit]'))
      .map(b => ({tekst: b.textContent.trim().slice(0, 44), klasa: b.className})),
    kolumny: kolumny.map(k => {
      const lista = k.querySelector('.assign__list');
      const akcje = Array.from(k.querySelectorAll('button')).filter(x => x.getClientRects().length > 0);
      const ost = akcje[akcje.length - 1] || null;
      const r = k.getBoundingClientRect();
      return {
        tytul: (k.querySelector('.assign__title') || {}).textContent,
        licznik: ((k.querySelector('.assign__count') || {}).textContent || '').replace(/\\s+/g, ' ').trim(),
        szerokosc: Math.round(r.width),
        wysokosc: Math.round(r.height), top: Math.round(r.top), bottom: Math.round(r.bottom),
        szukajka: !!k.querySelector('.assign__search input[type=search]'),
        zbiorcza: ((k.querySelector('.assign__foot button[type=submit]') || {}).textContent || '').trim(),
        wierszy: k.querySelectorAll('.assign__row').length,
        lista_przewija: lista ? lista.scrollHeight > lista.clientHeight : null,
        kolumna_przewija: k.scrollHeight > k.clientHeight + 1,
        pusta_tresc: (k.querySelector('.assign__empty') || {}).textContent || null,
        ostatnia_akcja: ost === null ? null
          : Object.assign({tekst: ost.textContent.trim().slice(0, 40)}, __osiagalnosc(ost)),
      };
    }),
    // ⚠ Selektory przepięte na dzisiejszy toast (Z63 cz. A): komunikat wyniku to `.toast`
    // w pojemniku `#toasty` (Z47), a jego treść `.toast__tresc`. `[data-toast]`
    // i `.toast__tekst` nie istnieją w żadnym szablonie ani arkuszu — ten pomiar zwracał
    // więc `null` zawsze i na pytanie „co napisał toast" nie odpowiadał nigdy.
    toast: (() => {
      const t = document.querySelector('.toasty .toast');
      if (!t) { return null; }
      const b = t.querySelector('button:not(.toast__zamknij)');
      return {tekst: ((t.querySelector('.toast__tresc') || {}).textContent || '').trim(),
              cofnij: !!b, osiagalnosc: b ? __osiagalnosc(b) : null};
    })(),
    // Plakietki przy nazwiskach: lewa kolumna mówi o POZOSTAŁYCH grupach, prawa o wszystkich.
    plakietki: kolumny.map(k => Array.from(k.querySelectorAll('.assign__row')).map(r => ({
      osoba: (r.querySelector('.assign__name') || {}).textContent.trim(),
      tag: Array.from(r.querySelectorAll('.assign__tag')).map(t => t.textContent.trim()).join(' | '),
    }))),
  };
}"""


POMIAR_OKNA_GRUPY = """() => {
""" + OSIAGALNOSC_JS + """
  const d = document.getElementById('grupa-okno');
  if (!d) { return {jest: false}; }
  const kol = Array.from(d.querySelectorAll('.assign__col'));
  const de = document.documentElement;
  return {
    jest: true,
    otwarte: d.open === true,
    tytul: ((document.getElementById('grupa-okno-tytul') || {}).textContent || '').trim(),
    // Okno jest warstwą `fixed` w warstwie górnej — mierzymy je tym samym kryterium
    // co każdą nakładkę: przecięcie z przodkami plus `elementFromPoint`.
    okno: __osiagalnosc(d),
    szerokosc: Math.round(d.getBoundingClientRect().width),
    strona_przewija: de.scrollHeight > de.clientHeight,
    kolumn: kol.length,
    kolumny: kol.map(k => {
      const lista = k.querySelector('.assign__list');
      const akcje = Array.from(k.querySelectorAll('button')).filter(x => x.getClientRects().length > 0);
      const ost = akcje[akcje.length - 1] || null;
      const r = k.getBoundingClientRect();
      return {
        tytul: (k.querySelector('.assign__title') || {}).textContent,
        szerokosc: Math.round(r.width), wysokosc: Math.round(r.height),
        szukajka: !!k.querySelector('.assign__search input[type=search]'),
        lista_przewija: lista ? lista.scrollHeight > lista.clientHeight : null,
        kolumna_przewija: k.scrollHeight > k.clientHeight + 1,
        ostatnia_akcja: ost === null ? null
          : Object.assign({tekst: ost.textContent.trim().slice(0, 40)}, __osiagalnosc(ost)),
      };
    }),
  };
}"""


POMIAR_LISTY_GRUP = """() => {
""" + OSIAGALNOSC_JS + """
  const wiersze = Array.from(document.querySelectorAll('.group-list__row'));
  const bez = document.querySelector('.bez-grupy');
  const okno = document.getElementById('grupa-nowa');
  return {
    wierszy: wiersze.length,
    // ⚠ Zero tabel na ekranie: kolumny `NAZWA` (pole edycji w każdym wierszu) i `USUNIĘCIE`
    // (szary tekst „ma osoby") miały zniknąć razem z tabelą (§4 zlecenia).
    tabel: document.querySelectorAll('table').length,
    pol_nazwy_w_wierszach: document.querySelectorAll('.group-list__row input[name=nazwa]').length,
    wiersze: wiersze.map(r => ({
      nazwa: (r.querySelector('.group-list__name') || {}).textContent.trim(),
      sklad: (r.querySelector('.group-list__sklad') || {}).textContent.trim(),
      licznik: (r.querySelector('.group-list__count') || {}).textContent.replace(/\\s+/g, ' ').trim(),
      // Cały wiersz klikalny: odnośnik rozpięty pseudoelementem na prostokąt wiersza.
      klikalny: (() => {
        const rr = r.getBoundingClientRect();
        const el = document.elementFromPoint(Math.round(rr.left + rr.width / 2),
                                            Math.round(rr.top + rr.height / 2));
        const a = r.querySelector('.group-list__name');
        return !!el && !!a && (el === a || a.contains(el) || el.closest('.group-list__row') === r);
      })(),
      // ⚠ Od Z33 wiersz ma DWA WIDOCZNE przyciski zamiast menu „⋯": pytamy o nie, bo to one
      // są dziś jedyną drogą do zmiany nazwy i usunięcia grupy.
      akcje: !!r.querySelector('.ikona-akcja--edycja') && !!r.querySelector('.ikona-akcja--usun'),
    })),
    bez_grupy: bez ? {tekst: bez.innerText.replace(/\\s+/g, ' ').trim(), rozwijalny: bez.tagName === 'DETAILS',
                      w_tabeli: !!bez.closest('table')} : null,
    okno_nowa: {jest: !!okno, zamkniete: okno ? okno.open === false : null,
                przycisk: !!document.querySelector('[data-modal-open=grupa-nowa]'),
                bez_js: document.querySelectorAll('noscript').length},
    okien_nazwy: document.querySelectorAll('dialog[id^=grupa-nazwa-]').length,
    // ⚠ **Jedno puste okno na cały ekran** (aneks §1), a nie jedno na wiersz — i każdy
    // wiersz ma prawdziwy adres podstrony, bo to ścieżka bez JavaScriptu.
    okno_grupy: (() => {
      const o = document.getElementById('grupa-okno');
      const link = document.querySelector('.group-list__name');
      return {okien: document.querySelectorAll('#grupa-okno').length,
              zamkniete: o ? o.open === false : null,
              puste: o ? o.textContent.trim() === '' : null,
              wyzwalacz_na_wierszu: !!(link && link.getAttribute('data-modal-open') === 'grupa-okno'),
              adres_wiersza: link ? link.getAttribute('href') : null};
    })(),
  };
}"""


# ⚠ **Pomiar przepięty z menu „⋯" na dwa widoczne przyciski** (Z33, 18.10.2026). Dotąd kryterium
# otwierało `details.menu` i sprawdzało, czy OSTATNIA pozycja panelu jest osiągalna — panel stał
# w karcie, a `.card` ma `overflow: hidden`, więc bez `.card--os` bywał ucięty. Menu zniknęło;
# jego miejsce zajęły ołówek i kosz stojące w wierszu na stałe. Pytanie zostaje to samo: **czy
# da się w nie kliknąć**, i dalej mierzymy to przecięciem z przodkami plus `elementFromPoint`,
# a nie samą obecnością w dokumencie.
# ⚠ Powód wyłączenia kosza przy grupie z osobami przeniósł się z `.menu__powod` do `title` —
# wyłączony przycisk bez powodu każe się domyślać, co jest nie tak.
POMIAR_MENU_GRUPY = """() => {
""" + OSIAGALNOSC_JS + """
  const r = document.querySelector('.group-list__row');
  if (!r) { return null; }
  const olowek = r.querySelector('.ikona-akcja--edycja');
  const kosz = r.querySelector('.ikona-akcja--usun');
  if (!olowek || !kosz) { return null; }
  const puste = Array.from(document.querySelectorAll('.group-list__row'))
    .filter(x => !x.querySelector('.ikona-akcja--usun[disabled]')).length;
  return {
    przyciskow: 2,
    etykiety: [olowek.getAttribute('aria-label'), kosz.getAttribute('aria-label')],
    tytuly: [olowek.getAttribute('title'), kosz.getAttribute('title')],
    kosz_wylaczony: !!kosz.disabled,
    powod: kosz.getAttribute('title'),
    wierszy_z_czynnym_koszem: puste,
    olowek_osiagalny: Object.assign({tekst: olowek.getAttribute('title') || ''}, __osiagalnosc(olowek)),
    kosz_osiagalny: Object.assign({tekst: kosz.getAttribute('title') || ''}, __osiagalnosc(kosz)),
  };
}"""


def sprawdz_grupy(b) -> dict:
    """**Grupy** — model, granica spółki, zapis natychmiastowy i **dwie kolumny**
    (zlecenia 08.10 `grupy-dzialy`, 10.10 `grupy-wiele-grup`, 10.10 `grupy-ekran-dwie-kolumny`).

    ⚠ **Osiem kryteriów, każde o innej rzeczy:**
      1. `granica_spolki` — zatrudnienie z INNEJ spółki nie wchodzi do grupy, i nie wchodzi
         **w zapytaniu** (`e.company_id = g.company_id`), a nie w `if`-ie nad zapisem; cudzy
         identyfikator nie **wypisuje** też nikogo z jego grupy. Mierzone na warstwie zapytań
         (próbka) **i po HTTP** na pojedynczych żądaniach `+`/`✕` — bo od 10.10 to jest
         trzydzieści siedem małych drzwi zamiast jednych dużych,
      2. `dwie_warstwy` — usunięcie grupy z członkami odrzuca zapytanie, a po pominięciu
         warunku **klucz obcy** (`ON DELETE RESTRICT`). Aplikację da się ominąć bezpośrednim
         SQL-em, klucza nie,
      3. `wiele_grup` — jedno zatrudnienie należy do wielu grup; mierzone z próbki i z ekranu
         (dodanie do drugiej grupy nie odbiera pierwszej, a plakietki mówią o obu),
      4a. `wygaszone_nie_wchodza` — wygaszone zatrudnienie **nie wchodzi do nowej grupy**:
         zapis odmawia (warunek w zapytaniu), lista „pozostali" go nie oferuje, a ten, który
         był w grupie przed wygaszeniem, w niej zostaje (§8a nietknięte),
      4. `licznik_nieaktywnych` — wygaszone zatrudnienie **zostaje** w grupie, ale licznik
         i lista o tym mówią. Mierzone i w repozytorium, i **na ekranie**: etykieta
         „(nieaktywnych: N)" i plakietka „wygaszone" przy nazwisku,
      5. `jedno_zadanie` — **zastąpiło `stan_z_listy`** (10.10.2026, §3 zlecenia). Dotąd zapis
         utrwalał stan pokazanej listy, więc dowodziło się, że odznaczenie wypisuje, a osoba
         poza listą zostaje nietknięta. Takiego żądania już nie ma. Teraz dowód brzmi: żądanie
         o jedną osobę zmienia **dokładnie jeden wiersz w całej tabeli** przynależności —
         migawka przed i po, różnica co do jednego wiersza, i to po kliknięciu w ekran,
      6. `akcja_odwrotna` — **zastąpiło `cofniecie`** (aneks §3a, pasek „Cofnij" usunięty):
         dla każdej akcji istnieje akcja odwrotna **na tym samym ekranie** i po niej stan wraca
         **dokładnie** do poprzedniego — mierzone dla pary pojedynczej (`+` ↔ `✕`) i zbiorczej,
         a etykiety akcji zbiorczych nad zawężoną listą mówią „pokazanych",
      6a. `okno_grupy` — kliknięcie wiersza otwiera obie kolumny w **oknie modalnym**
         (aneks §1), tytułem jest nazwa grupy, kolumny są równe i osiągalne, zapis działa,
         a po zamknięciu **wiersz na liście ma nową liczbę** — i to dla **wszystkich trzech
         dróg zamknięcia** (`×`, `Escape` w stanie wrogim, kliknięcie w tło). Wersja mierząca
         jedną drogę była zielona nad ekranem, na którym Escape nie zamykał okna wcale,
      7. `dwie_kolumny` — układ: dwie kolumny o stałej wysokości, **zero nakładek**, żaden
         przodek nie przycina, przewija się wyłącznie lista, strona stoi, ostatnia akcja
         każdej kolumny jest **osiągalna** (przecięcie z przodkami + `elementFromPoint`),
         zero pól wyboru i zero przycisków „Zapisz",
      8. `jedna_droga` — lista grup: zero tabel i zero pól edycji nazwy w wierszach, skład pod
         nazwą, cały wiersz klikalny, „Bez grupy" poza tabelą, menu `⋯` **osiągalne** (stoi
         w karcie, a `.card` przycina), „Usuń" wyłączone z powodem.

    ⚠ Pomiar ekranowy pracuje na **własnych grupach** `__probka_ekran*` i na osobie **bez ani
    jednej grupy** — po tym, jak jego pierwsza wersja (na danych roboczych) zniszczyła przy
    wstrzykniętej usterce przynależność, której nikt nie zmieniał. Sprząta po sobie, a usunięcie
    grupy przechodzi **tylko gdy jest pusta**, więc zniknięcie grup JEST dowodem zdjęcia
    przynależności.
    """
    import subprocess

    def probka() -> dict:
        wyn = subprocess.run(["php", "dev/probka-grup.php"], cwd=KORZEN, capture_output=True, text=True)
        if wyn.returncode != 0 or not wyn.stdout.strip():
            raise RuntimeError(f"probka-grup.php kod={wyn.returncode}: "
                               f"{(wyn.stderr or wyn.stdout).strip()[:300]}")
        return json.loads(wyn.stdout)

    ctx = None
    try:
        p = probka()
        granica_zapytania = (
            p["a_obcy_w_grupie"] == 0
            and p["a_przypisanie"]["pominieci"] != []
            and p["b_przynaleznosc_obcego"]["nietknieta"] is True
            and isinstance(p["e_dwa_etaty"], list) and len(p["e_dwa_etaty"]) == 2
            # ⚠ Dwa zatrudnienia jednej osoby = dwie NIEZALEŻNE przynależności, każda w swojej
            # spółce. Gdyby granica spółki nie działała, byłaby jedna i przeskakiwałaby.
            and len({w["company_id"] for w in p["e_dwa_etaty"]}) == 2
        )
        dwie_warstwy = (
            p["d_usuniecie_niepustej"] == "niepusta"
            and str(p["d_usuniecie_bez_warunku"]).startswith("23000")
        )
        nazwa_wielkie_litery = p["c_nazwa_wielkie_litery"] == "nazwa_zajeta"
        g = p["g_jedno_zadanie"]
        jedno_zadanie_zapytanie = (
            isinstance(g, dict)
            and len(g["dodany_wiersz"]) == 1 and g["przy_dodaniu_nic_nie_zniklo"] == []
            and len(g["zdjety_wiersz"]) == 1 and g["przy_wypisaniu_nic_nie_doszlo"] == []
            and g["druga_grupa_osoby_zostala"] is True
        )
        licznik = p["f_licznik_nieaktywnych"]
        # ⚠ Kryterium na ROZDZIELENIU liczb, nie na konkretnej sumie: ilu łącznie jest w grupie
        # próbnej, zależy od wcześniejszych kroków próbki, a wymaganie brzmi „licznik wyodrębnia
        # nieaktywnych", nie „grupa ma dokładnie dwie osoby".
        licznik_zapytanie = (
            isinstance(licznik, dict) and licznik["nieaktywnych"] == 1
            and licznik["osob"] > licznik["nieaktywnych"]
        )
        h = p["h_wiele_grup"]
        wiele_grup_zapytanie = (
            isinstance(h, dict) and h["w_dwoch"] is True and h["bez_duplikatu"] is True
            and h["wypisanie_nie_rusza_drugiej"] is True
        )

        haslo = HASLO or haslo_dev(LOGIN)
        ctx = b.new_context(viewport={"width": SZEROKOSC_LAPTOPA, "height": 900}, ignore_https_errors=True)
        pg = ctx.new_page()
        pg.goto(BAZA + "/logowanie", wait_until="networkidle")
        pg.fill("#login-email", LOGIN)
        pg.fill("#login-password", haslo)
        pg.click("button[type=submit]")
        pg.wait_for_load_state("networkidle")

        # ——— Lista grup: kształt po przebudowie z §4 ———————————————————————————————
        pg.goto(BAZA + "/admin/grupy", wait_until="networkidle")
        lista = pg.evaluate(POMIAR_LISTY_GRUP)
        # ⚠ Spółka ekranu czytana ze **znacznika w belce** (`data-spolka`), a nie z `?spolka=`
        # w odnośniku wiersza: od 10.10.2026 aplikacja tego parametru nie generuje (§1 zlecenia
        # o globalnym wyborze spółki). Zmierzone przed poprawką: pomiar dostawał pusty łańcuch
        # i wywracał próbkę („probka-wygaszony.php utworz '' 658"), czerwieniąc trzy kryteria
        # z powodu w narzędziu, nie w aplikacji.
        spolka = pg.evaluate(
            """() => ((document.querySelector('.spolka') || {}).dataset || {}).spolka || ''""")
        menu = None
        if lista["wierszy"] > 0:
            # ⚠ **Nie ma już czego otwierać** (Z33): menu „⋯" zastąpiły dwa przyciski stojące
            # w wierszu na stałe, więc pomiar czyta je wprost. Kliknięcie wyzwalacza zniknęło
            # razem z menu, a osiągalność mierzy się tak samo — przecięciem z przodkami
            # i `elementFromPoint`, bo przyciski dalej stoją w karcie z `overflow: hidden`.
            menu = pg.evaluate(POMIAR_MENU_GRUPY)

        # ——— Ekran grupy: własne grupy pomiaru, osoba bez grupy ————————————————————
        # ⚠ **Pomiar ekranowy w KROKACH, a nie jeden wielki `try`.** Zmierzone przy dowodach
        # 10.10.2026: usterka „brak przycisku Cofnij" wywracała kliknięcie w ósmym kroku,
        # wyjątek zjadał WSZYSTKIE wcześniejsze pomiary i czerwieniło się sześć kryteriów
        # zamiast jednego. Kryteria mają się łamać osobno, więc awaria kroku zostaje w tym
        # kroku: zapisujemy ją pod `bledy[krok]`, a to, co już zmierzone, zostaje.
        ekran: dict = {"powod": None, "bledy": {}}

        def krok(nazwa: str, fn):
            try:
                return fn()
            except Exception as e:  # noqa: BLE001
                ekran["bledy"][nazwa] = str(e).split(chr(10))[0][:160]
                return None

        # Selektory wiersza po identyfikatorze zatrudnienia — raz, bo używane w kilku krokach.
        def wiersz_lewej(etat: str) -> str:
            return '#w-grupie-wynik .assign__row:has(input[value="' + etat + '"])'

        def wiersz_prawej(etat: str) -> str:
            return '#poza-wynik .assign__row:has(input[value="' + etat + '"])'

        # ⚠ Spółka ekranu jest już znana (ze znacznika w belce), więc „obcego" szukamy
        # **poza nią** — inaczej krok z cudzym zatrudnieniem nie ma czego mierzyć.
        dane = dane_grup(int(spolka or 0))
        try:
            csrf = pg.evaluate("() => (document.querySelector('input[name=_csrf]') || {}).value || ''")
            nowa = pg.evaluate("""async ({csrf, spolka, nazwy}) => {
              const out = [];
              for (const nazwa of nazwy) {
                const r = await fetch('/admin/grupy', {method: 'POST', redirect: 'follow',
                  body: new URLSearchParams([['_csrf', csrf], ['spolka', spolka], ['nazwa', nazwa]])});
                out.push({kod: r.status, adres: r.url});
              }
              return out;
            }""", {"csrf": csrf, "spolka": spolka,
                   # ⚠ Trzecia grupa jest wyłącznie na parę zbiorczą i musi być **pusta**:
                   # przy pomiarze na grupie używanej wcześniej akcja odwrotna zdjęłaby też
                   # osobę dodaną w poprzednim kroku i „stan nie wrócił" (zmierzone).
                   "nazwy": ["__probka_ekran1", "__probka_ekran2", "__probka_ekran3"]})
            grupy_id = [a["adres"].split("/admin/grupy/")[1].split("?")[0] for a in nowa]
            adres1 = BAZA + "/admin/grupy/" + grupy_id[0] + "?spolka=" + spolka
            adres2 = BAZA + "/admin/grupy/" + grupy_id[1] + "?spolka=" + spolka
            adres3 = BAZA + "/admin/grupy/" + grupy_id[2] + "?spolka=" + spolka

            pg.goto(adres1, wait_until="networkidle")
            pusta = pg.evaluate(POMIAR_EKRANU_GRUPY)
            # Osoba **bez ani jednej grupy**: wiersz prawej kolumny bez plakietki.
            kto = pg.evaluate("""() => {
              const r = Array.from(document.querySelectorAll('#poza-wynik .assign__row'))
                .find(x => !x.querySelector('.assign__tag'));
              return r ? r.querySelector('input[name="osoby[]"]').value : null;
            }""")
            if kto is None:
                raise RuntimeError("brak osoby bez ani jednej grupy — NIE zmierzono")

            przed = dane["pary"]
            pg.click(wiersz_prawej(kto) + " .assign__btn--add")
            pg.wait_for_timeout(700)
            po_dodaniu = dane_grup()["pary"]
            z_jedna = pg.evaluate(POMIAR_EKRANU_GRUPY)

            # ⚠ **AKCJA ODWROTNA zamiast paska „Cofnij"** (aneks §3a): osoba właśnie dodana
            # stoi w lewej kolumnie i ma przy sobie `✕`. Kryterium nie pyta, czy taki przycisk
            # istnieje — pyta, czy **stan wraca dokładnie** do poprzedniego, i to po kliknięciu
            # w ekran, a nie przez żądanie z boku.
            def odwrotna_pojedyncza():
                pg.click(wiersz_lewej(kto) + " .assign__btn--del", timeout=5000)
                pg.wait_for_timeout(700)

                return dane_grup()["pary"]

            po_cofnieciu = krok("odwrotna_pojedyncza", odwrotna_pojedyncza)

            # ⚠ Jeśli akcja odwrotna nie wyszła, osoba zostaje w grupie — a dalszy pomiar
            # zakłada, że jej tam nie ma. Zdejmujemy ją żądaniem, żeby jedna awaria nie
            # przenosiła się na następne kryterium (to też jest sprzątanie po sobie).
            if po_cofnieciu is None:
                csrf = pg.evaluate("() => (document.querySelector('input[name=_csrf]') || {}).value || ''")
                krok("sprzatanie_po_cofnij", lambda: pg.evaluate("""async ({g, kto, csrf, spolka}) => {
                  await fetch('/admin/grupy/' + g + '/osoby/usun', {method: 'POST', redirect: 'follow',
                    body: new URLSearchParams([['_csrf', csrf], ['spolka', spolka], ['osoby[]', kto]])});
                }""", {"g": grupy_id[0], "kto": kto, "csrf": csrf, "spolka": spolka}))

            # Dwie grupy na jedną osobę — przez ekran, kliknięciami.
            pg.goto(adres1, wait_until="networkidle")
            pg.click(wiersz_prawej(kto) + " .assign__btn--add")
            pg.wait_for_timeout(700)
            pg.goto(adres2, wait_until="networkidle")
            pg.click(wiersz_prawej(kto) + " .assign__btn--add")
            pg.wait_for_timeout(700)
            w_obu = []
            for adres in (adres1, adres2):
                pg.goto(adres, wait_until="networkidle")
                w_obu.append(pg.evaluate("""(kto) => {
                  const w = Array.from(document.querySelectorAll('.assign__col:nth-child(1) .assign__row'))
                    .find(r => r.querySelector('input[value="' + kto + '"]'));
                  return {w_grupie: !!w,
                          plakietka: w ? Array.from(w.querySelectorAll('.assign__tag'))
                            .map(t => t.textContent.trim()).join(' | ') : null};
                }""", kto))

            # ⚠ **Para zbiorcza: dodanie wszystkich pokazanych i jej akcja odwrotna**
            # (aneks §3a). Pasek „Cofnij" zniknął, więc drogą powrotu po akcji masowej jest
            # druga akcja masowa w przeciwnej kolumnie — i to jest jedyny powód, dla którego
            # lewa stopka istnieje. Mierzymy na **zawężonej** liście, bo tak się jej używa,
            # i na własnej grupie `__probka_ekran2`.
            def para_zbiorcza() -> dict:
                """Cztery kroki na PUSTEJ grupie próbnej, każdy o czym innym.

                1. akcja zbiorcza **bez filtru** — etykieta nie ma prawa mówić „pokazanych",
                   bo pokazuje wszystkich; zapis dokłada dokładnie tyle wierszy, ile zapowiada,
                2. akcja zbiorcza po lewej **bez filtru** — to samo z drugiej strony,
                3. akcja zbiorcza po lewej **z filtrem** — etykieta MUSI powiedzieć „pokazanych",
                   a zapis ruszyć **wyłącznie pokazanych** (reszta członków zostaje),
                4. zdjęcie reszty — stan wraca **dokładnie** do wyjściowego.
                """
                pg.goto(adres3, wait_until="networkidle")
                fraza = pg.evaluate("""() => {
                  const n = document.querySelector('#poza-wynik .assign__name');
                  return n ? n.textContent.trim().split(' ')[0].slice(0, 4) : '';
                }""")
                przed_zb = dane_grup()["pary"]
                etykiety = pg.evaluate("""() => ({
                  prawa: ((document.querySelector('#poza-wynik .assign__foot button') || {}).textContent || '').trim(),
                  licznik_prawy: ((document.getElementById('poza-licznik') || {}).textContent || '').trim(),
                  pokazanych: document.querySelectorAll('#poza-wynik .assign__row').length,
                })""")
                pg.click("#poza-wynik .assign__foot button", timeout=5000)
                pg.wait_for_timeout(1200)
                po_dodaniu_zb = dane_grup()["pary"]
                bez_filtru_lewa = pg.evaluate("""() => ({
                  etykieta: ((document.querySelector('#w-grupie-wynik .assign__foot button') || {}).textContent || '').trim(),
                  licznik: ((document.getElementById('w-grupie-licznik') || {}).textContent || '').trim(),
                  wierszy: document.querySelectorAll('#w-grupie-wynik .assign__row').length,
                })""")

                # 3. Lewa kolumna ZAWĘŻONA: etykieta i zasięg zapisu.
                pg.goto(adres3 + "&szukajg=" + fraza, wait_until="networkidle")
                z_filtrem_lewa = pg.evaluate("""() => ({
                  etykieta: ((document.querySelector('#w-grupie-wynik .assign__foot button') || {}).textContent || '').trim(),
                  licznik: ((document.getElementById('w-grupie-licznik') || {}).textContent || '').trim(),
                  wierszy: document.querySelectorAll('#w-grupie-wynik .assign__row').length,
                })""")
                przed_filtrem = dane_grup()["pary"]
                pg.click("#w-grupie-wynik .assign__foot button", timeout=5000)
                pg.wait_for_timeout(900)
                po_filtrze = dane_grup()["pary"]

                # 4. Reszta — i stan wraca do wyjściowego.
                pg.goto(adres3, wait_until="networkidle")
                if pg.query_selector("#w-grupie-wynik .assign__foot button") is not None:
                    pg.click("#w-grupie-wynik .assign__foot button", timeout=5000)
                    pg.wait_for_timeout(1200)
                po_zdjeciu_zb = dane_grup()["pary"]

                return {
                    "fraza": fraza,
                    "etykiety": etykiety,
                    "bez_filtru_lewa": bez_filtru_lewa,
                    "z_filtrem_lewa": z_filtrem_lewa,
                    "dodane": len(set(po_dodaniu_zb) - set(przed_zb)),
                    "zniknelo_przy_dodaniu": sorted(set(przed_zb) - set(po_dodaniu_zb)),
                    "ile_pokazanych": etykiety["pokazanych"],
                    # Zdjęcie z filtrem rusza DOKŁADNIE pokazanych — ani więcej, ani mniej.
                    "zdjete_z_filtrem": len(set(przed_filtrem) - set(po_filtrze)),
                    "doszlo_przy_zdjeciu": sorted(set(po_filtrze) - set(przed_filtrem)),
                    "pokazanych_z_filtrem": z_filtrem_lewa["wierszy"],
                    # ⚠ Sedno kryterium: po akcjach odwrotnych stan jest **dokładnie** ten sam.
                    "wrocilo": sorted(po_zdjeciu_zb) == sorted(przed_zb),
                }

            def oczysc_grupe(gid: str) -> None:
                """Zdejmij wszystkich z grupy próbnej **żądaniem**, nie kliknięciem.

                ⚠ Sprzątanie nie może zależeć od przycisku, który jest przedmiotem pomiaru.
                Zmierzone przy dowodzie 15 („lewa akcja zbiorcza ukryta"): kliknięcie padło,
                37 przynależności zostało w grupie próbnej i **następny krok nie miał na kim
                mierzyć** — czerwieniły się dwa kryteria zamiast jednego.
                """
                czlonkowie = [w["e"] for w in dane_grup()["pary_pelne"] if str(w["g"]) == str(gid)]
                if not czlonkowie:
                    return
                csrf3 = pg.evaluate("() => (document.querySelector('input[name=_csrf]') || {}).value || ''")
                pg.evaluate("""async ({g, kto, csrf, spolka}) => {
                  const body = new URLSearchParams([['_csrf', csrf], ['spolka', spolka]]);
                  kto.forEach(k => body.append('osoby[]', String(k)));
                  await fetch('/admin/grupy/' + g + '/osoby/usun', {method: 'POST', redirect: 'follow', body: body});
                }""", {"g": gid, "kto": czlonkowie, "csrf": csrf3, "spolka": spolka})

            zbiorcze = krok("para_zbiorcza", para_zbiorcza)
            krok("oczyszczenie_grupy3", lambda: oczysc_grupe(grupy_id[2]))

            # ⚠ **Okno modalne** (aneks §1): kliknięcie wiersza na liście otwiera obie kolumny
            # w warstwie, a po zamknięciu **wiersz pod spodem ma aktualną liczbę i skład**.
            # To ostatnie jest tu najważniejsze: liczba sprzed otwarcia jest dokładnie tą samą
            # chorobą co licznik, którego nikt nie przelicza.
            # ⚠ **Sonda zdarzeń w stronie** — kryterium ma mierzyć MECHANIZM, nie tylko wynik.
            # Zlecenie z 10.10 wieczorem (druga tura §2): nadzór zmierzył u siebie, że okno
            # zamyka się, a wiersz listy stoi 2,5 s — a kryterium było ZIELONE. Mój pomiar tego
            # nie odtworzył, więc kryterium musi odpowiadać na trzy pytania osobno: czy okno
            # faktycznie się zamknęło, **czym** (zdarzenie `close` czy samo zdjęcie atrybutu),
            # czy wyszło **żądanie** odświeżenia i **po ilu milisekundach** wiersz się zmienił.
            SONDA_OKNA = """() => {
              window.__sonda = {close: 0, cancel: 0, atrybut: [], zadania: 0};
              document.addEventListener('close', () => { window.__sonda.close += 1; }, true);
              document.addEventListener('cancel', () => { window.__sonda.cancel += 1; }, true);
              const d = document.getElementById('grupa-okno');
              if (d) {
                new MutationObserver(() => window.__sonda.atrybut.push(d.hasAttribute('open')))
                  .observe(d, {attributes: true, attributeFilter: ['open']});
              }
              document.addEventListener('htmx:beforeRequest', (e) => {
                const p = e.detail && e.detail.requestConfig ? e.detail.requestConfig.path : '';
                if (typeof p === 'string' && p.indexOf('/admin/grupy') === 0 && p.indexOf('/osoby') < 0) {
                  window.__sonda.zadania += 1;
                }
              });
              return !!d;
            }"""

            def okno_modalne() -> dict:
                """Okno grupy — **TRZY DROGI ZAMKNIĘCIA, każda zmierzona osobno i z CZASEM**.

                ⚠ Historia tego kryterium jest ostrzeżeniem, więc stoi w kodzie: najpierw mierzyło
                **jedną** drogę (`Escape`) w **wygodnym** stanie ogniska i było zielone nad ekranem,
                na którym Escape nie zamykał okna wcale. Potem mierzyło trzy drogi, ale **stałym
                odczekaniem 1200 ms i bez sprawdzania mechanizmu** — i znowu było zielone nad
                pomiarem nadzoru, który widział wiersz nieodświeżony przez 2,5 s. Teraz:
                  — wiersz czytamy **w pętli, z zegarem**, i zapisujemy, po ilu ms się zmienił
                    (budżet: `ODSWIEZENIE_MS`), zamiast ufać jednemu odczytowi po stałym czasie,
                  — liczymy **zdarzenia zamknięcia** i **żądania odświeżenia**: brak żądania to
                    usterka mechanizmu, nawet gdyby wiersz przypadkiem był aktualny,
                  — `Escape` idzie w stanie **wrogim** (ognisko w szukajce, tekst wpisany).
                """
                wiersz = '.group-list__row:has(a[href*="/admin/grupy/' + grupy_id[0] + '"])'

                def z_wiersza() -> dict:
                    return pg.evaluate("""(sel) => {
                      const r = document.querySelector(sel);
                      if (!r) { return null; }
                      const licz = ((r.querySelector('.group-list__count') || {}).textContent || '').trim();
                      const m = licz.match(/(\\d+)/);
                      return {licznik: licz, osob: m ? parseInt(m[1], 10) : null,
                              sklad: ((r.querySelector('.group-list__sklad') || {}).textContent || '').trim()};
                    }""", wiersz)

                pierwsze = None
                drogi = {}
                for droga in ("krzyzyk", "escape", "tlo"):
                    pg.goto(BAZA + "/admin/grupy?spolka=" + spolka, wait_until="networkidle")
                    pg.evaluate(SONDA_OKNA)
                    przed_wiersz = z_wiersza()
                    pg.click(wiersz + " .group-list__name")
                    pg.wait_for_timeout(1000)
                    if pierwsze is None:
                        pierwsze = pg.evaluate(POMIAR_OKNA_GRUPY)
                    kto2 = pg.evaluate("""() => {
                      const r = Array.from(document.querySelectorAll('#grupa-okno #poza-wynik .assign__row'))
                        .find(x => !x.querySelector('.assign__tag'));
                      return r ? r.querySelector('input[name="osoby[]"]').value : null;
                    }""")
                    if kto2 is None:
                        drogi[droga] = {"powod": "brak osoby bez grupy — NIE zmierzono"}
                        continue
                    przed_okno = dane_grup()["pary"]
                    pg.click('#grupa-okno ' + wiersz_prawej(kto2) + " .assign__btn--add", timeout=5000)
                    pg.wait_for_timeout(900)
                    dodano = sorted(set(dane_grup()["pary"]) - set(przed_okno))
                    ognisko = None
                    # ⚠ Licznik zdarzeń zerujemy TU: wszystko, co policzymy dalej, jest skutkiem
                    # samego zamknięcia, a nie otwierania okna i dodawania osoby.
                    pg.evaluate("() => { window.__sonda.close = 0; window.__sonda.cancel = 0;"
                                " window.__sonda.atrybut = []; window.__sonda.zadania = 0; }")
                    if droga == "krzyzyk":
                        pg.click("#grupa-okno .modal__close", timeout=5000)
                    elif droga == "escape":
                        pg.click("#grupa-okno .assign__col:last-child input[name=szukaj]", timeout=5000)
                        pg.keyboard.type("Ra")
                        pg.wait_for_timeout(900)
                        ognisko = pg.evaluate(
                            "() => { const a = document.activeElement;"
                            " return a ? a.tagName + ' ' + (a.name || a.className || '') : null; }")
                        pg.evaluate("() => { window.__sonda.zadania = 0; }")
                        pg.keyboard.press("Escape")
                    else:
                        pg.mouse.click(10, 10)

                    # ⚠ **Zegar, nie stałe odczekanie**: tyle samo, ile widzi człowiek — patrzy
                    # na wiersz i czeka, aż się zmieni. Zapisujemy, ile to trwało.
                    przed_n = (przed_wiersz or {}).get("osob")
                    po_wiersz = przed_wiersz
                    ms = None
                    for krok_ms in range(0, ODSWIEZENIE_MS + 250, 250):
                        pg.wait_for_timeout(250)
                        po_wiersz = z_wiersza()
                        if isinstance((po_wiersz or {}).get("osob"), int) and isinstance(przed_n, int) \
                                and po_wiersz["osob"] == przed_n + 1:
                            ms = krok_ms + 250
                            break
                    sonda = pg.evaluate("() => window.__sonda || null")
                    zamkniete = pg.evaluate(
                        "() => { const d = document.getElementById('grupa-okno');"
                        " return d ? (d.open === false && !d.hasAttribute('open')) : null; }")
                    drogi[droga] = {
                        "kto": kto2, "dodano": dodano, "ognisko": ognisko,
                        "okno_zamkniete": zamkniete,
                        "sonda": sonda,
                        "wiersz_przed": przed_wiersz, "wiersz_po": po_wiersz,
                        "odswiezenie_ms": ms,
                        "licznik_wzrosl_o_jeden": ms is not None,
                        "sklad_sie_zmienil": (przed_wiersz or {}).get("sklad") != (po_wiersz or {}).get("sklad"),
                    }
                    csrf2 = pg.evaluate("() => (document.querySelector('input[name=_csrf]') || {}).value || ''")
                    pg.evaluate("""async ({g, kto, csrf, spolka}) => {
                      await fetch('/admin/grupy/' + g + '/osoby/usun', {method: 'POST', redirect: 'follow',
                        body: new URLSearchParams([['_csrf', csrf], ['spolka', spolka], ['osoby[]', kto]])});
                    }""", {"g": grupy_id[0], "kto": kto2, "csrf": csrf2, "spolka": spolka})

                return {"po_otwarciu": pierwsze, "drogi": drogi}

            okno_grupy = krok("okno_modalne", okno_modalne)

            # Wygaszone zatrudnienie W GRUPIE — etykieta i plakietka na ekranie.
            #
            # ⚠ **Pomiar WYTWARZA sobie ten stan na własnych obiektach** (zlecenie 10.10.2026,
            # druga tura §3). Poprzednia wersja **czytała dane robocze**: jedno wygaszone
            # zatrudnienie, które ktoś wstawił do Magazynu ręcznie. Krzysztof zdjął je przy
            # sprawdzaniu i kryterium przestało mieć co mierzyć — a po słusznej poprawce
            # („wygaszone nie wchodzą do grupy") tego stanu **nie da się odtworzyć z interfejsu**.
            # `dev/probka-wygaszony.php` robi go jedyną drogą, jaką zna aplikacja: własna osoba,
            # własne zatrudnienie, wejście do grupy jako **czynne**, **potem** wygaszenie —
            # i sprząta po sobie. Jeśli bramka musi coś zapisać, zapisuje **u siebie**.
            def nieaktywny_na_ekranie() -> dict:
                import subprocess
                wyn = subprocess.run(
                    ["php", "dev/probka-wygaszony.php", "utworz", str(spolka), str(grupy_id[0])],
                    cwd=KORZEN, capture_output=True, text=True, check=True)
                wytworzony = json.loads(wyn.stdout or "{}")
                try:
                    pg.goto(adres1, wait_until="networkidle")

                    return dict(wytworzony, **pg.evaluate("""() => {
                      const wiersz = Array.from(document.querySelectorAll('#w-grupie-wynik .assign__row'))
                        .find(r => /wygaszone/.test(r.textContent));
                      return {
                        etykieta_w_naglowku: ((document.getElementById('w-grupie-licznik') || {}).textContent || '')
                          .replace(/\\s+/g, ' ').trim(),
                        plakietka: wiersz ? Array.from(wiersz.querySelectorAll('.assign__tag'))
                          .map(x => x.textContent.trim()).join(' | ') : null,
                        // ⚠ …i ten sam człowiek **nie stoi w ofercie po prawej**: lista
                        // „pozostali" nie ma ani jednego wygaszonego.
                        wygaszonych_po_prawej: Array.from(document.querySelectorAll('#poza-wynik .assign__row'))
                          .filter(r => /wygaszone/.test(r.textContent)).length,
                        pozycji_po_prawej: document.querySelectorAll('#poza-wynik .assign__row').length,
                        // Zdanie nad kolumnami też ma o nim mówić.
                        zdanie_ekranu: ((document.querySelector('.page__sub') || {}).textContent || '')
                          .replace(/\\s+/g, ' ').trim(),
                      };
                    }"""))
                finally:
                    # ⚠ Sprzątanie w `finally` i **żądaniem do własnego skryptu**, nie kliknięciem
                    # w mierzony ekran: wygaszonego i tak nie da się wypisać z ekranu, bo prawa
                    # kolumna go nie pokazuje — a to jest właśnie mierzona cecha.
                    subprocess.run(["php", "dev/probka-wygaszony.php", "sprzataj"],
                                   cwd=KORZEN, capture_output=True, text=True, check=False)

            nieaktywny_ekran = krok("nieaktywny_na_ekranie", nieaktywny_na_ekranie) or {
                "powod": "pomiar etykiety padł"}

            # ⚠ **Cudze zatrudnienie w POJEDYNCZYM żądaniu** — i w dodającym, i w usuwającym.
            # To nowa powierzchnia z 10.10: małe żądania muszą mieć ten sam zamek co duże.
            obce = None
            if dane["obcy"] is not None and str(dane["obcy"]["c"]) != str(spolka):
                przed_obce = dane_grup()["pary"]
                kody = pg.evaluate("""async ({g, obcy, csrf, spolka}) => {
                  const out = {};
                  for (const [nazwa, sciezka] of [['dodaj', 'dodaj'], ['usun', 'usun']]) {
                    const r = await fetch('/admin/grupy/' + g + '/osoby/' + sciezka,
                      {method: 'POST', redirect: 'follow',
                       body: new URLSearchParams([['_csrf', csrf], ['spolka', spolka], ['osoby[]', obcy]])});
                    out[nazwa] = r.status;
                  }
                  return out;
                }""", {"g": grupy_id[0], "obcy": str(dane["obcy"]["e"]), "csrf": csrf, "spolka": spolka})
                po_obce = dane_grup()["pary"]
                obce = {"zatrudnienie": dane["obcy"]["e"], "jego_spolka": dane["obcy"]["c"],
                        "kody": kody,
                        "nie_weszlo": (str(dane["obcy"]["e"]) + "-" + grupy_id[0]) not in po_obce,
                        "jego_przynaleznosc_nietknieta":
                            (str(dane["obcy"]["e"]) + "-" + str(dane["obcy"]["g"])) in po_obce,
                        "tabela_bez_zmian": sorted(po_obce) == sorted(przed_obce)}

            # Sprzątanie: wypisanie wszystkich i usunięcie obu grup. ⚠ `usun` przechodzi
            # WYŁĄCZNIE na pustej grupie, więc zniknięcie grup jest dowodem posprzątania.
            for gid in grupy_id:
                pg.goto(BAZA + "/admin/grupy/" + gid + "?spolka=" + spolka, wait_until="networkidle")
                csrf = pg.evaluate("() => (document.querySelector('input[name=_csrf]') || {}).value || ''")
                czlonkowie = pg.evaluate(
                    """() => Array.from(document.querySelectorAll('.assign__col:nth-child(1) .assign__row input[name="osoby[]"]')).map(i => i.value)""")
                if czlonkowie:
                    pg.evaluate("""async ({g, kto, csrf, spolka}) => {
                      const body = new URLSearchParams([['_csrf', csrf], ['spolka', spolka]]);
                      kto.forEach(k => body.append('osoby[]', k));
                      await fetch('/admin/grupy/' + g + '/osoby/usun', {method: 'POST', redirect: 'follow', body: body});
                    }""", {"g": gid, "kto": czlonkowie, "csrf": csrf, "spolka": spolka})
                pg.evaluate("""async ({g, csrf, spolka}) => {
                  await fetch('/admin/grupy/' + g + '/usun', {method: 'POST', redirect: 'follow',
                    body: new URLSearchParams([['_csrf', csrf], ['spolka', spolka]])});
                }""", {"g": gid, "csrf": csrf, "spolka": spolka})
            pg.goto(BAZA + "/admin/grupy?spolka=" + spolka, wait_until="networkidle")
            zostalo = pg.evaluate("() => document.body.innerText.split('__probka_ekran').length - 1")
            koniec = dane_grup()["pary"]

            ekran.update({
                "kto": kto, "grupy": grupy_id,
                "pusta": pusta, "z_jedna": z_jedna,
                "dodany": sorted(set(po_dodaniu) - set(przed)),
                "zniknelo_przy_dodaniu": sorted(set(przed) - set(po_dodaniu)),
                "po_cofnieciu_jak_przed": po_cofnieciu is not None
                    and sorted(po_cofnieciu) == sorted(przed),
                "w_obu_grupach": w_obu,
                "zbiorcze": zbiorcze,
                "okno": okno_grupy,
                "nieaktywny": nieaktywny_ekran,
                "obce_zadanie": obce,
                "probne_grupy_zniknely": zostalo == 0,
                "dane_jak_na_starcie": sorted(koniec) == sorted(przed),
            })
        except Exception as e:  # noqa: BLE001
            # ⚠ Nie nadpisujemy całego `ekran`: to, co zdążyło się zmierzyć, jest wynikiem
            # pomiaru i ma dojść do kryteriów. Nadpisanie było przyczyną czerwieni zbiorowej.
            ekran["powod"] = "pomiar ekranowy padł: " + str(e).split(chr(10))[0][:200]

        # ——— Wnioski ————————————————————————————————————————————————————————————
        def osiagalne(k: dict) -> bool:
            o = k.get("ostatnia_akcja")
            return isinstance(o, dict) and o.get("osiagalna") is True

        z_jedna = ekran.get("z_jedna") or {}
        pusta = ekran.get("pusta") or {}
        dwie_kolumny = (
            z_jedna.get("jest") is True and z_jedna.get("kolumn") == 2
            and z_jedna.get("przodkowie_przycinajacy") == []
            and z_jedna.get("pozycjonowane") == []
            and z_jedna.get("strona_przewija") is False
            and z_jedna.get("pol_wyboru") == 0
            and z_jedna.get("przyciskow_zapisu") == 0
            # ⚠ Pasek „Cofnij" nie ma prawa wrócić obok akcji odwrotnej (aneks §3a).
            and z_jedna.get("paskow_cofnij") == 0
            # ⚠ **Symetria** (aneks §3): wyszukiwarka po obu stronach i akcja zbiorcza po obu.
            and z_jedna.get("szukajek") == 2
            and len(z_jedna.get("zbiorcze", [])) == 2
            # ⚠ Oba przyciski zbiorcze **ciche**: zero `btn--primary` w stopkach (aneks §3b).
            # ⚠ Kryterium pyta o CICHOŚĆ, nie o jedną konkretną klasę: od Z31 oba są
            # `btn--quiet` z zielonym napisem (`assign__hurt`), bo ramka czytała się jak
            # trzeci element wiersza obok dwóch kolumn. Reguła ta sama, znacznik inny.
            and all("btn--primary" not in b["klasa"]
                    and ("btn--ghost" in b["klasa"] or "btn--quiet" in b["klasa"])
                    for b in z_jedna.get("zbiorcze", []))
            and len(z_jedna.get("kolumny", [])) == 2
            and all(osiagalne(k) for k in z_jedna.get("kolumny", []))
            # Przewija się WYŁĄCZNIE lista: sama kolumna nigdy.
            and all(k.get("kolumna_przewija") is False for k in z_jedna.get("kolumny", []))
            # Obie kolumny tej samej wysokości — i to niezależnie od liczby wierszy.
            and len({k.get("wysokosc") for k in z_jedna.get("kolumny", [])}) == 1
            # ⚠ **I tej samej SZEROKOŚCI — 50:50** (aneks §2, odwołanie `1fr / 1.15fr`).
            # Tolerancja 1 px na zaokrąglenie siatki, nie na „prawie równo".
            and len(z_jedna.get("kolumny", [])) == 2
            and abs(z_jedna["kolumny"][0].get("szerokosc", 0)
                    - z_jedna["kolumny"][1].get("szerokosc", -1)) <= 1
            and pusta.get("kolumny", [{}])[0].get("wysokosc") == z_jedna.get("kolumny", [{}])[0].get("wysokosc")
            # Pusta lewa kolumna mówi, co zrobić, zamiast świecić pustą ramką.
            and "dodaj po prawej" in (pusta.get("kolumny", [{}])[0].get("pusta_tresc") or "")
        )
        jedno_zadanie = (
            jedno_zadanie_zapytanie
            and len(ekran.get("dodany", [])) == 1
            and ekran.get("zniknelo_przy_dodaniu") == []
            and ekran.get("dane_jak_na_starcie") is True
            and ekran.get("probne_grupy_zniknely") is True
        )
        # ⚠ **`akcja_odwrotna` ZASTĄPIŁO `cofniecie`** (aneks §3a) — i to jest zamiana,
        # nie usunięcie, tak samo jak `jedno_zadanie` zastąpiło `stan_z_listy`. Stare kryterium
        # mierzyło pasek „Cofnij": że istnieje, że jego przycisk jest osiągalny i że po nim stan
        # wraca. Paska nie ma — więc kryterium pyta o to, co go zastąpiło: **czy dla każdej
        # akcji istnieje akcja odwrotna NA TYM SAMYM EKRANIE i czy po niej stan wraca dokładnie
        # do poprzedniego**. Dwie pary, bo są dwa rodzaje akcji: pojedyncza (`+` ↔ `✕`)
        # i zbiorcza („Dodaj wszystkich pokazanych" ↔ „Usuń wszystkich pokazanych").
        zb = ekran.get("zbiorcze") or {}
        akcja_odwrotna = (
            # para pojedyncza: po kliknięciu `✕` przy tej samej osobie stan jest co do wiersza
            # ten sam, co przed `+`
            ekran.get("po_cofnieciu_jak_przed") is True
            # para zbiorcza: dodanie i zdjęcie znoszą się **dokładnie**
            and zb.get("wrocilo") is True
            and zb.get("ile_pokazanych", 0) > 0
            and zb.get("dodane") == zb.get("ile_pokazanych")
            and zb.get("zniknelo_przy_dodaniu") == []
            # ⚠ **Etykieta zgadza się ze STANEM listy, nie mówi zawsze to samo.** Bez filtru
            # „wszystkich" jest prawdą i ma tak stać; nad zawężoną listą „wszystkich" byłoby
            # zaproszeniem do wyczyszczenia grupy przez pomyłkę, więc musi być „pokazanych".
            # Mierzymy OBA stany po lewej — inaczej kryterium przeszłoby przy napisie stałym.
            and "pokazanych" not in ((zb.get("etykiety") or {}).get("prawa") or "x")
            and "pokazanych" not in ((zb.get("bez_filtru_lewa") or {}).get("etykieta") or "x")
            and "pokazanych" in ((zb.get("z_filtrem_lewa") or {}).get("etykieta") or "")
            # ⚠ …i zawężone zdjęcie zbiorcze rusza **wyłącznie pokazanych**.
            and zb.get("pokazanych_z_filtrem", 0) > 0
            and zb.get("zdjete_z_filtrem") == zb.get("pokazanych_z_filtrem")
            and zb.get("doszlo_przy_zdjeciu") == []
            # …a paska „Cofnij" nie ma, czyli nie zostawiliśmy dwóch dróg do jednego stanu.
            and z_jedna.get("paskow_cofnij") == 0
        )
        granica_spolki = granica_zapytania and (
            ekran.get("obce_zadanie") is None or (
                ekran["obce_zadanie"]["nie_weszlo"] is True
                and ekran["obce_zadanie"]["jego_przynaleznosc_nietknieta"] is True
                and ekran["obce_zadanie"]["tabela_bez_zmian"] is True
            )
        )
        # ⚠ Brak cudzej spółki w danych nie ma prawa dać ZIELONEGO kryterium „po HTTP":
        # to byłoby „zielone z braku okazji". Jeśli nie ma na czym mierzyć, mówimy to wprost.
        granica_po_http = ekran.get("obce_zadanie") is not None and granica_spolki
        w_obu = ekran.get("w_obu_grupach") or []
        wiele_grup = (
            wiele_grup_zapytanie
            and len(w_obu) == 2
            and all(x.get("w_grupie") is True for x in w_obu)
            and "__probka_ekran2" in (w_obu[0].get("plakietka") or "")
            and "__probka_ekran1" in (w_obu[1].get("plakietka") or "")
        )
        nieakt = ekran.get("nieaktywny") or {}
        licznik_nieaktywnych = (
            licznik_zapytanie
            # ⚠ Stan wytworzony przez pomiar: jedno wygaszone zatrudnienie w grupie próbnej.
            and nieakt.get("w_grupie") is True
            and "nieaktywnych: 1" in (nieakt.get("etykieta_w_naglowku") or "")
            and "wygaszone" in (nieakt.get("plakietka") or "")
            # ⚠ Czego tu NIE MA i dlaczego: podtytułu ekranu. Doliczyłem go w pierwszym
            # podejściu („wygaszonych zatrudnień: 1") i kryterium zapaliło się na poprawnym
            # ekranie — bo podtytuł **stracił liczbę** w aneksie §4, świadomie, jako liczba
            # stojąca poza wymienianym fragmentem. Kryterium pilnujące cofniętej decyzji jest
            # gorsze niż brak kryterium.
        )
        # ⚠ **Okno grupy** (aneks §1): kliknięcie wiersza otwiera obie kolumny w warstwie,
        # tytułem jest nazwa grupy, kolumny są równe i osiągalne, a po zamknięciu **wiersz
        # na liście ma aktualną liczbę i skład**. Ostatni warunek jest tu najważniejszy:
        # liczba sprzed otwarcia to ta sama choroba co licznik, którego nikt nie przelicza.
        ok_okno = ekran.get("okno") or {}
        po_otwarciu = ok_okno.get("po_otwarciu") or {}
        kol_okna = po_otwarciu.get("kolumny") or []
        okno_grupy = (
            po_otwarciu.get("jest") is True and po_otwarciu.get("otwarte") is True
            and po_otwarciu.get("tytul", "").startswith("__probka_ekran")
            and po_otwarciu.get("kolumn") == 2
            and (po_otwarciu.get("okno") or {}).get("osiagalna") is True
            and len(kol_okna) == 2
            and all(k.get("szukajka") is True for k in kol_okna)
            and all(k.get("kolumna_przewija") is False for k in kol_okna)
            and abs(kol_okna[0].get("szerokosc", 0) - kol_okna[1].get("szerokosc", -1)) <= 1
            and all(isinstance(k.get("ostatnia_akcja"), dict)
                    and k["ostatnia_akcja"].get("osiagalna") is True for k in kol_okna)
            # ⚠ **TRZY DROGI ZAMKNIĘCIA, każda osobno** (zlecone 10.10.2026 po pomiarze
            # Krzysztofa). Dla każdej: okno faktycznie się zamknęło, zapis w oknie ruszył
            # **dokładnie jeden** wiersz, a wiersz listy pod spodem ma liczbę większą o jeden
            # i inny skład. Kryterium mierzące jedną drogę świeciło zielono nad ekranem,
            # na którym dwie pozostałe nie działały.
            and set((ok_okno.get("drogi") or {}).keys()) == {"krzyzyk", "escape", "tlo"}
            and all(
                d.get("okno_zamkniete") is True
                and len(d.get("dodano") or []) == 1
                # ⚠ Wiersz odświeżony **w budżecie czasu**, a nie „kiedyś": `odswiezenie_ms`
                # jest zmierzone, nie założone.
                and d.get("licznik_wzrosl_o_jeden") is True
                and isinstance(d.get("odswiezenie_ms"), int)
                and d["odswiezenie_ms"] <= ODSWIEZENIE_MS
                and d.get("sklad_sie_zmienil") is True
                # ⚠ …i odświeżenie przyszło z **żądania wywołanego zamknięciem**, dokładnie
                # jednego. Bez tego warunku kryterium przeszłoby nad ekranem, który odświeża
                # się przypadkiem (np. przy następnym kliknięciu), i nie zauważyłoby też
                # dwóch odświeżeń na jedno zamknięcie.
                and (d.get("sonda") or {}).get("zadania") == 1
                for d in (ok_okno.get("drogi") or {}).values()
            )
        )

        # ⚠ **`wygaszone_nie_wchodza`** (znalezisko Krzysztofa 10.10.2026 wieczorem): wygaszone
        # zatrudnienie **nie wchodzi do nowej grupy** — zapis odmawia, a lista go nie oferuje.
        # Dwie połowy, bo lista to UI, a odmowę ma wystawiać zapytanie: żądanie da się wysłać
        # z ręki (CLAUDE.md §10). Trzecia część pilnuje, że §8a zostało nietknięte: ten,
        # który był w grupie przed wygaszeniem, w niej zostaje.
        i_wyg = p.get("i_wygaszone_nie_wchodzi") or {}
        wygaszone_nie_wchodza = (
            i_wyg.get("odmowa") is True
            and i_wyg.get("bez_zmian_w_grupie") is True
            and i_wyg.get("wygaszonych_w_ofercie") == 0
            and i_wyg.get("zostal_w_swojej_grupie") is True
            # …i to samo na ekranie: zero wygaszonych w prawej kolumnie, przy niepustej liście.
            and nieakt.get("wygaszonych_po_prawej") == 0
            and nieakt.get("pozycji_po_prawej", 0) > 0
        )

        m = menu or {}
        jedna_droga = (
            lista.get("tabel") == 0
            and lista.get("pol_nazwy_w_wierszach") == 0
            and lista.get("wierszy", 0) > 0
            and all(w["akcje"] and w["klikalny"] and w["sklad"] != "" for w in lista.get("wiersze", []))
            and lista.get("okno_nowa", {}).get("jest") is True
            and lista.get("okno_nowa", {}).get("zamkniete") is True
            and lista.get("okno_nowa", {}).get("przycisk") is True
            and lista.get("okno_nowa", {}).get("bez_js", 0) > 0
            and lista.get("okien_nazwy") == lista.get("wierszy")
            and (lista.get("bez_grupy") or {}).get("w_tabeli") is False
            and (lista.get("bez_grupy") or {}).get("rozwijalny") is True
            # ⚠ Od Z31 blok nazywa się „Osoby bez grupy", więc porównanie idzie bez wielkości
            # liter: kryterium pyta, czy blok MÓWI, czym jest, a nie jak dokładnie brzmi tytuł.
            and "bez grupy" in ((lista.get("bez_grupy") or {}).get("tekst") or "").lower()
            # ⚠ **Jedno puste okno na ekran** i wyzwalacz na wierszu, a wiersz ma prawdziwy
            # adres podstrony — to ścieżka bez JavaScriptu i jedyny sposób, żeby odnośnik
            # do grupy dało się komuś wysłać (aneks §1).
            and (lista.get("okno_grupy") or {}).get("okien") == 1
            and (lista.get("okno_grupy") or {}).get("zamkniete") is True
            and (lista.get("okno_grupy") or {}).get("puste") is True
            and (lista.get("okno_grupy") or {}).get("wyzwalacz_na_wierszu") is True
            and "/admin/grupy/" in ((lista.get("okno_grupy") or {}).get("adres_wiersza") or "")
            # Menu `⋯` musi być OSIĄGALNE — stoi w karcie, a `.card` przycina.
            # ⚠ Oba przyciski wiersza muszą być OSIĄGALNE, nie tylko obecne: stoją 6 px od
            # siebie, w karcie, obok przycisku „Członkowie" rozpiętego pseudoelementem odnośnika.
            and m.get("przyciskow") == 2
            and all(e for e in m.get("etykiety", []))
            and all(t for t in m.get("tytuly", []))
            and m.get("olowek_osiagalny", {}).get("osiagalna") is True
            and m.get("kosz_osiagalny", {}).get("widoczna_czesc") == 100
            # Kosz przy grupie z osobami jest wyłączony i mówi DLACZEGO (dawny `.menu__powod`).
            and (m.get("kosz_wylaczony") is False or bool(m.get("powod")))
            # Niepusta grupa: „Usuń grupę" wyłączone i z powodem pod spodem.
            and (m.get("wylaczone") == [] or (m.get("powod") or "").strip() != "")
        )

        return {
            "ok": granica_spolki and granica_po_http and dwie_warstwy and nazwa_wielkie_litery
                and wiele_grup and licznik_nieaktywnych and wygaszone_nie_wchodza
                and jedno_zadanie and akcja_odwrotna
                and dwie_kolumny and okno_grupy and jedna_droga,
            "granica_spolki": granica_spolki,
            "granica_po_http": granica_po_http,
            "dwie_warstwy": dwie_warstwy,
            "nazwa_wielkie_litery": nazwa_wielkie_litery,
            "wiele_grup": wiele_grup,
            "licznik_nieaktywnych": licznik_nieaktywnych,
            "wygaszone_nie_wchodza": wygaszone_nie_wchodza,
            "jedno_zadanie": jedno_zadanie,
            "akcja_odwrotna": akcja_odwrotna,
            "dwie_kolumny": dwie_kolumny,
            "okno_grupy": okno_grupy,
            "jedna_droga": jedna_droga,
            "lista_grup": lista,
            "menu_grupy": menu,
            "ekran_grupy": ekran,
            "probka": p,
        }
    except Exception as e:  # noqa: BLE001
        return {"ok": False, "powod": str(e)}
    finally:
        if ctx is not None:
            try:
                ctx.close()
            except Exception:  # noqa: BLE001
                pass

def opis_osiagalnosci(o: dict) -> list[str]:
    """Trzy warunki osiągalności, **każdy opisany osobno** — bramka ma mówić, CO nie wyszło.

    ⚠ Wypisujemy wszystkie trzy, także spełnione: „prostokąt w oknie: TAK" przy zerowym polu
    po przecięciu jest dokładnie tym zdaniem, którego brakowało w raporcie z 10.10 nad ranem.
    """
    wiersze = [
        f"prostokąt {o['top']}→{o['bottom']} px w oknie {o['okno']} px: "
        f"{'TAK' if o['w_oknie'] else 'NIE'}",
        f"pole po przecięciu z przodkami: {o['pole']} z {o['pole_wlasne']} px² "
        f"({o['widoczna_czesc']}%)"
        + (f" — uciął {', '.join(u['przodek'] + '{overflow: ' + u['overflow'] + ', dół ' + str(u['bottom']) + ' px}' for u in o['uciely'])}"
           if o["uciely"] else ""),
        f"elementFromPoint({o['punkt'][0]}, {o['punkt'][1]}) → {o['trafiony'] or 'nic'}: "
        f"{'TAK' if o['trafia'] else 'NIE — oczekiwano tego elementu albo jego potomka'}",
    ]
    if o.get("przewinieto"):
        wiersze.append("(liczby PO przewinięciu strony do elementu — warstwa jedzie ze stroną, "
                       "więc samo wyjście poniżej okna jej nie unieruchamia)")
    return wiersze


def powody_nakladki(n: dict) -> list[str]:
    """Dlaczego to sprawdzenie nakładki nie przechodzi. Pusta lista = przechodzi."""
    if not n.get("otwarta"):
        return ["NIE OTWARŁA SIĘ" + (" — " + n["blad"] if n.get("blad") else "")]
    powody = []
    for nazwa, ost in (("ostatnia akcja warstwy", n.get("ostatni")),
                       ("ostatnia akcja obszaru przewijanego", n.get("ostatni_w_obszarze"))):
        if ost and not ost.get("osiagalna"):
            powody.append(f"{nazwa} {ost['tekst']!r} — NIEOSIĄGALNA:")
            powody += ["  " + w for w in opis_osiagalnosci(ost)]
    o = n.get("obszar")
    if o and o.get("przewija") is False:
        powody.append(f"obszar .{o['klasa']} ma {o['scrollH']} px treści w {o['h']} px, "
                      f"a scrollTop nie ruszył ({o['scrollTop_przed']}→{o['scrollTop_po']}) "
                      "— obcięty, nie przewijany")
    if not n.get("dialog_w_oknie", True):
        powody.append(f"warstwa wystaje poniżej okna (dół na {n['dialog']['bottom']} px)")
    d = n.get("dialog_osiagalnosc")
    if d and d["pole"] == 0:
        powody.append("warstwa jest PRZYCIĘTA do zera:")
        powody += ["  " + w for w in opis_osiagalnosci(d)]
    return powody


# ⚠ **Rejestr kontroli negatywnych**: nazwa usterki → kryterium, które ma się na niej złamać.
# Wpis tutaj jest częścią przepięcia kryterium, nie dodatkiem — kryterium bez wpisu nikt nigdy
# nie złamał i nie wiadomo, czy potrafi zapalić się z właściwego powodu (tura Z22).
# ⚠ **Usterka kontrolna: okno znów wybiera za człowieka** — dokładnie stan sprzed Z81 (24.10.2026).
# Podstawia zakres, wypełnia podpisy kafelków i odgasza przycisk zapisu, czyli odwraca wszystkie
# trzy twierdzenia o pustym starcie naraz. Nie przez `DOMContentLoaded`, a przez odpytywanie:
# treść okna wymienia htmx przy każdym kroku, więc jednorazowe podstawienie zniknęłoby
# po pierwszym kliknięciu „Dalej".
_usterka("okno_widocznosci", """
    window.setInterval(() => {
      const pierwszy = document.querySelector('.wid-zakres input');
      if (pierwszy && !document.querySelector('.wid-zakres input:checked')) { pierwszy.checked = true; }
      document.querySelectorAll('[data-wid-zapis][aria-disabled]').forEach(
        (b) => b.removeAttribute('aria-disabled'));
      document.querySelectorAll('.wid-krok__podpis--pusty').forEach((p) => {
        p.textContent = 'Bez szczegółów';
        p.classList.remove('wid-krok__podpis--pusty');
      });
    }, 120);
""")

# ⚠ **Usterka kontrolna dla `nazwa_wyswietlana`: powrót do stanu sprzed Z76** — nazwa z R2
# wraca do TREŚCI wiersza na `/dane`. Dotąd to kryterium nie miało ani jednej usterki, czyli
# nie było dowodu, że w ogóle potrafi świecić; przy przepisywaniu trzeciego twierdzenia
# (24.10.2026) dokładamy ją razem ze zmianą.
_usterka("nazwa_wyswietlana", """
    window.setInterval(() => {
      document.querySelectorAll('.dane-firma__nazwa').forEach((e) => {
        const r2 = e.getAttribute('title') || '';
        if (r2 !== '' && e.textContent.trim() !== r2) { e.textContent = r2; }
      });
    }, 120);
""")

# Stan okna reguły widoczności — jeden odczyt na wszystkie twierdzenia tego kryterium.
OKNO_WIDOCZNOSCI = r"""() => {
  const w = document.querySelector('#widocznosc-okno-tresc') || document.body;
  const txt = (el) => el ? (el.textContent || '').trim().replace(/\s+/g, ' ') : null;
  return {
    kroki: [...w.querySelectorAll('.wid-krok')].map((k) => ({
      nazwa: txt(k.querySelector('.wid-krok__nazwa')),
      podpis: txt(k.querySelector('.wid-krok__podpis')),
      gotowy: k.classList.contains('wid-krok--gotowy'),
      wylaczony: k.classList.contains('wid-krok--wylaczony'),
      odnosnik: k.tagName === 'A',
      biezacy: k.getAttribute('aria-current') === 'step',
    })),
    stopka: [...w.querySelectorAll('.modal__foot .btn')].map((b) => ({
      tekst: txt(b),
      glowny: b.classList.contains('btn--primary'),
      martwy: !!b.disabled,
      przygaszony: b.getAttribute('aria-disabled') === 'true',
      zapis: b.hasAttribute('data-wid-zapis'),
    })),
    sekcje: [...w.querySelectorAll('.wid-seg__poz')].map((p) => ({
      sekcja: p.getAttribute('data-sekcja'),
      on: p.classList.contains('wid-seg__poz--on'),
      zablokowana: p.getAttribute('aria-disabled') === 'true',
    })),
    zakresow_wybranych: w.querySelectorAll('.wid-zakres input:checked').length,
    zakresow: w.querySelectorAll('.wid-zakres input').length,
  };
}"""

LISTA_WIDOCZNOSCI = r"""() => {
  const txt = (el) => el ? (el.textContent || '').trim().replace(/\s+/g, ' ') : null;
  return [...document.querySelectorAll('.wid-wiersz')].map((e) => ({
    tekst: (txt(e) || '').slice(0, 90),
    separator: e.classList.contains('wid-wiersz--separator'),
    nieaktywna: e.classList.contains('wid-wiersz--nieaktywna'),
    plakietka: txt(e.querySelector('.wid-wiersz__plakietka')),
    ma_przelacznik: !!e.querySelector('.wid-stan'),
  }));
}"""


def sprawdz_okno_widocznosci(b, usterka: str | None = None) -> dict:
    """**Okno reguły widoczności: nic nie jest wybrane z góry, stopka ma dwa przyciski, a reguła
    bez skutku widnieje jako nieaktywna** (zlecenia Z78, Z79, Z80 i Z81).

    ⚠ To kryterium powstało, bo cztery decyzje z tych zleceń są **niewidoczne w kodzie
    i niewidoczne w testach**: żyją w tym, co pokazuje okno. Zmierzone w trakcie Z80 i Z81:
    reguła stała dawała się przestawić na krok 1 adresem z ręki, wybór w sekcji nie blokował
    pozostałych, a krok 3 startował z wybranym zakresem — i każda z tych rzeczy wyglądała
    w kodzie poprawnie.

    Siedem twierdzeń, każde łamane osobno:
      1. `stala_bez_krokow` — w regule stałej kroki 1 i 2 są WYŁĄCZONE (nie są odnośnikami),
         bo podmiotu ani celu nie da się w niej wskazać (Z78, Z79),
      2. `stala_stopka` — jej stopka to dokładnie „Anuluj" i „Zapisz zmiany": jeden krok nie ma
         gdzie wracać, a okno zmieniające dostęp całej firmy musi mieć wyjście bez zapisu,
      3. `nowa_pusty_start` — w nowej regule WSZYSTKIE trzy kafelki mówią „nie wybrano", żaden
         zakres nie jest zaznaczony i żadna sekcja nie jest zablokowana (Z81 pkt 1),
      4. `stopka_krokow` — na kroku 1 „Wstecz" jest martwy, a obok stoi „Dalej"; na kroku 3
         w miejscu „Dalej" stoi przycisk zapisu i NIE MA osobnego zapisu w krokach 1–2 (Z81 pkt 2),
      5. `zapis_przygaszony` — przy niewskazanym zakresie przycisk zapisu jest przygaszony
         (`aria-disabled`), a po wskazaniu karty odżywa (Z81 pkt 2, rozstrzygnięcie o drodze
         bez JavaScriptu: przygaszony, ale działający),
      6. `blokada_sekcji` — wybór w jednej sekcji blokuje pozostałe (Z80 A1),
      7. `separator_nieaktywnych` — po wyłączeniu reguły lista pokazuje wiersz „NIEAKTYWNE · …",
         a sam wiersz reguły ma plakietkę „wyłączona" (Z80 B6).

    ⚠ **Regułę zakładamy i kasujemy PRZEZ OKNO, a jej podmiotem jest osoba próbna.** Przez okno,
    bo to właśnie droga, którą to kryterium mierzy — zapis SQL-em sprawdziłby listę, a nie
    kreator. Na osobie próbnej (`dev/probka-zespolu.php`), bo nadanie i odebranie wglądu
    w cudze nieobecności prawdziwej osobie na czas pomiaru jest zmianą w danych kadrowych,
    a nie pomiarem (ta sama zasada co w `przelacznik_zespolu`).

    ⚠ Pomiar oddaje spółkę kontekstu, którą zastał — stan jest trwały
    (`account.context_company_id`), więc zostawiony wędruje do następnych sprawdzeń.
    """
    haslo = HASLO or haslo_dev(LOGIN)
    if not haslo:
        return {"ok": False, "powod": f"brak hasła konta {LOGIN} — pomiaru NIE wykonano"}
    probka_zespolu("sprzataj")
    probka = probka_zespolu("utworz")
    spolka = int(probka.get("spolka") or 0)
    if spolka == 0:
        return {"ok": False, "powod": "próbka nie podała spółki — pomiaru NIE wykonano"}
    ctx = None
    spolka_przed = ""
    wynik: dict = {"probka_spolka": spolka}
    try:
        ctx = nowy_kontekst(b, usterka, viewport={"width": SZEROKOSC_LAPTOPA, "height": 900},
                            ignore_https_errors=True)
        pg = ctx.new_page()
        pg.goto(BAZA + "/logowanie", wait_until="networkidle")
        pg.fill("#login-email", LOGIN)
        pg.fill("#login-password", haslo)
        pg.click("button[type=submit]")
        pg.wait_for_load_state("networkidle")
        pg.goto(BAZA + "/kalendarz?widok=firma", wait_until="networkidle")
        spolka_przed = pg.evaluate(
            "() => ((document.querySelector('.spolka') || {}).dataset || {}).spolka || ''")
        ustaw_spolke_w_belce(pg, spolka)
        pg.goto(BAZA + "/admin/widocznosc", wait_until="networkidle")

        # ——— 1 i 2: reguła stała ———————————————————————————————————————————————
        pg.locator(".wid-wiersz a.ikona-akcja--edycja").first.click()
        pg.wait_for_timeout(700)
        stala = pg.evaluate(OKNO_WIDOCZNOSCI)
        wynik["stala"] = stala
        stala_bez_krokow = (len(stala["kroki"]) == 3
                            and stala["kroki"][0]["wylaczony"] and not stala["kroki"][0]["odnosnik"]
                            and stala["kroki"][1]["wylaczony"] and not stala["kroki"][1]["odnosnik"]
                            and stala["kroki"][2]["biezacy"])
        stala_stopka = [p["tekst"] for p in stala["stopka"]] == ["Anuluj", "Zapisz zmiany"]

        # ——— 3 i 4: nowa reguła, krok 1 ————————————————————————————————————————
        pg.keyboard.press("Escape")
        pg.goto(BAZA + "/admin/widocznosc", wait_until="networkidle")
        pg.locator("a.btn--akcja", has_text="Dodaj dostęp").first.click()
        pg.wait_for_timeout(700)
        krok1 = pg.evaluate(OKNO_WIDOCZNOSCI)
        wynik["krok1"] = krok1
        nowa_pusty_start = (
            [k["podpis"] for k in krok1["kroki"]] == ["nie wybrano"] * 3
            and not any(k["gotowy"] for k in krok1["kroki"])
            and not any(s["zablokowana"] for s in krok1["sekcje"])
        )
        stopka1 = [(p["tekst"], p["martwy"], p["zapis"]) for p in krok1["stopka"]]

        # ——— 6: wybór blokuje pozostałe sekcje ————————————————————————————————
        pg.fill(".wid-szukanie__pole", "__probka_zespolu")
        pg.keyboard.press("Enter")
        pg.wait_for_timeout(800)
        pg.locator(".wid-poz", has_text="__probka_zespolu").first.click()
        pg.wait_for_timeout(500)
        po_wyborze = pg.evaluate(OKNO_WIDOCZNOSCI)
        wynik["po_wyborze_podmiotu"] = po_wyborze
        blokada_sekcji = (po_wyborze["kroki"][0]["gotowy"]
                          and sum(1 for s in po_wyborze["sekcje"] if s["zablokowana"]) == 2)

        # ——— krok 2: cel ———————————————————————————————————————————————————————
        # ⚠ Pole szukania czyścimy PRZED przejściem: tekst jedzie z formularzem, a w kroku 2
        # odsiewa pozycję „Cała firma" (szukanie znaczy „chcę konkretne osoby").
        pg.fill(".wid-szukanie__pole", "")
        pg.locator(".modal__foot .btn", has_text="Dalej").first.click()
        pg.wait_for_timeout(700)
        krok2 = pg.evaluate(OKNO_WIDOCZNOSCI)
        wynik["krok2"] = krok2
        pg.locator("label[for=wid-cala-firma], #wid-cala-firma").first.click(force=True)
        pg.wait_for_timeout(500)

        # ——— 4 i 5: krok 3 ———————————————————————————————————————————————————
        pg.locator(".modal__foot .btn", has_text="Dalej").first.click()
        pg.wait_for_timeout(700)
        krok3 = pg.evaluate(OKNO_WIDOCZNOSCI)
        wynik["krok3_bez_zakresu"] = krok3
        pg.locator("label:has(#wid-zakres-pelny), #wid-zakres-pelny").first.click(force=True)
        pg.wait_for_timeout(500)
        krok3_po = pg.evaluate(OKNO_WIDOCZNOSCI)
        wynik["krok3_po_zakresie"] = krok3_po
        zapis_przygaszony = (
            krok3["zakresow_wybranych"] == 0
            and any(p["zapis"] and p["przygaszony"] for p in krok3["stopka"])
            and krok3_po["zakresow_wybranych"] == 1
            and any(p["zapis"] and not p["przygaszony"] and not p["martwy"] for p in krok3_po["stopka"])
        )
        stopka3 = [(p["tekst"], p["glowny"], p["zapis"]) for p in krok3["stopka"]]
        # ⚠ Krotki, nie same napisy: „Wstecz" w kroku 1 ma być MARTWY, a „Dalej" żywy. Pierwsza
        # wersja tego warunku oczekiwała `martwy` u obu i kryterium świeciło na poprawnym ekranie.
        # Oba zestawy jadą do wyniku, żeby czerwień mówiła, co zobaczyła, a nie tylko „false".
        wynik["stopka_krok1"] = stopka1
        wynik["stopka_krok3"] = stopka3
        stopka_krokow = (
            stopka1 == [("Wstecz", True, False), ("Dalej", False, False)]
            and [p["tekst"] for p in krok2["stopka"]] == ["Wstecz", "Dalej"]
            and not any(p["martwy"] for p in krok2["stopka"])
            and stopka3 == [("Wstecz", False, False), ("Nadaj dostęp", True, True)]
        )

        # ——— 7: zapis, wyłączenie, separator ————————————————————————————————
        pg.locator("[data-wid-zapis]").first.click(force=True)
        pg.wait_for_load_state("networkidle")
        pg.wait_for_timeout(600)
        wynik["lista_po_zapisie"] = pg.evaluate(LISTA_WIDOCZNOSCI)
        wiersz = pg.locator(".wid-wiersz", has_text="__probka_zespolu").first
        dodana = wiersz.count() > 0
        if dodana:
            wiersz.locator(".wid-stan").first.click()
            pg.wait_for_load_state("networkidle")
            pg.wait_for_timeout(600)
        lista = pg.evaluate(LISTA_WIDOCZNOSCI)
        wynik["lista_po_wylaczeniu"] = lista
        separator = next((w for w in lista if w["separator"]), None)
        wiersz_nieaktywny = next((w for w in lista
                                  if w["nieaktywna"] and "__probka_zespolu" in w["tekst"]), None)
        separator_nieaktywnych = (
            dodana and separator is not None and "NIEAKTYWNE" in (separator["tekst"] or "")
            and wiersz_nieaktywny is not None and wiersz_nieaktywny["plakietka"] == "wyłączona"
        )

        # ——— sprzątanie reguły TĄ SAMĄ drogą, którą ją nadaliśmy ———————————————
        usunieta = None
        if dodana:
            kosz = pg.locator(".wid-wiersz", has_text="__probka_zespolu").first.locator(
                ".ikona-akcja--usun").first
            kosz.click()
            pg.wait_for_timeout(500)
            potwierdz = pg.locator("dialog[open] button[type=submit]").first
            if potwierdz.count() > 0:
                potwierdz.click()
            pg.wait_for_load_state("networkidle")
            pg.wait_for_timeout(500)
            usunieta = pg.locator(".wid-wiersz", has_text="__probka_zespolu").count() == 0
        wynik["regula_usunieta"] = usunieta

        wynik.update({
            "ok": bool(stala_bez_krokow and stala_stopka and nowa_pusty_start and stopka_krokow
                       and zapis_przygaszony and blokada_sekcji and separator_nieaktywnych
                       and usunieta),
            "stala_bez_krokow": stala_bez_krokow,
            "stala_stopka": stala_stopka,
            "nowa_pusty_start": nowa_pusty_start,
            "stopka_krokow": stopka_krokow,
            "zapis_przygaszony": zapis_przygaszony,
            "blokada_sekcji": blokada_sekcji,
            "separator_nieaktywnych": separator_nieaktywnych,
        })

        return wynik
    except Exception as e:  # noqa: BLE001
        wynik.update({"ok": False, "powod": f"{type(e).__name__}: {e}"})

        return wynik
    finally:
        # ⚠ Kolejność: najpierw oddajemy spółkę (czyta ją ekran), potem kasujemy próbkę.
        try:
            if ctx is not None and spolka_przed not in ("", str(spolka)):
                strona = ctx.new_page()
                strona.goto(BAZA + "/kalendarz?widok=firma", wait_until="networkidle")
                ustaw_spolke_w_belce(strona, int(spolka_przed))
                strona.close()
        except Exception:  # noqa: BLE001 — sprzątanie nie ma prawa przewrócić pomiaru
            pass
        wynik["probka_sprzatanie"] = probka_zespolu("sprzataj")
        if ctx is not None:
            ctx.close()


KONTROLE: dict[str, object] = {
    "znacznik_dzis": sprawdz_znacznik_dzis,
    "przelacznik_zespolu": sprawdz_przelacznik_zespolu,
    "zespol_schowany": sprawdz_przelacznik_zespolu,
    # ⚠ Trzy kryteria przepięte w Z63 cz. A — każde z własną usterką. `jedna_wizualizacja`
    # ma dwie, bo ma dwa nowe twierdzenia: „zero obcych krat" i „tylko jedna rodzina na ekran".
    "skale_czasu": sprawdz_skale_czasu,
    # ⚠ Druga usterka tego samego kryterium: kratka bez podpowiedzi (szóste twierdzenie, Z66).
    "kratka_bez_title": sprawdz_skale_czasu,
    "tlo_dnia_pod_paskiem": sprawdz_paski_nieobecnosci,
    "prawda_kalendarza": sprawdz_prawde_kalendarza,
    "jedna_wizualizacja_obca_krata": sprawdz_jedna_wizualizacje,
    "jedna_wizualizacja_dwie_rodziny": sprawdz_jedna_wizualizacje,
    # ⚠ Jedna usterka, DWA kryteria: ta sama reguła („cudzy pasek mówi tylko »nieobecny«")
    # obowiązuje w widoku firmy (`poziomy`) i w widoku zespołu (`zasieg`), a każde z nich
    # mierzy ją na swoim ekranie i na swoim dostępie. Dwa wpisy, nie jeden, bo złamanie
    # jednego nie dowodzi niczego o drugim.
    "rodzaj_u_cudzej_osoby": sprawdz_poziomy_kalendarza,
    "rodzaj_u_cudzej_osoby_zespol": sprawdz_zasieg_podgladu,
    "pasy_szczelin": sprawdz_pasy_szczelin,
    # ⚠ Jedno kryterium, DWIE usterki: zwężony pas tła (Z25) i wyłączony `ResizeObserver` (Z26).
    # Druga jest konieczna, bo pierwsza łamie kryterium na geometrii pasa, a nie na tym, czy
    # zmierzona liczba nadąża za wysokością karty — a to był właśnie zgłoszony objaw.
    "pasy_szczelin_bez_obserwatora": sprawdz_pasy_szczelin,
    # ⚠ Usterka odwraca pusty start kroków 2 i 3 — czyli to, co Z81 wprost zdjęło.
    "okno_widocznosci": sprawdz_okno_widocznosci,
    # ⚠ Dokładane razem z przepisaniem trzeciego twierdzenia na decyzję z Z76: nazwa z R2
    # wraca do treści wiersza `/dane`, czyli do stanu, który tamta decyzja zdjęła.
    "nazwa_wyswietlana": sprawdz_nazwe_wyswietlana,
}


# ⚠ **Kryteria po nazwie** — żeby jedno dało się puścić osobno (Z63 cz. A). Powód jest
# dowodowy, nie wygodowy: przepięcie kryterium wymaga zobaczenia go czerwonego i zielonego,
# a pełny przebieg trwa kilkadziesiąt minut i nadaje w bazie role oraz poziomy. Nazwy są te
# same, co klucze w raporcie — inaczej byłby to drugi słownik tych samych rzeczy.
KRYTERIA: dict[str, object] = {
    "bez_dostepu": sprawdz_bez_dostepu,
    "cykl": sprawdz_odrzucenie_cyklu,
    "obca_spolka": sprawdz_obca_spolke,
    "ostrzezenie": sprawdz_ostrzezenie_wyjscia,
    "zasieg": sprawdz_zasieg_podgladu,
    "wymiana": sprawdz_wymiane_fragmentu,
    "poziomy": sprawdz_poziomy_kalendarza,
    "prawda_kalendarza": sprawdz_prawde_kalendarza,
    "panel_wyboru": sprawdz_panel_wyboru,
    "rozdzial_rol": sprawdz_rozdzial_rol,
    "kasowanie_klucza": sprawdz_kasowanie_klucza,
    "nazwa_wyswietlana": sprawdz_nazwe_wyswietlana,
    "wybor_spolki": sprawdz_wybor_spolki,
    "paski_nieobecnosci": sprawdz_paski_nieobecnosci,
    "skale_czasu": sprawdz_skale_czasu,
    "znacznik_dzis": sprawdz_znacznik_dzis,
    "przelacznik_zespolu": sprawdz_przelacznik_zespolu,
    "pasy_szczelin": sprawdz_pasy_szczelin,
    "jedna_wizualizacja": sprawdz_jedna_wizualizacje,
    "kontekst_spolki": sprawdz_kontekst_spolki,
    "dziennik_i_zdania": sprawdz_dziennik_i_zdania,
    "grupy": sprawdz_grupy,
    "liczniki_nieobecnosci": sprawdz_liczniki_nieobecnosci,
    "okno_widocznosci": sprawdz_okno_widocznosci,
}


def tryb_kryterium(nazwy: list[str]) -> int:
    """Puszcza wymienione kryteria i tylko je; kod wyjścia 1, gdy którekolwiek nie przeszło."""
    nieznane = [n for n in nazwy if n not in KRYTERIA]
    if nazwy == [] or nieznane:
        print("Użycie: bramka-ekranowa.py kryterium <nazwa> [...]")
        if nieznane:
            print("Nieznane: " + ", ".join(nieznane))
        print("Znane: " + ", ".join(sorted(KRYTERIA)))
        return 1
    zle = False
    with sync_playwright() as pw:
        b = pw.chromium.launch(args=[f"--host-resolver-rules=MAP {HOST} 127.0.0.1"])
        for nazwa in nazwy:
            wynik = KRYTERIA[nazwa](b)  # type: ignore[operator]
            print(json.dumps({nazwa: wynik}, ensure_ascii=False, indent=1))
            print(f"KRYTERIUM {nazwa}: {'zielone' if wynik.get('ok') else 'CZERWONE'}")
            zle = zle or not wynik.get("ok")
        b.close()
    return 1 if zle else 0


def tryb_kontrola() -> int:
    """Uruchamia każde kryterium z jego wstrzykniętą usterką i żąda wyniku `ok: False`."""
    zle = False
    # ⚠ **Kontrola kodów odpowiedzi idzie BEZ przeglądarki** i to jest świadome: usterką była
    # sama decyzja („pomiń i nic nie zapisuj"), a nie żaden widok. Sprawdzenie w przeglądarce
    # wymagałoby zepsucia ekranu na żywej instancji na czas przebiegu — a tego nie robimy
    # (instancja jest używana). Dowód end-to-end i tak istnieje: `/ustawienia` zwracało 500
    # przez cztery i pół godziny 20.10.2026 i stara bramka nie powiedziała ani słowa.
    probny: dict = {"kody": []}
    zapisz_kod(probny, "próba", "/", 200)
    zapisz_kod(probny, "próba", "/zaproszenie", 410)
    zapisz_kod(probny, "próba", "/ustawienia", 500)
    zapisane = [(k["adres"], k["kod"]) for k in probny["kody"]]
    serwerowe = [k for k in probny["kody"] if k["kod"] >= 500]
    kody_ok = zapisane == [("/zaproszenie", 410), ("/ustawienia", 500)] and len(serwerowe) == 1
    print(f"KONTROLA kody_odpowiedzi: {'zapisane (dobrze)' if kody_ok else 'NIE ZAPISANE'} — {zapisane}")
    if not kody_ok:
        zle = True
    with sync_playwright() as pw:
        b = pw.chromium.launch(args=[f"--host-resolver-rules=MAP {HOST} 127.0.0.1"])
        for nazwa, funkcja in KONTROLE.items():
            if nazwa not in USTERKI:
                print(f"KONTROLA {nazwa}: BRAK WSTRZYKIWANEJ USTERKI")
                zle = True
                continue
            # ⚠ **Osłona na wyjątek Z KRYTERIUM, nie z usterki** (dopisane 21.10.2026, Z68).
            # Bez niej jedno kryterium przewracające się na `TypeError` przerywało CAŁY przebieg
            # kontroli: zmierzone tego dnia — `sprawdz_paski_nieobecnosci()` nie przyjmowało
            # nazwy usterki, przebieg padł na piątej pozycji, a o siedmiu dalszych nie dowiedzieliśmy
            # się niczego. Co gorsza, ostatnia linia („wszystkie kryteria się łamią") wtedy po prostu
            # nie powstaje — brak dobrej wiadomości łatwo wziąć za jej brak w logu, nie za awarię.
            # Wyjątek jest więc WYNIKIEM CZERWONYM tego kryterium, a przebieg idzie dalej.
            try:
                wynik = funkcja(b, nazwa)  # type: ignore[operator]
            except Exception as e:  # noqa: BLE001 — kontrola ma dojść do końca rejestru
                print(f"KONTROLA {nazwa}: BŁĄD KRYTERIUM ({type(e).__name__}: {e})")
                zle = True
                continue
            zlamane = wynik.get("ok") is False
            print(f"KONTROLA {nazwa}: {'złamane (dobrze)' if zlamane else 'NIE ZŁAMANE — kryterium jest ozdobą'}")
            if not zlamane:
                print(f"  {wynik}")
                zle = True
        b.close()
    print("KONTROLA NEGATYWNA: " + ("CZʌŚĆ KRYTERIÓW NIE DAŁA SIĘ ZŁAMAĆ" if zle else "wszystkie kryteria z rejestru się łamią"))
    return 1 if zle else 0


def _przerwanie(_sygnal, _ramka) -> None:
    """Pierwszy `Ctrl-C` (SIGINT) przerywa pomiar, **kolejne są ignorowane**.

    ⚠ Dopisane 20.10.2026 (Z63 cz. A), po tym, jak przerwany przebieg zostawił w bazie rolę
    `calendar_viewer_full` nadaną na czas pomiaru. Każde kryterium przywraca swój stan
    w `finally`, a `KeyboardInterrupt` te bloki wykonuje — ale przywrócenie idzie przez
    **podproces PHP**, więc drugi sygnał w trakcie sprzątania zabija je w połowie. Po pierwszym
    sygnale odwieszamy więc obsługę: pomiar i tak się kończy, a sprzątanie dostaje tyle czasu,
    ile potrzebuje. Niecierpliwe drugie `Ctrl-C` nie jest powodem, żeby zostawić w bazie
    nadany dostęp.
    """
    signal.signal(signal.SIGINT, signal.SIG_IGN)
    raise KeyboardInterrupt


def main() -> int:
    signal.signal(signal.SIGINT, _przerwanie)
    prog = prog_z_arkusza()
    tryb = sys.argv[1] if len(sys.argv) > 1 else "oba"
    if tryb == "kontrola":
        return tryb_kontrola()
    if tryb == "kryterium":
        return tryb_kryterium(sys.argv[2:])
    # ⚠ **Tryb `kody`: sam przebieg po ekranach, bez kryteriów** (Z63 cz. A). Powód nie jest
    # oszczędnością czasu, tylko dowodliwością: sprawdzenie „zero 5xx" trzeba umieć złamać
    # i naprawić, a każde kryterium nadaje role i poziomy w bazie, więc pełny przebieg nie
    # jest czymś, co się puszcza dwa razy pod rząd dla jednego twierdzenia. Ten tryb nie
    # rusza w bazie niczego.
    tylko_ekrany = tryb == "kody"
    if tylko_ekrany:
        tryb = "oba"
    okna = []
    admin = (LOGIN, HASLO)
    # ⚠ Ekrany pracownika chodzą na KONCIE PRACOWNIKA. Administrator tenanta nie ma zatrudnienia,
    # więc na `/` i `/kalendarz` widzi zdanie „to konto nie ma zatrudnienia" — bramka mierzyła
    # dotąd ten stan i ani jednej liczby z kadr.
    pracownik = (LOGIN3, HASLO3 or haslo_dev(LOGIN3))
    # Miesiąc z danymi wpisany jawnie: bieżący miesiąc może nie mieć ani jednej nieobecności,
    # a wtedy przebieg nie zmierzyłby siatki z wpisami.
    PRACOWNIK_WEB = ["/", "/?rok=2025", "/kalendarz", "/kalendarz?miesiac=2026-08"]
    PRACOWNIK_MOB = ["", "/kalendarz"]
    if tryb in ("oba", "waska"):
        okna.append(("374-dotyk", 374, 812, True, "/m", MOBILNE, admin))
        if pracownik[1]:
            okna.append(("374-dotyk-pracownik", 374, 812, True, "/m", PRACOWNIK_MOB, pracownik))
    if tryb in ("oba", "szeroka"):
        okna.append(("1920-mysz-mobilny", 1920, 1080, False, "/m", MOBILNE, admin))
        okna.append(("1920-mysz-web", 1920, 1080, False, "", sciezki_web(), admin))
        if pracownik[1]:
            okna.append(("1920-mysz-pracownik", 1920, 1080, False, "", PRACOWNIK_WEB, pracownik))
        # ⚠ Szerokość laptopowa — i przez trzy tygodnie ślepy punkt tej bramki: mierzyła 1920
        # i 374, a tabele przewijały się w bok dokładnie POMIĘDZY. Zmierzone przed naprawą:
        # /diagnostyka 301 i 167 px, /dane/synchronizacja 191 px, /admin/integracja 80 px,
        # /dane 10 px — pięć tabel, o dwóch z nich nikt nie wiedział.
        # ⚠ Próg dołożony PO naprawie, nie przed: bramka świecąca na czerwono od pierwszego dnia
        # przestaje być bramką.
        # ⚠ **1366 px, nie 1440** (decyzja zarządcy z 20.10.2026, Z63): bramka i pomiar odbiorowy
        # mają mierzyć TE SAME trzy szerokości (1920 / 1366 / 374), bo dwie listy szerokości
        # znaczą dwa różne „sprawdzone" — a 1366 jest i węższa, i częstsza na laptopach.
        # Czwartej szerokości nie dokładamy: każda kosztuje ~25% czasu przebiegu.
        okna.append(("1366-mysz-web", 1366, 900, False, "", sciezki_web(), admin))
        if pracownik[1]:
            okna.append(("1366-mysz-pracownik", 1366, 900, False, "", PRACOWNIK_WEB, pracownik))

    raport: dict = {"prog_z_arkusza": prog, "naruszenia": [], "prog_naruszony": [],
                    "ekranow": 0, "bledy": [], "csp": [], "kody": [], "bez_dostepu": None, "nakladki": [],
                    "wciecia": [], "wciecia_zle": [], "ukryte": [], "siatka": [], "toasty": [], "cykl": None,
                    "obca_spolka": None, "ostrzezenie": None, "zasieg": None, "wymiana": None, "poziomy": None,
                    "prawda_kalendarza": None, "panel_wyboru": None, "rozdzial_rol": None,
                    "kasowanie_klucza": None, "nazwa_wyswietlana": None,
                    "wybor_spolki": None,
                    "paski_nieobecnosci": None,
                    "skale_czasu": None,
                    "znacznik_dzis": None,
                    "pasy_szczelin": None,
                    "slowo_spolka": [],
                    "tytul_ekranu": [],
                    "jedna_wizualizacja": None,
                    "kontekst_spolki": None,
                    "dziennik_i_zdania": None,
                    "grupy": None,
                    "liczniki_nieobecnosci": None}

    # ⚠ Nazwy firm z bazy raz na przebieg — jedyny wyjątek kryterium `bez_slowa_spolka`.
    try:
        nazwy = nazwy_firm()
    except Exception:  # noqa: BLE001
        nazwy = []
    with sync_playwright() as pw:
        b = pw.chromium.launch(args=[f"--host-resolver-rules=MAP {HOST} 127.0.0.1"])
        for etyk, w, h, mob, prefiks, sciezki, konto in okna:
            ctx = b.new_context(viewport={"width": w, "height": h}, ignore_https_errors=True,
                                is_mobile=mob, has_touch=mob)
            pg = ctx.new_page()
            # ⚠ Błędy konsoli zbieram do KOSZYKA na stronę i dopisuję do raportu tylko wtedy,
            # gdy ta strona odpowiedziała poniżej 400. Inaczej bramka zgłaszała jako usterkę
            # własne odwiedziny poprawnych odmów: 410 na wygasłym zaproszeniu, 419 na ekranie
            # „formularz wygasł", 404 na kodzie QR, który istnieje tylko w trakcie parowania.
            koszyk: list[str] = []
            pg.on("console", lambda m, e=etyk: koszyk.append(f"{e} {m.type}: {m.text}")
                  if m.type in ("error", "warning") else None)
            pg.on("pageerror", lambda x, e=etyk: koszyk.append(f"{e} pageerror: {x}"))
            pg.add_init_script("""document.addEventListener('securitypolicyviolation',
                e => (window.__csp = window.__csp || []).push(e.violatedDirective + ' <- ' + e.blockedURI));""")
            pg.goto(BAZA + "/logowanie", wait_until="networkidle")
            pg.fill("#login-email", konto[0])
            pg.fill("#login-password", konto[1])
            pg.click("button[type=submit]")
            pg.wait_for_load_state("networkidle")
            # ⚠ Konto pracownika z dwoma zatrudnieniami trafia najpierw na wybór kontekstu.
            # Bez tego kroku każdy ekran tego przebiegu byłby ekranem wyboru firmy.
            if "/kontekst" in pg.url:
                pg.click(".page form button[type=submit]")
                pg.wait_for_url(lambda u: "/kontekst" not in u, timeout=15000)
            for s in sciezki:
                koszyk.clear()
                odp = pg.goto(BAZA + prefiks + s if prefiks else BAZA + s,
                              wait_until="domcontentloaded")
                # ⚠ **Kod odpowiedzi jest WYNIKIEM POMIARU, nie powodem ciszy** (Z63 cz. A).
                # Do 20.10.2026 ekran z kodem >= 400 był tu po prostu pomijany: nie wchodził
                # ani do liczby zmierzonych ekranów, ani do usterek — czyli ekran w **500
                # wyglądał w raporcie dokładnie jak ekran, którego bramka nie odwiedziła**.
                # Zmierzony koszt tej ciszy: `/ustawienia` i `/ustawienia/poczta` zwracały 500
                # od Z60 przez cztery i pół godziny i znalazł to człowiek, nie bramka.
                # 5xx to usterka bez wyjątków; 4xx trafia na listę do przeczytania, bo część
                # odwiedzanych adresów odmawia **poprawnie** (410 na wygasłym zaproszeniu,
                # 419 na wygasłym formularzu, 404 na kodzie QR spoza parowania).
                if odp is not None:
                    zapisz_kod(raport, etyk, (prefiks + s) or "/m", odp.status)
                if odp is None or odp.status >= 400:
                    continue
                try:
                    pg.wait_for_load_state("networkidle", timeout=5000)
                except Exception:  # noqa: BLE001
                    pass
                raport["ekranow"] += 1
                adres = (prefiks + s) or "/m"
                m = pg.evaluate(POMIAR, KONTENERY)
                for k in m["kontenery"]:
                    raport["naruszenia"].append({"okno": etyk, "adres": adres, **k})
                if m["strona"]["przewija"]:
                    raport["naruszenia"].append({"okno": etyk, "adres": adres, "sel": "STRONA",
                                                 **m["strona"]})
                for r in m["rekordy"]:
                    if r["naturalna"] > prog:
                        raport["prog_naruszony"].append({"okno": etyk, "adres": adres, "prog": prog, **r})
                for u in m["ukryte"]:
                    raport["ukryte"].append({"okno": etyk, "adres": adres, **u})
                t = m["toasty"]
                if (t.get("brak")
                        or (t["dzieci"] == 0 and t["h"] > 0)
                        or t["wskaznik"] != "none"
                        or t["rola"] != "status" or t["live"] != "polite"):
                    raport["toasty"].append({"okno": etyk, "adres": adres, **t})
                if m["siatka"] and abs(m["siatka"]["roznica"]) > SIATKA_TOLERANCJA:
                    raport["siatka"].append({"okno": etyk, "adres": adres, **m["siatka"]})
                for w in m["wciecia"]:
                    raport["wciecia"].append({"okno": etyk, "adres": adres, **w})
                    zle_wciecie = abs(w["wciecie"] - WCIECIE_W_KARCIE) > WCIECIE_TOLERANCJA
                    # ⚠ Szersza od karty = świadome przewijanie w opakowaniu (sprawdza je pomiar
                    # kontenerów wyżej). Usterką jest tabela WĘŻSZA od karty, w której stoi.
                    zle_szer = w["karta"] - w["tabela"] > WCIECIE_TOLERANCJA
                    if zle_wciecie or zle_szer:
                        raport["wciecia_zle"].append({"okno": etyk, "adres": adres, **w})
                # ⚠ Nazwa ekranu — mierzona TU, z tego samego powodu co słowo „spółka": zasada
                # dotyczy wszystkich ekranów, a tu bramka i tak na każdym jest.
                wady = wady_tytulu(pg.evaluate(POMIAR_TYTULU))
                if wady:
                    raport["tytul_ekranu"].append({"okno": etyk, "adres": adres, "wady": wady})
                # ⚠ Słowo „spółka" w napisach — mierzone TU, bo tu bramka odwiedza wszystkie
                # 104 ekrany w szerokim i wąskim oknie, na koncie administracyjnym i pracownika.
                # Osobny przebieg po tych samych adresach byłby drugim licznikiem tej samej rzeczy.
                for z in (pg.evaluate(POMIAR_SLOWA, nazwy) or []):
                    raport["slowo_spolka"].append({"okno": etyk, "adres": adres, **z})
                csp = pg.evaluate("window.__csp || []") or []
                if csp:
                    raport["csp"].append({"okno": etyk, "adres": adres, "csp": csp})
                raport["bledy"].extend(f"{adres} {w}" for w in koszyk)
            ctx.close()
        if not tylko_ekrany:
            raport["nakladki"] = sprawdz_nakladki(b, sciezki_web())
            raport["bez_dostepu"] = sprawdz_bez_dostepu(b)
            raport["cykl"] = sprawdz_odrzucenie_cyklu(b)
            raport["obca_spolka"] = sprawdz_obca_spolke(b)
            raport["ostrzezenie"] = sprawdz_ostrzezenie_wyjscia(b)
            raport["zasieg"] = sprawdz_zasieg_podgladu(b)
            raport["wymiana"] = sprawdz_wymiane_fragmentu(b)
            raport["poziomy"] = sprawdz_poziomy_kalendarza(b)
            raport["prawda_kalendarza"] = sprawdz_prawde_kalendarza(b)
            raport["panel_wyboru"] = sprawdz_panel_wyboru(b)
            raport["rozdzial_rol"] = sprawdz_rozdzial_rol(b)
            raport["kasowanie_klucza"] = sprawdz_kasowanie_klucza(b)
            raport["nazwa_wyswietlana"] = sprawdz_nazwe_wyswietlana(b)
            raport["wybor_spolki"] = sprawdz_wybor_spolki(b)
            raport["paski_nieobecnosci"] = sprawdz_paski_nieobecnosci(b)
            raport["skale_czasu"] = sprawdz_skale_czasu(b)
            raport["znacznik_dzis"] = sprawdz_znacznik_dzis(b)
            raport["pasy_szczelin"] = sprawdz_pasy_szczelin(b)
            raport["jedna_wizualizacja"] = sprawdz_jedna_wizualizacje(b)
            raport["kontekst_spolki"] = sprawdz_kontekst_spolki(b)
            raport["dziennik_i_zdania"] = sprawdz_dziennik_i_zdania(b)
            raport["grupy"] = sprawdz_grupy(b)
            raport["liczniki_nieobecnosci"] = sprawdz_liczniki_nieobecnosci(b)
        b.close()

    print(json.dumps(raport, ensure_ascii=False, indent=1))
    print()
    # ⚠ W trybie `kody` kryteria NIE BYŁY MIERZONE i wypis mówi to wprost, zamiast podstawiać
    # im wynik „ok": sprawdzenie, które nie poszło, nie jest sprawdzeniem zielonym. Dwa
    # kryteria czytane wzorcem `raport[...] or {}` dostają sentinel z tą właśnie informacją.
    if tylko_ekrany:
        print("⚠ TRYB `kody`: zmierzone wyłącznie ekrany (kody odpowiedzi, przewijanie, konsola,")
        print("  CSP, wcięcia, pojemnik komunikatów). KRYTERIÓW NIE MIERZONO — pełny przebieg")
        print("  idzie bez argumentu albo z `oba`.")
        for _k in ("bez_dostepu", "liczniki_nieobecnosci"):
            raport[_k] = {"ok": True, "nie_mierzone": "tryb kody"}
    zle = False
    if raport["prog_naruszony"]:
        zle = True
        print("NIEZMIENNIK PROGU ZŁAMANY — tabela rekordów szersza niż próg z arkusza:")
        for r in raport["prog_naruszony"]:
            print(f"  {r['adres']} ({r['okno']}) tabela nr {r['nr']}: naturalna {r['naturalna']} px "
                  f"> próg {r['prog']} px; kolumny: {', '.join(r['naglowki']) or '(bez thead)'}")
        print("  ⚠ Przy tym progu ta tabela NIE zamieni się w listę i zostanie z przewijaniem w bok.")
        print("  Albo zawęź tabelę, albo podnieś próg w 30-components.css — świadomie, bo próg")
        print("  wiąże wszystkie oznaczone tabele.")
    zle_nakladki = [n for n in raport["nakladki"] if powody_nakladki(n)]
    if zle_nakladki:
        zle = True
        print(f"NAKŁADKI — {len(zle_nakladki)} z {len(raport['nakladki'])} sprawdzeń nie przechodzi:")
        for n in zle_nakladki:
            print(f"  {n['adres']} · {n['okno_id']} @ {n['wysokosc']}px:")
            for w in powody_nakladki(n):
                print(f"    {w}")
        print("  ⚠ Kolumna flex musi być nieprzerwana: `min-height: 0` na każdym ogniwie między")
        print("  stałą wysokością nakładki a obszarem przewijanym; stopka `flex: none`.")
        print("  ⚠ A jeśli powodem jest PRZYCIĘCIE: warstwa nie ma prawa stać w przodku")
        print("  z `overflow: hidden`, bo wtedy jej wysokość wyznacza nie okno, a ten przodek.")

    ln = raport["liczniki_nieobecnosci"] or {}
    if not ln.get("ok"):
        zle = True
        print("LICZNIKI NIEOBECNOŚCI SIĘ ROZJECHAŁY:")
        if ln.get("powod"):
            print(f"  {ln['powod']}")
        else:
            print(f"  osoba #{ln['osoba']['person_id']} ({ln['osoba']['last_name']}), rok {ln['rok']}: "
                  f"baza mówi {ln['osoba']['dni']} dni obowiązujących "
                  f"i {ln['osoba']['wycofanych']} wycofanych")
            print(f"  lista firmy: {ln['z_listy']}")
            print(f"  karta osoby: {ln['z_karty']}")
        print("  ⚠ Jeden wzór na jedną liczbę: **wycofana nieobecność nie jest nieobecnością**")
        print("  (`removed_at IS NULL`), a wycofane nazywa się OSOBNO — i tyle samo razy, ile")
        print('  plakietek „wycofana” ma tabela pod licznikiem.')

    # ⚠ **5xx to usterka bez wyjątków** (Z63 cz. A). 4xx wypisujemy osobno i NIE psuje
    # przebiegu: część odwiedzanych adresów odmawia poprawnie i taka odmowa jest cechą ekranu,
    # nie usterką. Rozdział idzie po stronie serwera, bo tylko 5xx znaczy „kod się wywrócił".
    serwer = [k for k in raport["kody"] if k["kod"] >= 500]
    odmowy = [k for k in raport["kody"] if k["kod"] < 500]
    if serwer:
        zle = True
        print(f"EKRAN ODPOWIEDZIAŁ BŁĘDEM SERWERA ({len(serwer)}):")
        for k in serwer:
            print(f"  {k['adres']} ({k['okno']}): {k['kod']}")
        print("  ⚠ Identyfikator zdarzenia i wyjątek są w storage/logs/app-RRRR-MM-DD.log —")
        print("  ekran w 500 nie pokazuje przyczyny użytkownikowi (CLAUDE.md §14).")
    if odmowy:
        print(f"ODMOWY 4xx na odwiedzonych adresach ({len(odmowy)}) — do przeczytania, nie usterka:")
        for k in odmowy:
            print(f"  {k['adres']} ({k['okno']}): {k['kod']}")

    bd = raport["bez_dostepu"] or {}
    if not bd.get("ok"):
        zle = True
        print("EKRAN BRAKU DOSTĘPU NIE ZGADZA SIĘ:")
        print(f"  konto {bd.get('login')}: kod {bd.get('kod')}, nagłówek {bd.get('naglowek')!r}")
        print(f"  oczekiwano kodu {BEZ_DOSTEPU[0]} i nagłówka zawierającego {BEZ_DOSTEPU[1]!r}")
        if bd.get("blad"):
            print(f"  błąd: {bd['blad']}")
        print("  ⚠ Konto musi istnieć na instancji: pracownik z zatrudnieniem i BEZ dostępu")
        print("  (dev/utworz-administratora.php --osoba=<id> --rola=), patrz dev/README.md.")
    if raport["ukryte"]:
        zle = True
        print(f"UKRYTY ELEMENT MA PUDEŁKO ({len(raport['ukryte'])}):")
        for u in raport["ukryte"]:
            print(f"  {u['adres']} ({u['okno']}) {u['el']}: {u['w']}×{u['h']} px, "
                  f"display: {u['display']}")
        print("  ⚠ `[hidden]` z arkusza przeglądarki przegrywa z każdą regułą na klasie.")
        print("  W `10-base.css` stoi `[hidden] { display: none !important }` — jeśli element")
        print("  nadal ma pudełko, ktoś ustawił mu wymiary inaczej niż przez `display`.")
    if raport["toasty"]:
        zle = True
        print(f"POJEMNIK KOMUNIKATÓW NIE ZGADZA SIĘ ({len(raport['toasty'])}):")
        for t in raport["toasty"]:
            print(f"  {t['adres']} ({t['okno']}): {t}")
        print("  ⚠ Na każdym ekranie ma stać `#toasty` z `role=\"status\"` i `aria-live=\"polite\"`,")
        print("  pusty ma 0 px wysokości i `pointer-events: none` — inaczej przezroczysty pas")
        print("  u dołu okna przykrywa przyciski zapisu. To kryterium zastąpiło pilnowanie")
        print("  paska `#flash[hidden]`, który zniknął razem ze zleceniem Z47.")
    if raport["siatka"]:
        zle = True
        print(f"KONTENER SIATKI NIŻSZY NIŻ DOKUMENT ({len(raport['siatka'])}):")
        for s in raport["siatka"]:
            print(f"  {s['adres']} ({s['okno']}): .app {s['wysokosc']} px, dokument "
                  f"{s['dokument']} px, różnica {s['roznica']} px")
        print("  ⚠ Coś stoi POD kontenerem siatki — element `position: sticky` (pasek boczny)")
        print("  odklei się na tej różnicy na końcu przewijania.")
    if raport["wciecia_zle"]:
        zle = True
        print(f"WCIĘCIE TABELI W KARCIE ({len(raport['wciecia_zle'])} z {len(raport['wciecia'])} tabel):")
        for w in raport["wciecia_zle"]:
            print(f"  {w['adres']} ({w['okno']}) tabela nr {w['nr']}: pierwsza komórka "
                  f"{w['wciecie']} px od krawędzi karty (ma być {WCIECIE_W_KARCIE}), "
                  f"tabela {w['tabela']} px w karcie {w['karta']} px; rodzic: {w['rodzic']}")
        print("  ⚠ Tabela na pełną szerokość karty jest BEZPOŚREDNIM DZIECKIEM karty; wcięcie robi")
        print("  padding komórki, nie opakowanie z własnym paddingiem (system-wizualny.md §3.4).")
    if raport["cykl"] is not None and not raport["cykl"]["ok"]:
        zle = True
        print("HIERARCHIA — ZAPIS NIE ODRZUCIŁ PĘTLI:")
        print(f"  {raport['cykl']}")
        print("  ⚠ To nie jest usterka wyglądu: cykl w hierarchii zawiesza eskalację")
        print("  w nieskończonej pętli (znalezisko L2). Zapis musi go odrzucić błędem")
        print("  walidacji i ZOSTAWIĆ stan nietknięty.")
    if raport["obca_spolka"] is not None and not raport["obca_spolka"]["ok"]:
        zle = True
        print("HIERARCHIA — ZAPIS NIE ODRZUCIŁ PRZEŁOŻONEGO Z INNEJ SPÓŁKI:")
        print(f"  {raport['obca_spolka']}")
        print("  ⚠ Tą regułą dane kadrowe jednej spółki mogłyby wyjść ścieżką akceptacji drugiej.")
    if raport["ostrzezenie"] is not None and not raport["ostrzezenie"]["ok"]:
        zle = True
        print("HIERARCHIA — OSTRZEŻENIE O NIEZAPISANYCH ZMIANACH:")
        print(f"  {raport['ostrzezenie']}")
        print("  ⚠ Reguła: ostrzegamy przy WYJŚCIU, nigdy przy ZAPISIE. Okno na własnym zapisie")
        print("  blokuje podstawową operację ekranu; brak okna przy wyjściu kasuje pracę po cichu.")
    if raport["zasieg"] is not None and not raport["zasieg"]["ok"]:
        zle = True
        print("KALENDARZ ZESPOŁU — ZASIĘG PODGLĄDU NIE TRZYMA:")
        print(f"  {raport['zasieg']}")
        print("  ⚠ Wybór osób to FILTR, nie uprawnienie: identyfikator spoza własnej gałęzi")
        print("  (i z innej spółki) ma wrócić pustym wynikiem, a rodzaj na pasku ma być OGÓLNY")
        print("  (urlop albo zwolnienie), nigdy kodem ze słownika — to dana o zdrowiu.")
        print("  Zmierzone na wstrzykniętej usterce: bez warunku zakresu jedno żądanie pokazało")
        print("  27 osób całej spółki zamiast czterech z gałęzi.")
    if raport["wymiana"] is not None and not raport["wymiana"]["ok"]:
        zle = True
        print("KALENDARZ — WYMIANA FRAGMENTU:")
        print(f"  {raport['wymiana']}")
        print("  ⚠ Trzy warunki naraz: treść się zmienia BEZ przeładowania, adres się zmienia")
        print("  (żeby dało się wkleić, zakładkować i cofnąć), a bez JavaScriptu ten sam odnośnik")
        print("  prowadzi do pełnej strony. Wymiana, która psuje przycisk wstecz, jest stratą.")
    if raport["poziomy"] is not None and not raport["poziomy"]["ok"]:
        zle = True
        print("KALENDARZ FIRMY — POZIOMY WIDOCZNOŚCI NIE TRZYMAJĄ:")
        print(f"  {raport['poziomy']}")
        print("  ⚠ Poziom 0 (domyślny, także dla administratora) nie widzi nic ponad swoje;")
        print("  poziom 1 nie ma prawa oddać nazwy rodzaju ze słownika W TREŚCI odpowiedzi;")
        print("  granica spółki trzyma także przy podmienionym `?spolka=`; a limit wyświetlania")
        print("  NIE zapisuje się jako wyłączenia — inaczej pracownik przyjęty jutro zniknąłby.")
    if raport["prawda_kalendarza"] is not None and not raport["prawda_kalendarza"]["ok"]:
        zle = True
        print("KALENDARZ FIRMY — EKRAN NIE MÓWI PRAWDY:")
        print(f"  {raport['prawda_kalendarza']}")
        print("  ⚠ Podpisy przełożonego są LICZONE, nie szukane: konto bez zatrudnienia nie może")
        print('  podpisać ani jednej osoby „przełożony: Ty”, a korzeń hierarchii nie może mieć')
        print('  tego podpisu u nikogo. Zmierzone przed naprawą: 25 z 30 wierszy miało „Ty”.')
        print("  ⚠ Dalej w tej samej pozycji: `h1`, tytuł dokumentu i okruch mówią jedno i to samo;")
        print("  zdanie o dniach wolnych nie przypisuje oglądającemu rozkładu, którego nie ma;")
        print("  liczba nieobecności w zdaniu zgadza się z liczbą pasków; nagłówek dni zostaje")
        print("  widoczny po przewinięciu o ekran — z hit-testem, nie samym prostokątem.")
    if raport["panel_wyboru"] is not None and not raport["panel_wyboru"]["ok"]:
        zle = True
        print("KONTROLKA WYBORU OSÓB — PANEL ALBO ARYTMETYKA:")
        print(f"  {raport['panel_wyboru']}")
        print("  ⚠ Nazwany wyjątek z system-wizualny.md 12 jest tu REGUŁĄ: przewija się wyłącznie")
        print("  lista, panel i formularz nie, a przycisk zapisu jest w całości na ekranie.")
        print('  ⚠ „Odznacz wszystkich” na liście przyciętej limitem zapisuje DOKŁADNIE tyle')
        print("  wyłączeń, ile pozycji było pokazanych — ani jednego więcej.")
        print("  ⚠ Panel sprawdzany TRZY RAZY: po wejściu, po zmianie miesiąca i po zmianie spółki.")
        print("  Karta jest wymieniana przez htmx, a kontrolka dostrajana w JS przestaje wtedy")
        print("  działać bez błędu i bez komunikatu — to już się zdarzyło w prototypie.")
    if raport["rozdzial_rol"] is not None and not raport["rozdzial_rol"]["ok"]:
        zle = True
        print("ROZDZIAŁ RÓL (administrator ≠ pracownik) NIE TRZYMA:")
        print(f"  {raport['rozdzial_rol']}")
        print("  ⚠ Trzy drogi, trzy dowody: nadanie `tenant_admin` koncie z etatem odrzucone,")
        print("  nadanie dostępu pracowniczego koncie administracyjnemu odrzucone, a zatrudnienie")
        print("  przysłane z kadr dla konta administracyjnego daje pozycję BŁĄD na /diagnostyka.")
        print("  ⚠ Trzeciej drogi nie da się odrzucić i nie wolno: synchronizacji nie blokujemy")
        print("  i danych z kadr nie prostujemy — rozwiązanie należy do człowieka (etap3 §8c).")
    if raport["kasowanie_klucza"] is not None and not raport["kasowanie_klucza"]["ok"]:
        zle = True
        print("KASOWANIE KLUCZA INSTALACJI — PRZESZKODY NIE TRZYMAJĄ:")
        print(f"  {raport['kasowanie_klucza']}")
        print("  ⚠ Cztery przeszkody, każda w innym miejscu: klucz CZYNNY odrzucony (404),")
        print("  brak potwierdzenia (422), brak tokenu CSRF (419), a poprawne żądanie kasuje")
        print("  i zostawia wpis w dzienniku. Ukryty przycisk nie jest żadną z nich.")
        print("  ⚠ Ekran potwierdzenia sprawdzany OSOBNO od endpointu: pierwsza wersja dawała")
        print("  404 na tym ekranie, więc przycisk prowadził w pustkę, a kasowanie działało")
        print("  tylko przy żądaniu zbudowanym ręcznie. Endpoint bez ekranu to połowa funkcji.")
    if raport["nazwa_wyswietlana"] is not None and not raport["nazwa_wyswietlana"]["ok"]:
        zle = True
        print("NAZWA WYŚWIETLANA SPÓŁKI — NIE PODSTAWIA SIĘ ALBO NIE SORTUJE:")
        print(f"  {raport['nazwa_wyswietlana']}")
        print("  ⚠ Trzy kryteria, każde osobno: `podstawienie` (ekran pokazuje nazwę wyświetlaną")
        print("  i nie pokazuje nazwy z R2), `kolejnosc` (spółka z nazwą sortującą się pierwsza")
        print("  stoi pierwsza, choć po nazwie z R2 była ostatnia) i `wyjatek_kadry` (/dane DALEJ")
        print("  pokazuje nazwę z R2 — tam nazwa z kadr jest treścią do porównania, nie etykietą).")
        print("  ⚠ Samo `podstawienie` przechodzi przy `SELECT` z nazwą wyświetlaną i `ORDER BY")
        print("  c.name` — dlatego kolejność jest osobnym warunkiem, a nie częścią tego samego.")
    if raport["wybor_spolki"] is not None and not raport["wybor_spolki"]["ok"]:
        zle = True
        print("GLOBALNY WYBÓR SPÓŁKI — ZNACZNIK NIE JEST JEDEN ALBO NIE DZIAŁA:")
        print(f"  {raport['wybor_spolki']}")
        print("  ⚠ `jeden_znacznik` (dokładnie jeden `.spolka` w belce i ZERO innych kontrolek")
        print("  wyboru spółki w treści), `bezwladny_na_tenancie` („Wszystkie firmy”, bez")
        print("  rozwijania), `przelacza` (zmienia widoczną treść, nie tylko napis), `trwa`")
        print("  (przeżywa zmianę ekranu i ponowne zalogowanie), `zapomniana_spolka` (utracony")
        print("  dostęp do zapamiętanej spółki jest NAZWANY, nie przemilczany),")
        print("  `jedna_droga_na_spolke` (warunek Krzysztofa z 11.10: ŻADNA spółka nie ma dwóch")
        print("  dróg przełączenia, a liczba pozycji „przez zatrudnienie” równa się liczbie")
        print("  spółek z czynnym etatem W BAZIE — mierzone na koncie bez etatów i na koncie")
        print("  z etatem plus nadanym podglądem, bo jedno konto nie pokazuje obu kształtów).")
        print("  ⚠ Sam napis w znaczniku niczego nie dowodzi: ekran czytający spółkę po staremu")
        print("  pokazuje jedno w belce, a drugie na liście — i wygląda bez zarzutu.")
    if raport["pasy_szczelin"] is not None and not raport["pasy_szczelin"]["ok"]:
        zle = True
        print("SZCZELINY WOK\u00d3\u0141 PRZYKLEJONEGO NAG\u0141\u00d3WKA KALENDARZA \u2014 PRZE\u015aWITUJ\u0104:")
        print(f"  {raport['pasy_szczelin']}")
        print("  \u26a0 Pas mi\u0119dzy belk\u0105 a blokiem nag\u0142\u00f3wka i pas mi\u0119dzy blokiem a siatk\u0105 maj\u0105 mie\u0107")
        print("  WY\u0141\u0104CZNIE kolor t\u0142a strony \u2014 pod przyklejonym blokiem przewijaj\u0105 si\u0119 wiersze")
        print("  i w ka\u017cdej nieprzykrytej szparze wida\u0107 ich kraw\u0119dzie (Z25).")
        print("  \u26a0 Mierzone BARW\u0104 PIKSELA na pe\u0142nej szeroko\u015bci kolumny tre\u015bci: hit-test w \u015brodku")
        print("  pasa przepuszcza\u0142 1 px ramki przy samej kraw\u0119dzi, czyli dok\u0142adnie t\u0119 usterk\u0119.")
        print("  \u26a0 Drugie twierdzenie (Z26): wiersz dni stoi DOK\u0141ADNIE odst\u0119p blok\u00f3w pod kart\u0105")
        print("  (`odstepy` ma mie\u0107 jedn\u0105 warto\u015b\u0107 \u2014 16 px). Ujemna znaczy, \u017ce wiersz schowa\u0142 si\u0119 pod")
        print("  kart\u0105; wi\u0119ksza \u2014 \u017ce odjecha\u0142 od niej i w szczelinie wida\u0107 wiersze os\u00f3b.")
        print("  \u26a0 Pi\u0119\u0107 okien, w tym trzy z powi\u0119kszeniem przegl\u0105darki (110/125/150 %) i pr\u00f3ba")
        print("  zmiany wysoko\u015bci karty w trakcie \u2014 to po niej liczba bez `ResizeObserver` zostaje stara.")
    if raport["znacznik_dzis"] is not None and not raport["znacznik_dzis"]["ok"]:
        zle = True
        print("ZNACZNIK „DZIŚ” ALBO KSZTAŁT KRATKI DNIA — NIE TRZYMA:")
        print(f"  {raport['znacznik_dzis']}")
        print("  ⚠ Pięć twierdzeń, każde osobno (zlecenie poprawkowe 11.10 §3):")
        print("  `slabszy_od_danej` (kontrast znacznika < kontrastu nieobecności, w KAŻDYM z trzech")
        print("  widoków, z pułapem: znacznik zniknięty też jest błędem), `jedna_nazwa` (`--today`")
        print("  zero wystąpień w całych katalogach szablonów i arkuszy), `w_naglowku` (numer dnia")
        print("  dzisiejszego wyróżniony w nagłówku i RÓŻNI się od pozostałych nagłówków),")
        print("  i `kratka_ksztalt` (proporcja w pasmie wokół dnia z Miesiąca — OBIE granice).")
        print("  ⚠ `rok_bez_przewijania` NIE ISTNIEJE — skasowane (tura Z22, podpowiedź została")
        print("  do 20.10.2026): przewijanie widoku roku jest wybrane świadomie.")
    if raport["skale_czasu"] is not None and not raport["skale_czasu"]["ok"]:
        zle = True
        print("SKALE CZASU W „TYLKO JA” — JEDNA Z TRZECH NIE TRZYMA:")
        print(f"  {raport['skale_czasu']}")
        print("  ⚠ Pięć twierdzeń, każde osobno (zlecenie 11.10 §3a): `stan_z_adresu` (adres ze")
        print("  skalą daje tę skalę, przełącznik ją pokazuje, wartość bzdurna wraca do miesiąca,")
        print("  a na widoku zespołu skala nie działa), `dane_nie_pustka` (liczba nieobecności")
        print("  zgadza się z BAZĄ dla zakresu — z sufitem, bo więcej też jest usterką),")
        print("  `rok_numer_nad_kolumna` (geometrią, nie indeksem), `lista_wyrownanie` (ostatnia")
        print("  kolumna u prawej krawędzi karty) i `kwartal_waski` (trzy siatki WIDOCZNE,")
        print("  zero przewijania strony przy 374 i 320 px).")
    if raport["tytul_ekranu"]:
        zle = True
        print("NAZWA EKRANU NIE WYSTĘPUJE RAZ W BELCE — %d ekranów:" % len(raport["tytul_ekranu"]))
        for z in raport["tytul_ekranu"][:20]:
            print(f"  {z['okno']} {z['adres']}: {'; '.join(z['wady'])}")
        if len(raport["tytul_ekranu"]) > 20:
            print(f"  … i {len(raport['tytul_ekranu']) - 20} dalszych")
        print("  ⚠ Trzy warunki naraz (zlecenie 11.10 §3b): dokładnie JEDEN `h1` i stoi")
        print("  w belce; jego tekst niepusty i zgodny z `<title>` (jedno źródło — blok")
        print("  `nazwa_ekranu`); ten tekst NIE występuje jako inny nagłówek na ekranie.")
        print("  ⚠ Sam brak `h1` pod belką przeszedłby przy ekranie, który stracił nazwę")
        print("  całkowicie; sama obecność `h1` w belce — przy ekranie, gdzie nazwa dalej stoi")
        print("  w nagłówku karty. Oba stany były zmierzone na prototypie.")
    if raport["slowo_spolka"]:
        zle = True
        print("SŁOWO „SPÓŁKA” W NAPISACH — %d wystąpień:" % len(raport["slowo_spolka"]))
        for z in raport["slowo_spolka"][:20]:
            print(f"  {z['okno']} {z['adres']} [{z['gdzie']}] {z['zdanie']}")
        if len(raport["slowo_spolka"]) > 20:
            print(f"  … i {len(raport['slowo_spolka']) - 20} dalszych")
        print("  ⚠ Rozstrzygnięcie Krzysztofa 11.10.2026: **nie każda firma jest spółką** —")
        print("  „spółka” to forma prawna, a klientem bywa jednoosobowa działalność, spółdzielnia")
        print("  albo fundacja. W interfejsie jednostka organizacyjna nazywa się FIRMA. Nazwy klas,")
        print("  kolumn, tras i kody zdarzeń zostają bez zmian — to język kodu, nie człowieka.")
        print("  ⚠ Jedyny wyjątek: nazwa prawna przysłana przez R2, porównywana z nazwami firm")
        print("  Z BAZY (nie z kształtem napisu) — nazwy klienta nie poprawiamy.")
    if raport["paski_nieobecnosci"] is not None and not raport["paski_nieobecnosci"]["ok"]:
        zle = True
        print("PASKI NIEOBECNOŚCI — KONTRAST ALBO NUMER DNIA NIE TRZYMA:")
        print(f"  {raport['paski_nieobecnosci']}")
        print("  ⚠ Następca kryterium kontrastu z 06.10 (tamto mierzyło OBRYS, którego już nie ma).")
        print("  `kontrast_tekstu` (napis na pasku ≥ 4,5 do wypełnienia, dla każdego z trzech")
        print("  rodzajów — `kadrowe` sondą, bo w danych go nie ma), `kontrast_paska` (wypełnienie")
        print("  do tła wiersza 1,12–3,0 i do tła dnia wolnego ≥ 1,05, a pasek mocniejszy niż samo")
        print("  wygaszenie weekendu), `numer_dnia_zgodny` (pasek jednodniowy niesie numer ZGODNY")
        print("  z kolumną — rozstrzygane geometrią — nieucięty, także przy 374 i 320 px, przy")
        print("  zerowym przewijaniu strony) oraz `legenda_zgodna` (próbka legendy ma to samo tło,")
        print("  co pasek tego rodzaju; inaczej legenda opisuje kolor, którego na ekranie nie ma).")
    if raport["jedna_wizualizacja"] is not None and not raport["jedna_wizualizacja"]["ok"]:
        zle = True
        print("JEDNA WIZUALIZACJA KALENDARZA — NIE TRZYMA:")
        print(f"  {raport['jedna_wizualizacja']}")
        print("  ⚠ `jedna_wizualizacja` (oś czasu na każdym koncie i w każdym widoku, zero")
        print("  starej siatki miesiąca), `wlasna_lista` (wykaz pod osią zgadza się z paskami)")
        print("  i `znacznik_pracownika` (pracownik z dwoma etatami przełącza spółkę znacznikiem")
        print("  pulpitu zostaje — `?spolka=` jest parametrem widoku, nie zmianą stanu).")
        print("  ⚠ Powód istnienia: przed 07.10.2026 pracownik bez podwładnych i bez nadanego")
        print("  poziomu widział WYŁĄCZNIE starą siatkę, więc żadna poprawka wyglądu")
        print("  nieobecności do niego nie dochodziła.")
    if raport["kontekst_spolki"] is not None and not raport["kontekst_spolki"]["ok"]:
        zle = True
        print("KONTEKST SPÓŁKI Z KALENDARZA (wariant C) — NIE TRZYMA:")
        print(f"  {raport['kontekst_spolki']}")
        print("  ⚠ Pięć kryteriów: `przelacza_kontekst` (kalendarz i pulpit mówią to samo po")
        print("  przełączeniu, miesiąc zachowany), `naglowek_wiodacy` (przełączenie w nagłówku")
        print("  ciągnie kalendarz), `obce_odrzucone` (POST /kontekst z cudzym zatrudnieniem")
        print("  → 404, kontekst nietknięty), `dwa_rodzaje` (pozycje o dwóch znaczeniach widoczne")
        print("  jako dwa rodzaje PRZED kliknięciem) i `bez_js` (formularz działa bez JavaScriptu).")
        print("  ⚠ Pracownik na dwóch połówkach etatu ma DWA różne limity — kalendarz pokazujący")
        print("  inną spółkę niż pulpit to planowanie urlopu na cudzej liczbie.")
    if raport["dziennik_i_zdania"] is not None and not raport["dziennik_i_zdania"]["ok"]:
        zle = True
        print("DZIENNIK I ZDANIA (tura porządkowa 08.10) — NIE TRZYMA:")
        print(f"  {raport['dziennik_i_zdania']}")
        print("  ⚠ `dziennik_z_na` (wpis o zmianie niesie wartość PRZED i PO — to ta usterka,")
        print("  która kosztowała dane 06.10), `zdania_zamiast_ciszy` (?widok=zespol i ?widok=firma")
        print("  bez prawa mówią DLACZEGO, a ?widok=ja milczy) i `kolizja_nazw` (nazwa równa")
        print("  nazwie z kadr innej spółki odrzucona, a sprawdzenie serializuje się blokadą —")
        print("  progiem jest CZAS oczekiwania, bo bez `FOR UPDATE` ten sam wyścig daje 0 s).")
    if raport["grupy"] is not None and not raport["grupy"]["ok"]:
        zle = True
        print("GRUPY — NIE TRZYMAJĄ:")
        print(f"  {raport['grupy']}")
        print("  ⚠ `granica_spolki` (zatrudnienie z innej spółki nie wchodzi do grupy I nie")
        print("  wypisuje nikogo z jego grupy — klucz obcy tej zgodności NIE wyrazi, musi być")
        print("  w zapytaniu), `dwie_warstwy` (zapytanie odrzuca usunięcie niepustej grupy,")
        print("  a po pominięciu warunku odrzuca ją klucz obcy), `nazwa_wielkie_litery`,")
        print("  `wiele_grup` (jedna osoba w kilku grupach: próbka + ekran, bez kroku")
        print("  potwierdzenia i bez gubienia pozostałych grup) oraz `bez_grupy_na_ekranie`.")
    if raport["naruszenia"]:
        zle = True
        print(f"PRZEWIJANIE W POZIOMIE ({len(raport['naruszenia'])}):")
        for n in raport["naruszenia"]:
            print(f"  {n['adres']} ({n['okno']}) {n['sel']} nr {n.get('nr', '-')}: "
                  f"{n['clientWidth']} / {n['scrollWidth']}")
    if zle:
        print(f"\nBRAMKA: NARUSZENIA — {raport['ekranow']} ekranów, próg z arkusza {prog} px")
        return 1
    if tylko_ekrany:
        print(f"BRAMKA (tryb kody): OK — {raport['ekranow']} ekranów, zero 5xx, "
              f"{len(raport['kody'])} odpowiedzi 4xx, zero przewijania w kontenerach "
              "i na stronie, zero błędów konsoli i naruszeń CSP. Kryteriów nie mierzono.")
        return 0
    print(f"BRAMKA: OK — {raport['ekranow']} ekranów, próg z arkusza {prog} px, "
          f"{len(raport['wciecia'])} tabel z wcięciem {WCIECIE_W_KARCIE} px, "
          f"zero ukrytych elementów z pudełkiem, pojemnik komunikatów pusty i przezroczysty "
          f"dla wskaźnika, siatka równa dokumentowi, "
          f"zero przewijania w kontenerach i na stronie, {len(raport['nakladki'])} sprawdzeń "
          "nakładek, ekran braku dostępu na miejscu, zapis hierarchii odrzuca pętlę "
          "i przełożonego z innej spółki, ostrzeżenie tylko przy wyjściu, kalendarz zespołu "
          "odrzuca identyfikator spoza gałęzi i z innej spółki, pasek osi czasu ma kontrast "
          "powyżej dnia wolnego, nawigacja wymienia fragment z zachowaniem adresu, a poziomy "
          "widoczności kalendarza firmy trzymają (0 nie widzi, 1 nie przecieka szczegółem), "
          'ekran kalendarza nie podpisuje „przełożony: Ty” nikomu bez przełożonego, nagłówek dni '
          "zostaje przyklejony po przewinięciu, panel wyboru mieści się w oknie z widocznym "
          'przyciskiem zapisu i NIE ROŚNIE przy przewijaniu, „Odznacz pokazanych” nie wychodzi poza '
          'pokazane a „Zaznacz wszystkich” sięga całego zasięgu, przycisk spółki nie przesuwa '
          'nawigacji, przełącznik widoku nie powstaje z jedną pozycją, a rozdział ról '
          "trzyma na wszystkich trzech drogach, a kasowanie unieważnionego klucza przechodzi "
          "cztery przeszkody i nie rusza klucza czynnego")
    return 0


if __name__ == "__main__":
    sys.exit(main())
