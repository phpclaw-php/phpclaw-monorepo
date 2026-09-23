{*
  phpClaw: About Page
  PrestaShop 8 Back Office admin template (Bootstrap 4, Smarty)

  Marketing/product page only.
  Never expose internal architecture, class names, or implementation details.
*}

<div class="page-header d-print-none">
  <div class="container-xl">
    <div class="row g-2 align-items-center">
      <div class="col">
        <h2 class="page-title">phpClaw AI Agent: About</h2>
      </div>
    </div>
  </div>
</div>

<div class="container-xl pt-2 pb-4">

  {* Hero *}
  <div class="phpclaw-about-hero-layout">
  <div class="d-flex align-items-center gap-4">
    <div class="phpclaw-hero-logo">
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#fff" width="38" height="38">
        <path d="M12 2a2 2 0 0 1 2 2 2 2 0 0 1-2 2 2 2 0 0 1-2-2 2 2 0 0 1 2-2m0 5c2.67 0 8 1.34 8 4v2H4v-2c0-2.66 5.33-4 8-4z"/>
        <rect x="3" y="14" width="18" height="8" rx="2"/>
        <circle cx="8.5" cy="18" r="1.5" fill="#2271b1"/>
        <circle cx="15.5" cy="18" r="1.5" fill="#2271b1"/>
        <rect x="10.5" y="16.5" width="3" height="1" rx=".5" fill="#2271b1"/>
      </svg>
    </div>
    <div>
      <h1 class="phpclaw-hero-title">phpClaw</h1>
      <p class="phpclaw-hero-subtitle">Universal AI agent engine for PrestaShop</p>
      <p class="phpclaw-hero-version">Version {$about.version|escape:'htmlall'}</p>
    </div>
  </div>
  <div class="d-flex gap-3 flex-wrap align-items-center">
    <a href="https://phpclaw.ai" target="_blank" rel="noopener" class="phpclaw-hero-btn--primary">
      phpclaw.ai
    </a>
    <a href="https://phpclaw.ai/docs" target="_blank" rel="noopener" class="phpclaw-hero-btn--ghost">
      Documentation
    </a>
    <a href="https://github.com/phpclaw-php/phpclaw-monorepo" target="_blank" rel="noopener" class="phpclaw-hero-btn--ghost">
      GitHub
    </a>
  </div>
  </div>

  {* What is phpClaw *}
  <div class="card mb-4">
    <div class="card-body">
      <h3 class="card-title mt-0">What is phpClaw?</h3>
      <p class="text-muted mb-2 phpclaw-about-desc">
        phpClaw is an AI assistant that lives inside your PrestaShop Back Office. Connect any AI provider (Anthropic Claude, OpenAI GPT, Groq, Google Gemini, Mistral, DeepSeek, or a local Ollama model) and ask questions about your store in plain English.
      </p>
      <p class="text-muted mb-2 phpclaw-about-desc">
        No prompts to memorize. No SQL to write. No dashboards to learn. Just ask: <em>"Any pending orders?"</em>, <em>"Low stock products?"</em>, <em>"Revenue this week?"</em>, and get real answers from real data.
      </p>
      <p class="text-muted mb-0 phpclaw-about-desc">
        Part of the phpClaw ecosystem: the same AI engine available for Laravel, WordPress, Drupal, Magento, and 6 other PHP frameworks and CMS platforms.
      </p>
    </div>
  </div>

  {* Features grid *}
  <h3 class="mb-3">Built-in features</h3>
  <div class="phpclaw-features-grid">
    <div class="phpclaw-feature-card">
      <div class="phpclaw-feature-icon">🤖</div>
      <strong class="phpclaw-feature-label">8 AI Providers</strong>
      <p class="text-muted mb-0 phpclaw-feature-desc">Anthropic Claude, OpenAI GPT, Groq, Google Gemini, Mistral, DeepSeek, Ollama (local), Custom (any OpenAI-compatible endpoint). Switch from Settings, zero code changes.</p>
    </div>
    <div class="phpclaw-feature-card">
      <div class="phpclaw-feature-icon">🛠</div>
      <strong class="phpclaw-feature-label">14 Built-in Tools</strong>
      <p class="text-muted mb-0 phpclaw-feature-desc">Products, Orders, Customers, Categories, Stock, Coupons, Modules, Reports, Configuration, Employees, Carts, Manufacturers, plus Database queries and Logs. The agent picks the right tool automatically.</p>
    </div>
    <div class="phpclaw-feature-card">
      <div class="phpclaw-feature-icon">💬</div>
      <strong class="phpclaw-feature-label">Chat UI</strong>
      <p class="text-muted mb-0 phpclaw-feature-desc">Chat-style conversation interface in the Back Office. Conversation history, searchable sidebar, multi-turn context.</p>
    </div>
    <div class="phpclaw-feature-card">
      <div class="phpclaw-feature-icon">⚡</div>
      <strong class="phpclaw-feature-label">Live Tool Streaming</strong>
      <p class="text-muted mb-0 phpclaw-feature-desc">Watch the agent work in real time: tool calls stream in as they run and show typed result cards, while the reply fills in live.</p>
    </div>
    <div class="phpclaw-feature-card">
      <div class="phpclaw-feature-icon">🔌</div>
      <strong class="phpclaw-feature-label">REST API</strong>
      <p class="text-muted mb-0 phpclaw-feature-desc">POST to the phpClaw API endpoint: integrate the AI agent into any frontend, mobile app, chatbot, or external service.</p>
    </div>
    <div class="phpclaw-feature-card">
      <div class="phpclaw-feature-icon">🖥</div>
      <strong class="phpclaw-feature-label">PS CLI</strong>
      <p class="text-muted mb-0 phpclaw-feature-desc">Full terminal access via the PS CLI script. Script automations, run in CI/CD, or pipe output to other tools.</p>
    </div>
    <div class="phpclaw-feature-card">
      <div class="phpclaw-feature-icon">🔒</div>
      <strong class="phpclaw-feature-label">Enterprise-Grade Security</strong>
      <p class="text-muted mb-0 phpclaw-feature-desc">Multi-layered protection against prompt injection, unsafe commands, and data leakage. Your data stays safe. Your agent stays under control.</p>
    </div>
  </div>

  {* Open source ecosystem *}
  <div class="card mb-4">
    <div class="card-body">
      <h3 class="card-title mt-0">Open Source Ecosystem</h3>
      <p class="text-muted mb-3 phpclaw-about-fs-sm">MIT-licensed. Available as separate packages:</p>
      <table class="table table-bordered mb-0 phpclaw-guide-fs">
        <thead class="table-light">
          <tr>
            <th class="phpclaw-about-th-pkg">Package</th>
            <th class="phpclaw-col-100">Installed</th>
            <th>What it adds</th>
          </tr>
        </thead>
        <tbody>
          {foreach $about.packages as $p}
          <tr>
            <td><code class="phpclaw-about-pkg-code">{$p.package|escape:'htmlall'}</code></td>
            <td>{if $p.installed}<span class="text-success" aria-label="Installed">&#10004;</span>{else}<span class="text-danger" aria-label="Not installed">&#10008;</span>{/if}</td>
            <td class="text-muted">{$p.description|escape:'htmlall'}</td>
          </tr>
          {/foreach}
        </tbody>
      </table>
    </div>
  </div>

  {* Community Card *}
  {include file="./_community_card.tpl"}

</div>
