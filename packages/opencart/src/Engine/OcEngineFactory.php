<?php

declare(strict_types=1);

namespace PhpClaw\OpenCart\Engine;

use PhpClaw\Agent\CliApprovalGate;
use PhpClaw\AutoDiscovery\Bootstrap;
use PhpClaw\Claw;
use PhpClaw\ClawBuilder;
use PhpClaw\ClawConfig;
use PhpClaw\Memory\MemoryRegistry;
use PhpClaw\Memory\PrivacyAwareMemory;
use PhpClaw\OpenCart\Contracts\OcDbInterface;
use PhpClaw\OpenCart\OcEventFirer;
use PhpClaw\OpenCart\Tools\DatabaseTool;
use PhpClaw\OpenCart\Tools\LogTool;
use PhpClaw\OpenCart\Tools\OcCategoryTool;
use PhpClaw\OpenCart\Tools\OcCouponTool;
use PhpClaw\OpenCart\Tools\OcCustomerTool;
use PhpClaw\OpenCart\Tools\OcManufacturerTool;
use PhpClaw\OpenCart\Tools\OcOrderTool;
use PhpClaw\OpenCart\Tools\OcProductTool;
use PhpClaw\OpenCart\Tools\OcReviewTool;
use PhpClaw\OpenCart\Tools\OcShippingTool;
use PhpClaw\Providers\Contracts\ProviderInterface;
use PhpClaw\Providers\OpenAIPresets;
use PhpClaw\Providers\OpenAIProvider;
use PhpClaw\Providers\ProviderCatalogue;
use PhpClaw\Providers\ProviderRegistry;
use PhpClaw\Skills\SkillResolver;
use PhpClaw\Tools\Contracts\ToolInterface;
use PhpClaw\Tools\FileEditTool;
use PhpClaw\Tools\FileWriteTool;
use PhpClaw\Tools\ShellTool;
use PhpClaw\Tools\ToolCatalogue;
use PhpClaw\Tools\ToolProfileResolver;
use PhpClaw\Tools\ToolRegistry;

/**
 * Assembles the configured Claw engine for OpenCart 3/4.
 */
final class OcEngineFactory
{
    private const PROVIDER_CUSTOM = 'custom';

    /**
     * Assemble and return a fully-configured Claw engine.
     *
     * @param  array<string, mixed>  $config  Package default config (config/phpclaw.php).
     * @param  array<string, mixed>  $saved  Admin-saved settings (from {DB_PREFIX}setting).
     * @param  OcDbInterface|null  $db  OC native DB adapter, or null (CLI / tests).
     * @param  string  $prefix  Resolved OC table prefix.
     * @param  OcEventFirer  $eventFirer  Registry event dispatcher.
     * @param  bool  $callerMayUseModule  Whether the acting caller holds the phpClaw module grant.
     * @param  bool  $mayQueryRaw  Whether the caller holds the grant that permits raw SQL.
     * @return Claw
     */
    public function build(
        array $config,
        array $saved,
        ?OcDbInterface $db,
        string $prefix,
        OcEventFirer $eventFirer,
        bool $callerMayUseModule,
        bool $mayQueryRaw,
    ): Claw {
        $resolved = $this->resolveEngineConfig($saved);

        $this->registerProviderCatalogueIfAvailable();
        Bootstrap::activateFromSettings($saved);

        $memory = MemoryRegistry::build('oc_router');

        if (! $resolved['storeMessages']) {
            $memory = new PrivacyAwareMemory($memory, false);
        }

        $builder = Claw::builder()
            ->apiKey($resolved['apiKey'])
            ->provider($resolved['provider'])
            ->model($resolved['model'])
            ->systemPrompt($resolved['systemPrompt'])
            ->storeMessages($resolved['storeMessages'])
            ->maxIterations($resolved['maxIterations'])
            ->maxToolsPerTurn(ToolProfileResolver::maxTools(ToolProfileResolver::resolve((string) $resolved['provider'], (string) $resolved['model'])))
            ->tools($this->buildTools($config, $db, $prefix, $eventFirer, $callerMayUseModule, $mayQueryRaw))
            ->shellAllowlist((array) ($config['shell_allowlist'] ?? []))
            ->memory($memory)
            ->skills(SkillResolver::resolve((array) ($config['skills'] ?? [])))
            ->approvalGate(new CliApprovalGate)
            ->cloudKey($resolved['storeMessages'] ? $resolved['cloudKey'] : '')
            ->cloudSigningSecret($resolved['cloudSigningSecret'])
            ->cloudDisable($resolved['cloudDisable']);

        $override = $this->customProviderOverride($resolved);
        if ($override !== null) {
            $builder->providerOverride($override);
        }

        foreach ($this->resolveRemoteSkillUrls($saved) as $url) {
            $builder->withRemoteSkills($url);
        }

        $this->applyAgentPrimitives($builder, $saved);

        return $builder->build();
    }

