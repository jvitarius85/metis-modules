(function (global) {
  'use strict';

  var service = {
    open: function (id) {
      var el = document.getElementById(id);
      if (!el) return;
      el.style.display = 'block';
      el.setAttribute('aria-hidden', 'false');
    },
    close: function (id) {
      var el = document.getElementById(id);
      if (!el) return;
      el.style.display = 'none';
      el.setAttribute('aria-hidden', 'true');
    }
  };

  global.MetisCore = global.MetisCore || {};
  global.MetisCore.modal = global.MetisCore.modal || service;
})(window);
