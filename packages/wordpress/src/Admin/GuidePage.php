<?php

declare(strict_types=1);

namespace PhpClaw\WordPress\Admin;

use PhpClaw\AutoDiscovery\DiscoveryCache;
use PhpClaw\Guards\GuardRegistry;
use PhpClaw\Providers\ProviderCatalogue;
use PhpClaw\Skills\SkillRegistry;
use PhpClaw\WordPress\Engine\EngineFactory;
use PhpClaw\WordPress\Plugin;

/**
 * phpClaw Developer Guide admin page.
 */
final class GuidePage
{
    private const CLOUD_SCAN_GUARD = 'PhpClaw\\Cloud\\CloudScanGuard';

    private const TABS = [
        'quickstart' => 'Quickstart',
        'tools' => 'Tools',
        'providers' => 'Providers',
        'memory' => 'Memory',
        'guards' => 'Guards',
        'hooks' => 'Hooks',
        'skills' => 'Skills',
        'rest' => 'REST API',
        'cli' => 'WP-CLI',
        'privacy' => 'Privacy',
    ];

    /**
     * Register the Guide submenu page under the phpClaw admin menu.
     *
     * @return void
     */
    public static function register(): void
    {
        add_submenu_page(
            parent_slug: 'phpclaw',
            page_title: 'phpClaw Guide',
            menu_title: 'Guide',
            capability: 'phpclaw_use_chat',
            menu_slug: 'phpclaw-guide',
            callback: [self::class, 'render'],
        );
    }

    /**
     * Render the Guide page with tabs, refusing anyone without phpclaw_use_chat.
     *
     * @return void
     */
    public static function render(): void
    {
        if (! current_user_can('phpclaw_use_chat')) {
            wp_die(esc_html__('Insufficient permissions.', 'phpclaw'));
        }

        $currentTab = sanitize_key((string) ($_GET['tab'] ?? 'quickstart'));
        if (! array_key_exists($currentTab, self::TABS)) {
            $currentTab = 'quickstart';
        }

        $baseUrl = admin_url('admin.php?page=phpclaw-guide');
        ?>
        <div class="wrap">
            <h1 class="pc-page-header">🤖 <?= esc_html__('phpClaw Developer Guide', 'phpclaw') ?></h1>

            <nav class="nav-tab-wrapper pc-nav-tab-flush">
                <?php foreach (self::TABS as $slug => $label) { ?>
                    <a href="<?= esc_url($baseUrl.'&tab='.$slug) ?>"
                       class="nav-tab <?= $currentTab === $slug ? 'nav-tab-active' : '' ?>">
                        <?= esc_html($label) ?>
                    </a>
                <?php } ?>
            </nav>

            <div class="pc-tab-content">
                <?php
                match ($currentTab) {
                    'tools' => self::renderTabTools(),
                    'memory' => self::renderTabMemory(),
                    'providers' => self::renderTabProviders(),
                    'guards' => self::renderTabGuards(),
                    'hooks' => self::renderTabHooks(),
                    'skills' => self::renderTabSkills(),
                    'rest' => self::renderTabRest(),
                    'cli' => self::renderTabCli(),
                    'privacy' => self::renderTabPrivacy(),
                    default => self::renderTabQuickstart(),
                };
        ?>
            </div>
        </div>

        <?php AdminFooter::renderCommunityCard(); ?>
        <?php
    }