    /**
     * Apply the fallback provider, outbound rate limit, response cache and token budget; each stays off unless set.
     *
     * @param  ClawBuilder  $builder  Builder for the engine being assembled.
     * @param  array<string, mixed>  $saved  Admin-saved settings, already clamped by SettingsPage::validate().
     * @return void
     */
    private function applyAgentPrimitives(ClawBuilder $builder, array $saved): void
    {
        $provider = (string) ($saved['provider'] ?? '');
        $primary = $provider !== '' ? $provider : (new ClawConfig)->providerName;
        $fallback = (string) ($saved['fallback_provider'] ?? '');
        $fallbackCompatible = $fallback !== ''
            && $fallback !== self::PROVIDER_CUSTOM
            && ToolRegistry::toolFormat($fallback) === ToolRegistry::toolFormat($primary);

        if ($fallbackCompatible) {
            $builder->withFallback($fallback, (string) ($saved['fallback_model'] ?? ''), (string) ($saved['fallback_api_key'] ?? ''));
        } elseif ($fallback !== '') {
            error_log('phpClaw: fallback provider "'.$fallback.'" is custom or uses another tool format than the primary, skipping fallback.');
        }

        $rpm = (int) ($saved['rate_limit_rpm'] ?? 0);
        $cacheOn = (string) ($saved['response_cache'] ?? '0') === '1';
        $store = $rpm > 0 || $cacheOn ? new OcCache : null;

        if ($store !== null && $rpm > 0) {
            $builder->rateLimit($rpm, store: $store);
        }

        if ($store !== null && $cacheOn) {
            $builder->responseCache($store, (int) ($saved['response_cache_ttl'] ?? 0));
        }

        $budget = (int) ($saved['max_token_budget'] ?? 0);

        if ($budget > 0) {
            $builder->maxTokenBudget($budget);
        }
    }

    /**
     * Resolve engine config values from admin-saved settings.
     *
     * @param  array<string, mixed>  $saved  Admin-saved settings (native oc_setting store).
     * @return array<string, mixed>
     */
    public function resolveEngineConfig(array $saved): array
    {
        $provider = (string) ($saved['provider'] ?? '');
        $model = (string) ($saved['model'] ?? '');
        $apiKey = (string) ($saved['api_key'] ?? '');
        $maxIterations = (int) ($saved['max_iterations'] ?? 0) ?: ClawConfig::DEFAULT_MAX_ITERATIONS;
        $smRaw = $saved['store_messages'] ?? null;
        $storeMessages = $smRaw !== null
            ? ($smRaw === '1' || $smRaw === true)
            : true;

        if ($provider === 'ollama') {
            $apiKey = '';
        }

        return [
            'provider' => $provider,
            'model' => $model,
            'apiKey' => $apiKey,
            'baseUrl' => trim((string) ($saved['base_url'] ?? '')),
            'maxIterations' => $maxIterations,
            'storeMessages' => $storeMessages,
            'systemPrompt' => (string) ($saved['system_prompt'] ?? ''),
            'cloudKey' => (string) ($saved['cloud_key'] ?? ''),
            'cloudSigningSecret' => (string) ($saved['cloud_signing_secret'] ?? ''),
            'cloudDisable' => (array) ($saved['cloud_disable'] ?? []),
        ];
    }

