<?php

declare(strict_types=1);

namespace PhpClaw\OpenCart\Admin;

use PhpClaw\AutoDiscovery\DiscoveryCache;
use PhpClaw\ClawConfig;
use PhpClaw\OpenCart\OcEventFirer;
use PhpClaw\OpenCart\Plugin;
use PhpClaw\Providers\ProviderCatalogue;
use PhpClaw\Skills\SkillRegistry;

/**
 * Settings page helper for the phpClaw OpenCart admin module.
 */
final class SettingsPage
{
    /**
     * Supported AI providers for the provider dropdown.
     *
     * @return array<string, string>
     */
    public static function providers(): array
    {
        if (! class_exists(ProviderCatalogue::class)) {
            return ['' => 'Select a provider'];
        }
        $options = ['' => 'Select a provider'];
        foreach (ProviderCatalogue::all() as $slug => $info) {
            $options[$slug] = (string) ($info['label'] ?? $slug);
        }

        return $options;
    }

    /**
     * Provider rows for the Guide providers tab (dynamic from the catalogue).
     *
     * @return list<array{name: string, key: string, signup: string, notes: string}>
     */
    public static function guideProviders(): array
    {
        if (! class_exists(ProviderCatalogue::class)) {
            return [];
        }

        $meta = [
            'anthropic' => ['name' => 'Anthropic',     'signup' => 'https://console.anthropic.com/',         'notes' => 'Claude Haiku, Sonnet, Opus'],
            'openai' => ['name' => 'OpenAI',         'signup' => 'https://platform.openai.com/api-keys',   'notes' => 'GPT-4o, GPT-4o-mini, o1'],
            'groq' => ['name' => 'Groq',           'signup' => 'https://console.groq.com/',              'notes' => 'Llama 3.1, 3.3, Mixtral - fast inference'],
            'gemini' => ['name' => 'Google Gemini',  'signup' => 'https://aistudio.google.com/app/apikey', 'notes' => 'Gemini 2.0 Flash, 1.5 Pro'],
            'mistral' => ['name' => 'Mistral',        'signup' => 'https://console.mistral.ai/',            'notes' => 'Mistral Large, Small, Nemo'],
            'deepseek' => ['name' => 'DeepSeek',       'signup' => 'https://platform.deepseek.com/',         'notes' => 'DeepSeek-V3, DeepSeek-R1'],
            'ollama' => ['name' => 'Ollama',         'signup' => 'https://ollama.com/',                    'notes' => 'Local / self-hosted. No API key needed. Default host: 127.0.0.1:11434.'],
            'custom' => ['name' => 'Custom',         'signup' => '',                                       'notes' => 'Any OpenAI-compatible endpoint. Set Base URL in Settings.'],
        ];

        $rows = [];
        foreach (ProviderCatalogue::all() as $slug => $info) {
            $slug = (string) $slug;
            $m = $meta[$slug] ?? [];
            $rows[] = [
                'name' => (string) ($m['name'] ?? ($info['label'] ?? '') ?: ucfirst($slug)),
                'key' => $slug,
                'signup' => (string) ($m['signup'] ?? ''),
                'notes' => (string) ($m['notes'] ?? ''),
            ];
        }

        return $rows;
    }

    /**
     * Auto-discovered core utility tools (#[Tool(default: true)]).
     *
     * @return list<array{tool: string, desc: string, deprecated: bool}>
     */
    public static function coreUtilityTools(): array
    {
        try {
            $cache = DiscoveryCache::load();
        } catch (\Throwable) {
            return [];
        }

        $rows = [];
        foreach ($cache['tools'] ?? [] as $class => $attr) {
            if (empty($attr['default'])) {
                continue;
            }
            $rows[] = [
                'tool' => self::shortName((string) $class),
                'desc' => (string) ($attr['description'] ?? ''),
                'deprecated' => ! empty($attr['deprecated']),
            ];
        }
        usort($rows, static fn (array $a, array $b): int => strcmp($a['tool'], $b['tool']));

        return $rows;
    }

