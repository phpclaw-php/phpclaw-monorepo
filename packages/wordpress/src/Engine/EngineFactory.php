<?php

declare(strict_types=1);

namespace PhpClaw\WordPress\Engine;

use PhpClaw\Agent\CliApprovalGate;
use PhpClaw\AutoDiscovery\Bootstrap;
use PhpClaw\Claw as PhpClaw;
use PhpClaw\ClawBuilder;
use PhpClaw\ClawConfig;
use PhpClaw\Exceptions\MemoryException;
use PhpClaw\Guards\Contracts\GuardInterface;
use PhpClaw\Guards\GuardRegistry;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Memory\MemoryRegistry;
use PhpClaw\Memory\PrivacyAwareMemory;
use PhpClaw\Providers\Contracts\ProviderInterface;
use PhpClaw\Providers\OpenAIPresets;
use PhpClaw\Providers\OpenAIProvider;
use PhpClaw\Providers\ProviderCatalogue;
use PhpClaw\Providers\ProviderRegistry;
use PhpClaw\Skills\Contracts\SkillInterface;
use PhpClaw\Tools\Contracts\ConfigurableToolInterface;
use PhpClaw\Tools\Contracts\ToolInterface;
use PhpClaw\Tools\FileEditTool;
use PhpClaw\Tools\FileWriteTool;
use PhpClaw\Tools\ShellTool;
use PhpClaw\Tools\ToolCatalogue;
use PhpClaw\Tools\ToolProfileResolver;
use PhpClaw\Tools\ToolRegistry;
use PhpClaw\Tools\ZipPackagerTool;
use PhpClaw\WooCommerce\Tools\CategoryTool;
use PhpClaw\WooCommerce\Tools\CouponTool;
use PhpClaw\WooCommerce\Tools\CustomerTool;
use PhpClaw\WooCommerce\Tools\OrderTool;
use PhpClaw\WooCommerce\Tools\ProductTool;
use PhpClaw\WooCommerce\Tools\ReportTool;
use PhpClaw\WooCommerce\Tools\ReviewTool;
use PhpClaw\WooCommerce\Tools\ShippingTool;
use PhpClaw\WooCommerce\Tools\StockTool;
use PhpClaw\WooCommerce\Tools\TaxTool;
use PhpClaw\WordPress\Plugin;
use PhpClaw\WordPress\Support\TransientCache;
use PhpClaw\WordPress\Tools\DatabaseTool;
use PhpClaw\WordPress\Tools\LogTool;
use PhpClaw\WordPress\Tools\WpCommentTool;
use PhpClaw\WordPress\Tools\WpCronTool;
use PhpClaw\WordPress\Tools\WpMediaTool;
use PhpClaw\WordPress\Tools\WpMenuTool;
use PhpClaw\WordPress\Tools\WpOptionsTool;
use PhpClaw\WordPress\Tools\WpPluginTool;
use PhpClaw\WordPress\Tools\WpQueryTool;
use PhpClaw\WordPress\Tools\WpTaxonomyTool;
use PhpClaw\WordPress\Tools\WpUserTool;
use PhpClaw\WordPress\Tools\WpZipBuilderTool;

/**
 * Shared factory that builds a PhpClaw engine from WordPress settings and config.
 */
final class EngineFactory
{
    private const DEFAULT_MEMORY_DRIVER = 'wpdb_router';

    private const DEFAULT_MAX_ITERATIONS = ClawConfig::DEFAULT_MAX_ITERATIONS;

    private const DEFAULT_RESPONSE_CACHE_TTL = 3600;

    private const DEFAULT_HOOK_PRIORITY = 10;

    private const SITE_TOOLS = [
        WpQueryTool::class => false,
        OrderTool::class => true,
        ProductTool::class => true,
        WpUserTool::class => false,
        StockTool::class => true,
        CustomerTool::class => true,
        WpOptionsTool::class => false,
        ReportTool::class => true,
        DatabaseTool::class => false,
        CouponTool::class => true,
        CategoryTool::class => true,
        WpPluginTool::class => false,
        ReviewTool::class => true,
        ShippingTool::class => true,
        TaxTool::class => true,
        WpTaxonomyTool::class => false,
        WpCommentTool::class => false,
        WpMediaTool::class => false,
        WpMenuTool::class => false,
        WpCronTool::class => false,
        LogTool::class => false,
    ];

