/* Świeży token formularza po powrocie do karty (zlecenie Z56 §2).

   ⚠ **Bez tego pliku nic się nie psuje.** Formularz wysłany ze starym tokenem wraca od nowa,
   ze świeżym tokenem i wpisanym adresem — robi to serwer (`Http\OdnowienieFormularza`).
   Ten plik sprawia tylko, że człowiek częściej nie zobaczy nawet tego komunikatu.

   ⚠ **Nic cyklicznego**: zero setInterval. Żądanie idzie po powrocie do karty
   (`visibilitychange`) albo po wejściu w pole formularza — i wyłącznie wtedy, gdy od wczytania
   strony (albo od poprzedniego odświeżenia) minął próg podany przez serwer. Odnawianie w kółko
   dałoby sesje bez końca i ruch z kart porzuconych na tydzień.

   ⚠ **Zero budowania HTML**: podmieniamy WARTOŚĆ pola `_csrf`, nic więcej (CLAUDE.md §6). */
(function () {
  'use strict';

  var znacznik = document.querySelector('[data-token-url][data-token-prog]');
  if (!znacznik) { return; }

  var adres = znacznik.getAttribute('data-token-url');
  var prog = parseInt(znacznik.getAttribute('data-token-prog'), 10);
  if (!adres || !(prog > 0)) { return; }

  /* Od tej chwili liczymy próg. ⚠ Przesuwamy go po KAŻDYM żądaniu, także nieudanym — inaczej
     niedostępny endpoint znaczyłby jedno żądanie na każde przełączenie karty. */
  var odkad = Date.now();
  var wTrakcie = false;

  function czasMinal() {
    return (Date.now() - odkad) / 1000 >= prog;
  }

  function wpisz(token) {
    var pola = document.querySelectorAll('input[name="_csrf"]');
    Array.prototype.forEach.call(pola, function (pole) { pole.value = token; });
  }

  function odswiez() {
    if (wTrakcie || !czasMinal()) { return; }
    wTrakcie = true;
    odkad = Date.now();
    var xhr = new XMLHttpRequest();
    xhr.open('GET', adres, true);
    xhr.setRequestHeader('Accept', 'application/json');
    /* ⚠ Bez niego przeglądarka potrafi oddać odpowiedź z pamięci, czyli token sprzed godziny.
       Serwer wysyła `no-store`, ale nagłówek żądania nic nie kosztuje. */
    xhr.setRequestHeader('Cache-Control', 'no-cache');
    xhr.onload = function () {
      wTrakcie = false;
      if (xhr.status !== 200) { return; }
      var token = null;
      try {
        var dane = JSON.parse(xhr.responseText);
        token = dane && typeof dane.token === 'string' ? dane.token : null;
      } catch (e) {
        token = null;
      }
      if (token) { wpisz(token); }
    };
    xhr.onerror = function () { wTrakcie = false; };
    xhr.send();
  }

  document.addEventListener('visibilitychange', function () {
    if (!document.hidden) { odswiez(); }
  });
  /* Drugie wejście: ktoś wrócił do okna i od razu klika w pole, a karta nigdy nie była ukryta
     (drugi monitor, okno obok). Zdarzenie na dokumencie, nie na każdym polu osobno. */
  document.addEventListener('focusin', function (event) {
    var cel = event.target;
    if (cel && cel.closest && cel.closest('form')) { odswiez(); }
  });
})();