    /**
     * Render the Quickstart tab with installation steps and first-prompt examples.
     *
     * @return void
     */
    private static function renderTabQuickstart(): void
    {
        $chatUrl = admin_url('admin.php?page=phpclaw-chat');
        ?>
        <h2 class="pc-mt-0"><?= esc_html__('Quickstart', 'phpclaw') ?></h2>
        <p class="pc-text-muted"><?= esc_html__('Get up and running in under 5 minutes.', 'phpclaw') ?></p>

        <div class="pc-card">
            <ol>
                <li>
                    <strong><?= esc_html__('Choose a provider', 'phpclaw') ?></strong>
                    <span class="pc-text-muted">(<?= esc_html__('Administrator only', 'phpclaw') ?>)</span>: go to
                    <?= self::settingsLink() ?>
                    <?= esc_html__('and select Anthropic, OpenAI, Groq, Gemini, Mistral, DeepSeek, Ollama (local), or Custom (any OpenAI-compatible endpoint).', 'phpclaw') ?>
                </li>
                <li>
                    <strong><?= esc_html__('Enter your API key', 'phpclaw') ?></strong>
                    <span class="pc-text-muted">(<?= esc_html__('Administrator only', 'phpclaw') ?>)</span>:
                    <?= esc_html__('paste the API key from your provider dashboard. No key needed for Ollama.', 'phpclaw') ?>
                </li>
                <li>
                    <strong><?= esc_html__('Save and test', 'phpclaw') ?></strong>
                    <span class="pc-text-muted">(<?= esc_html__('Administrator only', 'phpclaw') ?>)</span>:
                    <?= esc_html__('click Save Settings, then Test Connection to verify the key works.', 'phpclaw') ?>
                </li>
                <li>
                    <strong><?= esc_html__('Open Chat', 'phpclaw') ?></strong>:
                    <?= esc_html__('go to', 'phpclaw') ?>
                    <a href="<?= esc_url($chatUrl) ?>"><?= esc_html__('Chat', 'phpclaw') ?></a>
                    <?= esc_html__('and send your first message.', 'phpclaw') ?>
                </li>
            </ol>
        </div>

        <h3><?= esc_html__('First prompts to try', 'phpclaw') ?></h3>
        <table class="widefat striped pc-table-narrow">
            <thead>
                <tr>
                    <th><?= esc_html__('Prompt', 'phpclaw') ?></th>
                    <th><?= esc_html__('What it does', 'phpclaw') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php
                $examples = [
                    ['How many published posts do I have?',    'Queries your post count using WP Query Tool.'],
                    ['Which plugins are active right now?',    'Lists all active plugins with versions.'],
                    ['Show me the last 5 errors in debug.log', 'Reads your WordPress debug log.'],
                    ['Any pending comments to approve?',       'Checks comment moderation queue.'],
                    ['What is the current site URL?',          'Reads wp_options for the site URL.'],
                    ['List users with the editor role',        'Queries users by role.'],
                ];
        foreach ($examples as [$prompt, $desc]) { ?>
                <tr>
                    <td><code><?= esc_html($prompt) ?></code></td>
                    <td><?= esc_html($desc) ?></td>
                </tr>
                <?php } ?>
            </tbody>
        </table>

        <h3 class="pc-mt-24"><?= esc_html__('Common tasks', 'phpclaw') ?></h3>
        <table class="widefat striped pc-table-narrow">
            <thead>
                <tr>
                    <th><?= esc_html__('Task', 'phpclaw') ?></th>
                    <th><?= esc_html__('How', 'phpclaw') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php
        $guideUrl = admin_url('admin.php?page=phpclaw-guide&tab=cli');
        $tasks = [
            ['Automate from the command line',    'Install WP-CLI and use <code>wp phpclaw send "your prompt"</code>. See the <a href="'.esc_url($guideUrl).'">WP-CLI tab</a>.'],
            ['Call phpClaw from a custom plugin', 'POST to <code>/wp-json/phpclaw/send</code> with an Application Password. See the REST API tab.'],
        ];
        foreach ($tasks as [$task, $how]) { ?>
                <tr>
                    <td><?= esc_html($task) ?></td>
                    <td><?= wp_kses($how, ['a' => ['href' => []], 'code' => []]) ?></td>
                </tr>
                <?php } ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * Render the Tools tab listing built-in, WooCommerce, and external tools.
     *
     * @return void
     */
    private static function renderTabTools(): void
    {
        $meta = [
            'WpQueryTool' => ['name' => 'WP Query Tool',    'desc' => 'Query posts, pages, or custom post types. Read-only.',                        'prompts' => 'Show me recent posts · How many draft pages? · Search posts about "SEO"'],
            'WpOptionsTool' => ['name' => 'WP Options Tool',  'desc' => 'Read WordPress options. Sensitive keys are blocked.',                          'prompts' => 'What is the site tagline? · Is registration open? · What timezone is set?'],
            'DatabaseTool' => ['name' => 'Database Tool',    'desc' => 'Run read-only SELECT queries against any WordPress table.',                    'prompts' => 'How many rows in wp_posts? · Show table sizes · Count published posts by author'],
            'LogTool' => ['name' => 'Log Tool',         'desc' => 'Read the last N lines of wp-content/debug.log.',                               'prompts' => 'Show me recent errors · Any fatal errors today? · Last 20 log lines'],
            'WpCronTool' => ['name' => 'Cron Tool',        'desc' => 'List scheduled WP-Cron events with next run time and overdue detection.',      'prompts' => 'What cron jobs are scheduled? · Any stuck cron? · Is backup cron running?'],
            'WpUserTool' => ['name' => 'User Tool',        'desc' => 'Query users by role, search, last login. Emails protected.',                   'prompts' => 'How many editors? · Who logged in this week? · List all administrators'],
            'WpPluginTool' => ['name' => 'Plugin Tool',      'desc' => 'List installed plugins with status (active/inactive) and versions.',            'prompts' => 'Which plugins are active? · Is Yoast installed? · Any inactive plugins?'],
            'WpMenuTool' => ['name' => 'Menu Tool',        'desc' => 'Read navigation menus with items, hierarchy, and assigned locations.',          'prompts' => "What's in the main menu? · How many menus do I have? · Show footer navigation"],
            'WpMediaTool' => ['name' => 'Media Tool',       'desc' => 'Query media library: file types, sizes, unattached files.',                   'prompts' => 'How many images? · Any unattached media? · Show recent uploads'],
            'WpCommentTool' => ['name' => 'Comment Tool',     'desc' => 'Query comments by status. Emails excluded by default, available on request.',   'prompts' => 'Any pending comments? · How many spam this month? · Show recent comments'],
            'WpTaxonomyTool' => ['name' => 'Taxonomy Tool',    'desc' => 'Query categories, tags, and custom taxonomies with post counts.',               'prompts' => 'List categories with counts · What tags do I have? · Most used tags'],
            'WpZipBuilderTool' => ['name' => 'Plugin Zip Builder', 'desc' => 'Package a plugin directory into a distributable ZIP archive.', 'prompts' => 'Zip up my custom plugin · Build a release archive for phpclaw'],
        ];

        $toolRows = self::wordpressToolRows($meta);
        ?>
        <h2 class="pc-mt-0">Available Tools</h2>
        <p class="pc-text-muted">The AI agent automatically picks the right tool based on your prompt.</p>
        <p class="pc-text-muted">Every tool below ships with the plugin. How many are offered to the model depends on the provider: hosted providers get the full set, while local providers (Ollama, Groq) get a focused subset, 8 tools for 30B class models and above, otherwise 5. Switch provider in Settings to change this.</p>

        <p class="pc-text-muted"><strong>Who can use them.</strong> Every tool on this page is gated by a single capability, <code>phpclaw_use_chat</code>, held by Administrators and by any role with <code>edit_posts</code>. Anyone who can open the chat page can use every tool, including database queries, so treat that capability as administrator equivalent when deciding which roles receive it.</p>

        <h3>WordPress Tools</h3>
        <p class="pc-text-muted">Tools the AI agent can use on your site. Core utility tools (HTTP, file I/O, shell) appear in their own section below.</p>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th class="pc-col-160">Name</th>
                    <th class="pc-col-40pct">Description</th>
                    <th>Example Prompts</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($toolRows as $t) { ?>
                <tr>
                    <td><strong><?= esc_html($t['name']) ?></strong></td>
                    <td><?= esc_html($t['desc']) ?></td>
                    <td class="pc-prompts-cell"><?= esc_html($t['prompts']) ?></td>
                </tr>
                <?php } ?>
            </tbody>
        </table>

        <?php if (class_exists('WooCommerce')) { ?>
        <h3 class="pc-mt-24">WooCommerce Tools</h3>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th class="pc-col-160">Name</th>
                    <th class="pc-col-40pct">Description</th>
                    <th>Example Prompts</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $wcTools = [
                    ['name' => 'Order Tool',    'desc' => 'Query orders by status, totals, and items. HPOS compatible.',               'prompts' => 'Show pending orders · Revenue today · Last 10 orders'],
                    ['name' => 'Product Tool',  'desc' => 'Query products by SKU, price, stock, and category.',                        'prompts' => 'Products under $10 · Find SKU ABC123 · List featured products'],
                    ['name' => 'Customer Tool', 'desc' => 'Query customers by group and order count. No PII exposed.',                 'prompts' => 'How many customers? · Wholesale group members · Top buyers'],
                    ['name' => 'Report Tool',   'desc' => 'Sales reports: revenue, order count, average order, top products.',         'prompts' => 'Revenue this month · Compare this week vs last · Top selling products'],
                    ['name' => 'Stock Tool',    'desc' => 'Inventory status: out-of-stock, low-stock, backorder products.',            'prompts' => 'Low stock products · Out of stock items · Backorder status'],
                    ['name' => 'Coupon Tool',   'desc' => 'List coupons with codes, discount type, usage, and expiry.',                'prompts' => 'Active coupons · Any expired coupons? · Most used coupon'],
                    ['name' => 'Category Tool', 'desc' => 'Product categories with hierarchy and product counts.',                     'prompts' => 'Product categories · Any empty categories? · Category tree'],
                    ['name' => 'Review Tool',   'desc' => 'Product reviews with ratings and status. No email exposed.',                'prompts' => 'Pending reviews · Average rating for Product X · 1-star reviews'],
                    ['name' => 'Shipping Tool', 'desc' => 'Shipping zones, methods (flat rate, free, local pickup), and rates.',       'prompts' => 'Shipping zones · Free shipping rules · Which zone covers India?'],
                    ['name' => 'Tax Tool',      'desc' => 'Tax settings, classes, and rates by country/state.',                        'prompts' => 'Is tax enabled? · Tax rates for US · What tax classes exist?'],
                ];
            foreach ($wcTools as $t) { ?>
                <tr>
                    <td><strong><?= esc_html($t['name']) ?></strong></td>
                    <td><?= esc_html($t['desc']) ?></td>
                    <td class="pc-prompts-cell"><?= esc_html($t['prompts']) ?></td>
                </tr>
                <?php } ?>
            </tbody>
        </table>
        <?php } else { ?>
        <div class="pc-wc-missing">
            <strong>WooCommerce not detected.</strong> Install and activate WooCommerce to unlock 10 additional commerce tools (orders, products, customers, reports, stock, coupons, categories, reviews, shipping, tax).
        </div>
        <?php } ?>

        <h3 class="pc-mt-24">Core Utility Tools <span class="pc-tools-subtitle">ships with <code>phpclaw/phpclaw</code>, no extra install</span></h3>
        <?php
        $coreTools = [];
        $liveCore = self::liveCoreToolClasses();
        foreach (self::discovered('tools') as $class => $attr) {
            if (empty($attr['default']) && ! in_array((string) $class, $liveCore, true)) {
                continue;
            }
            $base = strrchr((string) $class, '\\');
            $coreTools[] = [
                'tool' => $base === false ? (string) $class : substr($base, 1),
                'desc' => (string) ($attr['description'] ?? ''),
                'dep' => ! empty($attr['deprecated']),
            ];
        }
        usort($coreTools, static fn (array $a, array $b): int => strcmp($a['tool'], $b['tool']));
        ?>
        <?php if ($coreTools === []) { ?>
            <p class="pc-text-muted">No core tools discovered.</p>
        <?php } else { ?>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th class="pc-col-160">Tool</th>
                    <th class="pc-col-140">Status</th>
                    <th>Notes</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($coreTools as $t) { ?>
                <tr>
                    <td><code><?= esc_html($t['tool']) ?></code></td>
                    <td>
                        <?php if ($t['dep']) { ?>
                        <span class="pc-status-warn">⚠ Deprecated</span>
                        <?php } else { ?>
                        <span class="pc-status-active">✔ Ready</span>
                        <?php } ?>
                    </td>
                    <td><?= esc_html($t['desc']) ?></td>
                </tr>
                <?php } ?>
            </tbody>
        </table>
        <?php } ?>

        <?php
    }

    /**
     * Class names of the core `PhpClaw\Tools\` tools the engine registers, or [] when the live list can't be read.
     *
     * @return list<string>
     */
    private static function liveCoreToolClasses(): array
    {
        try {
            $live = EngineFactory::registeredTools(
                Plugin::getInstance()->config(),
                (array) get_option('phpclaw_settings', []),
                Plugin::extraToolClasses(),
            );
        } catch (\Throwable) {
            return [];
        }

        $classes = [];

        foreach ($live as $tool) {
            if (str_starts_with($tool::class, 'PhpClaw\\Tools\\')) {
                $classes[] = $tool::class;
            }
        }

        return $classes;
    }

    /**
     * Rows for the WordPress tools table, falling back to the built-in copy when the live engine list can't be read.
     *
     * @param  array<string, array{name: string, desc: string, prompts: string}>  $meta
     * @return list<array{name: string, desc: string, prompts: string}>
     */
    private static function wordpressToolRows(array $meta): array
    {
        try {
            $live = EngineFactory::registeredTools(
                Plugin::getInstance()->config(),
                (array) get_option('phpclaw_settings', []),
                Plugin::extraToolClasses(),
            );
        } catch (\Throwable) {
            $live = null;
        }

        if ($live === null) {
            return array_values($meta);
        }

        $rows = [];

        foreach ($live as $tool) {
            $class = $tool::class;
            if (str_starts_with($class, 'PhpClaw\\WooCommerce\\') || str_starts_with($class, 'PhpClaw\\Tools\\')) {
                continue;
            }

            $base = strrchr($class, '\\');
            $base = $base === false ? $class : substr($base, 1);
            $m = $meta[$base] ?? [];
            $rows[] = [
                'name' => (string) ($m['name'] ?? $tool->name()),
                'desc' => (string) ($m['desc'] ?? $tool->description()),
                'prompts' => (string) ($m['prompts'] ?? ''),
            ];
        }

        return $rows;
    }

    /**
     * Render the Memory tab describing the database storage tables.
     *
     * @return void
     */
    private static function renderTabMemory(): void
    {
        ?>
        <h2 class="pc-mt-0">Memory</h2>
        <p class="pc-text-muted">Conversations and key-value state are stored in your WordPress
            database through the <code>wpdb_router</code> driver. The drivers below are available to
            developers registering their own; they are not selectable from Settings.</p>

        <h3 class="pc-mt-24">Memory Drivers Available To Developers</h3>
        <?php $memoryDrivers = self::discovered('memory'); ?>
        <?php $extraMemory = self::pluginExtras('phpclaw_extra_memory_drivers'); ?>
        <?php if ($memoryDrivers === [] && $extraMemory === []) { ?>
            <p class="pc-text-muted">No core memory drivers discovered.</p>
        <?php } else { ?>
        <table class="widefat striped">
            <thead>
                <tr><th>Driver</th><th>Label</th><th class="pc-col-80">Source</th><th>Class</th></tr>
            </thead>
            <tbody>
                <?php foreach ($memoryDrivers as $class => $attr) { ?>
                <tr>
                    <td><code><?= esc_html((string) ($attr['driver'] ?? '')) ?></code></td>
                    <td><?= esc_html((string) ($attr['label'] ?? $class)) ?></td>
                    <td><?= esc_html(self::discoverySource((string) $class)) ?></td>
                    <td><small><code><?= esc_html((string) $class) ?></code></small></td>
                </tr>
                <?php } ?>
                <?php foreach ($extraMemory as $slug => $factory) { ?>
                    <?php if (! is_string($slug)) {
                        continue;
                    } ?>
                <tr>
                    <td><code><?= esc_html($slug) ?></code></td>
                    <td><?= esc_html($slug) ?></td>
                    <td>wordpress</td>
                    <td><small><code>n/a</code></small></td>
                </tr>
                <?php } ?>
            </tbody>
        </table>
        <?php } ?>

        <h3 class="pc-mt-24">Database Tables</h3>
        <p class="pc-text-muted">Three dedicated tables created automatically on install:</p>
        <?php global $wpdb;
        $prefix = esc_html($wpdb->prefix); ?>
        <table class="widefat striped">
            <thead>
                <tr><th class="pc-col-260">Table</th><th>Purpose</th></tr>
            </thead>
            <tbody>
                <tr>
                    <td><code><?= $prefix ?>phpclaw_conversations</code></td>
                    <td>Conversation metadata: ID, namespace, title, metadata JSON, created/updated timestamps.</td>
                </tr>
                <tr>
                    <td><code><?= $prefix ?>phpclaw_messages</code></td>
                    <td>Full message history: every user and assistant turn per conversation.</td>
                </tr>
                <tr>
                    <td><code><?= $prefix ?>phpclaw_memory</code></td>
                    <td>Key-value store: agent state, cached data, custom namespaces.</td>
                </tr>
            </tbody>
        </table>

        <div class="pc-notice-info pc-mt-24">
            <strong>Backup tip:</strong> Include these three tables in your database backups to preserve all chat history.
        </div>

        <?php
    }

    /**
     * Render the Providers tab with API key setup instructions for all 8 built-in providers (7 API-key + Custom).
     *
     * @return void
     */
    private static function renderTabProviders(): void
    {

        $live = self::liveProviders();

        $meta = [
            'anthropic' => [
                'name' => 'Anthropic (Claude)',
                'models' => 'claude-opus-4-8, claude-sonnet-5, claude-haiku-4-5-20251001',
                'signup' => 'https://console.anthropic.com/',
                'notes' => 'The default provider. Recommended for complex reasoning and tool use.',
            ],
            'openai' => [
                'name' => 'OpenAI (GPT)',
                'models' => 'gpt-4o, gpt-4o-mini, o3-mini',
                'signup' => 'https://platform.openai.com/',
                'notes' => 'Broad ecosystem support. gpt-4o-mini is fast and cost-effective for simple tasks.',
            ],
            'groq' => [
                'name' => 'Groq',
                'models' => 'llama-3.3-70b-versatile, llama-3.1-8b-instant, mixtral-8x7b-32768',
                'signup' => 'https://console.groq.com/',
                'notes' => 'Extremely fast inference. Free tier available. Great for rapid iteration.',
            ],
            'gemini' => [
                'name' => 'Google Gemini',
                'signup' => 'https://aistudio.google.com/',
                'notes' => 'Large context window. Free tier available via Google AI Studio.',
            ],
            'mistral' => [
                'name' => 'Mistral AI',
                'models' => 'mistral-large-latest, mistral-small-latest, codestral-latest',
                'signup' => 'https://console.mistral.ai/',
                'notes' => 'European AI provider. Strong coding models. GDPR-friendly.',
            ],
            'ollama' => [
                'name' => 'Ollama (local)',
                'models' => 'qwen2.5:7b, mistral, phi3, gemma2, or any model you pull',
                'signup' => 'https://ollama.com/',
                'notes' => 'Runs on your own server. No API key needed. Default endpoint: 127.0.0.1:11434. For a remote Ollama, choose Custom and set its Base URL.',
            ],
            'deepseek' => [
                'name' => 'DeepSeek',
                'models' => 'deepseek-flash, deepseek-chat, deepseek-reasoner',
                'signup' => 'https://platform.deepseek.com/',
                'notes' => 'High capability at low cost. Ships with the core engine.',
            ],
            'custom' => [
                'name' => 'Custom',
                'models' => 'Any OpenAI-compatible model',
                'signup' => '',
                'notes' => 'Any OpenAI-compatible endpoint. Set Base URL in Settings.',
            ],
        ];

        $providers = [];
        foreach ($live as $slug => $info) {
            $m = $meta[$slug] ?? [];
            $providers[] = [
                'name' => (string) ($m['name'] ?? $info['label']) ?: ucfirst($slug),
                'key' => $slug,
                'models' => (string) ($m['models'] ?? $info['defaultModel']),
                'signup' => (string) ($m['signup'] ?? ''),
                'notes' => (string) ($m['notes'] ?? ''),
            ];
        }
        ?>
        <h2 class="pc-mt-0"><?= esc_html__('AI Providers', 'phpclaw') ?></h2>
        <p class="pc-text-muted">
            <?= esc_html__('phpClaw supports multiple AI providers. Select one in', 'phpclaw') ?>
            <?= self::settingsLink() ?>
            <?= esc_html__('and enter your API key. Switch providers at any time, zero code changes required.', 'phpclaw') ?>
        </p>

        <table class="widefat striped">
            <thead>
                <tr>
                    <th class="pc-col-160"><?= esc_html__('Provider', 'phpclaw') ?></th>
                    <th class="pc-col-260"><?= esc_html__('Popular Models', 'phpclaw') ?></th>
                    <th class="pc-col-120"><?= esc_html__('Sign Up', 'phpclaw') ?></th>
                    <th><?= esc_html__('Notes', 'phpclaw') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($providers as $p) { ?>
                <tr>
                    <td><strong><?= esc_html($p['name']) ?></strong></td>
                    <td><code class="pc-code-sm"><?= esc_html($p['models']) ?></code></td>
                    <td>
                        <?php if ($p['signup'] !== '') { ?>
                        <a href="<?= esc_url($p['signup']) ?>" target="_blank" rel="noopener">
                            <?= esc_html__('Get API key →', 'phpclaw') ?>
                        </a>
                        <?php } else { ?>
                        <?= esc_html__('n/a', 'phpclaw') ?>
                        <?php } ?>
                    </td>
                    <td><?= esc_html($p['notes']) ?></td>
                </tr>
                <?php } ?>
            </tbody>
        </table>

        <div class="pc-notice-info pc-mt-24">
            <strong><?= esc_html__('Tip:', 'phpclaw') ?></strong>
            <?= esc_html__('After entering your API key, click "Test Connection" on the Settings page to verify it works before sending real prompts.', 'phpclaw') ?>
        </div>

        <?php
    }

    /**
     * Source label for a discovered class: `wordpress` for adapter classes, `core` otherwise.
     *
     * @param  string  $class
     * @return string
     */
    private static function discoverySource(string $class): string
    {
        return str_starts_with($class, 'PhpClaw\\WordPress') ? 'wordpress' : 'core';
    }

    /**
     * One DiscoveryCache bucket, or [] when discovery is unavailable.
     *
     * @param  string  $bucket  memory|guards|hooks|skills
     * @return array<string, mixed>
     */
    private static function discovered(string $bucket): array
    {
        try {
            return DiscoveryCache::load()[$bucket] ?? [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Plugin-contributed extras for a subsystem, read from its `phpclaw_extra_*` filter (empty when unavailable).
     *
     * @param  string  $filter
     * @return array<int|string, mixed>
     */
    private static function pluginExtras(string $filter): array
    {
        if (! function_exists('apply_filters')) {
            return [];
        }

        try {
            return (array) apply_filters($filter, []);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Class basename for a fully-qualified class string.
     *
     * @param  string  $class
     * @return string
     */
    private static function shortName(string $class): string
    {
        $pos = strrchr($class, '\\');

        return $pos === false ? $class : substr($pos, 1);
    }

    /**
     * Live provider set - the same merged list the Settings dropdown shows, keyed `slug => {label, defaultModel}`.
     *
     * @return array<string, array{label: string, defaultModel: string}>
     */
    private static function liveProviders(): array
    {
        try {
            $catalogue = ProviderCatalogue::all();
        } catch (\Throwable) {
            return [];
        }

        $defaultModels = [];
        try {
            foreach (DiscoveryCache::load()['providers'] ?? [] as $attr) {
                $slug = (string) ($attr['name'] ?? '');
                if ($slug !== '') {
                    $defaultModels[$slug] = (string) ($attr['defaultModel'] ?? '');
                }
            }
        } catch (\Throwable) {
            $defaultModels = [];
        }

        $out = [];
        foreach ($catalogue as $slug => $info) {
            $slug = (string) $slug;
            $out[$slug] = [
                'label' => $info['label'],
                'defaultModel' => $defaultModels[$slug] ?? '',
            ];
        }

        return $out;
    }

    /**
     * Render the Guards tab - auto-discovered #[Guard] classes.
     *
     * @return void
     */
    private static function renderTabGuards(): void
    {
        ?>
        <h2 class="pc-mt-0">Guards</h2>
        <p class="pc-text-muted">Guards scan every user message before it reaches the LLM.</p>

        <h3 class="pc-mt-24">Discovered Guards</h3>
        <?php $guards = self::discovered('guards'); ?>
        <?php $extraGuards = self::pluginExtras('phpclaw_extra_guards'); ?>
        <?php if ($guards === [] && $extraGuards === []) { ?>
            <p class="pc-text-muted">No guards discovered.</p>
        <?php } else { ?>
        <table class="widefat striped">
            <thead>
                <tr><th>Name</th><th>Label</th><th class="pc-col-80">Priority</th><th class="pc-col-80">Default</th><th class="pc-col-80">Source</th><th>Class</th></tr>
            </thead>
            <tbody>
                <?php foreach ($guards as $class => $attr) { ?>
                <tr>
                    <td><code><?= esc_html((string) ($attr['name'] ?? '')) ?></code></td>
                    <td><?= esc_html((string) ($attr['label'] ?? $class)) ?></td>
                    <td><?= esc_html((string) (int) ($attr['priority'] ?? 0)) ?></td>
                    <td><?= ! empty($attr['enabledByDefault']) ? '✔' : '✗' ?></td>
                    <td><?= esc_html(self::discoverySource((string) $class)) ?></td>
                    <td><small><code><?= esc_html((string) $class) ?></code></small></td>
                </tr>
                <?php } ?>
                <?php foreach ($extraGuards as $guard) { ?>
                    <?php if (! is_object($guard)) {
                        continue;
                    } ?>
                    <?php $cls = $guard::class; ?>
                <tr>
                    <td><code><?= esc_html(self::shortName($cls)) ?></code></td>
                    <td><?= esc_html(self::shortName($cls)) ?></td>
                    <td></td>
                    <td>✔</td>
                    <td>wordpress</td>
                    <td><small><code><?= esc_html($cls) ?></code></small></td>
                </tr>
                <?php } ?>
                <?php if (GuardRegistry::hasClass(self::CLOUD_SCAN_GUARD)) { ?>
                <tr>
                    <td><code>cloud_scan</code></td>
                    <td>Cloud Security Scan</td>
                    <td></td>
                    <td>✔</td>
                    <td>cloud</td>
                    <td><small><code><?= esc_html(self::CLOUD_SCAN_GUARD) ?></code></small></td>
                </tr>
                <?php } ?>
            </tbody>
        </table>
        <?php } ?>

        <?php
    }

    /**
     * Render the Hooks tab - live inventory of every #[Hook] class loaded.
     *
     * @return void
     */
    private static function renderTabHooks(): void
    {
        ?>
        <h2 class="pc-mt-0">Hooks</h2>
        <p class="pc-text-muted">Below are the registered listener classes for this adapter.</p>

        <h3 class="pc-mt-24">Registered Hook Listeners</h3>
        <?php $hooks = self::discovered('hooks'); ?>
        <?php $extraHooks = self::pluginExtras('phpclaw_extra_hooks'); ?>
        <?php if ($hooks === [] && $extraHooks === []) { ?>
            <p class="pc-text-muted">No hook listeners discovered.</p>
        <?php } else { ?>
        <table class="widefat striped">
            <thead>
                <tr><th>Event</th><th>Name</th><th class="pc-col-80">Priority</th><th class="pc-col-80">Default</th><th class="pc-col-80">Source</th><th>Class</th></tr>
            </thead>
            <tbody>
                <?php foreach ($hooks as $class => $listeners) { ?>
                    <?php foreach ((array) $listeners as $l) { ?>
                    <tr>
                        <td><code><?= esc_html((string) ($l['event'] ?? '')) ?></code></td>
                        <td><?= esc_html((string) ($l['name'] ?? '')) ?></td>
                        <td><?= esc_html((string) (int) ($l['priority'] ?? 0)) ?></td>
                        <td><?= ! empty($l['enabledByDefault']) ? '✔' : '✗' ?></td>
                        <td><?= esc_html(self::discoverySource((string) $class)) ?></td>
                        <td><small><code><?= esc_html((string) $class) ?></code></small></td>
                    </tr>
                    <?php } ?>
                <?php } ?>
                <?php foreach ($extraHooks as $l) { ?>
                    <?php if (! is_array($l) || ! isset($l['event'])) {
                        continue;
                    } ?>
                    <?php
                    $handler = $l['handler'] ?? null;
                    $hClass = is_array($handler) ? ($handler[0] ?? '') : $handler;
                    $hClass = is_object($hClass) ? $hClass::class : (string) $hClass;
                    $hMethod = is_array($handler) ? (string) ($handler[1] ?? '') : '';
                    ?>
                    <tr>
                        <td><code><?= esc_html((string) $l['event']) ?></code></td>
                        <td><?= esc_html($hMethod !== '' ? $hMethod : self::shortName($hClass)) ?></td>
                        <td><?= esc_html((string) (int) ($l['priority'] ?? 10)) ?></td>
                        <td>✔</td>
                        <td>wordpress</td>
                        <td><small><code><?= esc_html($hClass) ?></code></small></td>
                    </tr>
                <?php } ?>
            </tbody>
        </table>
        <?php } ?>

        <?php
    }

    /**
     * Render the Skills tab - live inventory of every #[Skill] class loaded.
     *
     * @return void
     */
    private static function renderTabSkills(): void
    {
        ?>
        <h2 class="pc-mt-0">Skills</h2>
        <p class="pc-text-muted">
            Skills inject curated context snippets into the prompt when their keywords match the user message. All discovered skills are active automatically, no setup needed.
        </p>

        <h3 class="pc-mt-24">Discovered Skills</h3>
        <?php $skills = self::discovered('skills'); ?>
        <?php $extraSkills = self::pluginExtras('phpclaw_extra_skills'); ?>
        <?php if ($skills === [] && $extraSkills === []) { ?>
            <p class="pc-text-muted">No skills discovered.</p>
        <?php } else { ?>
        <table class="widefat striped">
            <thead>
                <tr><th>Name</th><th>Label</th><th>Keywords</th><th class="pc-col-80">Source</th><th>Class</th></tr>
            </thead>
            <tbody>
                <?php foreach ($skills as $class => $attr) { ?>
                <tr>
                    <td><code><?= esc_html((string) ($attr['name'] ?? '')) ?></code></td>
                    <td><?= esc_html((string) ($attr['label'] ?? $class)) ?></td>
                    <td><small><?= esc_html(implode(', ', array_map('strval', (array) ($attr['keywords'] ?? [])))) ?></small></td>
                    <td><?= esc_html(self::discoverySource((string) $class)) ?></td>
                    <td><small><code><?= esc_html((string) $class) ?></code></small></td>
                </tr>
                <?php } ?>
                <?php foreach ($extraSkills as $skill) { ?>
                    <?php if (! is_object($skill)) {
                        continue;
                    } ?>
                    <?php
                    $cls = $skill::class;
                    $sName = method_exists($skill, 'name') ? (string) $skill->name() : self::shortName($cls);
                    $sKw = method_exists($skill, 'tags') ? array_map('strval', (array) $skill->tags()) : [];
                    ?>
                <tr>
                    <td><code><?= esc_html($sName) ?></code></td>
                    <td><?= esc_html($sName) ?></td>
                    <td><small><?= esc_html(implode(', ', $sKw)) ?></small></td>
                    <td>wordpress</td>
                    <td><small><code><?= esc_html($cls) ?></code></small></td>
                </tr>
                <?php } ?>
            </tbody>
        </table>
        <?php } ?>

        <h3 class="pc-mt-24">Remote Skills (<code>remote_skill_urls</code>)</h3>
        <?php
        $remoteUrls = function_exists('get_option')
            ? (array) (get_option('phpclaw_settings', [])['remote_skill_urls'] ?? [])
            : [];

        if ($remoteUrls !== []) {
            try {
                Plugin::getInstance()->engine();
            } catch (\Throwable) {
            }
        }

        $knownNames = [];
        foreach ($skills as $attr) {
            $knownNames[(string) ($attr['name'] ?? '')] = true;
        }
        foreach ($extraSkills as $s) {
            if (is_object($s) && method_exists($s, 'name')) {
                $knownNames[(string) $s->name()] = true;
            }
        }

        $remoteSkills = class_exists(SkillRegistry::class)
            ? array_values(array_filter(
                SkillRegistry::all(),
                static fn ($s) => ! isset($knownNames[$s->name()]),
            ))
            : [];
        ?>
        <?php if ($remoteUrls === []) { ?>
            <p class="pc-text-muted">No remote skill URLs configured.</p>
        <?php } elseif ($remoteSkills === []) { ?>
            <p class="pc-text-muted">Remote skill URLs configured but none registered. Check they're saved, HTTPS, reachable, and end in <code>.md</code>/<code>.json</code>.</p>
        <?php } else { ?>
        <table class="widefat striped">
            <thead>
                <tr><th>Name</th><th>Description</th><th>Tags</th><th class="pc-col-80">Source</th></tr>
            </thead>
            <tbody>
                <?php foreach ($remoteSkills as $skill) { ?>
                <tr>
                    <td><code><?= esc_html($skill->name()) ?></code></td>
                    <td><small><?= esc_html($skill->description()) ?></small></td>
                    <td><small><?= esc_html(implode(', ', array_map('strval', $skill->tags()))) ?></small></td>
                    <td>remote</td>
                </tr>
                <?php } ?>
            </tbody>
        </table>
        <p class="pc-text-muted"><small>Configured URL(s): <?= esc_html(implode(', ', $remoteUrls)) ?></small></p>
        <?php } ?>

        <?php
    }

    /**
     * Render the REST API tab with endpoint documentation and curl examples.
     *
     * @return void
     */
    private static function renderTabRest(): void
    {
        $baseUrl = rest_url('phpclaw');
        ?>
        <h2 class="pc-mt-0">REST API</h2>
        <p class="pc-text-muted">Integrate phpClaw into any frontend, mobile app, or external service.</p>

        <h3>POST /phpclaw/send</h3>
        <table class="widefat striped pc-table-narrow">
            <tr><th class="pc-col-120">Endpoint</th><td><code><?= esc_html($baseUrl.'/send') ?></code></td></tr>
            <tr><th>Method</th><td><code>POST</code></td></tr>
            <tr><th>Auth</th><td>Application Password <em>or</em> cookie (any logged-in user holding the capability below). <a href="<?= esc_url(admin_url('profile.php#application-passwords-section')) ?>" target="_blank">Create one in your Profile →</a></td></tr>
            <tr><th>Capability</th><td><code>phpclaw_use_chat</code> (Administrators and any role with <code>edit_posts</code>), change via <code>phpclaw_rest_capability</code> filter</td></tr>
            <tr><th>Body</th><td><code>{"message": "your prompt"}</code></td></tr>
        </table>

        <h4 class="pc-mb-6">Request body parameters</h4>
        <table class="widefat striped pc-table-narrow">
            <thead><tr><th class="pc-col-160">Field</th><th class="pc-col-80">Type</th><th class="pc-col-80">Required</th><th>Description</th></tr></thead>
            <tbody>
                <tr><td><code>message</code></td><td>string</td><td>Yes</td><td>The prompt to send to the AI agent.</td></tr>
                <tr><td><code>conversation_id</code></td><td>string</td><td>No</td><td>Pass the <code>conversation_id</code> from a previous response to continue a conversation.</td></tr>
            </tbody>
        </table>

        <strong>curl example, new conversation:</strong>
        <pre class="pc-code-block">curl -X POST <?= esc_html($baseUrl.'/send') ?> \
  -H "Content-Type: application/json" \
  -u "your-username:xxxx xxxx xxxx xxxx xxxx xxxx" \
  -d '{"message": "List the 5 most recent posts"}'</pre>

        <strong>Success (200):</strong>
        <pre class="pc-code-block">{
  "text": "Here are the 5 most recent posts...",
  "provider": "anthropic",
  "model": "claude-haiku-4-5-20251001",
  "tokens": 312,
  "iterations": 2,
  "conversation_id": "01JQABCDE..."
}</pre>

        <strong>Error codes:</strong>
        <table class="widefat striped pc-table-narrow pc-mb-24">
            <thead><tr><th class="pc-col-70">HTTP</th><th class="pc-col-220">Code</th><th>Meaning</th></tr></thead>
            <tbody>
                <tr><td>400</td><td><code>phpclaw_empty_message</code></td><td>Message is empty</td></tr>
                <tr><td>400</td><td><code>phpclaw_empty</code></td><td>Message failed validation as empty</td></tr>
                <tr><td>400</td><td><code>phpclaw_too_long</code></td><td>Message exceeds the 40 000 character limit</td></tr>
                <tr><td>400</td><td><code>phpclaw_invalid_id</code></td><td><code>conversation_id</code> is not a valid format</td></tr>
                <tr><td>403</td><td><code>phpclaw_forbidden</code></td><td>Not authenticated, missing capability, or accessing another user's conversation</td></tr>
                <tr><td>422</td><td><code>phpclaw_guard</code></td><td>Request blocked by security guard</td></tr>
                <tr><td>503</td><td><code>phpclaw_not_configured</code></td><td>No API key set</td></tr>
                <tr><td>500</td><td><code>phpclaw_error</code></td><td>Provider or tool error</td></tr>
            </tbody>
        </table>

        <h3 class="pc-mt-24">Streaming Endpoint (Server-Sent Events)</h3>
        <p>The wp-admin chat UI also exposes a streaming endpoint that emits live tool activity and token chunks as Server-Sent Events. Use it for chat surfaces that want a real-time experience similar to the built-in admin chat.</p>

        <table class="widefat striped pc-table-narrow">
            <tr><th class="pc-col-120">Endpoint</th><td><code><?= esc_html(admin_url('admin-ajax.php')) ?></code></td></tr>
            <tr><th>Method</th><td><code>POST</code> (<code>action=phpclaw_stream</code>)</td></tr>
            <tr><th>Auth</th><td>Logged-in cookie + nonce (<code>phpclaw_stream</code>)</td></tr>
            <tr><th>Capability</th><td><code>phpclaw_use_chat</code> (Administrators and any role with <code>edit_posts</code>)</td></tr>
            <tr><th>Response</th><td><code>text/event-stream</code>, <code>X-Accel-Buffering: no</code>, framed as <code>event:</code> + <code>data:</code> lines separated by <code>\n\n</code></td></tr>
            <tr><th>Body</th><td><code>action=phpclaw_stream&amp;nonce=…&amp;message=…&amp;conversation_id=…</code></td></tr>
        </table>

        <h4 class="pc-mb-6">Event frames</h4>
        <table class="widefat striped pc-table-narrow">
            <thead><tr><th class="pc-col-120">Event</th><th>Payload</th></tr></thead>
            <tbody>
                <tr><td><code>tool_before</code></td><td><code>{tool_name, tool_input}</code>: fires the instant a tool is about to run; use it to render a placeholder card.</td></tr>
                <tr><td><code>tool_after</code></td><td><code>{tool_name, tool_input, tool_result}</code>: fires when a tool returns; swap the placeholder for the real result.</td></tr>
                <tr><td><code>chunk</code></td><td><code>{text}</code>: incremental assistant tokens (only emitted by streaming providers).</td></tr>
                <tr><td><code>done</code></td><td><code>{text, provider, model, tokens, iterations, conversation_id, title, is_new, tool_calls}</code>: final summary, also persisted to memory.</td></tr>
                <tr><td><code>error</code></td><td><code>{message}</code>: fatal error; the stream ends after this frame.</td></tr>
            </tbody>
        </table>

        <strong>Sample event stream:</strong>
        <pre class="pc-code-block">event: tool_before
data: {"tool_name":"wp_query","tool_input":{"action":"query","post_type":"post","posts_per_page":3}}

event: tool_after
data: {"tool_name":"wp_query","tool_input":{"action":"query","post_type":"post","posts_per_page":3},"tool_result":"{\"posts\":[...]}"}

event: done
data: {"text":"Here are the 3 most recent posts...","provider":"ollama","model":"qwen2.5:7b","tokens":2858,"iterations":2,"conversation_id":"01JQ...","tool_calls":[...]}</pre>

        <p class="description"><?= esc_html__('Every provider streams tool tokens live over this endpoint (Anthropic, OpenAI, Gemini, and all OpenAI-compatible providers including Groq, DeepSeek, Mistral, Ollama, and Custom).', 'phpclaw') ?></p>

        <h3 class="pc-mt-24">Streaming REST endpoint</h3>
        <p>Gated by <code>phpclaw_use_chat</code> (Administrators and any role with <code>edit_posts</code>): Application Password or cookie+nonce auth, same as <code>/send</code>. Requests are scoped to the caller's own conversations unless the caller holds <code>phpclaw_manage_all_conversations</code>.</p>
        <table class="widefat striped pc-table-narrow">
            <thead><tr><th class="pc-col-260">Endpoint</th><th class="pc-col-70">Method</th><th>Purpose</th></tr></thead>
            <tbody>
                <tr><td><code><?= esc_html($baseUrl.'/chat/stream') ?></code></td><td><code>POST</code></td><td>SSE chat stream: REST parity for the admin AJAX <code>phpclaw_stream</code> endpoint. Body: <code>{message, conversation_id?}</code>. Same event frames as the streaming section above.</td></tr>
            </tbody>
        </table>

        <?php
    }

    /**
     * Render the WP-CLI tab with a command reference table.
     *
     * @return void
     */
    private static function renderTabCli(): void
    {
        $commands = [
            ['cmd' => 'wp phpclaw send "your prompt"',                          'desc' => 'Send a prompt and print the response. Creates a new conversation owned by nobody, stored with user ID 0.'],
            ['cmd' => 'wp phpclaw send "prompt" --provider=anthropic',           'desc' => 'Override provider for one call.'],
            ['cmd' => 'wp phpclaw send "prompt" --model=claude-opus-4-8',        'desc' => 'Override model for one call.'],
            ['cmd' => 'wp phpclaw send "prompt" --stream',                       'desc' => 'Stream the response token-by-token.'],
            ['cmd' => 'wp phpclaw mcp-server',                                   'desc' => 'Start the phpClaw MCP server (stdio transport).'],
        ];
        ?>
        <h2 class="pc-mt-0">WP-CLI Commands</h2>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th class="pc-col-400">Command</th>
                    <th>Description</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($commands as $row) { ?>
                <tr>
                    <td><code class="pc-code-sm"><?= esc_html($row['cmd']) ?></code></td>
                    <td><?= esc_html($row['desc']) ?></td>
                </tr>
                <?php } ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * Render the Privacy tab describing what the Store Messages setting persists.
     *
     * @return void
     */
    private static function renderTabPrivacy(): void
    {
        ?>
        <h2 class="pc-mt-0">Privacy &amp; Data Storage</h2>
        <p class="pc-text-muted">
            The <strong>Store Messages</strong> setting in <?= self::settingsLink() ?> controls what data phpClaw persists. All data stays on your server, nothing is shared externally unless you enable cloud forwarding.
        </p>

        <?php global $wpdb;
        $prefix = esc_html($wpdb->prefix); ?>
        <table class="widefat striped pc-table-narrow pc-mb-24">
            <thead>
                <tr>
                    <th class="pc-col-240"></th>
                    <th class="pc-col-50pct">Store Messages = <span class="pc-privacy-on">ON</span></th>
                    <th>Store Messages = <span class="pc-privacy-off">OFF</span></th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><strong>Conversation metadata</strong><br><span class="pc-privacy-label">ID, title, timestamps</span></td>
                    <td>✅ Saved to <code><?= $prefix ?>phpclaw_conversations</code></td>
                    <td>❌ <strong>Never saved</strong></td>
                </tr>
                <tr>
                    <td><strong>Message text</strong><br><span class="pc-privacy-label">User prompts and AI replies</span></td>
                    <td>✅ Saved to <code><?= $prefix ?>phpclaw_messages</code></td>
                    <td>❌ <strong>Never saved</strong></td>
                </tr>
                <tr>
                    <td><strong>Tool call inputs/outputs</strong><br><span class="pc-privacy-label">Data returned by tools</span></td>
                    <td>✅ Saved to <code><?= $prefix ?>phpclaw_messages</code></td>
                    <td>❌ <strong>Never saved</strong></td>
                </tr>
            </tbody>
        </table>

        <div class="pc-notice-info pc-mb-24">
            <strong>Note:</strong> When <em>Store Messages</em> is OFF, multi-turn conversations still work within a single request, the agent holds context in memory, but nothing is persisted. Each new request starts fresh.
        </div>

        <div class="pc-notice-info pc-mb-24">
            <strong>phpClaw Cloud:</strong> Request data is forwarded to phpClaw Cloud only when a Cloud Key is set <em>and</em> Store Messages is ON. When Store Messages is OFF, nothing is sent to the cloud. No data is shared with third parties.
        </div>

        <?php
    }

    /**
     * Render the Settings page label, linked only for users allowed to open that page.
     *
     * @return string
     */
    private static function settingsLink(): string
    {
        $label = esc_html__('Settings', 'phpclaw');

        if (! current_user_can('phpclaw_use_admin_chat')) {
            return $label;
        }

        return '<a href="'.esc_url(admin_url('admin.php?page=phpclaw')).'">'.$label.'</a>';
    }
}
