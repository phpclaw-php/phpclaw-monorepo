<?php

declare(strict_types=1);

namespace PhpClaw\WordPress\Admin;

/**
 * phpClaw - About / Marketing page.
 */
final class AboutPage
{
    /**
     * Register the About submenu page under the phpClaw admin menu.
     *
     * @return void
     */
    public static function register(): void
    {
        add_submenu_page(
            parent_slug: 'phpclaw',
            page_title: 'phpClaw About',
            menu_title: 'About',
            capability: 'phpclaw_use_chat',
            menu_slug: 'phpclaw-about',
            callback: [self::class, 'render'],
        );
    }

    /**
     * Render the About admin page with marketing content and package listing, refusing
     * anyone without phpclaw_use_chat.
     *
     * @return void
     */
    public static function render(): void
    {
        if (! current_user_can('phpclaw_use_chat')) {
            wp_die(esc_html__('Insufficient permissions.', 'phpclaw'));
        }
        ?>
        <div class="phpclaw-about-wrap">

            <div class="pc-about-hero">
                <div class="pc-about-hero-brand">
                    <div class="pc-about-hero-logo">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#fff" width="38" height="38">
                            <path d="M12 2a2 2 0 0 1 2 2 2 2 0 0 1-2 2 2 2 0 0 1-2-2 2 2 0 0 1 2-2m0 5c2.67 0 8 1.34 8 4v2H4v-2c0-2.66 5.33-4 8-4z"/>
                            <rect x="3" y="14" width="18" height="8" rx="2"/>
                            <circle cx="8.5" cy="18" r="1.5" fill="#7c3aed"/>
                            <circle cx="15.5" cy="18" r="1.5" fill="#7c3aed"/>
                            <rect x="10.5" y="16.5" width="3" height="1" rx=".5" fill="#7c3aed"/>
                        </svg>
                    </div>
                    <div>
                        <h1 class="pc-about-hero-title">phpClaw</h1>
                        <p class="pc-about-hero-subtitle">Universal AI agent engine for WordPress</p>
                        <p class="pc-about-hero-version">Version <?= esc_html(defined('PHPCLAW_VERSION') ? PHPCLAW_VERSION : 'unknown') ?></p>
                    </div>
                </div>
                <nav class="pc-about-hero-nav" aria-label="<?= esc_attr__('phpClaw links', 'phpclaw') ?>">
                    <a href="https://phpclaw.ai" target="_blank" rel="noopener" class="pc-about-hero-btn pc-about-hero-btn--primary">
                        🌐 phpclaw.ai
                    </a>
                    <a href="https://phpclaw.ai/docs" target="_blank" rel="noopener" class="pc-about-hero-btn pc-about-hero-btn--ghost">
                        📖 Documentation
                    </a>
                    <a href="https://github.com/phpclaw-php/phpclaw-monorepo" target="_blank" rel="noopener" class="pc-about-hero-btn pc-about-hero-btn--ghost">
                        ⭐ GitHub
                    </a>
                </nav>
            </div>

            <div class="pc-about-body">

                <div class="pc-about-card">
                    <h2><?= esc_html__('What is phpClaw?', 'phpclaw') ?></h2>
                    <p>
                        phpClaw is an AI assistant that lives inside your WordPress admin. Connect any AI provider: Anthropic Claude, OpenAI GPT, Groq, Google Gemini, Mistral, DeepSeek, Ollama (local), Custom (any OpenAI-compatible endpoint), and more via extensions. Ask questions about your site in plain English.
                    </p>
                    <p>
                        No prompts to memorize. No SQL to write. No dashboards to learn. Just ask: <em>"Any pending comments?"</em>, <em>"Which plugins are active?"</em>, <em>"Show recent errors"</em>. Get real answers from real data.
                    </p>
                    <p>
                        Part of the phpClaw ecosystem, the same AI engine available for Laravel, Drupal, Magento, and other PHP frameworks and CMS platforms.
                    </p>
                </div>

                <h2><?= esc_html__('Built-in features', 'phpclaw') ?></h2>
                <div class="phpclaw-features-grid">
                    <?php
                    $wcActive = class_exists('WooCommerce');
        $wpToolNames = ['Posts', 'Users', 'Plugins', 'Menus', 'Media', 'Comments', 'Taxonomies',
            'Cron', 'Options', 'Database', 'Logs', 'HTTP', 'File Read', 'File Write', 'File Edit',
            'Shell', 'Code Search', 'Project Info', 'Zip Package', 'Plugin Zip Builder'];
        $wcToolNames = ['Orders', 'Products', 'Customers', 'Reports', 'Stock', 'Coupons',
            'Categories', 'Reviews', 'Shipping', 'Tax'];
        $toolCount = (string) (count($wpToolNames) + ($wcActive ? count($wcToolNames) : 0));
        $features = [
            ['icon' => '🤖', 'title' => '8 AI Providers',     'text' => 'Anthropic Claude, OpenAI GPT, Groq, Google Gemini, Mistral, DeepSeek, Ollama (local), Custom (any OpenAI-compatible endpoint). Switch from Settings, zero code changes.'],
            ['icon' => '🛠',  'title' => $toolCount.' Built-in Tools', 'text' => $wcActive
                ? 'WordPress: '.implode(', ', $wpToolNames).'. WooCommerce: '.implode(', ', $wcToolNames).'.'
                : implode(', ', $wpToolNames).'. Install WooCommerce to unlock '.count($wcToolNames).' more commerce tools.'],
            ['icon' => '🎚', 'title' => 'Adaptive Tool Profiles', 'text' => 'Hosted models (Anthropic, OpenAI, Gemini, Mistral, DeepSeek, Custom) receive the full tool set. Local providers (Ollama, Groq) receive a focused subset so smaller models stay reliable: 8 tools for 30B class models and above, otherwise 5.'],
            ['icon' => '💬', 'title' => 'Chat UI',             'text' => 'Chat-style conversation interface in wp-admin. Conversation history, searchable sidebar, multi-turn context. Ask anything, and the agent picks the right tool.'],
            ['icon' => '⚡', 'title' => 'Live Tool Streaming', 'text' => 'See the agent work in real time via Server-Sent Events. Spinner placeholders appear the moment a tool fires, typed result cards (posts, users, plugins, SQL tables) replace them as soon as data is back, and the assistant reply streams token-by-token.'],
            ['icon' => '🔌', 'title' => 'REST API',           'text' => 'POST /wp-json/phpclaw/send to integrate the AI agent into any frontend, mobile app, chatbot, or external service.'],
            ['icon' => '🖥',  'title' => 'WP-CLI',            'text' => 'wp phpclaw send, wp phpclaw mcp-server: full terminal access. Script automations, run in CI/CD, or pipe output.'],
            ['icon' => '🔒', 'title' => 'Enterprise-Grade Security',  'text' => 'Multi-layered protection against prompt injection, unsafe commands, and data leakage. Your data stays safe. Your agent stays under control.'],
        ];
        foreach ($features as $f) { ?>
                    <div class="pc-feature-card">
                        <div class="pc-feature-card-icon"><?= esc_html($f['icon']) ?></div>
                        <strong class="pc-feature-card-title"><?= esc_html($f['title']) ?></strong>
                        <p class="pc-feature-card-text"><?= esc_html($f['text']) ?></p>
                    </div>
                    <?php } ?>
                </div>

                <div class="pc-about-card">
                    <h2><?= esc_html__('Installable packages', 'phpclaw') ?></h2>
                    <p class="pc-about-pkg-intro">MIT-licensed. Available as separate packages:</p>
                    <table class="pc-about-pkg-table">
                        <thead>
                            <tr>
                                <th><?= esc_html__('Package', 'phpclaw') ?></th>
                                <th><?= esc_html__('Installed', 'phpclaw') ?></th>
                                <th><?= esc_html__('What it adds', 'phpclaw') ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                $packages = [
                    ['phpclaw/phpclaw',            'PhpClaw\\Agent\\Agent',          'Core AI agent engine: providers, tools, guards, hooks, memory, skills all included. Powers every phpClaw adapter.'],
                    ['phpclaw/phpclaw-wordpress',  'PhpClaw\\WordPress\\Plugin',     'This plugin.'],
                    ['phpclaw/phpclaw-cloud',      'PhpClaw\\Cloud\\CloudManager',   'Cloud transport: lifecycle event forwarding and webhook delivery to the phpClaw Cloud service.'],
                    ['phpclaw/phpclaw-mcp',        'PhpClaw\\Mcp\\PhpClawMcpServer', 'MCP server: expose your tools to Claude Desktop, Cursor, and other MCP clients.'],
                ];
        foreach ($packages as [$pkg, $sentinel, $desc]) {
            $installed = class_exists($sentinel);
            $statusLabel = $installed
                ? esc_html__('Installed', 'phpclaw')
                : esc_html__('Not installed', 'phpclaw');
            $statusIcon = $installed ? '✓' : '✗';
            ?>
                            <tr>
                                <td><code><?= esc_html($pkg) ?></code></td>
                                <td><span class="pc-about-pkg-status pc-about-pkg-status-<?= $installed ? 'installed' : 'missing' ?>" aria-label="<?= $statusLabel ?>"><?= $installed ? '&#10003;' : '&#10007;' ?></span></td>
                                <td><?= esc_html($desc) ?></td>
                            </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>

                <div class="pc-about-card pc-about-enterprise-cta">
                    <h2><?= esc_html__('Need more? phpClaw Enterprise', 'phpclaw') ?></h2>
                    <p>
                        <?= esc_html__('Looking for custom AI tools tailored to your site? Need to integrate phpClaw with your CRM, ERP, or internal systems? phpClaw Enterprise offers dedicated support, custom tool development, and managed onboarding.', 'phpclaw') ?>
                    </p>
                    <a href="https://phpclaw.ai/enterprise" target="_blank" rel="noopener" class="button button-primary pc-enterprise-btn">
                        <?= esc_html__('Learn about phpClaw Enterprise →', 'phpclaw') ?>
                    </a>
                </div>

                <?php AdminFooter::renderCommunityCard(); ?>

            </div>
        </div>
        <?php
    }
}
