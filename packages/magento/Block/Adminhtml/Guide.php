<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Block\Adminhtml;

use Magento\Backend\Block\Template;
use PhpClaw\AutoDiscovery\DiscoveryCache;
use PhpClaw\Magento\Factory\PhpClawFactoryInterface;
use PhpClaw\Magento\Model\Config;
use PhpClaw\Magento\Model\IdentityResolver;
use PhpClaw\Magento\Registry\PhpClawRegistrar;
use PhpClaw\Providers\ProviderCatalogue;
use PhpClaw\Skills\SkillRegistry;

/**
 * Block for the PhpClaw guide page (documentation tabs).
 */
// non-final: Magento interceptor required
class Guide extends Template
{
    /**
     * Bind the block context and registrar this block lists capabilities from.
     *
     * @param  Template\Context  $context  Magento block template context.
     * @param  PhpClawRegistrar  $registrar  phpClaw registry, provides external contributions.
     * @param  PhpClawFactoryInterface  $phpClawFactory  Factory that builds the configured agent (used to trigger remote-skill loading).
     * @param  Config  $config  Admin configuration reader.
     * @param  IdentityResolver  $identity  Resolves whether the viewer may reach the Settings page.
     * @param  array<string, mixed>  $data  Additional block data passed by layout XML.
     * @return void
     */
    public function __construct(
        Template\Context $context,
        private readonly PhpClawRegistrar $registrar,
        private readonly PhpClawFactoryInterface $phpClawFactory,
        private readonly Config $config,
        private readonly IdentityResolver $identity,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Whether the viewer may reach the Settings page, deciding if a Settings mention is a link.
     *
     * @return bool
     */
    public function canManageAll(): bool
    {
        return $this->identity->manageAll();
    }

    /**
     * Render a Settings mention as a link for an administrator and as plain text otherwise.
     *
     * @param  string  $label  Visible text for the mention.
     * @return string
     */
    public function settingsLink(string $label = 'phpClaw &rarr; Settings'): string
    {
        if (! $this->canManageAll()) {
            return $label;
        }

        return '<a href="'.$this->escapeUrl($this->getUrl('phpclaw/settings')).'">'.$label.'</a>';
    }

    /**
     * Provider rows for the Guide providers tab, driven by ProviderCatalogue.
     *
     * @return list<array{name: string, key: string, models: string, signup: string, notes: string}>
     */
    public function getProviders(): array
    {
        if (! class_exists(ProviderCatalogue::class)) {
            return [];
        }

        $meta = [
            'anthropic' => [
                'name' => 'Anthropic',
                'models' => 'claude-opus-4-8, claude-sonnet-5, claude-haiku-4-5-20251001',
                'signup' => 'https://console.anthropic.com/',
                'notes' => 'Recommended for complex reasoning and tool use.',
            ],
            'openai' => [
                'name' => 'OpenAI',
                'models' => 'gpt-4o, gpt-4o-mini, o3-mini',
                'signup' => 'https://platform.openai.com/api-keys',
                'notes' => 'Broad ecosystem. gpt-4o-mini is fast and cost-effective.',
            ],
            'groq' => [
                'name' => 'Groq',
                'models' => 'llama-3.3-70b-versatile, llama-3.1-8b-instant',
                'signup' => 'https://console.groq.com/',
                'notes' => 'Extremely fast inference. Free tier available.',
            ],
            'gemini' => [
                'name' => 'Google Gemini',
                'models' => 'gemini-1.5-pro, gemini-1.5-flash, gemini-2.0-flash',
                'signup' => 'https://aistudio.google.com/app/apikey',
                'notes' => 'Large context window. Free tier via Google AI Studio.',
            ],
            'mistral' => [
                'name' => 'Mistral AI',
                'models' => 'mistral-large-latest, mistral-small-latest, codestral-latest',
                'signup' => 'https://console.mistral.ai/',
                'notes' => 'European provider. Strong coding models. GDPR-friendly.',
            ],
            'deepseek' => [
                'name' => 'DeepSeek',
                'models' => 'deepseek-flash, deepseek-chat, deepseek-reasoner',
                'signup' => 'https://platform.deepseek.com/',
                'notes' => 'High capability at low cost.',
            ],
            'ollama' => [
                'name' => 'Ollama',
                'models' => 'qwen2.5:7b, mistral, phi3, gemma2, any pulled model',
                'signup' => 'https://ollama.com/',
                'notes' => 'Runs on your own server. No API key needed. Connects to http://127.0.0.1:11434 by default.',
            ],
            'custom' => [
                'name' => 'Custom',
                'models' => 'Any OpenAI-compatible model',
                'signup' => '',
                'notes' => 'Any OpenAI-compatible endpoint (OpenRouter, LM Studio, local proxy). Set your API key and Base URL in Settings.',
            ],
        ];

        $rows = [];
        foreach (ProviderCatalogue::all() as $slug => $info) {
            $slug = (string) $slug;
            $m = $meta[$slug] ?? [];
            $rows[] = [
                'name' => (string) ($m['name'] ?? ($info['label'] ?? '') ?: ucfirst($slug)),
                'key' => $slug,
                'models' => (string) ($m['models'] ?? ''),
                'signup' => (string) ($m['signup'] ?? ''),
                'notes' => (string) ($m['notes'] ?? ''),
            ];
        }

        return $rows;
    }

    /**
     * Core utility tools (#[Tool(default: true)] from PhpClaw\Tools\ namespace).
     *
     * @return list<array{tool: string, status: string, notes: string}>
     */
    public function getCoreUtilityTools(): array
    {
        try {
            $cache = DiscoveryCache::load();
        } catch (\Throwable) {
            return [];
        }

        $meta = [
            'HttpTool' => ['status' => '&#10004; Available', 'notes' => 'HTTP GET/POST to external URLs with SSRF protection (private IP ranges blocked).'],
            'FileReadTool' => ['status' => '&#10004; Available', 'notes' => 'Read files inside the workspace directory. Blocks .env, *.key, *.pem.'],
            'FileWriteTool' => ['status' => '&#10004; Available', 'notes' => 'Write files inside the workspace directory.'],
            'ShellTool' => ['status' => '&#10004; Ready',     'notes' => 'Execute allowlisted shell commands. Default commands active; blocklist enforced.'],
        ];

        $rows = [];
        foreach ($cache['tools'] ?? [] as $class => $attr) {
            if (empty($attr['default'])) {
                continue;
            }
            if (! str_starts_with((string) $class, 'PhpClaw\\Tools\\')) {
                continue;
            }
            $short = $this->shortName((string) $class);
            $m = $meta[$short] ?? [];
            $rows[] = [
                'tool' => $short,
                'status' => (string) ($m['status'] ?? '&#10004; Available'),
                'notes' => (string) ($m['notes'] ?? (string) ($attr['description'] ?? '')),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => strcmp($a['tool'], $b['tool']));

        return $rows;
    }

    /**
     * Magento-native tool rows plus any external module tools, listing every registered tool rather than the provider-filtered slice.
     *
     * @return list<array{tool: string, description: string, examples: string}>
     */
    public function getToolRows(): array
    {
        $meta = [
            'MagentoProductTool' => ['desc' => 'Query products by name, SKU, price, category, and attribute set. Read-only.',                    'examples' => 'Products under $20 &middot; Find SKU ABC-001 &middot; List featured products'],
            'MagentoOrderTool' => ['desc' => 'Query orders by status, date range, and customer. Revenue totals.',                              'examples' => 'Pending orders today &middot; Revenue this month &middot; Last 10 orders'],
            'MagentoCustomerTool' => ['desc' => 'Query customers by name, email, and group. No passwords or payment data exposed.',               'examples' => 'New customers this week &middot; Top buyers &middot; Wholesale group members'],
            'MagentoInventoryTool' => ['desc' => 'Stock levels, sources, and reservations across warehouses. MSI-aware.',                          'examples' => 'Out of stock items &middot; Low inventory alerts &middot; Backorder status'],
            'MagentoCategoryTool' => ['desc' => 'Product categories with hierarchy and product counts.',                                          'examples' => 'List top-level categories &middot; Any empty categories? &middot; Category tree'],
            'MagentoStoreTool' => ['desc' => 'Store views, websites, and configuration scope.',                                               'examples' => 'List store views &middot; Active websites &middot; Store base URL'],
            'MagentoReportTool' => ['desc' => 'Sales reports, bestsellers, and abandoned carts.',                                              'examples' => 'Bestsellers this month &middot; Abandoned carts &middot; Compare revenue week-over-week'],
            'MagentoCacheTool' => ['desc' => 'Cache type status: which caches are enabled, disabled, or invalidated.',                        'examples' => 'Cache status &middot; Which caches are disabled? &middot; Is FPC enabled?'],
            'DatabaseTool' => ['desc' => 'Read-only SELECT queries against any Magento table via ResourceConnection. Auto-LIMIT 500.',    'examples' => 'Count rows in sales_order &middot; Show table sizes &middot; Products by type'],
            'LogTool' => ['desc' => 'Read the last N lines of Magento logs (var/log/system.log, exception.log).',                    'examples' => 'Show recent errors &middot; Last 20 exceptions &middot; Any critical errors today?'],
        ];

        try {
            $live = $this->phpClawFactory->registeredTools();
        } catch (\Throwable) {
            $live = null;
        }

        if ($live === null) {
            $rows = [];
            foreach ($meta as $short => $m) {
                $rows[] = ['tool' => $short, 'description' => $m['desc'], 'examples' => $m['examples']];
            }

            return $rows;
        }

        $rows = [];
        foreach ($live as $tool) {
            $class = $tool::class;
            if (str_starts_with($class, 'PhpClaw\\Tools\\')) {
                continue;
            }

            $short = $this->shortName($class);
            $m = $meta[$short] ?? null;
            $rows[] = [
                'tool' => $m !== null ? $short : (method_exists($tool, 'name') ? (string) $tool->name() : $short),
                'description' => $m['desc'] ?? (method_exists($tool, 'description') ? (string) $tool->description() : ''),
                'examples' => $m['examples'] ?? '',
            ];
        }

        return $rows;
    }

    /**
     * Return auto-discovered capability records for the Guide tabs.
     *
     * @return array{memory: list<array<string, mixed>>, skills: list<array<string, mixed>>, guards: list<array<string, mixed>>, hooks: list<array<string, mixed>>}
     */
    public function getCapabilityRecords(): array
    {
        $cache = DiscoveryCache::load();
        $discovered = $this->discoveredCapabilities($cache);
        $external = $this->registrarCapabilities();

        return [
            'memory' => [...$discovered['memory'], ...$external['memory']],
            'skills' => [...$discovered['skills'], ...$external['skills']],
            'guards' => [...$discovered['guards'], ...$external['guards']],
            'hooks' => [...$discovered['hooks'],  ...$external['hooks']],
        ];
    }

    /**
     * Configured `remote_skill_urls`, or [] when none are set.
     *
     * @return list<string>
     */
    public function getRemoteSkillUrls(): array
    {
        return array_values(array_map('strval', $this->config->getRemoteSkillUrls()));
    }

    /**
     * Return skills registered via `remote_skill_urls`, diffed against the discovered skill records.
     * AutoDiscovery never sees skills loaded at engine-build time, so remote skills are diffed in explicitly.
     *
     * @return list<array{name: string, description: string, keywords: list<string>}>
     */
    public function getRemoteSkills(): array
    {
        if ($this->getRemoteSkillUrls() === []) {
            return [];
        }

        try {
            $this->phpClawFactory->create();
        } catch (\Throwable) {
            return [];
        }

        if (! class_exists(SkillRegistry::class)) {
            return [];
        }

        $known = [];
        foreach ($this->getCapabilityRecords()['skills'] as $s) {
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
     * Return all available tab IDs for the Guide page in render order.
     *
     * @return list<string> Ordered list of tab IDs.
     */
    public function getAllTabs(): array
    {
        return [
            'guide-quickstart',
            'guide-tools',
            'guide-providers',
            'guide-memory',
            'guide-guards',
            'guide-hooks',
            'guide-skills',
            'guide-rest',
            'guide-cli',
            'guide-privacy',
        ];
    }

    /**
     * Return the tab ID that should be active on initial page load.
     *
     * @return string Active tab ID.
     */
    public function getActiveTab(): string
    {
        $requested = (string) $this->getRequest()->getParam('tab', '');
        $all = $this->getAllTabs();

        return in_array($requested, $all, true) ? $requested : $all[0];
    }

    /**
     * Return the class basename from a fully-qualified class string.
     *
     * @param  string  $class  Fully-qualified class name.
     * @return string Short class name (basename after last backslash).
     */
    public function shortName(string $class): string
    {
        $pos = strrchr($class, '\\');

        return $pos === false ? $class : substr($pos, 1);
    }

    /**
     * Map auto-discovered capabilities from the discovery cache into Guide records.
     *
     * @param  array<string, mixed>  $cache  Loaded discovery cache.
     * @return array{memory: list<array<string, mixed>>, skills: list<array<string, mixed>>, guards: list<array<string, mixed>>, hooks: list<array<string, mixed>>}
     */
    private function discoveredCapabilities(array $cache): array
    {
        $records = ['memory' => [], 'skills' => [], 'guards' => [], 'hooks' => []];

        foreach ($cache['memory'] ?? [] as $class => $attr) {
            $records['memory'][] = [
                'driver' => (string) ($attr['driver'] ?? ''),
                'label' => (string) ($attr['label'] ?? $class),
                'source' => $this->sourceFromClass((string) $class),
                'class' => (string) $class,
            ];
        }

        foreach ($cache['skills'] ?? [] as $class => $attr) {
            $records['skills'][] = [
                'name' => (string) ($attr['name'] ?? ''),
                'label' => (string) ($attr['label'] ?? $class),
                'keywords' => array_values(array_map('strval', (array) ($attr['keywords'] ?? []))),
                'source' => $this->sourceFromClass((string) $class),
                'class' => (string) $class,
            ];
        }

        foreach ($cache['guards'] ?? [] as $class => $attr) {
            $records['guards'][] = [
                'name' => (string) ($attr['name'] ?? ''),
                'label' => (string) ($attr['label'] ?? $class),
                'priority' => (int) ($attr['priority'] ?? 0),
                'enabled_by_default' => (bool) ($attr['enabledByDefault'] ?? false),
                'source' => $this->sourceFromClass((string) $class),
                'class' => (string) $class,
            ];
        }

        foreach ($cache['hooks'] ?? [] as $class => $listeners) {
            $source = $this->sourceFromClass((string) $class);
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

        return $records;
    }

    /**
     * Map module-registered external capabilities from the registrar into Guide records.
     *
     * @return array{memory: list<array<string, mixed>>, skills: list<array<string, mixed>>, guards: list<array<string, mixed>>, hooks: list<array<string, mixed>>}
     */
    private function registrarCapabilities(): array
    {
        $records = ['memory' => [], 'skills' => [], 'guards' => [], 'hooks' => []];

        foreach ($this->registrar->getExternalMemoryDrivers() as $entry) {
            $records['memory'][] = [
                'driver' => (string) ($entry['driver'] ?? ''),
                'label' => (string) ($entry['label'] ?? ''),
                'source' => 'magento',
                'class' => (string) ($entry['class'] ?? ''),
            ];
        }

        foreach ($this->registrar->getExternalSkills() as $entry) {
            $records['skills'][] = [
                'name' => (string) ($entry['name'] ?? ''),
                'label' => (string) ($entry['label'] ?? ''),
                'keywords' => array_values(array_map('strval', (array) ($entry['keywords'] ?? []))),
                'source' => 'magento',
                'class' => (string) ($entry['class'] ?? ''),
            ];
        }

        foreach ($this->registrar->getExternalGuards() as $entry) {
            $records['guards'][] = [
                'name' => (string) ($entry['name'] ?? ''),
                'label' => (string) ($entry['label'] ?? ''),
                'priority' => (int) ($entry['priority'] ?? 0),
                'enabled_by_default' => (bool) ($entry['enabled_by_default'] ?? true),
                'source' => 'magento',
                'class' => (string) ($entry['class'] ?? ''),
            ];
        }

        foreach ($this->registrar->getExternalHooks() as $entry) {
            $records['hooks'][] = [
                'event' => (string) ($entry['event'] ?? ''),
                'name' => (string) ($entry['name'] ?? ''),
                'priority' => (int) ($entry['priority'] ?? 0),
                'enabled_by_default' => (bool) ($entry['enabled_by_default'] ?? true),
                'source' => 'magento',
                'class' => (string) ($entry['class'] ?? ''),
            ];
        }

        return $records;
    }

    /**
     * Derive a short source label from a class FQCN.
     *
     * @param  string  $class  The capability class FQCN.
     * @return string
     */
    private function sourceFromClass(string $class): string
    {
        return str_starts_with($class, 'PhpClaw\\Magento\\') ? 'magento' : 'core';
    }
}
