/* Wygody na ekranie „Moje konto" (zlecenie Z69, 21.10.2026).

   ⚠ **Ten plik niczego nie waliduje i o niczym nie rozstrzyga.** Numer telefonu sprawdza
   `Support\Telefon` po stronie serwera, hasła — `PasswordResetService`, a kod 2FA —
   `TwoFactorService`. Tu zostaje formatowanie, podpowiedź i kopiowanie. Bez tego pliku cała
   strona działa: okna otwiera serwer przez `?okno=…`, a odmowy wracają pełną stroną.

   ⚠ **Przyciski włączamy, a nie wyłączamy w szablonie.** „Zapisz" przy telefonie i „Gotowe"
   przy kodach startują jako czynne, a skrypt je blokuje, dopóki warunek nie jest spełniony.
   Odwrotna kolejność (`disabled` w HTML) zostawiłaby je martwe na zawsze w przeglądarce
   bez JavaScriptu — czyli zabrałaby funkcję tym, którym miała pomóc.

   ⚠ Zero `innerHTML` i zero budowania znaczników ze stringów (CLAUDE.md §6): wszystko idzie
   przez `textContent`, klasy i atrybuty. */
(function () {
  'use strict';

  var CYFR_TELEFONU = 9;
  var CYFR_KODU = 6;

  /** Same cyfry z tego, co wpisano — ta sama zasada co `Telefon::cyfry()` po stronie serwera. */
  function cyfry(tekst) {
    return (tekst || '').replace(/\D+/g, '');
  }

  /** Format 3-3-3 przy pisaniu; kursor zostaje na końcu, bo pole wypełnia się od lewej. */
  function sformatuj(surowe) {
    var c = cyfry(surowe).slice(0, CYFR_TELEFONU);
    var czesci = [c.slice(0, 3), c.slice(3, 6), c.slice(6, 9)].filter(function (x) { return x !== ''; });
    return czesci.join(' ');
  }

  function telefon() {
    var pole = document.querySelector('[data-telefon]');
    if (!pole) { return; }
    var hint = document.querySelector('[data-telefon-hint]');
    var zapisz = document.querySelector('[data-telefon-zapisz]');
    var opisOk = hint ? hint.getAttribute('data-opis-ok') : null;
    var opisWyjsciowy = hint ? hint.textContent : '';

    function odswiez() {
      var ile = cyfry(pole.value).length;
      var pelny = ile === CYFR_TELEFONU;
      if (zapisz) { zapisz.disabled = !pelny; }
      if (hint && opisOk) {
        hint.textContent = pelny ? opisOk : opisWyjsciowy;
        hint.classList.toggle('field__hint--ok', pelny);
      }
    }

    pole.addEventListener('input', function () {
      var przed = pole.value;
      var po = sformatuj(przed);
      if (po !== przed) { pole.value = po; }
      odswiez();
    });
    odswiez();
  }

  /** Pole kodu: tylko cyfry, przycisk czynny przy sześciu. */
  function kod() {
    var pola = document.querySelectorAll('[data-kod-6]');
    Array.prototype.forEach.call(pola, function (pole) {
      var form = pole.form;
      var zapisz = form ? form.querySelector('[data-kod-zapisz]') : null;

      function odswiez() {
        if (zapisz) { zapisz.disabled = cyfry(pole.value).length !== CYFR_KODU; }
      }

      pole.addEventListener('input', function () {
        var czyste = cyfry(pole.value).slice(0, CYFR_KODU);
        if (czyste !== pole.value) { pole.value = czyste; }
        odswiez();
      });
      odswiez();
    });
  }

  /** Zgodność nowych haseł — podpowiedź, nie wyrok: o polityce rozstrzyga serwer. */
  function haslo() {
    var nowe = document.querySelector('[data-haslo-nowe]');
    var powtorzone = document.querySelector('[data-haslo-powtorzone]');
    var opis = document.querySelector('[data-haslo-zgodnosc]');
    if (!nowe || !powtorzone || !opis) { return; }

    function odswiez() {
      if (powtorzone.value === '') {
        opis.textContent = '';
        opis.classList.remove('field__hint--ok');
        return;
      }
      var zgodne = nowe.value === powtorzone.value;
      opis.textContent = opis.getAttribute(zgodne ? 'data-opis-ok' : 'data-opis-zle') || '';
      opis.classList.toggle('field__hint--ok', zgodne);
    }

    nowe.addEventListener('input', odswiez);
    powtorzone.addEventListener('input', odswiez);
  }

  /** Potwierdzenie kodów zapasowych odblokowuje „Gotowe" — serwer sprawdza to pole i tak. */
  function kody() {
    var zgoda = document.querySelector('[data-kody-potwierdzenie]');
    var gotowe = document.querySelector('[data-kody-gotowe]');
    if (!zgoda || !gotowe) { return; }
    function odswiez() { gotowe.disabled = !zgoda.checked; }
    zgoda.addEventListener('change', odswiez);
    odswiez();
  }

  /**
   * Kopiowanie do schowka.
   *
   * ⚠ Przyciski „Kopiuj" są w szablonie `hidden` i odsłania je TEN plik — bez schowka
   * (stare przeglądarki, strona bez HTTPS) przycisk, który nic nie robi, byłby obietnicą
   * bez pokrycia. Treść bierzemy z `textContent`, nigdy z `innerHTML`.
   */
  function kopiowanie() {
    if (!navigator.clipboard) { return; }
    var przyciski = document.querySelectorAll('[data-kopiuj]');
    Array.prototype.forEach.call(przyciski, function (przycisk) {
      var zrodlo = document.getElementById(przycisk.getAttribute('data-kopiuj'));
      if (!zrodlo) { return; }
      przycisk.hidden = false;
      przycisk.addEventListener('click', function () {
        var tekst = (zrodlo.textContent || '').trim().replace(/\s*\n\s*/g, '\n');
        navigator.clipboard.writeText(tekst).then(function () {
          document.dispatchEvent(new CustomEvent('wezurlop:komunikat', {
            detail: { tekst: 'Skopiowano do schowka.', rodzaj: 'sukces' }
          }));
        });
      });
    });
  }

  function start() {
    telefon();
    kod();
    haslo();
    kody();
    kopiowanie();
  }

  if (document.readyState !== 'loading') { start(); } else {
    document.addEventListener('DOMContentLoaded', start);
  }
  // Fragmenty wymieniane przez htmx (lista urządzeń) nie niosą tych pól, ale okna mogą wrócić
  // z odpowiedzi serwera — po każdej podmianie podłączamy się od nowa.
  document.addEventListener('htmx:afterSwap', start);
})();