    /**
     * Built-in + module tool rows for the Guide tools tab.
     *
     * @param  array<int, object>  $tools  Live engine tool instances.
     * @return list<array{name: string, description: string, prompts: string}>
     */
    public static function guideToolRows(array $tools): array
    {
        $meta = [
            'OcProductTool' => ['name' => 'Product Tool',      'desc' => 'Query products by name, SKU, price, category, and stock status.',        'prompts' => 'Show low stock products · Find SKU ABC · Products under $20'],
            'OcOrderTool' => ['name' => 'Order Tool',        'desc' => 'Query orders by status, date range, and customer. Revenue totals.',      'prompts' => 'Pending orders today · Revenue this month · Last 10 orders'],
            'OcCustomerTool' => ['name' => 'Customer Tool',     'desc' => 'Query customers by name, email, group. No passwords exposed.',           'prompts' => 'New customers this week · Top buyers · Wholesale group'],
            'OcCategoryTool' => ['name' => 'Category Tool',     'desc' => 'Product categories with hierarchy and product counts.',                  'prompts' => 'List top-level categories · Any empty categories?'],
            'OcCouponTool' => ['name' => 'Coupon Tool',       'desc' => 'Active and expired coupons with usage counts and discount amounts.',     'prompts' => 'Active coupons · Most used coupon · Any expired?'],
            'OcReviewTool' => ['name' => 'Review Tool',       'desc' => 'Product reviews with ratings and approval status. No emails exposed.',   'prompts' => 'Pending reviews · 1-star reviews · Average rating'],
            'OcManufacturerTool' => ['name' => 'Manufacturer Tool', 'desc' => 'Brands with product counts and sort order.',                            'prompts' => 'List all brands · Products per brand · Top manufacturer'],
            'OcShippingTool' => ['name' => 'Shipping Tool',     'desc' => 'Shipping zones, geo-zones, methods, and rates.',                         'prompts' => 'Shipping zones · Free shipping rules · Rates for Australia'],
            'DatabaseTool' => ['name' => 'Database Tool',     'desc' => 'Read-only SELECT queries against any OpenCart table. Auto-LIMIT 200.',   'prompts' => 'Count rows in oc_order · Show table sizes'],
            'LogTool' => ['name' => 'Log Tool',          'desc' => 'Read the last N lines of the OpenCart error log.',                       'prompts' => 'Show recent errors · Any fatal errors today?'],
        ];

        $rows = [];
        foreach ($tools as $tool) {
            if (! is_object($tool)) {
                continue;
            }
            $class = $tool::class;
            if (str_starts_with($class, 'PhpClaw\\Tools\\')) {
                continue;
            }
            $base = self::shortName($class);
            $m = $meta[$base] ?? [];
            $rows[] = [
                'name' => (string) ($m['name'] ?? (method_exists($tool, 'name') ? $tool->name() : $base)),
                'description' => (string) ($m['desc'] ?? (method_exists($tool, 'description') ? $tool->description() : '')),
                'prompts' => (string) ($m['prompts'] ?? ''),
            ];
        }

        return $rows;
    }

