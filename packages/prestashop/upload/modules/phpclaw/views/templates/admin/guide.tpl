{*
  phpClaw: Guide Page
  PrestaShop 8 + 9 Back Office admin template (Bootstrap 4/5, Smarty)
*}

<div class="page-header d-print-none">
  <div class="container-xl">
    <div class="row g-2 align-items-center">
      <div class="col">
        <h2 class="page-title">phpClaw AI Agent: Guide</h2>
      </div>
    </div>
  </div>
</div>

<div class="container-xl py-4">

  {* Tab navigation *}
  <div class="phpclaw-guide-tabs">
    <button class="phpclaw-guide-tab active"  data-tab="quickstart">Quick Start</button>
    <button class="phpclaw-guide-tab"         data-tab="tools">Tools</button>
    <button class="phpclaw-guide-tab"         data-tab="providers">Providers</button>
    <button class="phpclaw-guide-tab"         data-tab="memory">Memory</button>
    <button class="phpclaw-guide-tab"         data-tab="guards">Guards</button>
    <button class="phpclaw-guide-tab"         data-tab="hooks">Hooks</button>
    <button class="phpclaw-guide-tab"         data-tab="skills">Skills</button>
    <button class="phpclaw-guide-tab"         data-tab="rest">REST API</button>
    <button class="phpclaw-guide-tab"         data-tab="cli">CLI</button>
    <button class="phpclaw-guide-tab"         data-tab="privacy">Privacy</button>
  </div>

  {* Quick Start Tab *}
  <div class="phpclaw-guide-panel active" id="panel-quickstart">
    <div class="card mb-4">
      <div class="card-body">
        <h3 class="card-title mt-0">Quickstart</h3>
        <p class="text-muted mb-3 phpclaw-guide-intro">Get up and running in under 5 minutes.</p>
        <ol class="phpclaw-guide-steps">
          <li>
            <strong>Choose a provider</strong> <span class="text-muted">(Administrator only)</span>
            <p class="text-muted">Go to {if $can_manage_all}<a href="{$url_settings|escape:'htmlall'}">Settings</a>{else}Settings{/if} and select Anthropic, OpenAI, Groq, Gemini, Mistral, DeepSeek, Ollama (local), or Custom (any OpenAI-compatible endpoint).</p>
          </li>
          <li>
            <strong>Enter your API key</strong> <span class="text-muted">(Administrator only)</span>
            <p class="text-muted">Paste the API key from your provider dashboard. No key needed for Ollama.</p>
          </li>
          <li>
            <strong>Save and test</strong> <span class="text-muted">(Administrator only)</span>
            <p class="text-muted">Click <em>Save Settings</em>, then <em>Test Connection</em> to verify the key works.</p>
          </li>
          <li>
            <strong>Open Chat</strong>
            <p class="text-muted">Go to <a href="{$url_debug|escape:'htmlall'}">Chat</a> and send your first message.</p>
          </li>
        </ol>
      </div>
    </div>

    <div class="card mb-4">
      <div class="card-body">
        <h3 class="card-title mt-0">First prompts to try</h3>
        <table class="table table-bordered mb-0 phpclaw-guide-fs">
          <thead class="table-light">
            <tr><th class="phpclaw-col-340">Prompt</th><th>What it does</th></tr>
          </thead>
          <tbody>
            <tr><td><code class="phpclaw-about-pkg-code">How many orders were placed this week?</code></td><td class="text-muted">Queries your orders with the Orders tool.</td></tr>
            <tr><td><code class="phpclaw-about-pkg-code">Which products are low on stock?</code></td><td class="text-muted">Checks stock levels with the Stock tool.</td></tr>
            <tr><td><code class="phpclaw-about-pkg-code">Show me the last 10 lines of the error log</code></td><td class="text-muted">Reads your PrestaShop log with the Log tool.</td></tr>
            <tr><td><code class="phpclaw-about-pkg-code">How many active customers do we have?</code></td><td class="text-muted">Queries customers with the Customers tool.</td></tr>
            <tr><td><code class="phpclaw-about-pkg-code">What is my revenue this month?</code></td><td class="text-muted">Builds a revenue report with the Report tool.</td></tr>
            <tr><td><code class="phpclaw-about-pkg-code">List installed modules</code></td><td class="text-muted">Lists modules with the Modules tool.</td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card">
      <div class="card-body">
        <h3 class="card-title mt-0">Common tasks</h3>
        <table class="table table-bordered mb-0 phpclaw-guide-fs">
          <thead class="table-light">
            <tr><th class="phpclaw-col-260">Task</th><th>How</th></tr>
          </thead>
          <tbody>
            <tr><td>Automate from the command line</td><td class="text-muted">Use <code class="phpclaw-about-pkg-code">php modules/phpclaw/cli/phpclaw.php send "your prompt"</code>. See the CLI tab.</td></tr>
            <tr><td>Call phpClaw from an external app</td><td class="text-muted">POST to the REST endpoint with a Bearer token. See the REST API tab.</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  {* Tools Tab *}
  <div class="phpclaw-guide-panel" id="panel-tools">
    <div class="card mb-4">
      <div class="card-body">
        <h3 class="card-title mt-0">Available Tools</h3>
        <p class="text-muted mb-3 phpclaw-guide-intro">
          The AI agent automatically picks the right tool based on your prompt. Most tools are read-only; the Shell, File Write, and File Edit tools can run allowlisted commands or modify files, scoped to the configured workspace.
        </p>
        <h4 class="phpclaw-guide-h4">PrestaShop Tools</h4>
        <table class="table table-bordered mb-0 phpclaw-guide-fs">
          <thead class="table-light">
            <tr><th class="phpclaw-col-150">Name</th><th>Description</th><th class="phpclaw-col-270">Example Prompts</th></tr>
          </thead>
          <tbody>
            {foreach $tools as $t}
            <tr>
              <td><strong>{$t.name|escape:'htmlall'}</strong></td>
              <td class="text-muted">{$t.description|escape:'htmlall'}</td>
              <td class="text-muted">{$t.examples|escape:'htmlall'}</td>
            </tr>
            {/foreach}
          </tbody>
        </table>
      </div>
    </div>

    <div class="card">
      <div class="card-body">
        <h3 class="card-title mt-0">Core Utility Tools <span class="text-muted phpclaw-guide-intro fw-normal">: ship with <code>phpclaw/phpclaw</code>, no extra install</span></h3>
        <table class="table table-bordered mb-0 phpclaw-guide-fs">
          <thead class="table-light">
            <tr><th class="phpclaw-col-150">Tool</th><th class="phpclaw-col-120">Status</th><th>Notes</th></tr>
          </thead>
          <tbody>
            {foreach $core_tools as $t}
            <tr>
              <td><code class="phpclaw-about-pkg-code">{$t.tool|escape:'htmlall'}</code></td>
              <td><span class="text-success">&#10004; {$t.status|escape:'htmlall'}</span></td>
              <td class="text-muted">{$t.notes|escape:'htmlall'}</td>
            </tr>
            {/foreach}
          </tbody>
        </table>
      </div>
    </div>
  </div>

  {* Providers Tab *}
  <div class="phpclaw-guide-panel" id="panel-providers">
    <div class="card">
      <div class="card-body">
        <h3 class="card-title mt-0">AI Providers</h3>
        <p class="text-muted mb-3 phpclaw-guide-intro">
          phpClaw supports multiple AI providers. Select one in {if $can_manage_all}<a href="{$url_settings|escape:'htmlall'}">Settings</a>{else}Settings{/if} and enter your API key. Switch providers at any time, zero code changes required.
        </p>
        <table class="table table-bordered mb-0 phpclaw-guide-fs">
          <thead class="table-light">
            <tr>
              <th class="phpclaw-col-150">Provider</th>
              <th class="phpclaw-col-240">Popular Models</th>
              <th class="phpclaw-col-110">Sign Up</th>
              <th>Notes</th>
            </tr>
          </thead>
          <tbody>
            {foreach $providers as $p}
            <tr>
              <td><strong>{$p.name|escape:'htmlall'}</strong></td>
              <td><code class="phpclaw-about-pkg-code">{$p.models|escape:'htmlall'}</code></td>
              <td>
                {if $p.signup neq ''}
                <a href="{$p.signup|escape:'htmlall'}" target="_blank" rel="noopener">Get API key &rarr;</a>
                {else}
                -
                {/if}
              </td>
              <td>{$p.notes|escape:'htmlall'}</td>
            </tr>
            {/foreach}
          </tbody>
        </table>
        <div class="alert alert-info mt-3 phpclaw-alert-fs">
          <strong>Tip:</strong> After entering your API key, click "Test Connection" on the Settings page to verify it works before sending real prompts.
        </div>
      </div>
    </div>
  </div>

  {* Memory Tab *}
  <div class="phpclaw-guide-panel" id="panel-memory">
    <div class="card mb-4">
      <div class="card-body">
        <h3 class="card-title mt-0">Memory</h3>
        <p class="text-muted mb-3 phpclaw-guide-intro">
          Memory drivers store conversations and key-value state.
        </p>
        <h4 class="phpclaw-guide-h4">Auto-Discovered Core Memory Drivers</h4>
        {if $memory_drivers|count > 0}
        <table class="table table-bordered mb-0 phpclaw-guide-fs">
          <thead class="table-light">
            <tr>
              <th class="phpclaw-col-140">Driver</th>
              <th class="phpclaw-col-200">Label</th>
              <th class="phpclaw-col-100">Source</th>
              <th>Class</th>
            </tr>
          </thead>
          <tbody>
            {foreach $memory_drivers as $driver}
            <tr>
              <td><code class="phpclaw-about-pkg-code">{$driver.driver|escape:'htmlall'}</code></td>
              <td class="text-muted">{$driver.label|escape:'htmlall'}</td>
              <td>{$driver.source|escape:'htmlall'}</td>
              <td><small><code>{$driver.class|escape:'htmlall'}</code></small></td>
            </tr>
            {/foreach}
          </tbody>
        </table>
        {else}
        <p class="text-muted phpclaw-guide-intro">No core memory drivers discovered.</p>
        {/if}
      </div>
    </div>

    <div class="card mb-4">
      <div class="card-body">
        <h3 class="card-title mt-0">Database Tables</h3>
        <p class="text-muted mb-3 phpclaw-guide-intro">
          Three dedicated tables created automatically on install:
        </p>
        <table class="table table-bordered mb-0 phpclaw-guide-fs">
          <thead class="table-light">
            <tr>
              <th class="phpclaw-col-260">Table</th>
              <th>Purpose</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td><code>{$db_prefix|escape:'htmlall'}phpclaw_conversations</code></td>
              <td class="text-muted">Conversation metadata: ID, title, created/updated timestamps.</td>
            </tr>
            <tr>
              <td><code>{$db_prefix|escape:'htmlall'}phpclaw_messages</code></td>
              <td class="text-muted">Full message history: every user and assistant turn per conversation.</td>
            </tr>
            <tr>
              <td><code>{$db_prefix|escape:'htmlall'}phpclaw_memory</code></td>
              <td class="text-muted">Key-value store: agent state, cached data, custom namespaces.</td>
            </tr>
          </tbody>
        </table>
        <div class="alert alert-info mt-3 mb-0 phpclaw-alert-fs">
          <strong>Backup tip:</strong> Include these three tables in your database backups to preserve all chat history.
        </div>
      </div>
    </div>
  </div>

  {* Guards Tab *}
  <div class="phpclaw-guide-panel" id="panel-guards">
    <div class="card">
      <div class="card-body">
        <h3 class="card-title mt-0">Guards</h3>
        <p class="text-muted mb-3 phpclaw-guide-intro">
          Guards scan every user message before it reaches the LLM.
        </p>
        <h4 class="phpclaw-guide-h4">Discovered Guards</h4>
        {if $guards|count > 0}
        <table class="table table-bordered mb-0 phpclaw-guide-fs">
          <thead class="table-light">
            <tr>
              <th class="phpclaw-col-160">Name</th>
              <th class="phpclaw-col-200">Label</th>
              <th class="phpclaw-col-80">Priority</th>
              <th class="phpclaw-col-80">Default</th>
              <th class="phpclaw-col-100">Source</th>
              <th>Class</th>
            </tr>
          </thead>
          <tbody>
            {foreach $guards as $guard}
            <tr>
              <td><code class="phpclaw-about-pkg-code">{$guard.name|escape:'htmlall'}</code></td>
              <td class="text-muted">{$guard.label|escape:'htmlall'}</td>
              <td>{$guard.priority|intval}</td>
              <td>{if $guard.enabled_by_default}&#10004;{else}&#10008;{/if}</td>
              <td>{$guard.source|escape:'htmlall'}</td>
              <td><small><code>{$guard.class|escape:'htmlall'}</code></small></td>
            </tr>
            {/foreach}
          </tbody>
        </table>
        {else}
        <p class="text-muted phpclaw-guide-intro">No guards discovered.</p>
        {/if}
      </div>
    </div>
  </div>

  {* Hooks Tab *}
  <div class="phpclaw-guide-panel" id="panel-hooks">
    <div class="card">
      <div class="card-body">
        <h3 class="card-title mt-0">Hooks</h3>
        <p class="text-muted mb-3 phpclaw-guide-intro">
          Below are the registered listener classes for this adapter.
        </p>
        <h4 class="phpclaw-guide-h4">Registered Hook Listeners</h4>
        {if $hooks|count > 0}
        <table class="table table-bordered mb-0 phpclaw-guide-fs">
          <thead class="table-light">
            <tr>
              <th class="phpclaw-col-160">Event</th>
              <th class="phpclaw-col-160">Name</th>
              <th class="phpclaw-col-80">Priority</th>
              <th class="phpclaw-col-80">Default</th>
              <th class="phpclaw-col-100">Source</th>
              <th>Class</th>
            </tr>
          </thead>
          <tbody>
            {foreach $hooks as $hook}
            <tr>
              <td><code class="phpclaw-about-pkg-code">{$hook.event|escape:'htmlall'}</code></td>
              <td class="text-muted">{$hook.name|escape:'htmlall'}</td>
              <td>{$hook.priority|intval}</td>
              <td>{if $hook.enabled_by_default}&#10004;{else}&#10008;{/if}</td>
              <td>{$hook.source|escape:'htmlall'}</td>
              <td><small><code>{$hook.class|escape:'htmlall'}</code></small></td>
            </tr>
            {/foreach}
          </tbody>
        </table>
        {else}
        <p class="text-muted phpclaw-guide-intro">No hook listeners discovered.</p>
        {/if}
      </div>
    </div>
  </div>

  {* Skills Tab *}
  <div class="phpclaw-guide-panel" id="panel-skills">
    <div class="card">
      <div class="card-body">
        <h3 class="card-title mt-0">Skills</h3>
        <p class="text-muted mb-3 phpclaw-guide-intro">
          Skills inject curated context snippets into the prompt when their keywords match the user message. Discovered skills are always active, no configuration needed.
        </p>
        <h4 class="phpclaw-guide-h4">Discovered Skills</h4>
        {if $skills|count > 0}
        <table class="table table-bordered mb-0 phpclaw-guide-fs">
          <thead class="table-light">
            <tr>
              <th class="phpclaw-col-160">Name</th>
              <th class="phpclaw-col-200">Label</th>
              <th>Keywords</th>
              <th class="phpclaw-col-100">Source</th>
              <th>Class</th>
            </tr>
          </thead>
          <tbody>
            {foreach $skills as $skill}
            <tr>
              <td><code class="phpclaw-about-pkg-code">{$skill.name|escape:'htmlall'}</code></td>
              <td class="text-muted">{$skill.label|escape:'htmlall'}</td>
              <td><small>{foreach $skill.keywords as $kw}{$kw|escape:'htmlall'}{if !$kw@last}, {/if}{/foreach}</small></td>
              <td>{$skill.source|escape:'htmlall'}</td>
              <td><small><code>{$skill.class|escape:'htmlall'}</code></small></td>
            </tr>
            {/foreach}
          </tbody>
        </table>
        {else}
        <p class="text-muted phpclaw-guide-intro">No skills discovered.</p>
        {/if}

        <h4 class="phpclaw-guide-h4">Remote Skills</h4>
        {if $remote_skill_urls|count == 0}
        <p class="text-muted phpclaw-guide-intro">No remote skill URLs configured.</p>
        {elseif $remote_skills|count == 0}
        <p class="text-muted phpclaw-guide-intro">Remote skill URLs configured but none registered. Check they're saved, HTTPS, reachable, and end in .md/.json.</p>
        {else}
        <table class="table table-bordered mb-0 phpclaw-guide-fs">
          <thead class="table-light">
            <tr>
              <th class="phpclaw-col-160">Name</th>
              <th>Description</th>
              <th>Keywords</th>
              <th class="phpclaw-col-100">Source</th>
            </tr>
          </thead>
          <tbody>
            {foreach $remote_skills as $skill}
            <tr>
              <td><code class="phpclaw-about-pkg-code">{$skill.name|escape:'htmlall'}</code></td>
              <td class="text-muted"><small>{$skill.description|escape:'htmlall'}</small></td>
              <td><small>{foreach $skill.keywords as $kw}{$kw|escape:'htmlall'}{if !$kw@last}, {/if}{/foreach}</small></td>
              <td>remote</td>
            </tr>
            {/foreach}
          </tbody>
        </table>
        <p class="text-muted phpclaw-guide-intro"><small>{foreach $remote_skill_urls as $url}{$url|escape:'htmlall'}{if !$url@last}, {/if}{/foreach}</small></p>
        {/if}
      </div>
    </div>
  </div>

  {* REST API Tab *}
  <div class="phpclaw-guide-panel" id="panel-rest">
    <div class="card">
      <div class="card-body">
        <h3 class="card-title mt-0">REST API</h3>
        <p class="text-muted mb-3 phpclaw-guide-intro">
          Integrate phpClaw into any frontend, mobile app, or external service.
        </p>

        <h4 class="phpclaw-guide-h4">POST &amp;action=send</h4>
        <table class="table table-bordered mb-3 phpclaw-guide-fs">
          <tbody>
            <tr><th class="phpclaw-col-120">Endpoint</th><td><code class="phpclaw-about-pkg-code">{$url_api_base|escape:'htmlall'}&amp;action=send</code></td></tr>
            <tr><th>Method</th><td><code>POST</code></td></tr>
            <tr><th>Auth</th><td>A per-employee token ({if $can_manage_all}<a href="{$url_settings|escape:'htmlall'}">Settings &rarr; REST API Tokens</a>{else}Settings &rarr; REST API Tokens{/if}) <em>or</em> an active Back Office session. A token acts as the employee it was issued for and carries exactly that employee's permissions, so the employee needs View on the phpClaw Chat tab for tools to run. Send it in the <code>Authorization: Bearer</code> header; a URL-embedded <code>?api_token=</code> is not accepted, since it can end up in access logs. The token is displayed once when it is created and stored only as a hash, so copy it then: if it is lost, revoke it and issue another. Revoking the token, removing the employee's Chat tab grant, or deactivating the employee each stop it working immediately.</td></tr>
            <tr><th>Body</th><td><code>message=your prompt</code> (form-encoded)</td></tr>
          </tbody>
        </table>

        <h4 class="phpclaw-guide-h4">Request body parameters</h4>
        <table class="table table-bordered mb-3 phpclaw-guide-fs">
          <thead class="table-light">
            <tr><th class="phpclaw-col-160">Field</th><th class="phpclaw-col-80">Type</th><th class="phpclaw-col-90">Required</th><th>Description</th></tr>
          </thead>
          <tbody>
            <tr><td><code>message</code></td><td>string</td><td>Yes</td><td class="text-muted">The prompt to send to the AI agent.</td></tr>
            <tr><td><code>conversation_id</code></td><td>string</td><td>No</td><td class="text-muted">Pass the <code>conversation_id</code> from a previous response to continue a conversation.</td></tr>
          </tbody>
        </table>

        <h4 class="phpclaw-guide-h4">curl example: new conversation</h4>
        <pre class="phpclaw-code-block">curl -X POST '{$url_api_base|escape:'htmlall'}&amp;action=send' \
  -H 'Authorization: Bearer YOUR_EMPLOYEE_TOKEN' \
  --data 'message=Show me the 5 most recent orders'</pre>

        <h4 class="phpclaw-guide-h4">Success (200)</h4>
        <pre class="phpclaw-code-block">{literal}{
  "success": true,
  "text": "Here are the 5 most recent orders...",
  "provider": "ollama",
  "model": "qwen2.5:7b",
  "tokens": 280,
  "iterations": 2,
  "conversation_id": "01JQABCDE...",
  "tool_calls": []
}{/literal}</pre>

        <h4 class="phpclaw-guide-h4">Error codes</h4>
        <table class="table table-bordered mb-0 phpclaw-guide-fs phpclaw-table-mw-640">
          <thead class="table-light">
            <tr><th class="phpclaw-col-70">HTTP</th><th class="phpclaw-col-200">Code</th><th>Meaning</th></tr>
          </thead>
          <tbody>
            <tr><td>400</td><td><code>phpclaw_empty_message</code></td><td class="text-muted">Missing or empty <code>message</code>.</td></tr>
            <tr><td>400</td><td><code>phpclaw_long_message</code></td><td class="text-muted">Message exceeds the maximum allowed length.</td></tr>
            <tr><td>403</td><td><code>phpclaw_forbidden</code></td><td class="text-muted">One of three things: the Bearer token is missing, unknown or revoked; the token's employee is inactive or lacks View on the phpClaw Chat tab; or the request named a <code>conversation_id</code> belonging to another employee. The first is a credential problem, the second a permissions one, the third is by design.</td></tr>
            <tr><td>404</td><td><code>phpclaw_not_found</code></td><td class="text-muted">Unknown <code>action</code>.</td></tr>
            <tr><td>405</td><td><code>phpclaw_forbidden</code></td><td class="text-muted">Wrong HTTP method for this action.</td></tr>
            <tr><td>422</td><td><code>phpclaw_guard</code></td><td class="text-muted">Prompt injection detected: request blocked by a security guard.</td></tr>
            <tr><td>429</td><td><code>phpclaw_rate_limited</code></td><td class="text-muted">Too many requests; try again shortly.</td></tr>
            <tr><td>400 / 500 / 502</td><td><code>phpclaw_error</code></td><td class="text-muted">Provider or tool error (500), or a bad request (400): check PrestaShop error logs.</td></tr>
            <tr><td>503</td><td><code>phpclaw_not_configured</code></td><td class="text-muted">phpClaw is not configured: set your AI provider and API key in Settings.</td></tr>
          </tbody>
        </table>

        <div class="mt-4">
          <h4 class="phpclaw-guide-h4">Streaming Endpoint (Server-Sent Events)</h4>
          <p class="text-muted mb-2 phpclaw-guide-intro">
            The Debug chat streams the agent reply live over Server-Sent Events (SSE). Tool calls appear as soon as
            they run, and the assistant text fills in as tokens arrive. The endpoint requires a valid admin session.
          </p>
          <table class="table table-bordered mb-3 phpclaw-guide-fs phpclaw-table-mw-600">
            <thead class="table-light">
              <tr><th class="phpclaw-col-70">Method</th><th>Path</th></tr>
            </thead>
            <tbody>
              <tr>
                <td><span class="phpclaw-badge phpclaw-badge--running">POST</span></td>
                <td><code class="phpclaw-about-pkg-code">AdminPhpClawDebug&amp;ajax=1&amp;action=Stream</code></td>
              </tr>
            </tbody>
          </table>
          <p class="text-muted mb-2 phpclaw-guide-intro">
            The response is a <code>text/event-stream</code>. Each frame is an <code>event:</code> line followed by a
            JSON <code>data:</code> line. Five event types are emitted:
          </p>
          <table class="table table-bordered mb-3 phpclaw-guide-fs">
            <thead class="table-light">
              <tr><th class="phpclaw-col-130">Event</th><th>Payload</th></tr>
            </thead>
            <tbody>
              <tr><td><code>tool_before</code></td><td class="text-muted"><code>{ldelim} tool_name, tool_input {rdelim}</code>: a tool is about to run.</td></tr>
              <tr><td><code>tool_after</code></td><td class="text-muted"><code>{ldelim} tool_name, tool_input, tool_result {rdelim}</code>: the tool finished.</td></tr>
              <tr><td><code>chunk</code></td><td class="text-muted"><code>{ldelim} text {rdelim}</code>: a fragment of the assistant reply.</td></tr>
              <tr><td><code>done</code></td><td class="text-muted"><code>{ldelim} text, provider, model, tokens, iterations, conversation_id, title, is_new, tool_calls {rdelim}</code>: final summary.</td></tr>
              <tr><td><code>error</code></td><td class="text-muted"><code>{ldelim} message {rdelim}</code>: the request could not be completed.</td></tr>
            </tbody>
          </table>
          <h4 class="phpclaw-guide-h4">Sample Event Stream</h4>
          <pre class="phpclaw-code-block">event: tool_before
