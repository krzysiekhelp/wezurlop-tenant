/* Pasek komunikatu #flash i obsługa błędów htmx (docs/szkielet-tenanta.md rozdz. 8).
   Bez tego błąd serwera w żądaniu htmx objawia się kliknięciem, które nic nie robi.
   Treść wyłącznie przez textContent (CLAUDE.md §6). */
(function () {
  'use strict';

  var MESSAGES = {
    0: 'Brak połączenia z serwerem. Sprawdź internet i spróbuj ponownie.',
    403: 'Nie masz uprawnień do tej operacji.',
    404: 'Nie znaleziono zasobu.',
    419: 'Formularz wygasł. Odśwież stronę i spróbuj ponownie.',
    /* 422 zwraca zapis adresu w wierszu listy osób: dane są nieprawidłowe, a nie serwer
       zepsuty — bez tego wpisu użytkownik dostaje „kod 422" i nie wie, co poprawić. */
    422: 'Wpisane dane są nieprawidłowe. Popraw je i spróbuj ponownie.',
    500: 'Wystąpił błąd serwera. Spróbuj ponownie za chwilę.'
  };

  function flashElement() {
    return document.getElementById('flash');
  }

  function show(text, kind) {
    var el = flashElement();
    if (!el) {
      return;
    }
    el.textContent = text;
    /* ⚠ Nazwy klas są SKLEJANE tutaj, więc przemianowanie komponentu w CSS nie wystarcza —
       to jedyne miejsce w JS, które wpisuje nazwy klas wyglądu, i przy zmianie `box` na `notice`
       trzeba je zmienić razem. Inaczej pasek komunikatu traci styl po cichu: klasa bez reguły
       nie zgłasza błędu, a na zrzucie ekranu tego nie widać.
       ⚠ Powodzenie NIE ma modyfikatora — zielonego wariantu nie ma (§2, zieleń znaczy akcję). */
    el.className = 'flash notice' + (kind === 'error' ? ' notice--danger' : '');
    el.hidden = false;
    window.clearTimeout(show.timer);
    show.timer = window.setTimeout(function () { el.hidden = true; }, 8000);
  }

  function messageFor(status) {
    if (MESSAGES[status]) {
      return MESSAGES[status];
    }
    return status >= 500 ? MESSAGES[500] : 'Nie udało się wykonać operacji (kod ' + status + ').';
  }

  /* Wspólny kanał komunikatu dla innych modułów. ⚠ Zdarzenie, nie zmienna globalna: `flash.js`
     zostaje jedynym miejscem, które zna nazwy klas paska i czas jego życia. */
  document.addEventListener('wezurlop:komunikat', function (event) {
    var d = event.detail || {};
    if (typeof d.tekst === 'string' && d.tekst !== '') {
      show(d.tekst, d.rodzaj === 'error' ? 'error' : null);
    }
  });

  /* ⚠ Serwer może PODAĆ powód odmowy zamiast kodu — i wtedy on wygrywa nad napisem z tablicy
     wyżej. Warunek jest wąski celowo: wyłącznie `text/plain`, wyłącznie 4xx. Treść wstawia
     `show()` przez `textContent`, więc nie ma tu drogi na HTML (CLAUDE.md §6), a odpowiedzi
     z fragmentem HTML (`text/html`) dalej nie trafiają na pasek — od nich jest podmiana treści.
     Bez tego odmowa „ten adres ma już inna osoba" wyglądałaby jak „dane są nieprawidłowe”,
     czyli komunikat, po którym nie wiadomo, co poprawić (zlecenie Z40). */
  function powodZOdpowiedzi(xhr) {
    if (!xhr || xhr.status < 400 || xhr.status >= 500) {
      return '';
    }
    var typ = (xhr.getResponseHeader('Content-Type') || '').toLowerCase();
    var tekst = typeof xhr.responseText === 'string' ? xhr.responseText.trim() : '';
    return typ.indexOf('text/plain') === 0 && tekst !== '' && tekst.length <= 300 ? tekst : '';
  }

  document.addEventListener('htmx:responseError', function (event) {
    var xhr = event.detail && event.detail.xhr;
    show(powodZOdpowiedzi(xhr) || messageFor(xhr ? xhr.status : 500), 'error');
  });

  document.addEventListener('htmx:sendError', function () {
    show(MESSAGES[0], 'error');
  });

  document.addEventListener('click', function (event) {
    var target = event.target;
    if (target instanceof Element && target.closest('#flash')) {
      flashElement().hidden = true;
    }
  });

  window.WezurlopFlash = { show: show };
})();