    /**
     * Validate and sanitize raw POST data.
     *
     * @param  array<string, mixed>  $post
     * @param  array<string, mixed>  $current  Current saved settings, used to preserve masked secret fields left blank.
     * @return array{errors: array<string, string>, data: array<string, mixed>}
     */
    public static function validate(array $post, array $current = []): array
    {
        $errors = [];
        $data = [];

        $data['provider'] = trim((string) ($post['provider'] ?? ''));
        $data['model'] = trim((string) ($post['model'] ?? ''));
        $data['api_key'] = self::preserveSecret($post['api_key'] ?? '', $current['api_key'] ?? '');

        $maxIter = (int) ($post['max_iterations'] ?? ClawConfig::DEFAULT_MAX_ITERATIONS);
        if ($maxIter < 1 || $maxIter > 50) {
            $errors['max_iterations'] = 'Max iterations must be between 1 and 50.';
            $maxIter = ClawConfig::DEFAULT_MAX_ITERATIONS;
        }
        $data['max_iterations'] = $maxIter;

        $baseUrl = $data['provider'] === 'custom' ? trim((string) ($post['base_url'] ?? '')) : '';
        if ($baseUrl !== '' && (filter_var($baseUrl, FILTER_VALIDATE_URL) === false || preg_match('~^https?://~i', $baseUrl) !== 1)) {
            $errors['base_url'] = 'Base URL must be a full http(s) OpenAI-compatible endpoint URL.';
            $baseUrl = '';
        }
        $data['base_url'] = $baseUrl;

        if (isset($post['store_messages'])) {
            $sm = $post['store_messages'];
            $on = $sm === '1' || $sm === 'true';
            $data['store_messages'] = $on ? '1' : '0';
        } else {
            $data['store_messages'] = '0';
        }

        $data['system_prompt'] = mb_substr(trim((string) ($post['system_prompt'] ?? '')), 0, 8000);
        $data['cloud_key'] = self::preserveSecret($post['cloud_key'] ?? '', $current['cloud_key'] ?? '');
        $data['cloud_signing_secret'] = self::preserveSecret($post['cloud_signing_secret'] ?? '', $current['cloud_signing_secret'] ?? '');

        if (isset($post['cloud_disable'])) {
            $raw = $post['cloud_disable'];
            $items = is_array($raw)
                ? array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $raw)
                : explode(',', (string) $raw);
            $data['cloud_disable'] = array_values(array_filter(
                array_map(static fn (string $s): string => trim($s), $items),
                static fn (string $s): bool => $s !== '',
            ));
        } else {
            $data['cloud_disable'] = (array) ($current['cloud_disable'] ?? []);
        }

        $data['remote_skill_urls'] = self::normaliseRemoteSkillUrls($post['remote_skill_urls'] ?? null);

