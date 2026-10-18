/* Drobne zachowania interfejsu — wszystkie opcjonalne: bez JS strona działa (scenariusz 20). */
(function () {
  'use strict';

  /* ⚠ **JEDNA funkcja zamyka okno — wszystkie trzy drogi prowadzą przez nią** (zlecenie
     10.10.2026, druga tura §2). Powód jest zmierzony dwa razy, za każdym razem inaczej:
     nadzór zmierzył u siebie okno, które zamyka się **bez zdarzenia `close`** (zdjęcie
     atrybutu `open`), więc wszystko, co na tym zdarzeniu wisiało — u nas odświeżenie listy
     pod oknem — po prostu nie działało; a przy trzech osobnych obsługach (`×`, `Escape`,
     kliknięcie w tło) czwarta droga rozjedzie się z pozostałymi tego samego dnia, w którym
     ktoś ją dopisze.

     ⚠ `dialog.close()`, nie `removeAttribute('open')`: tylko metoda wysyła `close`, zdejmuje
     okno z warstwy górnej i oddaje ognisko elementowi, który je otworzył. Zdjęcie atrybutu
     wygląda tak samo na zrzucie ekranu i jest czymś innym dla reszty kodu.
     ⚠ Funkcja jest **wyeksportowana** (`window.WezurlopUi.zamknij`), żeby inne moduły nie
     dorabiały drugiej drogi zamykania — ten sam powód, dla którego `flash.js` eksportuje
     `show`. */
  function zamknij(dialog) {
    if (!dialog) {
      return;
    }
    if (typeof dialog.close === 'function') {
      dialog.close();

      return;
    }
    /* Ścieżka zapasowa dla przeglądarki bez `close()` na `<dialog>` — wtedy i tak nie ma
       `showModal()`, więc okno jest zwykłym pudełkiem i atrybut jest jedynym stanem. */
    dialog.removeAttribute('open');
  }

  window.WezurlopUi = {zamknij: zamknij};

  document.addEventListener('click', function (event) {
    var target = event.target instanceof Element ? event.target : null;
    if (!target) {
      return;
    }

    /* ⚠ Blok obsługi `[data-sidebar-toggle]` USUNIĘTY. Nie była to zepsuta funkcja, tylko
       pozostałość po wycofanym podejściu: przycisk „☰" zniknął z layoutu przy przepisywaniu
       powłoki (ec6859a), reguła `.sidebar-open .sidebar` została skasowana jako martwy CSS
       (74dd963), a ta obsługa została. Szufladę zastąpiło układanie paska nad treścią przy
       `max-width: 1024px`, więc nawigacja jest osiągalna bez przełącznika — i dopóki ten kod
       tu był, następny czytający szukałby szuflady, której nie ma. */

    // Okno modalne (kreator podłączenia). Bez JS przycisk jest zwykłym odnośnikiem do ekranu,
    // więc niczego tu nie „naprawiamy” — dokładamy wygodę.
    var open = target.closest('[data-modal-open]');
    if (open) {
      var dialog = document.getElementById(open.getAttribute('data-modal-open'));
      if (dialog && typeof dialog.showModal === 'function') {
        event.preventDefault();
        dialog.showModal();
        // htmx dociąga treść dopiero teraz — zamknięte okno nie odpytuje serwera.
        // ⚠ Tylko dla kreatora: potwierdzenia mają własne hx-get na klikniętym odnośniku,
        // a wspólne zdarzenie budziłoby odpytywanie kreatora przy każdym innym oknie.
        if (dialog.id === 'kreator-modal') {
          document.body.dispatchEvent(new CustomEvent('kreator:pokaz'));
        }
      }
      return;
    }

    var close = target.closest('[data-modal-close]');
    if (close) {
      var toClose = document.getElementById(close.getAttribute('data-modal-close'));
      if (toClose && toClose.open) {
        // „Anuluj" jest odnośnikiem (bez JS wraca na ekran integracji) — w oknie ma tylko je zamknąć.
        event.preventDefault();
        window.WezurlopUi.zamknij(toClose);
      }
      return;
    }

    // Kopiowanie sekretu pokazywanego raz (docs/system-wizualny.md §5.7).
    // ⚠ Zapasowa ścieżka jest obowiązkowa: bez niej na przeglądarce bez Clipboard API albo przy
    // odmowie uprawnienia przycisk nie robi NIC i wygląda na awarię. Wtedy zaznaczamy treść pola.
    var copy = target.closest('[data-copy]');
    if (copy) {
      var field = document.getElementById(copy.getAttribute('data-copy'));
      var done = document.getElementById(copy.getAttribute('data-copy-done'));
      if (field) {
        var pokaz = function (tekst) {
          if (!done) {
            return;
          }
          done.textContent = tekst;
          done.hidden = false;
          window.setTimeout(function () { done.hidden = true; }, 4000);
        };
        var zaznacz = function () {
          field.focus();
          field.select();
          pokaz('Zaznaczone — skopiuj skrótem klawiszowym');
        };
        if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
          navigator.clipboard.writeText(field.value).then(function () {
            pokaz('Skopiowano');
          }, zaznacz);
        } else {
          zaznacz();
        }
      }
      return;
    }

    var reveal = target.closest('[data-toggle-password]');
    if (reveal) {
      var input = document.getElementById(reveal.getAttribute('data-toggle-password'));
      if (input) {
        var hidden = input.type === 'password';
        input.type = hidden ? 'text' : 'password';
        // ⚠ Stan idzie przez aria-pressed i aria-label, NIE przez zmianę napisu: napis zmieniałby
        // szerokość przycisku, a wcięcie pola jest stałe (docs/system-wizualny.md §4a).
        reveal.setAttribute('aria-pressed', hidden ? 'true' : 'false');
        reveal.setAttribute('aria-label', hidden ? 'Ukryj hasło' : 'Pokaż hasło');
        var ikony = reveal.querySelectorAll('svg');
        if (ikony.length === 2) {
          ikony[0].hidden = hidden;
          ikony[1].hidden = !hidden;
        }
      }
    }
  });

  // Zamknięcie okna czyści jego treść: razem z fragmentem znika element odpytujący serwer,
  // więc po zamknięciu nie leci ani jedno żądanie w tle.
  /* ⚠ Zamknięcie kliknięciem w TŁO. `<dialog>` daje `Escape` i ognisko z pudełka, ale nie
     zamyka się po kliknięciu poza treścią — a tego człowiek próbuje pierwszy. Reguła jest
     wspólna dla wszystkich okien, nie dorobiona przy jednym ekranie: drugie miejsce z tą samą
     logiką rozjechałoby się przy pierwszej poprawce.
     ⚠ Rozstrzyga PROSTOKĄT treści, nie `event.target`: kliknięcie w tło ma `target` równy
     samemu `dialog`, ale to samo dzieje się przy kliknięciu w jego wyściółkę. */
  /* ⚠ **Escape ZAMYKA OKNO, także gdy ognisko siedzi w polu szukania** (zmierzone 10.10.2026
     na oknie grupy). `<input type="search">` z wpisanym tekstem **zjada Escape** na czyszczenie
     pola, więc `<dialog>` nie dostaje klawisza i nie zamyka się wcale. Objaw jest zdradliwy:
     człowiek widzi otwarte okno i stary stan listy pod nim, a wygląda to na usterkę
     odświeżania, nie na niezamknięte okno. Zmierzone: ognisko w szukajce **z tekstem** →
     `dialog.open === true` po Escape i wiersz listy nieruszony przez 4 s; ognisko w szukajce
     pustej albo na oknie → zamyka się i wiersz odświeża się w pierwszym odczycie.

     ⚠ Faza **przechwytywania**, bo inaczej pole zdąży klawisz skonsumować. ⚠ Reguła jest
     wspólna dla wszystkich okien, nie dorobiona przy jednym ekranie: trzy drogi zamknięcia
     (`×`, `Escape`, kliknięcie w tło) mają być równoważne wszędzie, bo ekran, który obiecuje
     trzy, a dowozi dwie, jest gorszy od ekranu z jedną.
     ⚠ Kosztem jest to, że Escape w polu szukania **nie czyści pola** — zamyka okno. Tak jest
     uczciwiej: czyszczenie pola to wygoda przeglądarki, której nikt tu nie obiecywał,
     a zamknięcie okna jest zapisane w umowie ekranu. */
  document.addEventListener('keydown', function (event) {
    if (event.key !== 'Escape') {
      return;
    }
    var cel = event.target instanceof Element ? event.target : null;
    var dialog = cel ? cel.closest('dialog.modal') : null;
    if (!dialog || !dialog.open || typeof dialog.close !== 'function') {
      return;
    }
    event.preventDefault();
    window.WezurlopUi.zamknij(dialog);
  }, true);

  document.querySelectorAll('dialog.modal').forEach(function (dialog) {
    dialog.addEventListener('click', function (event) {
      if (event.target !== dialog) { return; }
      var r = dialog.getBoundingClientRect();
      var poza = event.clientX < r.left || event.clientX > r.right
              || event.clientY < r.top || event.clientY > r.bottom;
      if (poza) { window.WezurlopUi.zamknij(dialog); }
    });
    dialog.addEventListener('close', function () {
      // Kreator doszedł do końca → strona pod oknem pokazuje nieaktualny stan, więc ją przeładowujemy.
      // Bez tego administrator zamyka okno z napisem „Gotowe” i widzi pod spodem „Podłączenie w toku”.
      var done = dialog.querySelector('[data-kreator-gotowe]');
      var body = dialog.querySelector('.modal__body');
      /* ⚠ **`data-modal-trwale` wyłącza sprzątanie treści.** Czyszczenie powstało dla okien, które
         dociągają treść z serwera (kreator, panel osoby): tam treść zostawiona na drugie otwarcie
         byłaby nieaktualna. Okno z treścią OSADZONĄ w stronie (nazwy firm, Z29) wyczyszczone
         nie wróciłoby wcale — drugie otwarcie pokazałoby puste pudełko. */
      if (body && !dialog.hasAttribute('data-modal-trwale')) {
        body.replaceChildren();
      }
      if (done) {
        window.location.reload();
      }
    });
  });

  /* ⚠ **Okno otwarte PRZEZ SERWER podnosimy do warstwy wierzchniej.** `<dialog open>` wyrenderowane
     w HTML jest widoczne, ale NIE jest modalne: nie ma tła, nie zamyka się Escapem i treść pod nim
     zostaje klikalna. Serwer renderuje je otwarte po to, żeby ekran działał bez JavaScriptu
     (`/ustawienia?okno=nazwy`), a tu dokładamy to, czego sam HTML nie potrafi.
     ⚠ `close()` przed `showModal()` jest obowiązkowe: `showModal()` na już otwartym oknie rzuca
     `InvalidStateError`. Zamknięcie zdejmuje `open`, a że okno ma `data-modal-trwale`, obsługa
     wyżej nie wyczyści przy tym jego treści — i to jest powód, dla którego ten blok stoi PO niej. */
  document.querySelectorAll('dialog.modal[open]').forEach(function (dialog) {
    if (typeof dialog.showModal !== 'function') { return; }
    dialog.close();
    dialog.showModal();
  });

  // Przyciski „Pokaż hasło” są w HTML ukryte i pojawiają się dopiero, gdy JS działa.
  document.querySelectorAll('[data-toggle-password]').forEach(function (button) {
    button.hidden = false;
  });
})();
