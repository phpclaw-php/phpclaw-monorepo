{*
  phpClaw: Settings Page
  PrestaShop 8 Back Office admin template (Bootstrap 4, Smarty)
*}

<div class="page-header d-print-none">
  <div class="container-xl">
    <div class="row g-2 align-items-center">
      <div class="col">
        <h2 class="page-title">phpClaw AI Agent: Settings</h2>
      </div>
    </div>
  </div>
</div>

<div class="container-xl pt-2 pb-4">

  {* Status messages (not-configured warning, update info, errors) are rendered
     natively by PrestaShop core from $this->warnings / informations / errors. *}

  <div class="card">
    <div class="card-body">
      <form class="phpclaw-settings-form" action="{$smarty.server.REQUEST_URI|escape:'htmlall'}" method="post">
            <input type="hidden" name="submitPhpClawSettings" value="1" />

            {* 1. Provider *}
            <div class="mb-3 row">
              <label for="phpclaw-provider" class="col-sm-3 col-form-label">Provider</label>
              <div class="col-sm-6">
                <select name="provider" id="phpclaw-provider" class="form-select">
                  <option value="">Select a provider</option>
                  {foreach $phpclaw_providers as $value => $label}
                  <option value="{$value|escape:'htmlall'}" {if $phpclaw_settings.provider == $value}selected{/if}>{$label|escape:'htmlall'}</option>
                  {/foreach}
                </select>
              </div>
            </div>

            {* 2. Model *}
            <div class="mb-3 row">
              <label for="phpclaw-model" class="col-sm-3 col-form-label">Model</label>
              <div class="col-sm-6">
                <input type="text" name="model" id="phpclaw-model" class="form-control"
                       value="{$phpclaw_settings.model|escape:'htmlall'}"
                       placeholder="e.g. qwen2.5:7b, gpt-4o, claude-opus-4-8" />
              </div>
            </div>

            {* 3. API Key (hidden for Ollama) *}
            <div class="mb-3 row{if $phpclaw_settings.provider == 'ollama'} phpclaw-hidden{/if}" id="row-api-key">
              <label for="phpclaw-api-key" class="col-sm-3 col-form-label">API Key</label>
              <div class="col-sm-6">
                <input type="password" name="api_key" id="phpclaw-api-key" class="form-control"
                       value="{$phpclaw_settings.api_key|escape:'htmlall'}" autocomplete="new-password" />
                <small class="text-muted">Your API key for the selected provider. Leave blank for Ollama (local models).</small>
              </div>
            </div>

            {* 4. Base URL (Custom provider only) *}
            <div class="mb-3 row{if $phpclaw_settings.provider != 'custom'} phpclaw-hidden{/if}" id="row-base-url">
              <label for="phpclaw-base-url" class="col-sm-3 col-form-label">Base URL</label>
              <div class="col-sm-6">
                <input type="text" name="base_url" id="phpclaw-base-url" class="form-control"
                       value="{$phpclaw_settings.base_url|escape:'htmlall'}"
                       placeholder="https://api.example.com/v1/chat/completions" />
                <small class="text-muted">For the Custom provider only. The full OpenAI-compatible /chat/completions endpoint URL. Enter your API key above if the endpoint requires one.</small>
              </div>
            </div>

            {* 5. System Prompt *}
            <div class="mb-3 row">
              <label for="phpclaw-system-prompt" class="col-sm-3 col-form-label">System Prompt</label>
              <div class="col-sm-6">
                <textarea name="system_prompt" id="phpclaw-system-prompt" class="form-control" rows="4"
                          placeholder="e.g. You are a helpful assistant for my PrestaShop store. Always be concise.">{$phpclaw_settings.system_prompt|escape:'htmlall'}</textarea>
                <small class="text-muted">Optional. Customise the AI's persona and behaviour for your store.</small>
              </div>
            </div>

            {* 6. Store Messages *}
            <div class="mb-3 row">
              <label for="store_messages" class="col-sm-3 col-form-label">Store Messages</label>
              <div class="col-sm-6">
                <div class="form-check mt-2">
                  <input type="checkbox" name="store_messages" value="1" class="form-check-input"
                         id="store_messages" {if $phpclaw_settings.store_messages}checked{/if} />
                  <label class="form-check-label" for="store_messages">Save prompt &amp; response text</label>
                </div>
                <small class="text-muted">When enabled: prompt and response text is persisted to your store database, required for multi-turn chat to remember previous messages. When disabled: no message content is ever saved. Each prompt is processed independently with no memory of previous turns.</small>
              </div>
            </div>

            {* 7. Max Iterations *}
            <div class="mb-3 row">
              <label for="phpclaw-max-iterations" class="col-sm-3 col-form-label">Max Iterations</label>
              <div class="col-sm-3">
                <input type="number" name="max_iterations" id="phpclaw-max-iterations" class="form-control"
                       value="{$phpclaw_settings.max_iterations|intval}" min="1" max="50" />
              </div>
            </div>

            {* 8. Remote Skill URLs *}
            <div class="mb-3 row">
              <label for="phpclaw-remote-skill-urls" class="col-sm-3 col-form-label">Remote Skill URLs</label>
              <div class="col-sm-6">
                <textarea name="remote_skill_urls" id="phpclaw-remote-skill-urls" class="form-control" rows="3"
                          placeholder="https://example.com/skills/my-skill.md">{if is_array($phpclaw_settings.remote_skill_urls)}{foreach $phpclaw_settings.remote_skill_urls as $u}{$u|escape:'htmlall'}
{/foreach}{/if}</textarea>
                <small class="text-muted">Optional. One URL per line. Only <code>https://</code> links ending in <code>.md</code> or <code>.json</code> are loaded: each is fetched and registered as an additional skill when the engine builds.</small>
              </div>
            </div>


            {* 9. Cloud Key + Disable Cloud Features *}
            {if $phpclaw_cloud_available}
            <div class="alert alert-info{if $phpclaw_settings.store_messages} phpclaw-hidden{/if}" id="phpclaw-cloud-off-notice">
              Store Messages is off, so cloud tracing and the cloud security scan are inactive and the cloud fields are hidden. Your saved cloud settings are kept. Turn Store Messages on to see them again.
            </div>

            <div class="mb-3 row" id="row-cloud-key">
              <label for="phpclaw-cloud-key" class="col-sm-3 col-form-label">Cloud Key</label>
              <div class="col-sm-6">
                <input type="password" name="cloud_key" id="phpclaw-cloud-key" class="form-control"
                       value="{$phpclaw_settings.cloud_key|escape:'htmlall'}" autocomplete="new-password" placeholder="pgc_..." />
                <small class="text-muted">phpClaw Cloud API key. Enables cloud guards and webhook features.</small>
              </div>
            </div>

            <div class="mb-3 row" id="row-cloud-signing-secret">
              <label for="phpclaw-cloud-signing-secret" class="col-sm-3 col-form-label">Cloud Signing Secret</label>
              <div class="col-sm-6">
                <input type="password" name="cloud_signing_secret" id="phpclaw-cloud-signing-secret" class="form-control"
                       value="{$phpclaw_settings.cloud_signing_secret|escape:'htmlall'}" autocomplete="new-password" />
                <small class="text-muted">Shared secret used to verify signed cloud scan responses. Copy it from your phpClaw Cloud dashboard when you create the API key. Leave empty to skip signature verification.</small>
              </div>
            </div>

            <div class="mb-3 row" id="row-cloud-disable">
              <label for="phpclaw-cloud-disable" class="col-sm-3 col-form-label">Disable Cloud Features</label>
              <div class="col-sm-6">
                <input type="text" name="cloud_disable" id="phpclaw-cloud-disable" class="form-control"
                       value="{if is_array($phpclaw_settings.cloud_disable)}{foreach $phpclaw_settings.cloud_disable as $cd}{$cd|escape:'htmlall'}{if !$cd@last}, {/if}{/foreach}{else}{$phpclaw_settings.cloud_disable|escape:'htmlall'}{/if}" placeholder="e.g. webhooks,analytics" />
                <small class="text-muted">Only applies when a Cloud Key is set above.</small>
              </div>
            </div>
            {/if}

            {* 10. REST API tokens, issued per employee *}
            {if $phpclaw_can_manage_tokens}
            <div class="mb-3 row">
              <label class="col-sm-3 col-form-label">REST API Tokens</label>
              <div class="col-sm-9">
                <small class="text-muted d-block mb-2">
                  A token acts as the employee it is issued for and carries exactly that employee's permissions.
                  It is shown once here and stored hashed. Send it as <code>Authorization: Bearer &lt;token&gt;</code>.
                </small>

                <table class="table table-sm" id="phpclaw-token-table">
                  <thead>
                    <tr><th>Label</th><th>Employee</th><th>Created</th><th>Last used</th><th></th></tr>
                  </thead>
                  <tbody>
                    {foreach $phpclaw_api_tokens as $tok}
                    <tr data-token-row="{$tok.id|intval}">
                      <td>{if $tok.label}{$tok.label|escape:'htmlall'}{else}(no label){/if}</td>
                      <td>{$tok.employee|escape:'htmlall'}</td>
                      <td>{$tok.created_at|escape:'htmlall'}</td>
                      <td>{if $tok.last_used_at}{$tok.last_used_at|escape:'htmlall'}{else}never{/if}</td>
                      <td><button type="button" class="btn btn-sm btn-outline-danger phpclaw-revoke-token" data-id="{$tok.id|intval}">Revoke</button></td>
                    </tr>
                    {foreachelse}
                    <tr><td colspan="5" class="text-muted">No tokens issued.</td></tr>
                    {/foreach}
                  </tbody>
                </table>

                <div class="form-inline">
                  <select id="phpclaw-token-employee" class="form-control mr-2">
                    {foreach $phpclaw_employees as $emp}
                    <option value="{$emp.id|intval}">{$emp.email|escape:'htmlall'}</option>
                    {/foreach}
                  </select>
                  <input type="text" id="phpclaw-token-label" class="form-control mr-2" placeholder="Label, e.g. stock sync" maxlength="64" />
                  <button type="button" class="btn btn-secondary" id="phpclaw-issue-token">Issue token</button>
                </div>

                <div class="alert alert-success mt-2" id="phpclaw-token-result" hidden>
                  Copy this token now, it is not shown again:
                  <code id="phpclaw-token-value"></code>
                </div>
              </div>
            </div>
            {/if}

            <div class="row">
              <div class="col-sm-9 offset-sm-3">
                <button type="submit" class="btn btn-primary">
                  <i class="icon-save"></i> Save Settings
                </button>
                <button type="button" class="btn btn-outline-secondary ms-2" id="phpclaw-test-conn">
                  <i class="icon-plug"></i> Test Connection
                </button>
                <span id="phpclaw-test-result" class="ms-2 phpclaw-test-result"></span>
              </div>
            </div>

      </form>
    </div>{* /card-body *}
  </div>{* /card *}