    /**
     * Build a fully-configured PhpClaw engine.
     *
     * @param  array<string, mixed>  $config  Default config (from config/phpclaw.php).
     * @param  array<string, mixed>  $saved  Saved admin settings (from get_option).
     * @param  SkillInterface[]  $skills  Resolved skill instances.
     * @param  array<class-string>  $extraToolClasses  Extra tool class names to auto-discover.
     * @param  bool|null  $isCli  True to permit PHP-file writes (interactive WP-CLI). Null auto-detects via the WP_CLI constant.
     * @return PhpClaw
     */
    public static function build(
        array $config,
        array $saved,
        array $skills = [],
        array $extraToolClasses = [],
        ?bool $isCli = null,
    ): PhpClaw {
        $isCli = $isCli ?? (defined('WP_CLI') && WP_CLI);
        $provider = self::resolveProvider($saved);
        $model = self::resolveModel($saved);
        $apiKey = self::resolveApiKey($saved, $provider);
        $maxIterations = self::resolveMaxIterations($saved);
        $systemPrompt = (string) ($saved['system_prompt'] ?? '');
        $workspaceRoot = (string) ($config['workspace_root'] ?? '');
        $storeMessages = Plugin::storeMessagesEnabled($saved, true);

        $providerOverride = self::customProviderOverride($provider, $apiKey, $model, $systemPrompt, $saved);
        self::registerExtraProviders();

        $skills = self::applyExtensionFilters($skills);

        $memory = self::buildMemory($storeMessages);

        $builder = PhpClaw::builder()
            ->apiKey($apiKey)
            ->provider($provider)
            ->model($model)
            ->systemPrompt($systemPrompt)
            ->storeMessages($storeMessages)
            ->maxIterations($maxIterations)
            ->maxToolsPerTurn(self::resolveMaxToolsPerTurn($saved, $provider, $model))
            ->alwaysIncludeTools(array_values((array) ($saved['always_include_tools'] ?? [])))
            ->shellAllowlist((array) ($config['shell_allowlist'] ?? []))
            ->memory($memory)
            ->tools(self::buildTools($workspaceRoot, $config, $saved, $extraToolClasses, allowPhpWrite: $isCli))
            ->skills($skills)
            ->approvalGate(new CliApprovalGate);

        if ($providerOverride !== null) {
            $builder->providerOverride($providerOverride);
        }

        foreach (self::resolveRemoteSkillUrls($saved) as $url) {
            $builder->withRemoteSkills($url);
        }

        self::applyAgentOptions($builder, $saved);

        return $builder->build();
    }

    /**
     * Apply the phpclaw_extra_* filters: guards, hooks, skills, memory drivers and providers; a failing filter is logged and skipped.
     *
     * @param  SkillInterface[]  $skills  Skills resolved so far.
     * @return SkillInterface[] The skills plus any the phpclaw_extra_skills filter adds.
     */
    private static function applyExtensionFilters(array $skills): array
    {
        if (! function_exists('apply_filters')) {
            return $skills;
        }

        self::runExtensionFilter(static function (): void {
            foreach ((array) apply_filters('phpclaw_extra_guards', []) as $guard) {
                if ($guard instanceof GuardInterface) {
                    GuardRegistry::register($guard, replace: true);
                }
            }
        });

        self::runExtensionFilter(static function (): void {
            foreach ((array) apply_filters('phpclaw_extra_hooks', []) as $hook) {
                if (is_array($hook) && isset($hook['event'], $hook['handler'])) {
                    HookRegistry::on($hook['event'], $hook['handler'], $hook['priority'] ?? self::DEFAULT_HOOK_PRIORITY);
                }
            }
        });

        self::runExtensionFilter(static function () use (&$skills): void {
            $skills = array_merge($skills, (array) apply_filters('phpclaw_extra_skills', []));
        });

        self::runExtensionFilter(static function (): void {
            foreach ((array) apply_filters('phpclaw_extra_memory_drivers', []) as $slug => $factory) {
                if (is_string($slug) && is_callable($factory)) {
                    MemoryRegistry::register($slug, $factory);
                }
            }
        });

        self::runExtensionFilter(static function (): void {
            $natives = Bootstrap::providers();

            foreach ((array) apply_filters('phpclaw_extra_providers', []) as $slug => $entry) {
                if (! is_string($slug) || self::isBuiltInProvider($slug, $natives)) {
                    continue;
                }

                if (is_array($entry) && isset($entry['class'])
                    && is_string($entry['class']) && class_exists($entry['class'])) {
                    self::runExtensionFilter(static fn () => ProviderRegistry::register($slug, $entry['class']));
                }
            }
        });

        return $skills;
    }

