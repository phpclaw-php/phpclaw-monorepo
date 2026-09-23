<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Admin;

use PhpClaw\AutoDiscovery\DiscoveryCache;
use PhpClaw\PrestaShop\Plugin;
use PhpClaw\PrestaShop\PsHookBridge;
use PhpClaw\Providers\ProviderCatalogue;
use PhpClaw\Skills\SkillRegistry;

/**
 * Static helper for the phpClaw Guide admin page: providers, tools, and capability records.
 */
final class GuidePage
{
    private const PROVIDER_META = [
        'anthropic' => [
            'name' => 'Anthropic (Claude)',
            'models' => 'claude-opus-4-8, claude-sonnet-5, claude-haiku-4-5-20251001',
            'signup' => 'https://console.anthropic.com/',
            'notes' => 'Recommended for complex reasoning and tool use.',
        ],
        'openai' => [
            'name' => 'OpenAI (GPT)',
            'models' => 'gpt-4o, gpt-4o-mini, o3-mini',
            'signup' => 'https://platform.openai.com/api-keys',
            'notes' => 'Broad ecosystem support. gpt-4o-mini is fast and cost-effective.',
        ],
        'groq' => [
            'name' => 'Groq',
            'models' => 'llama-3.3-70b-versatile, llama-3.1-8b-instant, mixtral-8x7b-32768',
            'signup' => 'https://console.groq.com/',
            'notes' => 'Extremely fast inference. Free tier available.',
        ],
        'gemini' => [
            'name' => 'Google Gemini',
            'models' => 'gemini-3.5-flash-lite, gemini-3.5-flash, gemini-2.5-pro',
            'signup' => 'https://aistudio.google.com/app/apikey',
            'notes' => 'Large context window. Free tier via Google AI Studio.',
        ],
        'mistral' => [
            'name' => 'Mistral AI',
            'models' => 'mistral-large-latest, mistral-small-latest, codestral-latest',
            'signup' => 'https://console.mistral.ai/',
            'notes' => 'European AI provider. Strong coding models. GDPR-friendly.',
        ],
        'ollama' => [
            'name' => 'Ollama (local)',
            'models' => 'qwen2.5:7b, mistral, phi3, gemma2 (any model you pull)',
            'signup' => 'https://ollama.com/',
            'notes' => 'Runs on your own server. No API key needed. Default endpoint: 127.0.0.1:11434.',
        ],
        'deepseek' => [
            'name' => 'DeepSeek',
            'models' => 'deepseek-flash, deepseek-chat, deepseek-reasoner',
            'signup' => 'https://platform.deepseek.com/',
            'notes' => 'High capability at low cost.',
        ],
        'custom' => [
            'name' => 'Custom',
            'models' => 'Any OpenAI-compatible model',
            'signup' => '',
            'notes' => 'Any OpenAI-compatible endpoint: set Base URL in Settings.',
        ],
    ];

    private const PS_TOOL_META = [
        'ps_product' => ['name' => 'Products',       'description' => 'Query products by category, active status, price, or stock level.',                              'examples' => 'Products under €10 · Find reference ABC123 · Active product count'],
        'ps_order' => ['name' => 'Orders',         'description' => 'List orders by state and date range.',                                                           'examples' => 'Show pending orders · Revenue today · Last 10 orders'],
        'ps_customer' => ['name' => 'Customers',      'description' => 'Query customers by group. Email is blocked from default output and only returned when explicitly requested.',                                                      'examples' => 'How many customers? · Active customers · Customers by group'],
        'ps_category' => ['name' => 'Categories',     'description' => 'Product categories with hierarchy and product counts.',                                          'examples' => 'Category tree · Any empty categories? · Products per category'],
        'ps_stock' => ['name' => 'Stock',          'description' => 'Stock movements and low-stock items.',                                                           'examples' => 'Low stock products · Out of stock items · Stock for product X'],
        'ps_coupon' => ['name' => 'Coupons',        'description' => 'Cart rules and vouchers with usage and expiry.',                                                 'examples' => 'Active vouchers · Any expired rules? · Most used voucher'],
        'ps_module' => ['name' => 'Modules',        'description' => 'Installed modules with active/inactive status.',                                                 'examples' => 'Which modules are active? · Any disabled modules?'],
        'ps_report' => ['name' => 'Reports',        'description' => 'Revenue and order count by day, week, or month.',                                                'examples' => 'Revenue this month · Orders this week · Sales by day'],
        'ps_config' => ['name' => 'Configuration',  'description' => 'Read configuration values. Sensitive keys are always blocked.',                                  'examples' => 'Default currency? · Is maintenance mode on?'],
        'ps_employee' => ['name' => 'Employees',      'description' => 'Back-office employees with profile and active status. Emails masked.',                           'examples' => 'How many employees? · List back-office admins'],
        'ps_cart' => ['name' => 'Carts',          'description' => 'Active and abandoned shopping carts.',                                                           'examples' => 'Any abandoned carts? · Active carts right now'],
        'ps_manufacturer' => ['name' => 'Manufacturers',  'description' => 'Brands/manufacturers with product counts.',                                                      'examples' => 'List manufacturers · Products per brand'],
        'database' => ['name' => 'Database',       'description' => 'Read-only SELECT queries against the PrestaShop database.',                                      'examples' => 'How many rows in ps_orders? · Count products by category'],
        'ps_log' => ['name' => 'Log',            'description' => 'Read the PrestaShop error log.',                                                                 'examples' => 'Show recent errors · Last 20 log lines'],
    ];