        return ['errors' => $errors, 'data' => $data];
    }

    /**
     * Build the settings array for the admin form from admin-saved values.
     *
     * @param  array<string, mixed>  $saved  Admin-saved settings (native oc_setting store).
     * @return array<string, mixed>
     */
    public static function merge(array $saved): array
    {
        $storeMessages = isset($saved['store_messages'])
            ? ($saved['store_messages'] === '1')
            : true;

        return [
            'provider' => (string) ($saved['provider'] ?? ''),
            'model' => (string) ($saved['model'] ?? ''),
            'api_key' => (string) ($saved['api_key'] ?? ''),
            'max_iterations' => (int) ($saved['max_iterations'] ?? 0) ?: ClawConfig::DEFAULT_MAX_ITERATIONS,
            'base_url' => ($saved['provider'] ?? '') === 'custom' ? (string) ($saved['base_url'] ?? '') : '',
            'store_messages' => $storeMessages ? '1' : '0',
            'system_prompt' => (string) ($saved['system_prompt'] ?? ''),
            'cloud_key' => (string) ($saved['cloud_key'] ?? ''),
            'cloud_signing_secret' => (string) ($saved['cloud_signing_secret'] ?? ''),
            'cloud_disable' => self::normaliseCloudDisable($saved['cloud_disable'] ?? null),
            'remote_skill_urls' => self::normaliseRemoteSkillUrls($saved['remote_skill_urls'] ?? null),
        ];
    }

    /**
     * Normalise a raw cloud_disable value into a list of trimmed non-empty strings.
     *
     * @param  mixed  $raw  Raw value from form/import (string, array, or null).
     * @return list<string>
     */
    public static function normaliseCloudDisable(mixed $raw): array
    {
        if ($raw === null || $raw === '' || $raw === 'Array') {
            return [];
        }
        $items = is_array($raw)
            ? array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $raw)
            : explode(',', (string) $raw);

        return array_values(array_filter(
            array_map(static fn (string $s): string => trim($s), $items),
            static fn (string $s): bool => $s !== '' && $s !== 'Array',
        ));
    }

    /**
     * Normalise remote skill URLs to a list of trimmed https:// strings.
     *
     * @param  mixed  $raw  Raw stored/posted value (array or newline/comma text).
     * @return list<string>
     */
    public static function normaliseRemoteSkillUrls(mixed $raw): array
    {
        if ($raw === null || $raw === '' || $raw === 'Array') {
            return [];
        }
        $items = is_array($raw)
            ? array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $raw)
            : (preg_split('/[\r\n,]+/', (string) $raw) ?: []);

        $valid = array_values(array_filter(
            array_map(static fn (string $s): string => trim($s), $items),
            static fn (string $s): bool => $s !== '' && preg_match('~^https://[^\s]+\.(md|json)(\?[^\s]*)?$~i', $s) === 1,
        ));

        return array_slice($valid, 0, 50);
    }

    /**
     * Build the capability_records array for the Guide page.
     *
     * @param  OcEventFirer|null  $eventFirer  Optional event firer for boot-time subsystem extensions.
     * @return array{memory: list<array<string,mixed>>, skills: list<array<string,mixed>>, guards: list<array<string,mixed>>, hooks: list<array<string,mixed>>}
     */
    public static function buildCapabilityRecords(?OcEventFirer $eventFirer = null): array
    {
        $cache = DiscoveryCache::load();
        $records = ['memory' => [], 'skills' => [], 'guards' => [], 'hooks' => []];

        foreach ($cache['memory'] ?? [] as $class => $attr) {
            $records['memory'][] = [
                'driver' => (string) ($attr['driver'] ?? ''),
                'label' => (string) ($attr['label'] ?? $class),
                'source' => self::sourceFromClass((string) $class),
                'class' => (string) $class,
            ];
        }

        foreach ($cache['skills'] ?? [] as $class => $attr) {
            $records['skills'][] = [
                'name' => (string) ($attr['name'] ?? ''),
                'label' => (string) ($attr['label'] ?? $class),
                'keywords' => array_values(array_map('strval', (array) ($attr['keywords'] ?? []))),
                'source' => self::sourceFromClass((string) $class),
                'class' => (string) $class,
            ];
        }

        foreach ($cache['guards'] ?? [] as $class => $attr) {
            $records['guards'][] = [
                'name' => (string) ($attr['name'] ?? ''),
                'label' => (string) ($attr['label'] ?? $class),
                'priority' => (int) ($attr['priority'] ?? 0),
                'enabled_by_default' => (bool) ($attr['enabledByDefault'] ?? false),
                'source' => self::sourceFromClass((string) $class),
                'class' => (string) $class,
            ];
        }

        foreach ($cache['hooks'] ?? [] as $class => $listeners) {
            $source = self::sourceFromClass((string) $class);
            foreach ((array) $listeners as $listener) {
                $records['hooks'][] = [
                    'event' => (string) ($listener['event'] ?? ''),
                    'name' => (string) ($listener['name'] ?? ''),
                    'priority' => (int) ($listener['priority'] ?? 0),
                    'enabled_by_default' => (bool) ($listener['enabledByDefault'] ?? false),
                    'source' => $source,
                    'class' => (string) $class,
                ];
            }
        }

        if ($eventFirer !== null) {
            try {
                foreach ($eventFirer->fire('phpclaw/extra/memory', []) as $slug => $factory) {
                    if (! is_string($slug)) {
                        continue;
                    }
                    $records['memory'][] = ['driver' => $slug, 'label' => $slug, 'source' => 'opencart', 'class' => ''];
                }
                foreach ($eventFirer->fire('phpclaw/extra/skills', []) as $skill) {
                    if (! is_object($skill)) {
                        continue;
                    }
                    $name = method_exists($skill, 'name') ? (string) $skill->name() : self::shortName($skill::class);
                    $records['skills'][] = [
                        'name' => $name,
                        'label' => $name,
                        'keywords' => method_exists($skill, 'tags') ? array_values(array_map('strval', (array) $skill->tags())) : [],
                        'source' => 'opencart',
                        'class' => $skill::class,
                    ];
                }
                foreach ($eventFirer->fire('phpclaw/extra/guards', []) as $guard) {
                    if (! is_object($guard)) {
                        continue;
                    }
                    $records['guards'][] = [
                        'name' => self::shortName($guard::class),
                        'label' => self::shortName($guard::class),
                        'priority' => 0,
                        'enabled_by_default' => true,
                        'source' => 'opencart',
                        'class' => $guard::class,
                    ];
                }
                foreach ($eventFirer->fire('phpclaw/extra/hooks', []) as $entry) {
                    if (! is_array($entry) || ! isset($entry['event'])) {
                        continue;
                    }
                    $handler = $entry['handler'] ?? null;
                    $hClass = is_array($handler) ? ($handler[0] ?? '') : $handler;
                    $hClass = is_object($hClass) ? $hClass::class : (string) $hClass;
                    $hMethod = is_array($handler) ? (string) ($handler[1] ?? '') : '';
                    $records['hooks'][] = [
                        'event' => (string) $entry['event'],
                        'name' => $hMethod !== '' ? $hMethod : self::shortName($hClass),
                        'priority' => (int) ($entry['priority'] ?? 10),
                        'enabled_by_default' => true,
                        'source' => 'opencart',
                        'class' => $hClass,
                    ];
                }
            } catch (\Throwable) {
            }
        }

        return $records;
    }

    /**
     * Configured `remote_skill_urls`, or [] when none are set.
     *
     * @param  Plugin  $plugin  Booted plugin instance.
     * @return list<string>
     */
    public static function remoteSkillUrls(Plugin $plugin): array
    {
        return self::normaliseRemoteSkillUrls($plugin->saved()['remote_skill_urls'] ?? '');
    }

    /**
     * Skills registered via `remote_skill_urls`, diffed against buildCapabilityRecords()'s discovered and plugin-extra skill names since AutoDiscovery never sees engine-build-time skills.
     *
     * @param  Plugin  $plugin  Booted plugin instance.
     * @param  list<array{name: string, ...}>  $discoveredSkills  buildCapabilityRecords()['skills'].
     * @param  bool  $callerMayUseModule  Whether the acting caller holds the phpClaw module grant.
     * @return list<array{name: string, description: string, keywords: list<string>}>
     */
    public static function remoteSkills(Plugin $plugin, array $discoveredSkills, bool $callerMayUseModule): array
    {
        if (self::remoteSkillUrls($plugin) === []) {
            return [];
        }

        try {
            $plugin->engine($callerMayUseModule);
        } catch (\Throwable) {
            return [];
        }

        if (! class_exists(SkillRegistry::class)) {
            return [];
        }

        $known = [];
        foreach ($discoveredSkills as $s) {
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
     * Class basename for a fully-qualified class string.
     *
     * @param  string  $class  Fully-qualified class name.
     * @return string
     */
    private static function shortName(string $class): string
    {
        $pos = strrchr($class, '\\');

        return $pos === false ? $class : substr($pos, 1);
    }

    /**
     * Keep the stored secret when the submitted field is blank, so a masked form never wipes a saved credential.
     *
     * @param  mixed  $submitted  Raw submitted value for the secret field.
     * @param  mixed  $current  Currently stored value for the same field.
     * @return string The submitted value when non-empty, otherwise the current stored value.
     */
    private static function preserveSecret(mixed $submitted, mixed $current): string
    {
        $submitted = trim((string) $submitted);

        return $submitted !== '' ? $submitted : trim((string) $current);
    }

    /**
     * Derive a short source label from a class FQCN.
     *
     * @param  string  $class
     * @return string
     */
    private static function sourceFromClass(string $class): string
    {
        return str_starts_with($class, 'PhpClaw\\OpenCart\\') ? 'opencart' : 'core';
    }
}
