(function () {
  'use strict';

  function phpclawReadJson(r) {
    if ((r.headers.get('content-type') || '').indexOf('application/json') !== -1) {
      return r.json();
    }
    throw new Error(r.status === 403
      ? 'Your session expired or you were signed out. Reload the page and sign in again.'
      : 'Unexpected server response (HTTP ' + r.status + '). Reload the page and try again.');
  }

  document.addEventListener('DOMContentLoaded', function () {
    var sel         = document.getElementById('phpclaw-provider-select');
    var keyEl       = document.getElementById('phpclaw-api-key');
    var baseUrlEl   = document.getElementById('phpclaw-base-url');
    var keyWrap     = keyEl     ? keyEl.closest('.js-form-item')     : null;
    var baseUrlWrap = baseUrlEl ? baseUrlEl.closest('.js-form-item') : null;

    function phpclawToggle() {
      if (!sel || !keyWrap || !baseUrlWrap) return;
      var isOllama = sel.value === 'ollama';
      var isCustom = sel.value === 'custom';
      keyWrap.style.display     = isOllama ? 'none' : '';
      baseUrlWrap.style.display = isCustom ? '' : 'none';
    }

    if (sel) {
      sel.addEventListener('change', phpclawToggle);
      phpclawToggle();
    }

    var testBtn    = document.getElementById('phpclaw-test-conn');
    var testResult = document.getElementById('phpclaw-test-result');
    if (testBtn && testResult) {
      testBtn.addEventListener('click', function () {
        testBtn.disabled = true;
        testResult.style.color = '#555';
        testResult.textContent = 'Testing…';
        var csrfToken = (drupalSettings && drupalSettings.phpclaw_settings && drupalSettings.phpclaw_settings.csrf_token) || '';
        fetch('/admin/config/phpclaw/test-connection', {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'X-CSRF-Token': csrfToken },
        })
          .then(phpclawReadJson)
          .then(function (data) {
            if (data.ok) {
              testResult.style.color = '#3c763d';
              testResult.textContent = '✓ Connected · provider: ' + data.provider + ' · model: ' + data.model;
            } else {
              testResult.style.color = '#a94442';
              testResult.textContent = '✗ ' + (data.error || 'Unknown error');
            }
          })
          .catch(function (err) {
            testResult.style.color = '#a94442';
            testResult.textContent = '✗ Request failed: ' + err.message;
          })
          .finally(function () { testBtn.disabled = false; });
      });
    }
  });
})();