    private const CORE_TOOL_META = [
        'HttpTool' => ['status' => 'Ready', 'notes' => 'Fetch an HTTP response (GET/POST) from a public URL'],
        'FileReadTool' => ['status' => 'Ready', 'notes' => 'Read files inside the workspace directory'],
        'FileWriteTool' => ['status' => 'Ready', 'notes' => 'Write files inside the workspace directory'],
        'ShellTool' => ['status' => 'Ready', 'notes' => 'Default allowlisted commands'],
        'CodeSearchTool' => ['status' => 'Ready', 'notes' => 'Search for text across files in the workspace directory'],
        'FileEditTool' => ['status' => 'Ready', 'notes' => 'Apply a targeted find-and-replace edit to a workspace file'],
        'ProjectTool' => ['status' => 'Ready', 'notes' => 'Detect framework, Composer packages, and file tree (read-only)'],
    ];

    /**
     * Provider rows for the Guide providers tab, enriched with models/signup/notes.
     *
     * @return list<array{key: string, name: string, models: string, signup: string, notes: string}>
     */
    public static function guideProviders(): array
    {
        $catalogueClass = ProviderCatalogue::class;

        if (! class_exists($catalogueClass)) {
            $rows = [];
            foreach (self::PROVIDER_META as $slug => $m) {
                $rows[] = ['key' => $slug, 'name' => $m['name'], 'models' => $m['models'], 'signup' => $m['signup'], 'notes' => $m['notes']];
            }

            return $rows;
        }

        $rows = [];
        foreach (ProviderCatalogue::all() as $slug => $info) {
            $slug = (string) $slug;
            $m = self::PROVIDER_META[$slug] ?? [];
            $rows[] = [
                'key' => $slug,
                'name' => (string) ($m['name'] ?? $info['label'] ?: ucfirst($slug)),
                'models' => (string) ($m['models'] ?? ''),
                'signup' => (string) ($m['signup'] ?? ''),
                'notes' => (string) ($m['notes'] ?? ''),
            ];
        }

        return $rows;
    }

    /**
     * Native PS tools plus external contributions from `phpclaw/extra/tools`, filtered by `tool_deny`.
     *
     * @param  Plugin  $plugin  Booted plugin instance.
     * @return list<array{name: string, description: string, examples: string}>
     */
    public static function guideToolRows(Plugin $plugin): array
    {
        try {
            $live = $plugin->guideTools();
        } catch (\Throwable) {
            $live = null;
        }

        if ($live === null) {
            $rows = [];
            foreach (self::PS_TOOL_META as $m) {
                $rows[] = ['name' => $m['name'], 'description' => $m['description'], 'examples' => $m['examples']];
            }

            foreach (self::fireExtraEvent('phpclaw/extra/tools', []) as $tool) {
                if (! is_object($tool)) {
                    continue;
                }
                $rows[] = [
                    'name' => method_exists($tool, 'name') ? (string) $tool->name() : self::shortName($tool::class),
                    'description' => method_exists($tool, 'description') ? (string) $tool->description() : '',
                    'examples' => '',
                ];
            }

            return $rows;
        }

        $rows = [];
        foreach ($live as $tool) {
            if (! is_object($tool)) {
                continue;
            }

            $class = $tool::class;
            if (! str_contains($class, '@anonymous') && str_starts_with($class, 'PhpClaw\\Tools\\')) {
                continue;
            }

            $key = method_exists($tool, 'name') ? (string) $tool->name() : self::shortName($class);
            $m = self::PS_TOOL_META[$key] ?? null;
            $rows[] = [
                'name' => $m['name'] ?? $key,
                'description' => $m['description'] ?? (method_exists($tool, 'description') ? (string) $tool->description() : ''),
                'examples' => $m['examples'] ?? '',
            ];
        }

        return $rows;
    }

