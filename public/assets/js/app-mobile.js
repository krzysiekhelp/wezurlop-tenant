/* Punkt wejścia wariantu mobilnego (WebView). */
(function () {
  'use strict';
  document.documentElement.classList.add('js');

  // Linki zewnętrzne (poza hostem tenanta) otwiera przeglądarka systemowa, jeśli jest mostek.
  document.addEventListener('click', function (event) {
    var link = event.target instanceof Element ? event.target.closest('a[href]') : null;
    if (!link || !window.WezurlopBridge || !window.WezurlopBridge.available()) {
      return;
    }
    if (link.host && link.host !== window.location.host) {
      event.preventDefault();
      window.WezurlopBridge.openExternal(link.href);
    }
  });
})();
