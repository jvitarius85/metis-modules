(function (global) {
  'use strict';

  var service = {
    serialize: function (form) {
      var data = {};
      if (!form || !form.elements) return data;
      Array.prototype.forEach.call(form.elements, function (input) {
        if (!input.name) return;
        data[input.name] = input.value;
      });
      return data;
    },
    clearErrors: function (form) {
      if (!form) return;
      Array.prototype.forEach.call(form.querySelectorAll('[data-error]'), function (node) {
        node.textContent = '';
      });
    }
  };

  global.MetisCore = global.MetisCore || {};
  global.MetisCore.forms = global.MetisCore.forms || service;
})(window);