    /**
     * Build a pre-configured OpenAIProvider for the custom provider, or null for any other.
     *
     * @param  array<string, mixed>  $resolved  Result of resolveEngineConfig().
     * @return ?ProviderInterface
     */
    public function customProviderOverride(array $resolved): ?ProviderInterface
    {
        if ($resolved['provider'] !== 'custom') {
            return null;
        }

        $baseUrl = $resolved['baseUrl'];

        if ($baseUrl === '' || ! $this->isAllowedProviderUrl($baseUrl)) {
            return null;
        }

        return new OpenAIProvider(
            apiKey: $resolved['apiKey'],
            model: $resolved['model'],
            systemPrompt: $resolved['systemPrompt'],
            endpoint: $baseUrl,
            name: 'custom',
            authStyle: OpenAIPresets::AUTH_BEARER,
        );
    }

    /**
     * Register only genuinely external (non-preset, non-native) catalogue entries into ProviderRegistry.
     *
     * @return void
     */
    public function registerProviderCatalogueIfAvailable(): void
    {
        if (! class_exists(ProviderCatalogue::class)) {
            return;
        }

        $presets = class_exists(OpenAIPresets::class)
            ? array_keys(OpenAIPresets::all())
            : [];
        $natives = class_exists(Bootstrap::class)
            ? array_keys(Bootstrap::providers())
            : [];
        $skip = array_merge($presets, $natives);

        foreach (ProviderCatalogue::all() as $key => $info) {
            if (in_array($key, $skip, true)) {
                continue;
            }
            if (class_exists($info['class']) && ! ProviderRegistry::has($key)) {
                try {
                    ProviderRegistry::register($key, $info['class']);
                } catch (\Throwable) {
                }
            }
        }
    }

    /**
     * Build the tool list: developer-added extra tools, the OpenCart-native tools, and core's default tools.
     *
     * @param  array<string, mixed>  $config  Package default config.
     * @param  OcDbInterface|null  $db  OC native DB adapter.
     * @param  string  $prefix  Resolved OC table prefix.
     * @param  OcEventFirer  $eventFirer  Registry event dispatcher.
     * @param  bool  $callerMayUseModule  Whether the acting caller holds the phpClaw module grant.
     * @param  bool  $mayQueryRaw  Whether the caller holds the grant that permits raw SQL.
     * @param  bool  $applyProfile  Whether to apply the configured tool deny list.
     * @param  bool|null  $interactive  Whether the tools serve an interactive console session; null reads the console marker.
     * @return array<ToolInterface>
     */
    public function buildTools(
        array $config,
        ?OcDbInterface $db,
        string $prefix,
        OcEventFirer $eventFirer,
        bool $callerMayUseModule,
        bool $mayQueryRaw,
        bool $applyProfile = true,
        ?bool $interactive = null,
    ): array {
        $toolConfig = $this->configuredToolValues($config, $interactive);
        $extra = $this->resolveExtraTools($eventFirer->fire('phpclaw/extra/tools', []), $toolConfig);

        $tools = array_merge($extra, [
            new OcProductTool($db, $prefix, $callerMayUseModule),
            new OcCategoryTool($db, $prefix, $callerMayUseModule),
            new OcManufacturerTool($db, $prefix, $callerMayUseModule),
            new OcOrderTool($db, $prefix, $callerMayUseModule),
            new OcCustomerTool($db, $prefix, $callerMayUseModule),
            new OcReviewTool($db, $prefix, $callerMayUseModule),
            new OcCouponTool($db, $prefix, $callerMayUseModule),
            new OcShippingTool($db, $prefix, $callerMayUseModule),
            new DatabaseTool($db, $prefix, $callerMayUseModule, $mayQueryRaw, isConsole: $interactive),
            new LogTool('', $callerMayUseModule),
        ]);

        if (class_exists(ToolCatalogue::class)) {
            foreach (ToolCatalogue::instantiateDefaults($toolConfig) as $tool) {
                $tools[] = $tool;
            }
        }

        if (! $applyProfile) {
            return $tools;
        }

        ['deny' => $deny, 'groups' => $groups] = self::denyConfig($config);

        return ToolProfileResolver::filter($tools, $deny, $groups);
    }