    /**
     * Core utility tools from DiscoveryCache where default===true and class is in PhpClaw\Tools\.
     *
     * @return list<array{tool: string, status: string, notes: string}>
     */
    public static function getCoreUtilityTools(): array
    {
        try {
            $cache = DiscoveryCache::load();
        } catch (\Throwable) {
            return self::coreUtilityToolsFallback();
        }

        $rows = [];
        foreach ($cache['tools'] ?? [] as $class => $attr) {
            $class = (string) $class;
            if (empty($attr['default'])) {
                continue;
            }
            if (! str_starts_with($class, 'PhpClaw\\Tools\\')) {
                continue;
            }
            $base = self::shortName($class);
            $m = self::CORE_TOOL_META[$base] ?? [];
            $rows[] = [
                'tool' => $base,
                'status' => (string) ($m['status'] ?? 'Ready'),
                'notes' => (string) ($m['notes'] ?? ''),
            ];
        }

        if ($rows === []) {
            return self::coreUtilityToolsFallback();
        }

        usort($rows, static fn (array $a, array $b): int => strcmp($a['tool'], $b['tool']));

        return $rows;
    }

    /**
     * Auto-discovered memory drivers plus external contributions from `phpclaw/extra/memory`.
     *
     * @return list<array{driver: string, label: string, source: string, class: string}>
     */
    public static function getMemoryDrivers(): array
    {
        try {
            $cache = DiscoveryCache::load();
        } catch (\Throwable) {
            return [];
        }

        $drivers = [];
        foreach ($cache['memory'] ?? [] as $class => $attr) {
            $class = (string) $class;
            $drivers[] = [
                'driver' => (string) ($attr['driver'] ?? ''),
                'label' => (string) ($attr['label'] ?? $class),
                'source' => str_starts_with($class, 'PhpClaw\\PrestaShop') ? 'prestashop' : 'core',
                'class' => $class,
            ];
        }

        foreach (self::fireExtraEvent('phpclaw/extra/memory', []) as $slug => $factory) {
            if (! is_string($slug) || $slug === '') {
                continue;
            }
            $drivers[] = [
                'driver' => $slug,
                'label' => $slug,
                'source' => 'prestashop',
                'class' => '',
            ];
        }

        return $drivers;
    }

    /**
     * Auto-discovered guards plus external contributions from `phpclaw/extra/guards`.
     *
     * @return list<array{name: string, label: string, priority: int, enabled_by_default: bool, source: string, class: string}>
     */
    public static function getDiscoveredGuards(): array
    {
        try {
            $cache = DiscoveryCache::load();
        } catch (\Throwable) {
            return [];
        }

        $records = [];
        foreach ($cache['guards'] ?? [] as $class => $attr) {
            $class = (string) $class;
            $records[] = [
                'name' => (string) ($attr['name'] ?? ''),
                'label' => (string) ($attr['label'] ?? $class),
                'priority' => (int) ($attr['priority'] ?? 0),
                'enabled_by_default' => (bool) ($attr['enabledByDefault'] ?? false),
                'source' => str_starts_with($class, 'PhpClaw\\PrestaShop') ? 'prestashop' : 'core',
                'class' => $class,
            ];
        }

        foreach (self::fireExtraEvent('phpclaw/extra/guards', []) as $guard) {
            if (! is_object($guard)) {
                continue;
            }
            $records[] = [
                'name' => self::shortName($guard::class),
                'label' => self::shortName($guard::class),
                'priority' => 0,
                'enabled_by_default' => true,
                'source' => 'prestashop',
                'class' => $guard::class,
            ];
        }

        return $records;
    }

    /**
     * Auto-discovered hook listeners plus external contributions from `phpclaw/extra/hooks`.
     *
     * @return list<array{event: string, name: string, priority: int, enabled_by_default: bool, source: string, class: string}>
     */
    public static function getDiscoveredHooks(): array
    {
        try {
            $cache = DiscoveryCache::load();
        } catch (\Throwable) {
            return [];
        }

        $records = [];
        foreach ($cache['hooks'] ?? [] as $class => $listeners) {
            $class = (string) $class;
            $source = str_starts_with($class, 'PhpClaw\\PrestaShop') ? 'prestashop' : 'core';
            foreach ((array) $listeners as $listener) {
                $records[] = [
                    'event' => (string) ($listener['event'] ?? ''),
                    'name' => (string) ($listener['name'] ?? ''),
                    'priority' => (int) ($listener['priority'] ?? 0),
                    'enabled_by_default' => (bool) ($listener['enabledByDefault'] ?? false),
                    'source' => $source,
                    'class' => $class,
                ];
            }
        }

        foreach (self::fireExtraEvent('phpclaw/extra/hooks', []) as $entry) {
            if (! is_array($entry) || ! isset($entry['event'])) {
                continue;
            }
            $handler = $entry['handler'] ?? null;
            $hClass = is_array($handler) ? ($handler[0] ?? '') : $handler;
            $hClass = is_object($hClass) ? $hClass::class : (string) $hClass;
            $hMethod = is_array($handler) ? (string) ($handler[1] ?? '') : '';
            $records[] = [
                'event' => (string) $entry['event'],
                'name' => $hMethod !== '' ? $hMethod : self::shortName($hClass),
                'priority' => (int) ($entry['priority'] ?? 10),
                'enabled_by_default' => true,
                'source' => 'prestashop',
                'class' => $hClass,
            ];
        }

        return $records;
    }

