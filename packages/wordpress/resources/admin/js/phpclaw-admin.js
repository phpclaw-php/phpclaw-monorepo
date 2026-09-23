/* phpClaw Admin JS - settings page toggles + test connection */
/* jshint esversion: 6 */
(function () {
    function readJson(r) {
        var ct = r.headers.get('content-type') || '';
        if (!r.ok || ct.indexOf('application/json') === -1) {
            if (r.status === 403) {
                throw new Error('Your session expired or you were signed out. Reload the page and sign in again.');
            }
            throw new Error('Unexpected server response (HTTP ' + r.status + ').');
        }
        return r.json();
    }

    var data        = window.phpClawAdminData || {};
    var ajaxUrl     = data.ajaxUrl     || '';
    var testNonce   = data.testNonce   || '';

    var provSel = document.querySelector('select[name="phpclaw_settings[provider]"]');
    if (provSel) {
        function toggleProvider() {
            var keyRow    = document.querySelector('#phpclaw_api_key') &&
                            document.querySelector('#phpclaw_api_key').closest('tr');
            var baseRow   = document.querySelector('#phpclaw_base_url') &&
                            document.querySelector('#phpclaw_base_url').closest('tr');
            if (keyRow)    { keyRow.style.display    = provSel.value === 'ollama' ? 'none' : ''; }
            if (baseRow)   { baseRow.style.display   = provSel.value === 'custom' ? '' : 'none'; }
        }
        provSel.addEventListener('change', toggleProvider);
        toggleProvider();
    }

    var testBtn    = document.getElementById('phpclaw-test-conn');
    var testResult = document.getElementById('phpclaw-test-result');
    if (testBtn && testResult) {
        testBtn.addEventListener('click', function () {
            testResult.style.color = '';
            testResult.textContent = 'Testing…';
            var params = new URLSearchParams();
            params.append('action', 'phpclaw_test_connection');
            params.append('nonce',  testNonce);
            fetch(ajaxUrl, {
                method:  'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body:    params.toString(),
            })
            .then(readJson)
            .then(function (json) {
                if (json.success) {
                    testResult.style.color = '#00a32a';
                    testResult.textContent = '✅ Connected. Provider: ' + json.data.provider + ', model: ' + json.data.model;
                } else {
                    testResult.style.color = '#d63638';
                    testResult.textContent = '❌ Error: ' + (json.data && json.data.message ? json.data.message : 'Unknown error');
                }
            })
            .catch(function (err) {
                testResult.style.color = '#d63638';
                testResult.textContent = '❌ Error: ' + err.message;
            });
        });
    }

    var storeMessages = document.getElementById('phpclaw_store_messages');
    var cloudFieldIds = ['phpclaw_cloud_key', 'phpclaw_cloud_signing_secret', 'phpclaw_cloud_disable'];

    if (storeMessages) {
        var cloudRows = cloudFieldIds
            .map(function (id) {
                var field = document.getElementById(id);

                return field ? field.closest('tr') : null;
            })
            .filter(Boolean);

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