    /**
     * Run one extension filter, logging and swallowing any failure so one bad extension cannot stop the engine.
     *
     * @param  callable(): mixed  $apply  The filter work.
     * @return void
     */
    private static function runExtensionFilter(callable $apply): void
    {
        try {
            $apply();
        } catch (\Throwable $e) {
            error_log('phpClaw EngineFactory: extension filter failed: '.$e->getMessage());
        }
    }

    /**
     * Whether a provider slug is already served by an OpenAI preset or a natively discovered provider.
     *
     * @param  string  $slug  Provider slug.
     * @param  array<string, mixed>  $natives  Natively discovered providers keyed by slug.
     * @return bool
     */
    private static function isBuiltInProvider(string $slug, array $natives): bool
    {
        return (class_exists(OpenAIPresets::class) && OpenAIPresets::has($slug)) || isset($natives[$slug]);
    }

    /**
     * Apply the saved fallback, rate limit, response cache and token budget; each stays off unless set.
     *
     * @param  ClawBuilder  $builder
     * @param  array<string, mixed>  $saved  Saved admin settings.
     * @return void
     */
    private static function applyAgentOptions(ClawBuilder $builder, array $saved): void
    {
        $fallbackProvider = (string) ($saved['fallback_provider'] ?? '');
        if ($fallbackProvider !== '') {
            $builder->withFallback(
                $fallbackProvider,
                (string) ($saved['fallback_model'] ?? ''),
                (string) ($saved['fallback_api_key'] ?? ''),
            );
        }

        $rateLimit = (int) ($saved['rate_limit_rpm'] ?? 0);
        if ($rateLimit > 0) {
            $builder->rateLimit($rateLimit, store: new TransientCache);
        }

        if ((string) ($saved['response_cache'] ?? '0') === '1') {
            $ttl = (int) ($saved['response_cache_ttl'] ?? 0);
            $builder->responseCache(new TransientCache, $ttl > 0 ? $ttl : self::DEFAULT_RESPONSE_CACHE_TTL);
        }

        $tokenBudget = (int) ($saved['max_token_budget'] ?? 0);
        if ($tokenBudget > 0) {
            $builder->maxTokenBudget($tokenBudget);
        }
    }

    /**
     * Per-turn tool cap: the saved max_tools_per_turn, or the provider and model profile limit when it is 0.
     *
     * @param  array<string, mixed>  $saved  Saved admin settings.
     * @param  string  $provider  Provider in use.
     * @param  string  $model  Model in use.
     * @return int Cap passed to the builder.
     */
    private static function resolveMaxToolsPerTurn(array $saved, string $provider, string $model): int
    {
        $configured = (int) ($saved['max_tools_per_turn'] ?? 0);

        return $configured === 0
            ? ToolProfileResolver::maxTools(ToolProfileResolver::resolve($provider, $model))
            : $configured;
    }

    /**
     * Every tool the settings enable, after the deny list, without the engine's provider tool profile.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $saved
     * @param  array<class-string>  $extraToolClasses
     * @return array<int, object>
     */
    public static function registeredTools(array $config, array $saved, array $extraToolClasses = []): array
    {
        $provider = self::resolveProvider($saved);
        $model = self::resolveModel($saved);
        $workspaceRoot = (string) ($config['workspace_root'] ?? '');

        $tools = self::buildTools($workspaceRoot, $config, $saved, $extraToolClasses, applyProfile: false, allowPhpWrite: false);
        ['deny' => $deny, 'groups' => $groups] = self::denyConfig($config);

        $registry = new ToolRegistry;
        $registry->register($tools, $deny, $groups);

        return $registry->all();
    }

    /**
     * Resolve the configured tool_deny list and the fixed group definitions used to expand it.
     *
     * @param  array<string, mixed>  $config
     * @return array{deny: string[], groups: array<string, string[]>}
     */
    private static function denyConfig(array $config): array
    {
        return [
            'deny' => (array) ($config['tool_deny'] ?? []),
            'groups' => [
                'group:content' => ['wp_query', 'wp_taxonomy', 'wp_comments', 'wp_media'],
                'group:admin' => ['wp_users', 'wp_plugins', 'wp_option', 'wp_cron'],
                'group:system' => ['db_query', 'read_log', 'http_request', 'file_read', 'file_write',
                    'file_edit', 'code_search', 'project_info', 'shell_exec'],
                'group:builder' => ['zip_package', 'wp_zip_plugin'],
                'group:nav' => ['wp_menus', 'wp_taxonomy'],
            ],
        ];
    }

