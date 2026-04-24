(function (global) {
  'use strict';

  function show(message, type) {
    if (typeof global.metis_toast === 'function') {
      global.metis_toast(String(message || ''), String(type || 'info'));
      return;
    }
    if (global.console && typeof global.console.log === 'function') {
      global.console.log('[toast:' + String(type || 'info') + '] ' + String(message || ''));
    }
  }

  var service = {
    success: function (message) { show(message, 'success'); },
    warning: function (message) { show(message, 'warning'); },
    info: function (message) { show(message, 'info'); },
    error: function (message) { show(message, 'error'); }
  };

  global.MetisCore = global.MetisCore || {};
  global.MetisCore.toast = global.MetisCore.toast || service;
})(window);
