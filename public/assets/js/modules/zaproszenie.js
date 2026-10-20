/* Przycisk zaproszenia w panelu osoby: „Wyślij" albo „Zapisz i wyślij"
   (zlecenie Z64, zgłoszenie Krzysztofa).

   ⚠ **Zgłoszenie:** panel osoby bez konta i z PUSTYM polem e-mail miał czynny przycisk
   „Wyślij" przy wierszu „Wyślij zaproszenie". Nie było czego wysłać — serwer odmawiał,
   a człowiek dowiadywał się tego po kliknięciu. Dziś zaproszenie wymagało trzech kroków:
   wpisać adres › „Zapisz zmiany" › „Wyślij".

   ⚠ **To jest WYGODA, nie zabezpieczenie.** Bez tego pliku przycisk jest czynny zawsze,
   a serwer zachowuje się identycznie: zapisuje adres i wysyła zaproszenie albo odmawia
   z komunikatem przy karcie „Dane konta". Po stronie serwera ten moduł nie zmienia nic.

   ⚠ Stan wczytany czytamy z `defaultValue` (czyli z atrybutu `value` w HTML), nie z własnej
   zmiennej — ta sama zasada co w `modules/zapisz.js`: po podmianie panelu przez htmx nowy
   HTML przynosi nową wartość wczytaną, więc stan liczy się od nowa sam.

   ⚠ Napisy i zdania przychodzą z atrybutów `data-*` w szablonie i wstawiamy je przez
   `textContent` — żadnego budowania HTML-a w skrypcie (CLAUDE.md §6). */
(function () {
  'use strict';

  function poleAdresu() {
    var form = document.getElementById('osoba-dane');
    if (!form || !form.elements) {
      return null;
    }
    var pole = form.elements.namedItem('email');

    return pole && pole.type === 'email' ? pole : null;
  }

  function odswiez() {
    var przycisk = document.querySelector('[data-zapros]');
    var pole = poleAdresu();
    if (!przycisk || !pole) {
      return;
    }
    /* ⚠ Pole bywa wyłączone (konto aktywne — adres jest loginem i zmienia się osobną
       ścieżką). Wtedy nie ma tu nic do policzenia, a wiersza zaproszenia i tak nie ma. */
    if (pole.disabled) {
      return;
    }
    var wpisany = (pole.value || '').trim();
    var zapisany = (pole.defaultValue || '').trim();
    /* Format sprawdza przeglądarka (`type="email"`), nie nasz wyrażenie regularne:
       jedna reguła mniej do rozjechania się z regułą serwera. */
    var poprawny = wpisany !== '' && pole.checkValidity();
    var zmieniony = wpisany !== zapisany;

    przycisk.disabled = !poprawny;
    var napis = przycisk.getAttribute(poprawny && zmieniony ? 'data-napis-zapisz-i-wyslij' : 'data-napis-wyslij');
    if (napis !== null && przycisk.textContent.trim() !== napis) {
      przycisk.textContent = napis;
    }

    var opis = document.querySelector('[data-zapros-opis]');
    if (!opis) {
      return;
    }
    var klucz = 'data-opis-z-adresem';
    if (!poprawny) {
      klucz = 'data-opis-bez-adresu';
    } else if (zmieniony) {
      klucz = 'data-opis-z-zapisem';
    }
    var zdanie = opis.getAttribute(klucz);
    if (zdanie !== null && opis.textContent.trim() !== zdanie) {
      opis.textContent = zdanie;
    }
  }

  document.addEventListener('input', odswiez);
  document.addEventListener('change', odswiez);
  /* Panel przychodzi z serwera po każdym zapisie — stan liczy się wtedy od nowa. */
  document.addEventListener('htmx:afterSwap', odswiez);
  document.addEventListener('DOMContentLoaded', odswiez);
})();
