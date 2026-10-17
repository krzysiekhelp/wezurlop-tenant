# `dev/` — przybornik deweloperski

⚠ **Nic z tego katalogu nie jedzie do klienta.** Paczka powstaje z **listy dopuszczonych** ścieżek
(`dev/package-allowlist.txt`), a `dev` jest dodatkowo na liście zakazanej w `dev/build-package.php`
(krok `verify`). Nowy plik tutaj nie trafi do klienta przez przeoczenie — musiałby zostać
dopisany do listy dopuszczonych ręcznie.

## Narzędzia

| plik | język | co robi |
|---|---|---|
| `bramka-ekranowa.py` | Python 3 | mierzy **wyrenderowany** układ w przeglądarce: przewijanie w poziomie wewnątrz kontenerów, niezmiennik progu `@container`, błędy konsoli i CSP |
| `build-package.sh`, `build-package.php` | bash + PHP | paczka dla klienta z listy dopuszczonych ścieżek |
| `package-vhost.sh` | bash | vhost dla zbudowanej paczki (test paczki, nie źródeł) |
| `reset-tenanta.php` | PHP | czyści instancję roboczą do stanu po instalacji |
| `utworz-administratora.php` | PHP | konto administratora na instancji roboczej |
| `przygotuj-wniosek.php` | PHP | dane wejściowe do scenariuszy etapu B |

Narzędzia **statyczne** (czytające pliki, nie przeglądarkę) są w PHP i leżą w `bin/`:
`check-rules.php` (reguły z `CLAUDE.md`) i `inwentarz-klas.php` (klasy CSS: co żyje, co jest sierotą).

---

## `bramka-ekranowa.py`

### Dlaczego to jedyne narzędzie nie w PHP

Sterowanie przeglądarką z PHP oznacza własnego klienta protokołu **CDP** — czyli napisanie od zera
tego, co tutaj działa i co złapało już trzy usterki (`.card__body` zwinięty do 48 px na
`/diagnostyka`, przewijanie `/m/wnioski` przy szerokim oknie, dwie wymyślone ścieżki w samej
bramce). ⚠ Zasada „repozytorium jest w PHP" dotyczy **produktu**, nie przybornika.

### Zależności

| co | wersja / skąd | uwaga |
|---|---|---|
| `python3` | 3.10+ | `from __future__ import annotations` + składnia `list[str]` |
| `playwright` (pakiet Python) | `pip install --user playwright` | na maszynie deweloperskiej jest w `~/.local/lib/python3.12/site-packages` |
| Chromium dla Playwrighta | `python3 -m playwright install chromium` | ląduje w `~/.cache/ms-playwright`; mierzone na **153.0.8010.12** |
| działająca instancja | `https://tenant.wezurlop.pl` | ⚠ maszyna deweloperska **nie ma NAT loopback**, dlatego skrypt sam mapuje host na `127.0.0.1` |
| konto do zalogowania | `kreator@wezurlop.test` | zwykłe konto instancji roboczej, nie sekret produkcyjny |
| **drugie** konto | `bezdostepu@wezurlop.test` | ⚠ **nie-administrator**: pracownik z aktywnym zatrudnieniem i **bez nadanego dostępu** do żadnej spółki |

Sprawdzenie, czy wszystko jest na miejscu:

```bash
python3 -c "import playwright; print('playwright OK')"
ls ~/.cache/ms-playwright
```

### Uruchomienie

```bash
cd /home/dev/tenant
python3 dev/bramka-ekranowa.py            # oba okna: 374 px z dotykiem i 1920 px z myszą
python3 dev/bramka-ekranowa.py waska      # tylko 374 px (lustro /m)
python3 dev/bramka-ekranowa.py szeroka    # tylko 1920 px (oba warianty)
```

Kod wyjścia **0** = czysto, **1** = naruszenia. Wypis to JSON plus czytelne podsumowanie.

Nadpisywalne zmiennymi środowiskowymi: `BRAMKA_BAZA`, `BRAMKA_LOGIN`, `BRAMKA_HASLO`,
`BRAMKA_LOGIN2`, `BRAMKA_HASLO2`, `BRAMKA_LOGIN3`, `BRAMKA_HASLO3`, `BRAMKA_PARAMETRYCZNE`
(trasy z `{id}`, domyślnie `/dane/firma/3,/dane/pracownik/74`).