    /**
     * Resolve the configured tool_deny list and the fixed group definitions used to expand it.
     *
     * @param  array<string, mixed>  $config
     * @return array{deny: string[], groups: array<string, string[]>}
     */
    public static function denyConfig(array $config): array
    {
        return [
            'deny' => (array) ($config['tool_deny'] ?? []),
            'groups' => [
                'group:commerce' => ['oc_product', 'oc_order', 'oc_customer', 'oc_coupon'],
                'group:catalog' => ['oc_category', 'oc_manufacturer', 'oc_review'],
                'group:system' => ['db_query', 'read_log', 'oc_shipping'],
            ],
        ];
    }

    /**
     * Compute the workspace root, store root, shell allowlist and CLI-only PHP-write rule shared by the
     * extras loop and {@see ToolCatalogue::instantiateDefaults()}.
     *
     * @param  array<string, mixed>  $config  Package default config.
     * @param  bool|null  $interactive  Whether the tools serve an interactive console session; null reads the console marker.
     * @return array{workspaceRoot: ?string, projectRoot: ?string, allowlist: array<int, string>, allowPhpWrite: bool}
     */
    private function configuredToolValues(array $config, ?bool $interactive): array
    {
        $workspaceRoot = (string) ($config['workspace_root'] ?? '');

        return [
            'workspaceRoot' => $workspaceRoot !== '' ? $workspaceRoot : null,
            'projectRoot' => defined('DIR_SYSTEM') ? dirname(rtrim((string) constant('DIR_SYSTEM'), '/')) : null,
            'allowlist' => (array) ($config['shell_allowlist'] ?? []),
            'allowPhpWrite' => $interactive ?? (defined('PHPCLAW_OC_CONSOLE') && constant('PHPCLAW_OC_CONSOLE') === true),
        ];
    }

    /**
     * Resolve the raw `phpclaw/extra/tools` bucket: an object passes through, `ShellTool`,
     * `FileWriteTool` and `FileEditTool` by class name get the configured values, any other no-arg class name is instantiated, everything else is dropped.
     *
     * @param  array<array-key, mixed>  $extra  Raw bucket from the extension event.
     * @param  array{workspaceRoot: ?string, projectRoot: ?string, allowlist: array<int, string>, allowPhpWrite: bool}  $toolConfig  Values from {@see configuredToolValues()}.
     * @return list<ToolInterface>
     */
    private function resolveExtraTools(array $extra, array $toolConfig): array
    {
        $resolved = [];

        foreach ($extra as $item) {
            if ($item instanceof ToolInterface) {
                $resolved[] = $item;

                continue;
            }

            if (! is_string($item) || ! class_exists($item) || ! is_a($item, ToolInterface::class, true)) {
                continue;
            }

            if ($item === ShellTool::class) {
                $resolved[] = new ShellTool(allowlist: $toolConfig['allowlist']);

                continue;
            }

            if ($item === FileWriteTool::class) {
                $resolved[] = new FileWriteTool(workspaceRoot: $toolConfig['workspaceRoot'], allowPhpWrite: $toolConfig['allowPhpWrite']);

                continue;
            }

            if ($item === FileEditTool::class) {
                $resolved[] = new FileEditTool(workspaceRoot: $toolConfig['workspaceRoot']);

                continue;
            }

            $constructor = (new \ReflectionClass($item))->getConstructor();
            if ($constructor !== null && $constructor->getNumberOfRequiredParameters() > 0) {
                continue;
            }

            $resolved[] = new $item;
        }

        return $resolved;
    }

    /**
     * Extract trimmed remote-skill collection URLs from saved settings.
     *
     * @param  array<string, mixed>  $saved  Admin-saved settings.
     * @return list<string> HTTPS URLs the builder loads on build.
     */
    private function resolveRemoteSkillUrls(array $saved): array
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
     * Whether a custom-provider base_url is allowed: HTTPS anywhere, plain HTTP only for a loopback host.
     *
     * @param  string  $baseUrl  Trimmed custom-provider endpoint URL.
     * @return bool True for https URLs and for http URLs targeting localhost/127.0.0.1/::1.
     */
    private function isAllowedProviderUrl(string $baseUrl): bool
    {
        if (preg_match('~^https://~i', $baseUrl) === 1) {
            return true;
        }

        if (preg_match('~^http://~i', $baseUrl) !== 1) {
            return false;
        }

        $host = strtolower((string) (parse_url($baseUrl, PHP_URL_HOST) ?: ''));
        $host = trim($host, '[]');

        return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    }
}