    /**
     * Auto-discovered skills plus external contributions from `phpclaw/extra/skills`.
     *
     * @return list<array{name: string, label: string, keywords: list<string>, source: string, class: string}>
     */
    public static function getDiscoveredSkills(): array
    {
        try {
            $cache = DiscoveryCache::load();
        } catch (\Throwable) {
            return [];
        }

        $records = [];
        foreach ($cache['skills'] ?? [] as $class => $attr) {
            $class = (string) $class;
            $records[] = [
                'name' => (string) ($attr['name'] ?? ''),
                'label' => (string) ($attr['label'] ?? $class),
                'keywords' => array_values(array_map('strval', (array) ($attr['keywords'] ?? []))),
                'source' => str_starts_with($class, 'PhpClaw\\PrestaShop') ? 'prestashop' : 'core',
                'class' => $class,
            ];
        }

        foreach (self::fireExtraEvent('phpclaw/extra/skills', []) as $skill) {
            if (! is_object($skill)) {
                continue;
            }
            $name = method_exists($skill, 'name') ? (string) $skill->name() : self::shortName($skill::class);
            $records[] = [
                'name' => $name,
                'label' => $name,
                'keywords' => method_exists($skill, 'tags') ? array_values(array_map('strval', (array) $skill->tags())) : [],
                'source' => 'prestashop',
                'class' => $skill::class,
            ];
        }

        return $records;
    }

    /**
     * Configured `remote_skill_urls`, or [] when none are set.
     *
     * @param  Plugin  $plugin  Booted plugin instance.
     * @return list<string>
     */
    public static function getRemoteSkillUrls(Plugin $plugin): array
    {
        return array_values(array_map('strval', (array) ($plugin->saved()['remote_skill_urls'] ?? [])));
    }

    /**
     * Skills registered via `remote_skill_urls`, diffed against getDiscoveredSkills() since AutoDiscovery never sees engine-build-time skills.
     *
     * @param  Plugin  $plugin  Booted plugin instance.
     * @return list<array{name: string, description: string, keywords: list<string>}>
     */
    public static function getRemoteSkills(Plugin $plugin): array
    {
        if (self::getRemoteSkillUrls($plugin) === []) {
            return [];
        }

        try {
            $plugin->engine();
        } catch (\Throwable) {
            return [];
        }

        if (! class_exists(SkillRegistry::class)) {
            return [];
        }

        $known = [];
        foreach (self::getDiscoveredSkills() as $s) {
            $known[$s['name']] = true;
        }

        $records = [];
        foreach (SkillRegistry::all() as $skill) {
            if (isset($known[$skill->name()])) {
                continue;
            }
            $records[] = [
                'name' => $skill->name(),
                'description' => $skill->description(),
                'keywords' => array_map('strval', $skill->tags()),
            ];
        }

        return $records;
    }

    /**
     * Fire a phpClaw extension event via PrestaShop hooks and return the merged bucket.
     *
     * @param  string  $eventName  e.g. `phpclaw/extra/tools`
     * @param  array<mixed>  $bucket  Initial accumulator.
     * @return array<mixed>
     */
    public static function fireExtraEvent(string $eventName, array $bucket): array
    {
        return PsHookBridge::fireExtra($eventName, $bucket);
    }

    /**
     * Fallback core utility tool rows when DiscoveryCache is unavailable.
     *
     * @return list<array{tool: string, status: string, notes: string}>
     */
    private static function coreUtilityToolsFallback(): array
    {
        $rows = [];
        foreach (self::CORE_TOOL_META as $tool => $m) {
            $rows[] = ['tool' => $tool, 'status' => $m['status'], 'notes' => $m['notes']];
        }

        return $rows;
    }

    /**
     * Class basename from a fully-qualified class name.
     *
     * @param  string  $class
     * @return string
     */
    private static function shortName(string $class): string
    {
        $pos = strrchr($class, '\\');

        return $pos === false ? $class : substr($pos, 1);
    }
}
