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

  document.addEventListener('htmx:responseError', function (event) {
    var xhr = event.detail && event.detail.xhr;
    show(messageFor(xhr ? xhr.status : 500), 'error');
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