data: {ldelim}"tool_name":"ps_product","tool_input":{ldelim}"limit":5{rdelim}{rdelim}

event: tool_after
data: {ldelim}"tool_name":"ps_product","tool_input":{ldelim}"limit":5{rdelim},"tool_result":"{ldelim}...{rdelim}"{rdelim}

event: chunk
data: {ldelim}"text":"Here are "{rdelim}

event: chunk
data: {ldelim}"text":"your products..."{rdelim}

event: done
data: {ldelim}"text":"Here are your products...","provider":"ollama","model":"qwen2.5:7b","iterations":2,"conversation_id":"01JQ...","tool_calls":[...]{rdelim}</pre>
          <div class="alert alert-info mt-2 mb-0 phpclaw-guide-fs">
            Token-by-token <code>chunk</code> events depend on the active provider. Providers that do not stream tokens
            deliver the full reply in the final <code>done</code> frame instead, tool cards still stream live.
          </div>
        </div>

        <div class="mt-4">
          <h4 class="phpclaw-guide-h4">Other REST endpoints</h4>
          <p class="text-muted mb-2 phpclaw-guide-intro">Same auth as <code>send</code>: a Bearer token or an active Back Office session.</p>
          <table class="table table-bordered mb-0 phpclaw-guide-fs">
            <thead class="table-light">
              <tr><th>Endpoint</th><th class="phpclaw-col-70">Method</th><th>Purpose</th></tr>
            </thead>
            <tbody>
              <tr><td><code class="phpclaw-about-pkg-code">{$url_api_base|escape:'htmlall'}&amp;action=chat/stream</code></td><td>POST</td><td class="text-muted">SSE chat stream: same event frames as above. Body: <code>message</code> (+ optional <code>conversation_id</code>).</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  {* CLI Tab *}
  <div class="phpclaw-guide-panel" id="panel-cli">
    <div class="card">
      <div class="card-body">
        <h3 class="card-title mt-0">CLI Commands</h3>
        <p class="text-muted mb-3 phpclaw-guide-intro">
          Run phpClaw from the command line. All commands are executed from the PrestaShop root directory.
        </p>

        <table class="table table-bordered mb-0 phpclaw-guide-fs">
          <thead class="table-light">
            <tr>
              <th class="phpclaw-col-420">Command</th>
              <th>Description</th>
            </tr>
          </thead>
          <tbody>
            {foreach $cli_commands as $cmd}
            <tr>
              <td><code class="phpclaw-about-pkg-code">{$cmd.command|escape:'htmlall'}</code></td>
              <td class="text-muted">{$cmd.description|escape:'htmlall'}</td>
            </tr>
            {/foreach}
          </tbody>
        </table>
        <p class="text-muted">Conversations started from the CLI are stored with an employee id of <code>0</code>, so they belong to no back-office employee and are visible only to a SuperAdmin.</p>
      </div>
    </div>
  </div>

  {* Privacy Tab *}
  <div class="phpclaw-guide-panel" id="panel-privacy">
    <div class="card mb-4">
      <div class="card-body">
        <h3 class="card-title mt-0">Privacy &amp; Data Storage</h3>
        <p class="text-muted mb-3 phpclaw-guide-intro">
          The <strong>Store Messages</strong> setting in {if $can_manage_all}<a href="{$url_settings|escape:'htmlall'}">Settings</a>{else}Settings{/if} controls what data phpClaw persists to your store's database.
        </p>
        <div class="alert alert-warning mb-3 phpclaw-alert-fs">
          <strong>AI provider:</strong> Every message you send is processed by the AI provider you configure in Settings (e.g. Anthropic, OpenAI, Groq); this happens on every request, regardless of the Store Messages setting, and is how phpClaw generates its responses. Consult your chosen provider's own privacy policy for how they handle this data. Ollama (local) is the exception: it runs on your own server and never sends data anywhere.
        </div>
        <table class="table table-bordered mb-0 phpclaw-guide-fs">
          <thead class="table-light">
            <tr>
              <th class="phpclaw-col-240"></th>
              <th>Store Messages = <span class="text-success">ON</span></th>
              <th>Store Messages = <span class="text-danger">OFF</span></th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td>
                <strong>Conversation metadata</strong><br>
                <small class="text-muted">ID, title, timestamps</small>
              </td>
              <td>&#9989; Saved to <code>{$db_prefix|escape:'htmlall'}phpclaw_conversations</code></td>
              <td>&#10060; <strong>Never saved</strong></td>
            </tr>
            <tr>
              <td>
                <strong>Message text</strong><br>
                <small class="text-muted">User prompts and AI replies</small>
              </td>
              <td>&#9989; Saved to <code>{$db_prefix|escape:'htmlall'}phpclaw_messages</code></td>
              <td>&#10060; <strong>Never saved</strong></td>
            </tr>
            <tr>
              <td>
                <strong>Tool call inputs/outputs</strong><br>
                <small class="text-muted">Data returned by tools</small>
              </td>
              <td>&#9989; Saved to <code>{$db_prefix|escape:'htmlall'}phpclaw_messages</code></td>
              <td>&#10060; <strong>Never saved</strong></td>
            </tr>
          </tbody>
        </table>
        <div class="alert alert-info mt-3 mb-0 phpclaw-alert-fs">
          <strong>Note:</strong> When <em>Store Messages</em> is OFF, multi-turn conversations still work within a single request (the agent holds context in memory) but nothing is persisted. Each new request starts fresh.
        </div>
        <div class="alert alert-info mt-3 mb-0 phpclaw-alert-fs">
          <strong>phpClaw Cloud:</strong> Request data is additionally forwarded to phpClaw Cloud only when a Cloud Key is set <em>and</em> Store Messages is ON. When Store Messages is OFF, or no Cloud Key is set, nothing is sent to phpClaw Cloud.
        </div>
      </div>
    </div>
  </div>

  {* Community Card *}
  {include file="./_community_card.tpl"}

</div>

<script>
(function () {ldelim}
  var tabs   = document.querySelectorAll('.phpclaw-guide-tab');
  var panels = document.querySelectorAll('.phpclaw-guide-panel');

  tabs.forEach(function (tab) {ldelim}
    tab.addEventListener('click', function () {ldelim}
      var target = tab.getAttribute('data-tab');

      tabs.forEach(function (t) {ldelim} t.classList.remove('active'); {rdelim});
      panels.forEach(function (p) {ldelim} p.classList.remove('active'); {rdelim});

      tab.classList.add('active');
      var panel = document.getElementById('panel-' + target);
      if (panel) panel.classList.add('active');
    {rdelim});
  {rdelim});

{rdelim})();
</script>
