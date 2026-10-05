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

    var fallbackSel  = document.getElementById('phpclaw-fallback-provider');
    var fallbackData = (window.drupalSettings && drupalSettings.phpclaw_settings && drupalSettings.phpclaw_settings.fallback) || null;
    if (sel && fallbackSel && fallbackData) {
      sel.addEventListener('change', function () {
        var format  = sel.value === '' ? fallbackData.autoFormat : fallbackData.formats[sel.value];
        var current = fallbackSel.value;
        var keep    = false;
        fallbackSel.options.length = 0;
        fallbackSel.add(new Option(fallbackData.offLabel, ''));
        Object.keys(fallbackData.providers).forEach(function (slug) {
          if (fallbackData.formats[slug] === format) {
            fallbackSel.add(new Option(fallbackData.providers[slug], slug));
            keep = keep || slug === current;
          }
        });
        fallbackSel.value = keep ? current : '';
        fallbackSel.dispatchEvent(new Event('change', { bubbles: true }));
      });
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
