/* Mostek natywny (docs/mostek-natywny.md): window.WezUrlop dostarcza powłoka Fluttera.
   Strona MUSI działać bez mostka — każde wywołanie sprawdza, czy metoda istnieje.
   Mostek nie uwierzytelnia: wynik biometrii nigdy nie jest wysyłany jako dowód do serwera. */
(function () {
  'use strict';

  function bridge() {
    return typeof window.WezUrlop === 'object' && window.WezUrlop !== null ? window.WezUrlop : null;
  }

  function has(method) {
    var b = bridge();
    return b !== null && typeof b[method] === 'function';
  }

  var api = {
    available: function () {
      return bridge() !== null;
    },
    version: function () {
      var b = bridge();
      return b && typeof b.version === 'object' ? b.version : null;
    },
    biometricAvailable: function () {
      return has('biometricAvailable') ? Promise.resolve(bridge().biometricAvailable()) : Promise.resolve(false);
    },
    biometricUnlock: function (reason) {
      return has('biometricUnlock') ? Promise.resolve(bridge().biometricUnlock(String(reason))) : Promise.resolve(false);
    },
    pushToken: function () {
      return has('pushToken') ? Promise.resolve(bridge().pushToken()) : Promise.resolve(null);
    },
    openExternal: function (url) {
      if (has('openExternal')) {
        bridge().openExternal(String(url));
        return;
      }
      window.open(String(url), '_blank', 'noopener');
    },
    haptic: function (kind) {
      if (has('haptic')) {
        bridge().haptic(String(kind));
      }
    }
  };

  window.WezurlopBridge = api;

  // Znacznik dla CSS: elementy wymagające mostka są widoczne tylko w aplikacji.
  if (api.available()) {
    document.documentElement.classList.add('has-native-bridge');
  }
})();
