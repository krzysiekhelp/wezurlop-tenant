/* Kontrolka „kogo widać na kalendarzu" — dociągnięcie wysokości panelu do okna i licznik
   zaznaczonych.

   ⚠ **Wszystko tutaj jest WYGODĄ, nie warunkiem działania** (CLAUDE.md §6). Bez JavaScriptu
   panel ma zapas wysokości z arkusza (`max-height: 60vh`), lista przewija się sama, a oba
   działania — „Zaznacz wszystkich" i „Odznacz wszystkich" — to przyciski wysyłające formularz,
   więc liczy je serwer. Ten plik tylko dopasowuje wysokość do faktycznego miejsca pod
   przyciskiem i przelicza napis w stopce.

   ⚠ **Odtwarzanie po wymianie fragmentu jest wymaganiem, nie ostrożnością** (zlecenie 06.10 §4).
   Karta kalendarza jest wymieniana przez htmx przy każdej zmianie miesiąca, widoku i spółki.
   Prototyp nadzoru żył tylko w DOM-ie i po pierwszym przełączeniu spółki ZNIKAŁ — bez błędu
   i bez komunikatu. Dlatego: nasłuch jest delegowany na `document` (przeżywa podmianę sam),
   a to, co wymaga pomiaru, przelicza się na `htmx:afterSwap`.

   ⚠ Zero innerHTML — tekst wyłącznie przez textContent (CLAUDE.md §6). */