</div>{* /container-xl *}

{* Community Card *}
<div class="container-xl">
  {include file="./_community_card.tpl"}
</div>

<script>
(function () {ldelim}
  {* Provider-based field toggle: hide API Key for Ollama, show Base URL only for Custom *}
  var provSel = document.querySelector('select[name="provider"]');
  if (provSel) {ldelim}
    function toggleProvider() {ldelim}
      var keyRow  = document.getElementById('row-api-key');
      var baseRow = document.getElementById('row-base-url');
      if (keyRow)  {ldelim} keyRow.classList.toggle('phpclaw-hidden', provSel.value === 'ollama'); {rdelim}
      if (baseRow) {ldelim} baseRow.classList.toggle('phpclaw-hidden', provSel.value !== 'custom'); {rdelim}
    {rdelim}
    provSel.addEventListener('change', toggleProvider);
    toggleProvider();
  {rdelim}

  {* Cloud fields follow Store Messages: hidden when off, values always kept *}
  var storeMessages = document.getElementById('store_messages');
  if (storeMessages) {ldelim}
    var cloudRows = ['row-cloud-key', 'row-cloud-signing-secret', 'row-cloud-disable']
      .map(function (id) {ldelim} return document.getElementById(id); {rdelim})
      .filter(Boolean);
    var cloudNotice = document.getElementById('phpclaw-cloud-off-notice');
    function syncCloudRows() {ldelim}
      var on = storeMessages.checked;
      cloudRows.forEach(function (row) {ldelim} row.classList.toggle('phpclaw-hidden', ! on); {rdelim});
      if (cloudNotice) {ldelim} cloudNotice.classList.toggle('phpclaw-hidden', on); {rdelim}
    {rdelim}
    storeMessages.addEventListener('change', syncCloudRows);
    syncCloudRows();
  {rdelim}

  {* Test Connection *}
  var testBtn    = document.getElementById('phpclaw-test-conn');
  var testResult = document.getElementById('phpclaw-test-result');
  var testUrl    = {$url_test_connection|json_encode};
  if (testBtn) {ldelim}
    testBtn.addEventListener('click', function () {ldelim}
      testResult.style.color = '';
      testResult.textContent = 'Testing\u2026';
      fetch(testUrl, {ldelim} method: 'POST' {rdelim})
        .then(function (r) {ldelim} return r.text(); {rdelim})
        .then(function (t) {ldelim}
          try {ldelim} return JSON.parse(t); {rdelim} catch (e) {ldelim} throw new Error('Session expired or invalid response, reload the page and try again.'); {rdelim}
        {rdelim})
        .then(function (data) {ldelim}
          if (data.success) {ldelim}
            testResult.style.color = '#00a32a';
            testResult.textContent = '\u2705 Connected, provider: ' + data.provider + ', model: ' + data.model;
          {rdelim} else {ldelim}
            testResult.style.color = '#d63638';
            testResult.textContent = '\u274c Error: ' + (data.error || 'Unknown error');
          {rdelim}
        {rdelim})
        .catch(function (err) {ldelim}
          testResult.style.color = '#d63638';
          testResult.textContent = '\u274c Error: ' + err.message;
        {rdelim});
    {rdelim});
  {rdelim}

  var issueBtn = document.getElementById('phpclaw-issue-token');
  if (issueBtn) {ldelim}
    issueBtn.addEventListener('click', function () {ldelim}
      var body = new URLSearchParams();
      body.append('id_employee', document.getElementById('phpclaw-token-employee').value);
      body.append('label', document.getElementById('phpclaw-token-label').value);
      fetch({$url_issue_token|json_encode nofilter}, {ldelim} method: 'POST', body: body {rdelim})
        .then(function (r) {ldelim} return r.json(); {rdelim})
        .then(function (data) {ldelim}
          if (!data.success) {ldelim} window.alert(data.error || 'Could not issue the token.'); return; {rdelim}
          document.getElementById('phpclaw-token-value').textContent = data.token;
          document.getElementById('phpclaw-token-result').hidden = false;
        {rdelim})
        .catch(function () {ldelim} window.alert('Could not issue the token.'); {rdelim});
    {rdelim});
  {rdelim}

  Array.prototype.forEach.call(document.querySelectorAll('.phpclaw-revoke-token'), function (btn) {ldelim}
    btn.addEventListener('click', function () {ldelim}
      var body = new URLSearchParams();
      body.append('id_api_token', btn.getAttribute('data-id'));
      fetch({$url_revoke_token|json_encode nofilter}, {ldelim} method: 'POST', body: body {rdelim})
        .then(function (r) {ldelim} return r.json(); {rdelim})
        .then(function (data) {ldelim}
          if (!data.success) {ldelim} window.alert(data.error || 'Could not revoke the token.'); return; {rdelim}
          var row = document.querySelector('[data-token-row="' + btn.getAttribute('data-id') + '"]');
          if (row) {ldelim} row.remove(); {rdelim}
        {rdelim})
        .catch(function () {ldelim} window.alert('Could not revoke the token.'); {rdelim});
    {rdelim});
  {rdelim});
{rdelim})();
</script>