⚠ **Haseł NIE MA w repozytorium i żadne nie ma wartości domyślnej.** Bramka bierze je
ze zmiennej środowiskowej albo z `~/.wezurlop-dev-konta` (poza repo, prawa 0600, wiersze
`email<TAB>hasło`). Gdy hasła nie ma w żadnym z tych miejsc, sprawdzenie **nie wykonuje się
i mówi o tym wprost** — zamiast logować się pustym hasłem i raportować 422 jako usterkę ekranu.

### ⚠ Drugie konto — po co i jak je odtworzyć

`kreator@wezurlop.test` jest **administratorem tenanta**, a administrator jest zwolniony z ekranu
braku dostępu — więc bramka logująca się tylko nim **nigdy nie widziała tego ekranu**. Ekran,
którego bramka nie widzi, nie jest pilnowany.

Drugie konto musi być w stanie, który renderuje ten ekran: **konto aktywne, osoba z aktywnym
zatrudnieniem, zero przypisań dostępu**. Odtworzenie:

```bash
dev/utworz-administratora.php --email=bezdostepu@wezurlop.test --haslo='<HASLO-Z-~/.wezurlop-dev-konta>' \
    --osoba=<id osoby z aktywnym zatrudnieniem> --rola=
```

⚠ `--osoba` podłącza konto do osoby **już istniejącej** w kartotece — bez tego powstałaby druga
osoba bez zatrudnienia i ekran pokazałby „brak zatrudnienia" zamiast „brak dostępu". Puste
`--rola=` znaczy „żadnej roli": po nadaniu dostępu to konto przestanie pilnować tego ekranu.

### Co sprawdza i dlaczego właśnie to

1. **Przewijanie w poziomie wewnątrz kontenerów** (`.table-scroll`, `.card__body`, `.m-content`,
   `.page`) **oraz** na stronie, przy **obu** szerokościach okna.
   ⚠ Sprawdzanie tylko strony przy 376 px **nie widziało** usterki: wariant mobilny otwarty
   w przeglądarce na komputerze ma okno 1920 px i kolumnę 520 px, więc tabela przewijała się
   w karcie, a strona była czysta.
2. **Niezmiennik progu**: dla każdej `.table--rekordy` naturalna szerokość ≤ próg z
   `@container tabela (max-width: …)`.
   ⚠ **Próg jest odczytywany z arkusza**, nie wpisany w skrypcie — dwie kopie tej samej liczby
   rozjadą się przy pierwszej zmianie. Dołożenie szerszej tabeli rekordów nie może więc przejść
   po cichu: bez tego reguła przestałaby ją obsługiwać, a nikt by tego nie zauważył, bo tabela
   wyglądałaby po prostu jak tabela z paskiem.
3. **Błędy konsoli, `pageerror`, naruszenia CSP** na każdym odwiedzonym ekranie.

Ścieżki wariantu komputerowego są **odczytywane z `config/routes.php`**, nie wpisane z pamięci —
pierwsza wersja bramki miała cztery nieistniejące adresy i zgłaszała własne 404 jako usterkę
aplikacji.

### Czego bramka NIE sprawdza

- **wyglądu** — mierzy układ i liczby, nie ocenia estetyki,
- **ekranu licencji w trybie ograniczonym ani instalatora** — pierwszy wymaga wymuszenia stanu
  licencji, drugi świeżej bazy bez znacznika instalacji. Oba trzeba sprawdzić osobno
  (patrz „Jak sprawdzić instalator" niżej),
- **kaskady** — reguła może istnieć i nie działać. To wychodzi wyłącznie z pomiaru skutku,
  i dlatego bramka mierzy skutek, a nie obecność reguł.

### Jak sprawdzić instalator (nie przechodzi zwykłego przebiegu)

Instalator po instalacji przestaje działać (znacznik poza webrootem), więc nie da się go odwiedzić
na instancji roboczej. Sposób bez psucia instancji:

```bash
PACZKA_DB=<nazwa-bazy>_test dev/package-vhost.sh     # paczka na BAZIE TESTOWEJ
```

i otworzyć `http://paczka.localhost:8090/instalator`. ⚠ Baza testowa, nie robocza — inaczej
instalator wyczyści dane, na których pracujemy.
