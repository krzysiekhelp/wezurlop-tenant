/* Kontrolka dostępu do spółek na liście osób (/admin/konta).

   ⚠ Zdarzenia wyłącznie przez addEventListener — zakaz `hx-on:*` i wszystkiego, co htmx
   wykonuje z tekstu (CLAUDE.md §5, §6, §13). Treść panelu dociąga htmx atrybutem `hx-get`
   z przycisku; ten plik zajmuje się tylko otwieraniem, pozycją i podglądem skutku licencyjnego.

   ⚠ Bez JavaScriptu przycisk nic nie robi — i to jest świadome: dostęp bez konta zakłada konto,
   a taka operacja nie ma sensu bez pokazania skutku licencyjnego przed zapisem. Ścieżka bez JS
   prowadzi przez panel osoby (osobny ekran), nie przez listę. */
(function () {
  'use strict';

  var ODSTEP = 4;

  function panel() {
    return document.getElementById('dostep-panel');
  }

  function otwarty() {
    return document.querySelector('[data-dostep][aria-expanded="true"]');
  }

  function ustawPozycje(przycisk) {
    var p = panel();
    if (!p || !przycisk) {
      return;
    }
    var r = przycisk.getBoundingClientRect();
    var szer = p.offsetWidth || 300;
    var wys = p.offsetHeight || 0;
    /* Panel mieści się pod przyciskiem albo — gdy do dołu okna brakuje miejsca — nad nim.
       Bez tego kontrolka w ostatnim wierszu otwierałaby się poza ekranem. */
    var doDolu = window.innerHeight - r.bottom;
    var top = doDolu >= wys + ODSTEP || r.top < wys ? r.bottom + ODSTEP : r.top - wys - ODSTEP;
    var left = Math.min(r.left, Math.max(ODSTEP, window.innerWidth - szer - ODSTEP));
    p.style.top = Math.max(ODSTEP, top) + 'px';
    p.style.left = left + 'px';
  }

  function zamknij() {
    var p = panel();
    var byl = otwarty();
    if (byl) {
      byl.setAttribute('aria-expanded', 'false');
    }
    if (p) {
      p.hidden = true;
    }
  }

  /* Skutek licencyjny PRZED zapisem: ile dostępów zajmie ta zmiana i ile będzie razem.
     Liczby wyjściowe przychodzą z serwera w atrybutach data-* (CLAUDE.md §6) — JS ich nie wymyśla. */
  function odswiezLicencje() {
    var p = panel();
    var form = p ? p.querySelector('.dropdown__form') : null;
    var cel = form ? form.querySelector('[data-licencja-tekst]') : null;
    if (!form || !cel) {
      return;
    }
    var uzyte = parseInt(form.getAttribute('data-licencja-uzyte'), 10);
    var teraz = parseInt(form.getAttribute('data-dostepy-teraz'), 10);
    var limitTekst = form.getAttribute('data-licencja-limit');
    var limit = limitTekst === '' ? null : parseInt(limitTekst, 10);
    if (isNaN(uzyte) || isNaN(teraz)) {
      return;
    }
    var zaznaczone = form.querySelectorAll('input[name="spolki[]"]:checked').length;
    var roznica = zaznaczone - teraz;
    var po = uzyte + roznica;
    var tekst;
    if (roznica === 0) {
      tekst = limit === null ? 'Zajęte dostępy: ' + uzyte + ' (licencja bez limitu).'
        : 'Zajęte dostępy: ' + uzyte + ' z ' + limit + '.';
    } else {
      var kierunek = roznica > 0 ? 'Zajmie ' + roznica + ' więcej' : 'Zwolni ' + (-roznica);
      tekst = limit === null ? kierunek + ': ' + po + ' (licencja bez limitu).'
        : kierunek + ': ' + po + ' z ' + limit + '.';
    }
    /* ⚠ Przekroczenie limitu pokazujemy, ale NIE blokujemy przycisku: decyzję podejmuje serwer
       w transakcji (§5 zlecenia, CLAUDE.md §15). Wyłączony przycisk udawałby zabezpieczenie. */
    if (limit !== null && po > limit) {
      tekst += ' Przekracza limit — serwer odrzuci zapis.';
    }
    cel.textContent = tekst;
  }

  document.addEventListener('click', function (event) {
    var target = event.target instanceof Element ? event.target : null;
    if (!target) {
      return;
    }
    var przycisk = target.closest('[data-dostep]');
    if (przycisk) {
      var bylOtwarty = przycisk.getAttribute('aria-expanded') === 'true';
      zamknij();
      if (!bylOtwarty) {
        przycisk.setAttribute('aria-expanded', 'true');
        var p = panel();
        if (p) {
          /* Widoczny od razu, treść dociąga htmx — pozycję poprawiamy po podmianie,
             gdy znana jest wysokość. */
          p.hidden = false;
          ustawPozycje(przycisk);
        }
      }
      return;
    }
    if (target.closest('[data-dostep-anuluj]')) {
      zamknij();
      return;
    }
    /* Klik poza panelem zamyka — ale klik W panelu (pole wyboru, przycisk) nie. */
    if (!target.closest('#dostep-panel')) {
      zamknij();
    }
  });

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && otwarty()) {
      zamknij();
    }
  });

  document.addEventListener('change', function (event) {
    var target = event.target instanceof Element ? event.target : null;
    if (target && target.closest('#dostep-panel')) {
      odswiezLicencje();
    }
  });

  /* Odmowa zapisu dostępu. ⚠ Serwer przysyła KOD, nie tekst: nazwa spółki to dane użytkownika,
     a polskie znaki w nagłówku HTTP i tak wychodzą jako kropki. Tekst stoi tutaj. */
  var POWODY = {
    brak_etatu: 'Część spółek pominięto: osoba nie ma tam aktywnego zatrudnienia.',
    brak_licencji: 'Część spółek pominięto: brak wolnych licencji.',
    /* Rozdział „administrator tenanta ≠ pracownik" (docs/etap3-logika-urlopowa.md §8c).
       Rozwiązaniem jest osobne konto pracownicze albo przeniesienie administracji —
       nie da się tego obejść z tego ekranu i komunikat ma to mówić. */
    konto_admina: 'Pominięto: to konto jest administratorem tenanta, a administrator nie może mieć dostępu pracowniczego. Założ osobne konto dla tej osoby.',
    odmowa: 'Część spółek pominięto — zapis odrzucony przez serwer.'
  };

  document.addEventListener('htmx:afterOnLoad', function (event) {
    var xhr = event.detail ? event.detail.xhr : null;
    var naglowek = xhr && typeof xhr.getResponseHeader === 'function'
      ? xhr.getResponseHeader('X-Wezurlop-Odmowa') : null;
    if (!naglowek) {
      return;
    }
    var teksty = naglowek.split(',').map(function (kod) {
      return POWODY[kod.trim()] || POWODY.odmowa;
    });
    document.dispatchEvent(new CustomEvent('wezurlop:komunikat', {
      detail: {tekst: teksty.join(' '), rodzaj: 'error'}
    }));
  });

  document.addEventListener('htmx:afterSwap', function (event) {
    var cel = event.detail && event.detail.target ? event.detail.target : null;
    if (cel && cel.id === 'dostep-panel') {
      ustawPozycje(otwarty());
      odswiezLicencje();
      return;
    }
    /* Zapis dostępu podmienia WIERSZ — wtedy panel zamykamy, bo pokazuje stan sprzed zapisu.
       ⚠ Bez warunku na `otwarty()`: przy `hx-swap="outerHTML"` wiersz z klikniętym przyciskiem
       jest już odczepiony od dokumentu, więc `aria-expanded="true"` nie ma gdzie stać i panel
       zostawał otwarty nad świeżo odświeżonym wierszem (zmierzone w przeglądarce). */
    zamknij();
  });

  /* Panel jest pozycjonowany na sztywno względem okna, więc przy przewijaniu musi iść za
     przyciskiem. Zamykanie przy przewijaniu byłoby prostsze, ale gubiłoby zaznaczone pola. */
  window.addEventListener('scroll', function () { ustawPozycje(otwarty()); }, true);
  window.addEventListener('resize', function () { ustawPozycje(otwarty()); });
})();
