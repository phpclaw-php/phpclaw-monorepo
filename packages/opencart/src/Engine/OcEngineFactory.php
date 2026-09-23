<?php

declare(strict_types=1);

namespace PhpClaw\OpenCart\Engine;

use PhpClaw\Agent\CliApprovalGate;
use PhpClaw\AutoDiscovery\Bootstrap;
use PhpClaw\Claw;
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
use PhpClaw\Tools\ToolCatalogue;
use PhpClaw\Tools\ToolProfileResolver;

/**
 * Assembles the configured Claw engine for OpenCart 3/4.
 */
final class OcEngineFactory
{
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

        return $builder->build();
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
     * Build the list of OpenCart-native tools.
     *
     * @param  array<string, mixed>  $config  Package default config.
     * @param  OcDbInterface|null  $db  OC native DB adapter.
     * @param  string  $prefix  Resolved OC table prefix.
     * @param  OcEventFirer  $eventFirer  Registry event dispatcher.
     * @param  bool  $callerMayUseModule  Whether the acting caller holds the phpClaw module grant.
     * @param  bool  $mayQueryRaw  Whether the caller holds the grant that permits raw SQL.
     * @param  bool  $applyProfile  Whether to apply the configured tool deny list.
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
    ): array {
        $extra = $eventFirer->fire('phpclaw/extra/tools', []);
        $extra = array_values(array_filter(
            $extra,
            static fn ($t): bool => $t instanceof ToolInterface,
        ));

        $tools = array_merge($extra, [
            new OcProductTool($db, $prefix, $callerMayUseModule),
            new OcCategoryTool($db, $prefix, $callerMayUseModule),
            new OcManufacturerTool($db, $prefix, $callerMayUseModule),
            new OcOrderTool($db, $prefix, $callerMayUseModule),
            new OcCustomerTool($db, $prefix, $callerMayUseModule),
            new OcReviewTool($db, $prefix, $callerMayUseModule),
            new OcCouponTool($db, $prefix, $callerMayUseModule),
            new OcShippingTool($db, $prefix, $callerMayUseModule),
            new DatabaseTool($db, $prefix, $callerMayUseModule, $mayQueryRaw),
            new LogTool('', $callerMayUseModule),
        ]);

        if (class_exists(ToolCatalogue::class)) {
            $workspaceRoot = (string) ($config['workspace_root'] ?? '');
            $allowlist = (array) ($config['shell_allowlist'] ?? []);

            foreach (ToolCatalogue::instantiateDefaults([
                'workspaceRoot' => $workspaceRoot !== '' ? $workspaceRoot : null,
                'allowlist' => $allowlist,
                'allowPhpWrite' => defined('PHPCLAW_OC_CONSOLE') && constant('PHPCLAW_OC_CONSOLE') === true,
            ]) as $tool) {
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
