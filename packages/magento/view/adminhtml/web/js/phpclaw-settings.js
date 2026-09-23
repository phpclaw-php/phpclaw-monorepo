(function () {
  var cfgEl  = document.getElementById('phpclaw-settings-config');
  var CONFIG = cfgEl ? JSON.parse(cfgEl.textContent || '{}') : {};
  var TEST_URL = CONFIG.test_url || '';
  var FORM_KEY = CONFIG.form_key || '';

  var provSel    = document.getElementById('phpclaw-provider');
  var apiRow     = document.getElementById('phpclaw-row-api-key');
  var baseUrlRow = document.getElementById('phpclaw-row-base-url');
  function toggleProvider() {
    if (!provSel) { return; }
    var isOllama = provSel.value === 'ollama';
    var isCustom = provSel.value === 'custom';
    if (apiRow)     { apiRow.style.display     = isOllama ? 'none' : ''; }
    if (baseUrlRow) { baseUrlRow.style.display = isCustom ? '' : 'none'; }
  }
  if (provSel) {
    provSel.addEventListener('change', toggleProvider);
    toggleProvider();
  }

  function phpclawReadJson(r) {
    var ct = r.headers.get('content-type') || '';
    if (!r.ok || ct.indexOf('application/json') === -1) {
      throw new Error('Your session expired or you were signed out. Reload the page and sign in again.');
    }
    return r.json();
  }

  var btn = document.getElementById('phpclaw-test-conn');
  var out = document.getElementById('phpclaw-test-result');
  if (btn && out) {
    btn.addEventListener('click', function () {
      out.className = 'phpclaw-test-result phpclaw-test-result--pending';
      out.textContent = 'Testing…';
      var form = new FormData();
      form.append('form_key', FORM_KEY);
      fetch(TEST_URL, { method: 'POST', body: form, credentials: 'same-origin' })
        .then(phpclawReadJson)
        .then(function (j) {
          if (j.ok) {
            out.className = 'phpclaw-test-result phpclaw-test-result--ok';
            out.textContent = '✅ Connected, provider: ' + j.provider + ', model: ' + j.model;
          } else {
            out.className = 'phpclaw-test-result phpclaw-test-result--err';
            out.textContent = '❌ ' + (j.message || 'Unknown error');
          }
        })
        .catch(function (e) {
          out.className = 'phpclaw-test-result phpclaw-test-result--err';
          out.textContent = '❌ ' + e.message;
        });
    });
  }

  var storeMessages = document.getElementById('phpclaw-store-messages');

  if (storeMessages) {
    var cloudRows = Array.prototype.slice.call(
      document.querySelectorAll('[data-phpclaw-cloud-row]')
    );
    var cloudNotice = document.getElementById('phpclaw-cloud-off-notice');

    var syncCloudRows = function () {
      var on = storeMessages.checked;

      cloudRows.forEach(function (row) {
        row.style.display = on ? '' : 'none';
      });

      if (cloudNotice) {
        cloudNotice.style.display = on ? 'none' : '';
      }
    };

    storeMessages.addEventListener('change', syncCloudRows);
    syncCloudRows();
  }
})();