    /**
     * Extract the saved remote skill collection URLs, trimmed and non-empty.
     *
     * @param  array<string, mixed>  $saved  Saved admin settings.
     * @return list<string>
     */
    private static function resolveRemoteSkillUrls(array $saved): array
    {
        $urls = $saved['remote_skill_urls'] ?? [];

        if (! is_array($urls)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $url): string => is_string($url) ? trim($url) : '', $urls),
            static fn (string $url): bool => $url !== '',
        ));
    }

    /**
     * Resolve the configured provider slug from saved settings.
     *
     * @param  array<string, mixed>  $saved
     * @return string
     */
    private static function resolveProvider(array $saved): string
    {
        return (string) ($saved['provider'] ?? '');
    }

    /**
     * Resolve the configured model identifier from saved settings.
     *
     * @param  array<string, mixed>  $saved
     * @return string
     */
    private static function resolveModel(array $saved): string
    {
        return (string) ($saved['model'] ?? '');
    }

    /**
     * Resolve the API key for the provider - Ollama is keyless, so any saved key is dropped.
     *
     * @param  array<string, mixed>  $saved
     * @param  string  $provider
     * @return string
     */
    private static function resolveApiKey(array $saved, string $provider): string
    {
        if ($provider === 'ollama') {
            return '';
        }

        return (string) ($saved['api_key'] ?? '');
    }

    /**
     * Resolve the ReAct loop cap, falling back to the core default.
     *
     * @param  array<string, mixed>  $saved
     * @return int
     */
    private static function resolveMaxIterations(array $saved): int
    {
        return (int) (($saved['max_iterations'] ?? 0) ?: self::DEFAULT_MAX_ITERATIONS);
    }

    /**
     * Build a provider override for the "custom" OpenAI-compatible endpoint from the saved base URL.
     *
     * @param  string  $provider
     * @param  string  $apiKey
     * @param  string  $model
     * @param  string  $systemPrompt
     * @param  array<string, mixed>  $saved
     * @return ?ProviderInterface Configured provider, or null when the provider is not "custom".
     */
    private static function customProviderOverride(
        string $provider,
        string $apiKey,
        string $model,
        string $systemPrompt,
        array $saved,
    ): ?ProviderInterface {
        if ($provider !== 'custom') {
            return null;
        }

        $baseUrl = trim((string) ($saved['base_url'] ?? ''));

        if ($baseUrl === '' || ! (bool) preg_match('~^https?://~i', $baseUrl)) {
            return null;
        }

        return new OpenAIProvider(
            apiKey: $apiKey,
            model: $model,
            systemPrompt: $systemPrompt,
            endpoint: $baseUrl,
            name: 'custom',
            authStyle: OpenAIPresets::AUTH_BEARER,
        );
    }

    /**
     * Auto-register every provider in ProviderCatalogue (core) that isn't already registered.
     *
     * @return void
     */
    private static function registerExtraProviders(): void
    {
        if (! class_exists(ProviderCatalogue::class)) {
            return;
        }

        $natives = Bootstrap::providers();

        foreach (ProviderCatalogue::all() as $key => $info) {
            if (self::isBuiltInProvider($key, $natives)) {
                continue;
            }
            if (class_exists($info['class']) && ! ProviderRegistry::has($key)) {
                ProviderRegistry::register($key, $info['class']);
            }
        }
    }

    /**
     * Build the default memory driver wrapped in privacy-aware gating.
     *
     * @param  bool  $storeMessages  Whether message content may be persisted.
     * @return PrivacyAwareMemory
     *
     * @throws MemoryException If the required memory driver is not registered.
     */
    private static function buildMemory(bool $storeMessages): PrivacyAwareMemory
    {
        $memoryDriver = self::DEFAULT_MEMORY_DRIVER;

        if (! MemoryRegistry::has($memoryDriver)) {
            error_log("phpClaw: memory driver '{$memoryDriver}' is not registered.");
            throw new MemoryException("phpClaw: required memory driver '{$memoryDriver}' is not registered.");
        }

        $rawMemory = MemoryRegistry::build($memoryDriver);

        return new PrivacyAwareMemory($rawMemory, $storeMessages);
    }

    /**
     * Build the list of tools, with the configured deny list applied.
     *
     * @param  string  $workspaceRoot  Workspace root for file tools.
     * @param  array<string, mixed>  $config  Default config array.
     * @param  array<string, mixed>  $saved  Saved admin settings.
     * @param  array<class-string>  $extraToolClasses  Extra tool class names to auto-discover.
     * @param  bool  $applyProfile  Apply the configured tool deny list.
     * @param  bool  $allowPhpWrite  True to permit file_write to write .php/.phtml/.phar files.
     * @return array<ToolInterface>
     */
    private static function buildTools(string $workspaceRoot, array $config, array $saved, array $extraToolClasses, bool $applyProfile = true, bool $allowPhpWrite = false): array
    {
        $hasWoo = class_exists('WooCommerce');
        $resolvedWorkspaceRoot = $workspaceRoot === '' ? null : $workspaceRoot;
        $shellAllowlist = (array) ($config['shell_allowlist'] ?? []);

        $coreTools = ToolCatalogue::instantiateDefaults([
            'workspaceRoot' => $resolvedWorkspaceRoot,
            'projectRoot' => defined('ABSPATH') ? rtrim((string) ABSPATH, '/') : null,
            'allowlist' => $shellAllowlist,
            'allowPhpWrite' => $allowPhpWrite,
        ]);

        $coreTools[] = new ZipPackagerTool($resolvedWorkspaceRoot);
        $coreTools[] = new WpZipBuilderTool($resolvedWorkspaceRoot);

        $tools = [];

        foreach (self::SITE_TOOLS as $class => $needsWooCommerce) {
            if ($hasWoo || ! $needsWooCommerce) {
                $tools[] = new $class;
            }
        }

        $tools = [...$tools, ...$coreTools];

        $registered = [];
        foreach ($tools as $registeredTool) {
            $registered[$registeredTool->name()] = true;
        }

        foreach ($extraToolClasses as $class) {
            if (! class_exists($class)) {
                continue;
            }

            $constructor = (new \ReflectionClass($class))->getConstructor();
            if ($constructor !== null && $constructor->getNumberOfRequiredParameters() > 0) {
                continue;
            }

            $tool = self::instantiateExtraTool($class, $resolvedWorkspaceRoot, $shellAllowlist, $allowPhpWrite);

            if (isset($registered[$tool->name()])) {
                continue;
            }

            if ($tool instanceof ConfigurableToolInterface) {
                try {
                    $tool->configure($saved);
                } catch (\Throwable $e) {
                    error_log("phpClaw: tool {$class} configure() failed: {$e->getMessage()}");

                    continue;
                }
                if ($tool->isConfigured()) {
                    $tools[] = $tool;
                    $registered[$tool->name()] = true;
                }
            } else {
                $tools[] = $tool;
                $registered[$tool->name()] = true;
            }
        }

        if (! $applyProfile) {
            return $tools;
        }

        ['deny' => $deny, 'groups' => $groups] = self::denyConfig($config);

        return ToolProfileResolver::filter(
            tools: $tools,
            deny: $deny,
            groups: $groups,
        );
    }

    /**
     * Instantiate one extra-tool class; the shell and file-write tools get this adapter's allowlist, workspace and PHP-write rule.
     *
     * @param  class-string  $class  Extra tool class name from the phpclaw_extra_tools filter.
     * @param  ?string  $workspaceRoot  Resolved workspace root, or null to use the tool's own default.
     * @param  string[]  $shellAllowlist  Configured shell command allowlist.
     * @param  bool  $allowPhpWrite  True to permit file_write to write .php/.phtml/.phar files.
     * @return object Instantiated tool.
     */
    private static function instantiateExtraTool(string $class, ?string $workspaceRoot, array $shellAllowlist, bool $allowPhpWrite): object
    {
        return match ($class) {
            ShellTool::class => new ShellTool(allowlist: $shellAllowlist),
            FileWriteTool::class => new FileWriteTool(workspaceRoot: $workspaceRoot, allowPhpWrite: $allowPhpWrite),
            FileEditTool::class => new FileEditTool(workspaceRoot: $workspaceRoot),
            default => new $class,
        };
    }
}
