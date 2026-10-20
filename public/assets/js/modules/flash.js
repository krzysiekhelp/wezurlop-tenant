/* Komunikaty o wyniku operacji: toast u dołu ekranu (zlecenie Z47).
   Jedno źródło dla trzech dróg: błędów htmx, komunikatów serwera wyrenderowanych w stronie
   (`[data-komunikat]`) i zdarzenia `wezurlop:komunikat` z innych modułów.
   Treść wyłącznie przez textContent (CLAUDE.md §6) — w tym pliku nie ma ani jednego
   miejsca, w którym tekst z serwera stawałby się HTML-em. */
(function () {
  'use strict';

  var CZAS_MS = 5000;        /* ile komunikat stoi, zanim sam zniknie */
  var ZEJSCIE_MS = 250;      /* tyle trwa przejście przy znikaniu (zgodnie z arkuszem) */
  var MAKS = 3;              /* więcej naraz nie ma po co stać — najstarszy schodzi */

  var MESSAGES = {
    0: 'Brak połączenia z serwerem. Sprawdź internet i spróbuj ponownie.',
    403: 'Nie masz uprawnień do tej operacji.',
    404: 'Nie znaleziono zasobu.',
    419: 'Formularz wygasł. Odśwież stronę i spróbuj ponownie.',
    /* 422 zwraca m.in. zapis adresu w wierszu listy osób: dane są nieprawidłowe, a nie serwer
       zepsuty — bez tego wpisu użytkownik dostaje „kod 422" i nie wie, co poprawić. */
    422: 'Wpisane dane są nieprawidłowe. Popraw je i spróbuj ponownie.',
    500: 'Wystąpił błąd serwera. Spróbuj ponownie za chwilę.'
  };

  var ZNAKI = { blad: '!', sukces: '✓', uwaga: '!', info: 'i' };

  /* ⚠ **Komunikat musi stać NAD oknem modalnym, inaczej go nie ma** (zlecenie Z57 U7).
     Zgłoszenie Krzysztofa brzmiało „po kliknięciu nie ma żadnej informacji, że wysłano" —
     i to była prawda o tym, co widać: okno otwarte przez `showModal()` siedzi w WARSTWIE
     WIERZCHNIEJ razem ze swoim tłem, a pojemnik komunikatów stoi w zwykłym przepływie, więc
     toast rysował się **pod** przygaszeniem okna. Żadna wartość `z-index` tego nie zmienia:
     warstwa wierzchnia jest ponad całym dokumentem.
     Dlatego przed pokazaniem komunikatu przenosimy pojemnik do otwartego okna, a gdy żadne nie
     jest otwarte — z powrotem na koniec `body`. Pojemnik jest `position: fixed`, więc po
     przeniesieniu stoi dokładnie tam, gdzie stał (okno nie jest dla niego blokiem
     zawierającym — nie ma `transform` ani `filter`). */
  /* Czy przeglądarka zna `:modal` (Chrome 105+). Bez niego zostaje stan jak przed zmianą:
     komunikat stoi tam, gdzie stał — nic się nie psuje, po prostu nie poprawia. */
  var ZNA_MODAL = (function () {
    try {
      document.body.matches(':modal');

      return true;
    } catch (e) {
      return false;
    }
  })();

  function kontener() {
    var cel = document.getElementById('toasty');
    if (!cel) {
      return null;
    }
    var okno = null;
    if (ZNA_MODAL) {
      document.querySelectorAll('dialog[open]').forEach(function (dialog) {
        /* `open` mają też okna niemodalne (serwer renderuje je tak dla drogi bez JS) — ale tylko
           modalne zasłaniają dokument, więc tylko one wymagają przeniesienia. */
        if (dialog.matches(':modal')) {
          okno = dialog;
        }
      });
    }
    var rodzic = okno || document.body;
    if (cel.parentNode !== rodzic) {
      rodzic.appendChild(cel);
    }

    return cel;
  }

  function rodzajZnany(rodzaj) {
    /* `error` zostaje przyjmowane ze starych wywołań (`wezurlop:komunikat` z modułu kontrolki
       dostępu), żeby zmiana nazw nie wyciszyła komunikatu po cichu. */
    if (rodzaj === 'error' || rodzaj === 'blad') { return 'blad'; }
    if (rodzaj === 'sukces' || rodzaj === 'ok') { return 'sukces'; }
    if (rodzaj === 'uwaga') { return 'uwaga'; }
    return 'info';
  }

  /* Pierwsze zdanie pogrubione, reszta zwykłym pismem. ⚠ Oba kawałki wstawia `textContent`,
     więc to jest formatowanie, a nie wstrzykiwanie znaczników. */
  function wypelnijTresc(el, tekst) {
    var granica = tekst.indexOf('. ');
    var mocno = granica === -1 ? tekst : tekst.slice(0, granica + 1);
    var reszta = granica === -1 ? '' : tekst.slice(granica + 1);
    var strong = document.createElement('strong');
    strong.textContent = mocno;
    el.appendChild(strong);
    if (reszta !== '') {
      el.appendChild(document.createTextNode(reszta));
    }
  }

  function zdejmij(toast) {
    if (toast.dataset.schodzi === '1') { return; }
    toast.dataset.schodzi = '1';
    window.clearTimeout(toast.odliczanie);
    toast.classList.add('toast--wychodzi');
    window.setTimeout(function () {
      if (toast.parentNode) { toast.parentNode.removeChild(toast); }
    }, ZEJSCIE_MS);
  }

  function odlicz(toast, ile) {
    toast.konczySie = Date.now() + ile;
    window.clearTimeout(toast.odliczanie);
    toast.odliczanie = window.setTimeout(function () { zdejmij(toast); }, ile);
  }

  function wstrzymaj(toast) {
    window.clearTimeout(toast.odliczanie);
    /* Zostaje tyle, ile zostało — najazd kursorem ma ZATRZYMAĆ odliczanie, a nie zacząć je
       od nowa: inaczej przeczytanie długiego komunikatu przedłuża go w nieskończoność. */
    toast.zostalo = Math.max(1000, toast.konczySie - Date.now());
  }

  function wznow(toast) {
    odlicz(toast, toast.zostalo || CZAS_MS);
  }

  function pokaz(tekst, rodzaj) {
    var cel = kontener();
    if (!cel || typeof tekst !== 'string' || tekst.trim() === '') { return null; }
    var typ = rodzajZnany(rodzaj);

    var toast = document.createElement('div');
    toast.className = 'toast toast--' + typ + ' toast--wchodzi';
    /* ⚠ Pojemnik jest obszarem `role="status"` / `aria-live="polite"`, a błąd ma być
       przeczytany od razu — `role="alert"` na samym komunikacie nadpisuje uprzejmość
       pojemnika dla tego poddrzewa. */
    if (typ === 'blad') {
      toast.setAttribute('role', 'alert');
      toast.setAttribute('aria-live', 'assertive');
    }

    var znak = document.createElement('span');
    znak.className = 'toast__znak';
    znak.setAttribute('aria-hidden', 'true');
    znak.textContent = ZNAKI[typ];
    toast.appendChild(znak);

    var tresc = document.createElement('p');
    tresc.className = 'toast__tresc';
    wypelnijTresc(tresc, tekst.trim());
    toast.appendChild(tresc);

    var zamknij = document.createElement('button');
    zamknij.type = 'button';
    zamknij.className = 'toast__zamknij';
    zamknij.setAttribute('aria-label', 'Zamknij');
    zamknij.textContent = '×';
    zamknij.addEventListener('click', function () { zdejmij(toast); });
    toast.appendChild(zamknij);

    /* Najnowszy na dole; powyżej MAKS schodzi najstarszy, czyli ten na górze stosu.
       ⚠ Liczymy komunikaty ŻYWE, a nie dzieci pojemnika: schodzący stoi w drzewie jeszcze
       przez czas przejścia, więc pętla po `children.length` nigdy by się nie skończyła. */
    cel.appendChild(toast);
    var zyjace = Array.prototype.filter.call(cel.children, function (t) {
      return t.dataset.schodzi !== '1';
    });
    for (var i = 0; i + MAKS < zyjace.length; i++) {
      zdejmij(zyjace[i]);
    }

    /* Stan początkowy musi zdążyć się narysować, inaczej nie ma czego animować. */
    window.requestAnimationFrame(function () {
      window.requestAnimationFrame(function () { toast.classList.remove('toast--wchodzi'); });
    });

    toast.addEventListener('mouseenter', function () { wstrzymaj(toast); });
    toast.addEventListener('mouseleave', function () { wznow(toast); });
    toast.addEventListener('focusin', function () { wstrzymaj(toast); });
    toast.addEventListener('focusout', function () { wznow(toast); });
    odlicz(toast, CZAS_MS);

    return toast;
  }

  function messageFor(status) {
    if (MESSAGES[status]) {
      return MESSAGES[status];
    }
    return status >= 500 ? MESSAGES[500] : 'Nie udało się wykonać operacji (kod ' + status + ').';
  }

  /* Wspólny kanał komunikatu dla innych modułów. ⚠ Zdarzenie, nie zmienna globalna: ten plik
     zostaje jedynym miejscem, które zna wygląd komunikatu i czas jego życia. */
  document.addEventListener('wezurlop:komunikat', function (event) {
    var d = event.detail || {};
    if (typeof d.tekst === 'string' && d.tekst !== '') {
      pokaz(d.tekst, d.rodzaj);
    }
  });

  /* ⚠ Serwer może PODAĆ powód odmowy zamiast kodu — i wtedy on wygrywa nad napisem z tablicy
     wyżej. Warunek jest wąski celowo: wyłącznie `text/plain`, wyłącznie 4xx, do 300 znaków.
     Odpowiedzi z fragmentem HTML dalej idą do podmiany treści, a nie na komunikat.
     Bez tego odmowa „ten adres ma już inna osoba" wyglądałaby jak „dane są nieprawidłowe",
     czyli komunikat, po którym nie wiadomo, co poprawić (zlecenie Z40). */
  function powodZOdpowiedzi(xhr) {
    if (!xhr || xhr.status < 400 || xhr.status >= 500) {
      return '';
    }
    var typ = (xhr.getResponseHeader('Content-Type') || '').toLowerCase();
    var tekst = typeof xhr.responseText === 'string' ? xhr.responseText.trim() : '';
    return typ.indexOf('text/plain') === 0 && tekst !== '' && tekst.length <= 300 ? tekst : '';
  }

  /* ——— błąd POLA ———————————————————————————————————————————————————————————————
     ⚠ Wartość zostaje w polu, bo przy odpowiedzi błędnej htmx niczego nie podmienia —
     i to jest cała sztuczka: nie przepisujemy wpisanego tekstu, tylko go nie ruszamy. */
  function wyczyscPole(pole) {
    pole.removeAttribute('aria-invalid');
    var oprawa = pole.closest ? pole.closest('.field') : null;
    if (oprawa) { oprawa.classList.remove('field--error'); }
  }

  /* ⚠ **Pole wskazuje samo ŻĄDANIE, a nie zgadywanie po kształcie ekranu.** Zapis adresu
     w wierszu listy osób wisi na PRZYCISKU (`hx-post`), a pole stoi obok niego i jedzie
     w żądaniu przez `hx-include="closest .pick__adres"` — więc czytamy właśnie ten zakres.
     ⚠ Gdy w zakresie jest więcej niż jedno pole, nie oznaczamy ŻADNEGO: serwer nie powiedział
     którego dotyczy odmowa, a czerwona ramka na niewłaściwym polu jest gorsza niż jej brak
     (wtedy komunikat musi wystarczyć). */
  function poleZadania(elt) {
    if (!elt || !elt.matches) { return null; }
    if (elt.matches('input, select, textarea')) { return elt; }
    var zasieg = null;
    var incl = (elt.getAttribute('hx-include') || '').trim();
    if (incl.indexOf('closest ') === 0) {
      zasieg = elt.closest(incl.slice(8).trim());
    } else if (incl !== '') {
      zasieg = document.querySelector(incl);
    }
    if (zasieg === null) { zasieg = elt; }
    if (zasieg.matches('input, select, textarea')) { return zasieg; }
    var pola = zasieg.querySelectorAll('input:not([type="hidden"]), select, textarea');
    return pola.length === 1 ? pola[0] : null;
  }

  function oznaczPole(elt) {
    var pole = poleZadania(elt);
    if (!pole) { return; }
    pole.setAttribute('aria-invalid', 'true');
    var oprawa = pole.closest('.field');
    if (oprawa) { oprawa.classList.add('field--error'); }
    try { pole.focus({ preventScroll: false }); } catch (e) { pole.focus(); }
    var zdejmij1 = function () { wyczyscPole(pole); };
    pole.addEventListener('input', zdejmij1, { once: true });
    pole.addEventListener('change', zdejmij1, { once: true });
  }

  document.addEventListener('htmx:responseError', function (event) {
    var xhr = event.detail && event.detail.xhr;
    pokaz(powodZOdpowiedzi(xhr) || messageFor(xhr ? xhr.status : 500), 'blad');
    oznaczPole(event.detail && event.detail.elt);
  });

  document.addEventListener('htmx:sendError', function () {
    pokaz(MESSAGES[0], 'blad');
  });

  /* ——— komunikat serwera wyrenderowany w stronie ————————————————————————————————
     ⚠ Serwer pisze komunikat W TREŚCI EKRANU (po przeładowaniu z `?ok=…` albo z odmową
     zapisu), a ten moduł przenosi go do toasta i chowa oryginał. Bez JavaScriptu klasa
     nigdy nie powstaje, więc komunikat zostaje tam, gdzie go postawił serwer — to jest
     ścieżka zapasowa i nie ma drugiej. */
  function przeniesKomunikaty(korzen) {
    var lista = korzen.querySelectorAll ? korzen.querySelectorAll('[data-komunikat]') : [];
    Array.prototype.forEach.call(lista, function (el) {
      if (el.classList.contains('komunikat--w-toascie')) { return; }
      var tekst = (el.textContent || '').replace(/\s+/g, ' ').trim();
      if (tekst === '') { return; }
      el.classList.add('komunikat--w-toascie');
      pokaz(tekst, el.getAttribute('data-komunikat'));
    });
  }

  document.addEventListener('DOMContentLoaded', function () { przeniesKomunikaty(document); });
  document.addEventListener('htmx:afterSwap', function (event) {
    przeniesKomunikaty(event.target || document);
  });

  window.WezurlopFlash = { show: pokaz };
})();
