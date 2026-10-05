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
                <small class="text-muted">Optional. Customise the AI's persona and behaviour for your site.</small>
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
                <small class="text-muted">When enabled: prompt and response text is saved, so a conversation remembers previous messages. When disabled: no message content is ever saved; each prompt is processed independently.</small>
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

            {* Fallback provider, rate limit, response cache and token budget *}
            <div class="mb-3 row">
              <label for="phpclaw-fallback-provider" class="col-sm-3 col-form-label">Fallback Provider</label>
              <div class="col-sm-6">
                <select name="fallback_provider" id="phpclaw-fallback-provider" class="form-select">
                  {foreach $phpclaw_fallback_providers as $value => $label}
                  <option value="{$value|escape:'htmlall'}" {if $phpclaw_settings.fallback_provider == $value}selected{/if}>{$label|escape:'htmlall'}</option>
                  {/foreach}
                </select>
                <small class="text-muted">Optional. Used only when the main provider fails with a connection error, a 429 or a 5xx. Lists only providers with the same tool format as the main provider. Off turns fallback off.</small>
              </div>
            </div>

            <div class="mb-3 row">
              <label for="phpclaw-fallback-model" class="col-sm-3 col-form-label">Fallback Model</label>
              <div class="col-sm-6">
                <input type="text" name="fallback_model" id="phpclaw-fallback-model" class="form-control"
                       value="{$phpclaw_settings.fallback_model|escape:'htmlall'}" />
                <small class="text-muted">Leave blank to use the fallback provider's default model.</small>
              </div>
            </div>

            <div class="mb-3 row{if $phpclaw_settings.fallback_provider == 'ollama' || $phpclaw_settings.fallback_provider == ''} phpclaw-hidden{/if}" id="row-fallback-api-key">
              <label for="phpclaw-fallback-api-key" class="col-sm-3 col-form-label">Fallback API Key</label>
              <div class="col-sm-6">
                <input type="password" name="fallback_api_key" id="phpclaw-fallback-api-key" class="form-control"
                       value="{$phpclaw_settings.fallback_api_key|escape:'htmlall'}" autocomplete="new-password" />
                <small class="text-muted">API key for the fallback provider.</small>
              </div>
            </div>

            <div class="mb-3 row">
              <label for="phpclaw-rate-limit-rpm" class="col-sm-3 col-form-label">Rate Limit (requests/min)</label>
              <div class="col-sm-3">
                <input type="number" name="rate_limit_rpm" id="phpclaw-rate-limit-rpm" class="form-control"
                       value="{$phpclaw_settings.rate_limit_rpm|intval}" min="0" max="600" />
                <small class="text-muted">Most calls to the AI provider per minute, shared by every request. 0 turns it off. Maximum 600.</small>
              </div>
            </div>

            <div class="mb-3 row">
              <label for="response_cache" class="col-sm-3 col-form-label">Response Cache</label>
              <div class="col-sm-6">
                <div class="form-check mt-2">
                  <input type="checkbox" name="response_cache" value="1" class="form-check-input"
                         id="response_cache" {if $phpclaw_settings.response_cache}checked{/if} />
                  <label class="form-check-label" for="response_cache">Reuse the answer for an identical request</label>
                </div>
                <small class="text-muted">An identical request within the TTL is answered from the cache instead of calling the provider again.</small>
              </div>
            </div>

            <div class="mb-3 row{if !$phpclaw_settings.response_cache} phpclaw-hidden{/if}" id="row-response-cache-ttl">
              <label for="phpclaw-response-cache-ttl" class="col-sm-3 col-form-label">Response Cache TTL (seconds)</label>
              <div class="col-sm-3">
                <input type="number" name="response_cache_ttl" id="phpclaw-response-cache-ttl" class="form-control"
                       value="{$phpclaw_settings.response_cache_ttl|intval}" min="60" max="86400" />
                <small class="text-muted">How long a cached answer is kept, in seconds: 60 to 86400. Default 3600.</small>
              </div>
            </div>

            <div class="mb-3 row">
              <label for="phpclaw-max-token-budget" class="col-sm-3 col-form-label">Max Token Budget</label>
              <div class="col-sm-3">
                <input type="number" name="max_token_budget" id="phpclaw-max-token-budget" class="form-control"
                       value="{$phpclaw_settings.max_token_budget|intval}" min="0" max="10000000" />
                <small class="text-muted">Stops a run before a provider call would take its token spend over this number. 0 turns it off. Maximum 10000000.</small>
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
                <small class="text-muted">phpClaw Cloud API key. Enables cloud guards and webhook features. Optional.</small>
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
                <small class="text-muted">Comma-separated cloud feature names to turn off, or hide_inputs, hide_outputs and hide_metadata to keep that content on your server while tracing stays on. Leave empty to use every feature in your plan. Only applies when a Cloud Key is set above.</small>
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

  {* Fallback API Key: hidden when fallback is off or Ollama *}
  var fallbackSel = document.getElementById('phpclaw-fallback-provider');
  var fallbackKeyRow = document.getElementById('row-fallback-api-key');
  if (fallbackSel && fallbackKeyRow) {ldelim}
    function toggleFallbackKey() {ldelim}
      fallbackKeyRow.classList.toggle('phpclaw-hidden', fallbackSel.value === '' || fallbackSel.value === 'ollama');
    {rdelim}
    fallbackSel.addEventListener('change', toggleFallbackKey);
    toggleFallbackKey();
  {rdelim}

  {* Response Cache TTL: shown only while Response Cache is ticked *}
  var cacheBox = document.getElementById('response_cache');
  var ttlRow = document.getElementById('row-response-cache-ttl');
  if (cacheBox && ttlRow) {ldelim}
    function toggleCacheTtl() {ldelim}
      ttlRow.classList.toggle('phpclaw-hidden', ! cacheBox.checked);
    {rdelim}
    cacheBox.addEventListener('change', toggleCacheTtl);
    toggleCacheTtl();
  {rdelim}

  {* Fallback Provider: rebuilt from the main provider's tool format whenever it changes *}
  var fallbackData = {$phpclaw_fallback_data|json_encode};
  if (provSel && fallbackSel) {ldelim}
    provSel.addEventListener('change', function () {ldelim}
      var format  = provSel.value === '' ? fallbackData.autoFormat : fallbackData.formats[provSel.value];
      var current = fallbackSel.value;
      var keep    = false;
      fallbackSel.options.length = 0;
      fallbackSel.add(new Option(fallbackData.offLabel, ''));
      Object.keys(fallbackData.providers).forEach(function (slug) {ldelim}
        if (fallbackData.formats[slug] === format) {ldelim}
          fallbackSel.add(new Option(fallbackData.providers[slug], slug));
          keep = keep || slug === current;
        {rdelim}
      {rdelim});
      fallbackSel.value = keep ? current : '';
      fallbackSel.dispatchEvent(new Event('change'));
    {rdelim});
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