(function () {
  'use strict';

  /* ⚠ **Znacznik mówi „kod, który obsługuje TĘ kontrolkę, jest na stronie"** — i stoi
     na kontrolce, nie na dokumencie (poprawka 10.10.2026, §5.1 zlecenia). Dotąd ten plik
     nadawał klasę `js-kalendarz-wybor` elementowi `<html>`, a arkusz wiązał z nią widoczność
     działań masowych. Skutek zmierzony na `/admin/grupy/2`: ta sama kontrolka na ekranie,
     który tego pliku nie ładuje, miała „Zaznacz pokazanych (53)" i „Odznacz pokazanych (53)"
     w HTML-u, oba z `display: none` — czyli masowego zaznaczania nie było wcale, a 53 osoby
     trzeba było klikać po jednej. Znacznik na panelu nie ma jak się rozjechać z miejscem,
     w którym kod faktycznie działa.
     ⚠ Nadajemy go przy każdym przeliczeniu, bo panel bywa wymieniany przez htmx — nowy
     element przychodzi z serwera bez klasy. */
  function oznacz(panel) {
    panel.classList.add('pickbox--js');
  }

  /* Odstęp od krawędzi okna i najmniejszy panel, który ma jeszcze sens (szukanie + stopka
     + kilka pozycji). Poniżej tego otwieramy panel NAD przyciskiem. */
  var ODSTEP = 12;
  var MINIMUM = 260;

  function panele() {
    return Array.prototype.slice.call(document.querySelectorAll('[data-pickbox]'));
  }

  function pola(panel) {
    return Array.prototype.slice.call(panel.querySelectorAll('input[name="osoby[]"]'));
  }

  /* ⚠ **WYSOKOŚCI PANELU TEN PLIK NIE USTAWIA WCALE** — i to jest naprawa usterki z 06.10.2026.
     Poprzednia wersja liczyła ją z POŁOŻENIA panelu w oknie (`innerHeight - top - odstęp`),
     a położenie zmienia się przy przewijaniu: zmierzone 513 px po otwarciu → **935 px po
     przewinięciu strony o 400 px**, i po powrocie na górę panel **nie wracał**, bo nikt już
     tej liczby nie zmniejszał.

     Wysokość jest teraz **wyłącznie w arkuszu** (`.pickbox { max-height: 60vh }`) — funkcją
     OKNA, nie pozycji. Przeglądarka przelicza ją sama przy zmianie rozmiaru okna i nigdy
     przy przewijaniu, więc panel jest tak samo wysoki niezależnie od tego, gdzie stoi strona.
     ⚠ Usunięcie kodu było tu tańsze niż jego poprawienie: dopóki JavaScript pisze
     `style.maxHeight`, wygrywa z arkuszem i ta usterka może wrócić pierwszą literówką.

     Zostaje jedna decyzja zależna od położenia — **czy panel otwiera się nad czy pod
     przyciskiem** — podejmowana PRZY OTWARCIU i przy zmianie rozmiaru okna. Nigdy przy
     przewijaniu: panel raz otwarty ma stać, a nie przeskakiwać pod kursorem. */
  function ustawStrone(panel) {
    var szczegoly = panel.closest('details');
    var wyzwalacz = szczegoly ? szczegoly.querySelector('summary') : null;
    if (!wyzwalacz || !szczegoly.open) {
      return;
    }
    var r = wyzwalacz.getBoundingClientRect();
    var podSpodem = window.innerHeight - r.bottom - ODSTEP;
    var nadSpodem = r.top - ODSTEP;
    panel.classList.toggle('pickbox--nad', podSpodem < MINIMUM && nadSpodem > podSpodem);
  }

  /* ⚠ „Zaznacz wszystkich" znaczy CAŁY ZASIĘG, a nie „co widać". Przeglądarka wyraża to
     stanem, nie flagą: zaznacza pokazane **i wyrzuca ukryte pola `wyl[]`**, czyli podstawę
     wyłączeń dla osób poza listą. Dzięki temu nie ma znacznika, który mógłby się rozjechać
     ze stanem pól wyboru — a wcześniejsza wersja właśnie na to wymagała dodatkowej reguły
     („ręczne kliknięcie czyści znacznik"). */
  function wyczyscWylaczenia(panel) {
    Array.prototype.slice.call(panel.querySelectorAll('input[name="wyl[]"]'))
      .forEach(function (p) { p.remove(); });
  }

  function odswiezLicznik(panel) {
    var cel = panel.querySelector('[data-pick-licznik]');
    if (!cel) {
      return;
    }
    var wszystkie = pola(panel);
    var zaznaczone = wszystkie.filter(function (p) { return p.checked; }).length;
    cel.textContent = 'Zaznaczono ' + zaznaczone + ' z ' + wszystkie.length;
  }

  function odswiezWszystkie() {
    panele().forEach(function (panel) {
      oznacz(panel);
      ustawStrone(panel);
      odswiezLicznik(panel);
    });
  }

  /* ⚠ **„Zaznacz wszystkich" i „Odznacz pokazanych" tylko PRZESTAWIAJĄ POLA — nie zapisują.**
     Zapisuje „Pokaż", czyli jeden przycisk na jeden stan panelu. Wcześniej każde z tych działań
     wysyłało formularz samo: panel zamykał się po kliknięciu i nie dało się zaznaczyć wszystkich,
     a potem jeszcze kogoś odznaczyć.

     ⚠ „Zaznacz wszystkich" znaczy **cały zasięg**, a pól wyboru na liście bywa mniej (szukanie),
     więc samymi polami nie da się tego wyrazić — stąd ukryte pole `akcja`. Serwer czyta je
     i czyści wyłączenia w całości. */
  document.addEventListener('click', function (event) {
    var target = event.target instanceof Element ? event.target : null;
    var przycisk = target ? target.closest('[data-pick]') : null;
    if (!przycisk) {
      return;
    }
    var panel = przycisk.closest('[data-pickbox]');
    if (!panel) {
      return;
    }
    var wszystkie = przycisk.getAttribute('data-pick') === 'wszystkie';
    pola(panel).forEach(function (p) { p.checked = wszystkie; });
    if (wszystkie) {
      wyczyscWylaczenia(panel);
    }
    odswiezLicznik(panel);
  });

  document.addEventListener('change', function (event) {
    var cel = event.target instanceof Element ? event.target.closest('[data-pickbox]') : null;
    if (cel) {
      odswiezLicznik(cel);
    }
  });

  /* ⚠ **OGNISKO KLAWIATURY W SZUKAJCE.** Szukanie wymienia cały panel (`outerHTML`), więc pole
     tekstowe jest **niszczone i tworzone od nowa** — a wraz z nim ginie ognisko i położenie
     kursora. Zmierzone przed poprawką: po pierwszym przeszukaniu `document.activeElement`
     to `BODY`, więc **drugi `Backspace` nie robił nic** i nie dało się cofnąć wpisanego tekstu.
     To nie była niedogodność, tylko zablokowana funkcja.

     Dlatego pamiętamy wpisaną treść i położenie kursora przy każdym stuknięciu w klawisz,
     a po podmianie przywracamy je razem z ogniskiem. ⚠ Jeśli człowiek dopisał coś w czasie
     żądania, treść z serwera jest starsza niż ta w pamięci — wtedy wygrywa pamięć i **wysyłamy
     zdarzenie `input`**, żeby lista dogoniła to, co faktycznie wpisano. Bez tego ekran pokazywałby
     wynik dla poprzedniego znaku. */
  var pamiecSzukania = null;
  var wymianaSzukajki = false;

  /* ⚠ Znacznik „to żądanie wyszło z SZUKAJKI" stawiamy przed wysłaniem, a nie zgadujemy po
     podmianie. Po wymianie `outerHTML` `event.detail.target` wskazuje **rodzica** usuniętego
     elementu, więc po nim nie da się rozpoznać, co się wymieniło — i właśnie dlatego pierwsza
     wersja przywracania ogniska nie robiła NIC (zmierzone: `activeElement` nadal `BODY`).
     Bez tego znacznika przywracanie musiałoby zgadywać i przenosiłoby kursor do szukajki
     po każdej podmianie na ekranie — także po kliknięciu w nawigację miesięcy. */
  document.addEventListener('htmx:beforeRequest', function (event) {
    var elt = event.detail && event.detail.elt instanceof Element ? event.detail.elt : null;
    wymianaSzukajki = !!elt && !!elt.closest('.pickbox__szukaj');
  });

  document.addEventListener('input', function (event) {
    var pole = event.target instanceof Element ? event.target : null;
    if (!pole || pole.getAttribute('name') !== 'szukaj' || !pole.closest('[data-pickbox]')) {
      return;
    }
    pamiecSzukania = {tekst: pole.value, od: pole.selectionStart, do: pole.selectionEnd};
  });

  function przywrocOgnisko() {
    var pole = document.querySelector('[data-pickbox] input[name="szukaj"]');
    if (!pole || pamiecSzukania === null) {
      return;
    }
    var rozne = pole.value !== pamiecSzukania.tekst;
    pole.value = pamiecSzukania.tekst;
    pole.focus();
    try {
      pole.setSelectionRange(pamiecSzukania.od, pamiecSzukania.do);
    } catch (e) {
      /* Pole bez zaznaczania (stara przeglądarka) — samo ognisko wystarczy. */
    }
    if (rozne) {
      pole.dispatchEvent(new Event('input', {bubbles: true}));
    }
  }

  /* ⚠ `toggle` na `<details>` NIE BĄBELKUJE — stąd nasłuch w fazie przechwytywania. */
  document.addEventListener('toggle', function (event) {
    var cel = event.target instanceof Element ? event.target.querySelector('[data-pickbox]') : null;
    if (cel) {
      oznacz(cel);
      ustawStrone(cel);
      odswiezLicznik(cel);
    }
  }, true);

  /* ⚠ **Nasłuchu `scroll` TU NIE MA I NIE MOŻE BYĆ** (§1 zlecenia z wieczora 06.10).
     To on zamieniał wysokość panelu w funkcję przewinięcia strony. Zmiana rozmiaru okna —
     owszem, bo wtedy zmienia się okno, czyli jedyna rzecz, od której ta wysokość zależy. */
  window.addEventListener('resize', odswiezWszystkie);
  document.addEventListener('htmx:afterSwap', function () {
    odswiezWszystkie();
    /* Ognisko przywracamy WYŁĄCZNIE po wymianie wywołanej szukajką (patrz znacznik wyżej). */
    if (wymianaSzukajki) {
      wymianaSzukajki = false;
      przywrocOgnisko();
    }
  });
  document.addEventListener('DOMContentLoaded', odswiezWszystkie);
})();
