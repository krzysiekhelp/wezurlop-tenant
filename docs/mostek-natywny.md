# Kontrakt mostka natywnego (Flutter WebView ↔ tenant)

Dokument opisuje granicę między powłoką natywną aplikacji mobilnej a stroną renderowaną
w WebView. Obowiązuje obie strony: kto pisze tenanta i kto pisze powłokę.

Stan: 16.09.2026. Wersja mostka: **1**.

---

## Dlaczego to jest kontrakt, a nie szczegół implementacji

Aplikacja w sklepie jest **wspólna dla wszystkich tenantów** i aktualizuje się przez Google Play
i App Store — czyli wtedy, kiedy użytkownik na to pozwoli. Tenant u klienta aktualizuje się osobno
i bywa o wersje w tyle albo do przodu. Te dwa byty nigdy nie są zsynchronizowane.

Nowe funkcje interfejsu jadą ze strony renderowanej w WebView, więc stary tenant po prostu
pokazuje stary interfejs — to się samo układa. **Prawdziwym kontraktem jest mostek**: biometria,
PIN, skan, aparat, wybór pliku, rejestracja tokenu push. Stąd jedna twarda reguła:

> **Mostek jest tylko do dopisywania.** Nigdy nie zmieniamy nazwy istniejącej metody, nie zmieniamy
> jej sygnatury i nigdy jej nie usuwamy — dokładamy nowe. Aplikacja działa poprawnie także wtedy,
> gdy strona nigdy nie zawoła nowszych metod. Tenant działa poprawnie także wtedy, gdy powłoka
> nie zna tych, które on zna.

Zmiana zachowania istniejącej metody = **nowa nazwa metody**. Nie ma „poprawienia po cichu":
poprawka trafia do sklepu, a u klienta zostaje stara powłoka.

---

## Granica bezpieczeństwa

⚠ **Kanał natywny wolno włączyć wyłącznie dla origin zwróconego przez podpisane discovery.**

Adres serwera pochodzi z tego, co użytkownik wpisze jako nazwę tenanta. Dopóki mostek jest
dostępny dla dowolnej załadowanej strony, cudzy serwer może wywołać logowanie Google i przejąć
token tożsamości. `flutter_inappwebview` pozwala włączyć handlery per origin — i to jest powód,
dla którego wybraliśmy je zamiast `webview_flutter`.

Do tego:

- **allowlista nawigacji** — WebView nie nawiguje poza host tenanta; wszystko inne otwiera się
  w przeglądarce systemowej,
- **mostek nie uwierzytelnia.** Biometria odblokowuje aplikację lokalnie. Sesję po stronie serwera
  zakłada wyłącznie logowanie. Zaufanie wynikowi `biometricUnlock()` po stronie PHP byłoby
  zaufaniem danym z urządzenia, którym steruje użytkownik (reguła §11).

---

## Interfejs

Przestrzeń nazw: `window.WezUrlop`. Wszystkie metody asynchroniczne zwracają `Promise`.

| Wywołanie | Zwraca | Uwagi |
|---|---|---|
| `WezUrlop.version` | `{app: "1.0.3", platform: "android"\|"ios", bridge: 1}` | pole, nie funkcja; `bridge` to wersja kontraktu |
| `WezUrlop.biometricAvailable()` | `Promise<bool>` | czy urządzenie ma skonfigurowaną biometrię |
| `WezUrlop.biometricUnlock(reason)` | `Promise<bool>` | `reason` — tekst pokazywany w systemowym oknie |
| `WezUrlop.pushToken()` | `Promise<string\|null>` | token FCM; `null`, gdy użytkownik nie zgodził się na powiadomienia |
| `WezUrlop.pickDocument(opts)` | `Promise<{name, mime, size, id}>` | `opts: {accept: ["application/pdf","image/*"], camera: bool}` |
| `WezUrlop.openExternal(url)` | `void` | otwiera w przeglądarce systemowej |
| `WezUrlop.haptic(kind)` | `void` | `kind: "light"\|"success"\|"error"` |

### Jak strona ma tego używać

```js
// modules/bridge.js
export const bridge = {
  available: (name) => typeof window.WezUrlop?.[name] === 'function',
  version: () => window.WezUrlop?.version ?? null,
};
```

**Strona musi działać bez mostka** — w zwykłej przeglądarce `window.WezUrlop` nie istnieje.
Każde wywołanie poprzedzone sprawdzeniem, każde z wariantem zapasowym:

