(function (global) {
  'use strict';

  function request(url, options) {
    return fetch(url, options || {}).then(function (response) {
      return response.json().catch(function () {
        return { status: 'error', message: 'Invalid JSON response.', errors: {} };
      });
    });
  }

  var service = {
    request: request,
    post: function (url, data) {
      var body = data instanceof FormData ? data : new URLSearchParams(data || {});
      return request(url, { method: 'POST', body: body, credentials: 'same-origin' });
    }
  };

  global.MetisCore = global.MetisCore || {};
  global.MetisCore.ajax = global.MetisCore.ajax || service;
})(window);
