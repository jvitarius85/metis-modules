(function (global) {
  'use strict';

  var service = {
    go: function (url) {
      var target = String(url || '').trim();
      if (!target) return false;
      if (global.Metis && global.Metis.navigation && typeof global.Metis.navigation.go === 'function') {
        global.Metis.navigation.go(target);
        return true;
      }
      global.location.assign(target);
      return true;
    },
    replace: function (url) {
      var target = String(url || '').trim();
      if (!target) return false;
      if (global.Metis && global.Metis.navigation && typeof global.Metis.navigation.replace === 'function') {
        global.Metis.navigation.replace(target);
        return true;
      }
      global.location.replace(target);
      return true;
    }
  };

  global.MetisCore = global.MetisCore || {};
  global.MetisCore.navigation = global.MetisCore.navigation || service;
})(window);