| Funkcja | Wariant zapasowy bez mostka |
|---|---|
| `biometricUnlock` | zwykłe logowanie hasłem |
| `pickDocument` | `<input type="file">` |
| `pushToken` | brak rejestracji urządzenia, powiadomienia e-mailem |
| `haptic` | nic |
| `openExternal` | zwykły `<a target="_blank" rel="noopener">` |

Sprawdzanie **nazwy metody**, nie wersji: powłoka może znać metodę, której wersja `bridge`
jeszcze nie obejmowała, i odwrotnie.

---

## Rozpoznanie klienta — ciastko

Powłoka ustawia przed pierwszym załadowaniem, przez `CookieManager`, dwa ciastka dla hosta
tenanta (`Secure`, `SameSite=Lax`, `HttpOnly`, długi termin):

```
wu_client=app
wu_app=1.0.3/android/1        wersja / platforma / wersja kontraktu mostka
```

⚠ **Ciastko, a nie nagłówek** — i to nie jest kwestia gustu. Nagłówek ustawiony przez
`initialHeaders` albo `URLRequest` w `flutter_inappwebview` obejmuje **nawigację główną,
ale nie żądania `fetch`/`XHR`** wychodzące ze strony. Tenant używa htmx, więc połowa
interakcji poszłaby bez nagłówka: aplikacja byłaby mobilna przy wejściu na ekran i webowa
po pierwszym kliknięciu w liście. Ciastko idzie samo przy każdym żądaniu, także po
przekierowaniu i przy POST formularza, i działa **przed zalogowaniem**, gdy sesji jeszcze nie ma.

⚠ **To jest znacznik prezentacji, nie poświadczenie.** Każdy ustawi je w narzędziach
przeglądarki, więc serwer wolno na nim oprzeć wyłącznie wybór layoutu, przekierowanie
i komunikat o aktualizacji — nigdy decyzję o uprawnieniach.

Po stronie tenanta wartość jest odczytywana raz, przy logowaniu, i zapisywana w sesji
(`session.client`, `session.app_version`). Dalej decyduje sesja, więc wyczyszczenie ciastka
w trakcie pracy niczego nie psuje.

## Kierunek serwer → powłoka

Nagłówek poniżej zostaje **wyłącznie jako wariant zapasowy** dla powłok starszych niż ciastko —
mostka nie zmieniamy, tylko dopisujemy. Powłoka wysyła przy **każdym** żądaniu nagłówek:

```
X-Wezurlop-App: 1.0.3/android/1
                 │      │      └─ wersja kontraktu mostka
                 │      └─ platforma
                 └─ wersja aplikacji
```

Tenant używa go do:

- przekierowania wejścia na `/` do wariantu mobilnego (`/m/...`),
- komunikatu „zaktualizuj aplikację", gdy wersja jest niższa niż wymagana przez tę wersję tenanta.

⚠ Nagłówek **nie jest zabezpieczeniem** — każdy może go wysłać (reguła §11). Decyduje o formie
odpowiedzi i o komunikacie, nigdy o uprawnieniach. Wariant mobilny wybiera **adres** (`/m/...`),
nie nagłówek; nagłówek co najwyżej przekierowuje raz, na wejściu.

⚠ **Wykrywania po `User-Agent` nie ma.** Jest zawodne i nie wolno na nim opierać niczego,
co użytkownik zobaczy jako „aplikacja się zepsuła".

---

## Czego w wariancie mobilnym nie ma

- **instalator tenanta** — instalacja odbywa się w przeglądarce, na serwerze klienta, przez osobę
  wdrażającą; aplikacja ze sklepu łączy się z **już zainstalowanym** tenantem. Trasy instalatora
  nie mają wariantu mobilnego w ogóle — nie są ukryte, nie istnieją.
- **strona diagnostyczna** — z tego samego powodu.

---

## Rejestr zmian kontraktu

| Wersja | Data | Zmiana |
|---|---|---|
| 1 | 2026-09-16 | wersja pierwsza: `version`, `biometricAvailable`, `biometricUnlock`, `pushToken`, `pickDocument`, `openExternal`, `haptic` |
| 1 | 2026-09-17 | ciastka `wu_client` i `wu_app` jako podstawowe rozpoznanie klienta; nagłówek `X-Wezurlop-App` zostaje wariantem zapasowym. Metod mostka nie zmienia, więc wersja kontraktu bez zmian. |

Każda kolejna pozycja **dokłada** metody. Usunięcie albo zmiana istniejącej wymaga decyzji
architektonicznej i nowej nazwy — nie wpisu w tej tabeli.
