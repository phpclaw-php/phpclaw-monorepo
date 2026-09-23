<?php

declare(strict_types=1);

namespace PhpClaw\WordPress\Engine;

use PhpClaw\Agent\CliApprovalGate;
use PhpClaw\AutoDiscovery\Bootstrap;
use PhpClaw\Claw as PhpClaw;
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
        $storeMessages = self::resolveStoreMessages($saved);

        $providerOverride = self::customProviderOverride($provider, $apiKey, $model, $systemPrompt, $saved);
        self::registerExtraProviders();

        if (function_exists('apply_filters')) {
            try {
                foreach ((array) apply_filters('phpclaw_extra_guards', []) as $guard) {
                    if ($guard instanceof GuardInterface) {
                        GuardRegistry::register($guard, replace: true);
                    }
                }
            } catch (\Throwable $e) {
                error_log('phpClaw EngineFactory: extension filter failed: '.$e->getMessage());
            }
            try {
                foreach ((array) apply_filters('phpclaw_extra_hooks', []) as $hook) {
                    if (is_array($hook) && isset($hook['event'], $hook['handler'])) {
                        HookRegistry::on($hook['event'], $hook['handler'], $hook['priority'] ?? 10);
                    }
                }
            } catch (\Throwable $e) {
                error_log('phpClaw EngineFactory: extension filter failed: '.$e->getMessage());
            }
            try {
                $skills = array_merge($skills, (array) apply_filters('phpclaw_extra_skills', []));
            } catch (\Throwable $e) {
                error_log('phpClaw EngineFactory: extension filter failed: '.$e->getMessage());
            }
            try {
                foreach ((array) apply_filters('phpclaw_extra_memory_drivers', []) as $slug => $factory) {
                    if (is_string($slug) && is_callable($factory)) {
                        MemoryRegistry::register($slug, $factory);
                    }
                }
            } catch (\Throwable $e) {
                error_log('phpClaw EngineFactory: extension filter failed: '.$e->getMessage());
            }
            try {
                $filterHasPresets = class_exists(OpenAIPresets::class);
                $filterNatives = Bootstrap::providers();
                foreach ((array) apply_filters('phpclaw_extra_providers', []) as $slug => $entry) {
                    if (! is_string($slug)) {
                        continue;
                    }
                    if ($filterHasPresets && OpenAIPresets::has($slug)) {
                        continue;
                    }
                    if (isset($filterNatives[$slug])) {
                        continue;
                    }
                    if (is_array($entry) && isset($entry['class'])
                        && is_string($entry['class']) && class_exists($entry['class'])) {
                        try {
                            ProviderRegistry::register($slug, $entry['class']);
                        } catch (\Throwable $e) {
                            error_log('phpClaw EngineFactory: extension filter failed: '.$e->getMessage());
                        }
                    }
                }
            } catch (\Throwable $e) {
                error_log('phpClaw EngineFactory: extension filter failed: '.$e->getMessage());
            }
        }

        $memory = self::buildMemory($storeMessages);

        $builder = PhpClaw::builder()
            ->apiKey($apiKey)
            ->provider($provider)
            ->model($model)
            ->systemPrompt($systemPrompt)
            ->storeMessages($storeMessages)
            ->maxIterations($maxIterations)
            ->maxToolsPerTurn(ToolProfileResolver::maxTools(ToolProfileResolver::resolve($provider, $model)))
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

        return $builder->build();
    }

    /**
     * Return every tool the settings enable, after the deny list but without the provider
     * tool profile the engine itself applies.
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
     * Resolve whether message content may be persisted (defaults to true).
     *
     * @param  array<string, mixed>  $saved
     * @return bool
     */
    private static function resolveStoreMessages(array $saved): bool
    {
        return Plugin::storeMessagesEnabled($saved, true);
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

        $hasPresets = class_exists(OpenAIPresets::class);
        $natives = Bootstrap::providers();

        foreach (ProviderCatalogue::all() as $key => $info) {
            if (($hasPresets && OpenAIPresets::has($key)) || isset($natives[$key])) {
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

        $coreTools = ToolCatalogue::instantiateDefaults([
            'workspaceRoot' => $workspaceRoot !== '' ? $workspaceRoot : null,
            'allowlist' => (array) ($config['shell_allowlist'] ?? []),
            'allowPhpWrite' => $allowPhpWrite,
        ]);

        $coreTools[] = new ZipPackagerTool(
            $workspaceRoot !== '' ? $workspaceRoot : null,
        );
        $coreTools[] = new WpZipBuilderTool(
            $workspaceRoot !== '' ? $workspaceRoot : null,
        );

        if ($hasWoo) {
            $tools = [
                new WpQueryTool,
                new OrderTool,
                new ProductTool,
                new WpUserTool,
                new StockTool,
                new CustomerTool,
                new WpOptionsTool,
                new ReportTool,
                new DatabaseTool,
                new CouponTool,
                new CategoryTool,
                new WpPluginTool,
                new ReviewTool,
                new ShippingTool,
                new TaxTool,
                new WpTaxonomyTool,
                new WpCommentTool,
                new WpMediaTool,
                new WpMenuTool,
                new WpCronTool,
                new LogTool,
                ...$coreTools,
            ];
        } else {
            $tools = [
                new WpQueryTool,
                new WpUserTool,
                new WpOptionsTool,
                new DatabaseTool,
                new WpPluginTool,
                new WpTaxonomyTool,
                new WpCommentTool,
                new WpMediaTool,
                new WpMenuTool,
                new WpCronTool,
                new LogTool,
                ...$coreTools,
            ];
        }

        $registered = [];
        foreach ($tools as $registeredTool) {
            $registered[$registeredTool->name()] = true;
        }

        foreach ($extraToolClasses as $class) {
            if (! class_exists($class)) {
                continue;
            }

            $ctor = (new \ReflectionClass($class))->getConstructor();
            if ($ctor !== null && $ctor->getNumberOfRequiredParameters() > 0) {
                continue;
            }

            $tool = new $class;

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
}
