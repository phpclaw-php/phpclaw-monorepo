(function () {
    'use strict';

    function bindProviderToggle() {
        var sel    = document.getElementById('phpclaw-provider');
        var rowBase = document.getElementById('row-base-url');
        var rowKey  = document.getElementById('row-api-key');
        if (!sel) { return; }
        function toggle() {
            var val      = sel.value;
            var isOllama = val === 'ollama';
            var isCustom = val === 'custom';
            if (rowBase) { rowBase.classList.toggle('is-hidden', !isCustom); }
            if (rowKey)  { rowKey.style.display  = isOllama ? 'none' : ''; }
        }
        sel.addEventListener('change', toggle);
        toggle();
    }

    function bindCloudToggle() {
        var box = document.getElementById('phpclaw-store-messages');
        var ids = ['row-cloud-key', 'row-cloud-signing-secret', 'row-cloud-disable'];
        var note = document.getElementById('row-cloud-inactive');
        if (!box || !document.getElementById(ids[0])) { return; }
        function toggle() {
            var on = box.checked;
            ids.forEach(function (id) {
                var row = document.getElementById(id);
                if (row) { row.classList.toggle('is-hidden', !on); }
            });
            if (note) { note.classList.toggle('is-hidden', on); }
        }
        box.addEventListener('change', toggle);
        toggle();
    }

    function bindTestConnection() {
        var btn    = document.getElementById('phpclaw-test-conn');
        var result = document.getElementById('phpclaw-test-result');
        if (!btn) { return; }
        var url = btn.getAttribute('data-test-url') || '';
        if (!url) { return; }
        btn.addEventListener('click', function () {
            btn.disabled = true;
            result.style.color = '#555';
            result.textContent = 'Testing…';
            fetch(url, { method: 'POST', credentials: 'same-origin' })
                .then(function (r) {
                    if (!r.ok) { throw new Error('HTTP ' + r.status); }
                    return r.json();
                })
                .then(function (data) {
                    if (data.success) {
                        result.style.color = '#3c763d';
                        result.textContent = '✓ Connected. Provider: ' + data.provider + ', model: ' + data.model;
                    } else {
                        result.style.color = '#a94442';
                        result.textContent = '✗ ' + (data.error || 'Unknown error');
                    }
                })
                .catch(function (err) {
                    result.style.color = '#a94442';
                    result.textContent = '✗ Request failed: ' + err.message;
                })
                .finally(function () { btn.disabled = false; });
        });
    }

    function bindSaveButtonTabToggle() {
        var btn = document.getElementById('btn-save');
        if (!btn) { return; }
        var anchors = document.querySelectorAll('#phpclaw-tabs a[data-toggle="tab"]');
        anchors.forEach(function (a) {
            a.addEventListener('shown.bs.tab', function (e) {
                btn.style.display = e.target.getAttribute('href') === '#tab-settings' ? '' : 'none';
            });
        });
        var active = document.querySelector('#phpclaw-tabs li.active > a');
        if (active && active.getAttribute('href') !== '#tab-settings') {
            btn.style.display = 'none';
        }
    }

    function bindMaxIterGuard() {
        var el = document.getElementById('phpclaw-max-iterations');
        if (!el) { return; }
        el.addEventListener('wheel', function (e) { e.preventDefault(); }, { passive: false });
        el.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowUp' || e.key === 'ArrowDown') { e.preventDefault(); }
        });
    }

    function bindSaveButtonClick() {
        var btn  = document.getElementById('btn-save');
        var form = document.getElementById('form-phpclaw');
        if (!btn || !form) { return; }
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            form.submit();
        });
    }

    function init() {
        bindProviderToggle();
        bindCloudToggle();
        bindMaxIterGuard();
        bindTestConnection();
        bindSaveButtonTabToggle();
        bindSaveButtonClick();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
