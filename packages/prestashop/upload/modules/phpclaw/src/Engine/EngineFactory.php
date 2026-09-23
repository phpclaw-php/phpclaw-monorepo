<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Engine;

use PhpClaw\Agent\CliApprovalGate;
use PhpClaw\AutoDiscovery\Bootstrap;
use PhpClaw\Claw;
use PhpClaw\ClawConfig;
use PhpClaw\Cloud\CloudManager;
use PhpClaw\Guards\Contracts\GuardInterface;
use PhpClaw\Guards\GuardRegistry;
use PhpClaw\Hooks\HookEventBridge;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Memory\MemoryRegistry;
use PhpClaw\Memory\PrivacyAwareMemory;
use PhpClaw\PrestaShop\Contracts\PsDbInterface;
use PhpClaw\PrestaShop\Memory\PsDbConversationMemory;
use PhpClaw\PrestaShop\Memory\PsDbMemory;
use PhpClaw\PrestaShop\Memory\PsRouterMemory;
use PhpClaw\PrestaShop\Memory\PsSettingMemory;
use PhpClaw\PrestaShop\PsHookBridge;
use PhpClaw\PrestaShop\Tools\DatabaseTool;
use PhpClaw\PrestaShop\Tools\LogTool;
use PhpClaw\PrestaShop\Tools\PsCartTool;
use PhpClaw\PrestaShop\Tools\PsCategoryTool;
use PhpClaw\PrestaShop\Tools\PsConfigTool;
use PhpClaw\PrestaShop\Tools\PsCouponTool;
use PhpClaw\PrestaShop\Tools\PsCustomerTool;
use PhpClaw\PrestaShop\Tools\PsEmployeeTool;
use PhpClaw\PrestaShop\Tools\PsManufacturerTool;
use PhpClaw\PrestaShop\Tools\PsModuleTool;
use PhpClaw\PrestaShop\Tools\PsOrderTool;
use PhpClaw\PrestaShop\Tools\PsProductTool;
use PhpClaw\PrestaShop\Tools\PsReportTool;
use PhpClaw\PrestaShop\Tools\PsStockTool;
use PhpClaw\Providers\Contracts\ProviderInterface;
use PhpClaw\Providers\OpenAIPresets;
use PhpClaw\Providers\OpenAIProvider;
use PhpClaw\Providers\ProviderRegistry;
use PhpClaw\Skills\Contracts\SkillInterface;
use PhpClaw\Skills\SkillCatalogue;
use PhpClaw\Skills\SkillRegistry;
use PhpClaw\Skills\SkillResolver;
use PhpClaw\Tools\Contracts\ToolInterface;
use PhpClaw\Tools\ToolCatalogue;
use PhpClaw\Tools\ToolProfileResolver;

/**
 * Builds a fully-configured phpClaw engine from saved settings and package config.
 */
final class EngineFactory
{
    private const DEFAULT_MEMORY_DRIVER = 'ps_router';

    /**
     * Create a new EngineFactory instance.
     *
     * @param  array<string, mixed>  $saved  Admin-saved settings from ps_configuration.
     * @param  array<string, mixed>  $config  Package defaults from config/phpclaw.php.
     * @param  PsDbInterface|null  $db  Native PS database handle.
     * @param  string  $tablePrefix  Table prefix (e.g. 'ps_').
     * @param  int  $actingEmployeeId  Acting employee id, or the `0` unverified-identity sentinel.
     * @param  bool  $manageAll  Whether the acting identity holds the manage-all-conversations grant.
     */
    public function __construct(
        private readonly array $saved,
        private readonly array $config,
        private readonly ?PsDbInterface $db,
        private readonly string $tablePrefix,
        private readonly int $actingEmployeeId = 0,
        private readonly bool $manageAll = false,
        private readonly bool $isConsole = false,
    ) {}

    /**
     * Register all PS memory drivers, guards, hooks, skills, and cloud at boot time.
     *
     * @return void
     */
    public function bootRegistries(): void
    {
        $msgSaved = $this->saved['store_messages'] ?? '';
        $persistMsg = $msgSaved !== ''
            ? in_array($msgSaved, ['1', 'true', 'yes', 'on'], true)
            : true;

        GuardRegistry::registerDefaults();

        MemoryRegistry::register('ps_setting', fn () => new PsSettingMemory(
            $this->db,
            $this->tablePrefix,
        ));

        MemoryRegistry::register('ps_db', fn () => new PsDbMemory(
            $this->db,
            $this->tablePrefix,
        ));

        MemoryRegistry::register('ps_router', fn () => new PsRouterMemory(
            new PsDbConversationMemory(
                $this->db,
                $this->tablePrefix,
                $persistMsg,
                $this->actingEmployeeId,
                $this->manageAll,
            ),
            new PsDbMemory($this->db, $this->tablePrefix),
        ));

        Bootstrap::boot();
        SkillCatalogue::activateDefaults();

        $this->registerConfigGuards();
        $this->registerConfigHooks();
        $this->registerSkills();

        $this->registerExtraGuards();
        $this->registerExtraHooks();
        $this->registerExtraSkills();
        $this->registerExtraMemory();
        $this->registerExtraProviders();

        $cloudKey = (string) ($this->saved['cloud_key'] ?? '');
        $cloudSigningSecret = (string) ($this->saved['cloud_signing_secret'] ?? '');
        $cloudDisable = ($this->saved['cloud_disable'] ?? null) !== null
            ? (array) $this->saved['cloud_disable']
            : [];

        if ($persistMsg && class_exists(CloudManager::class)) {
            CloudManager::boot($cloudKey, $cloudDisable, $cloudSigningSecret);
        }
    }

    /**
     * Build and return a configured Claw engine instance.
     *
     * @param  bool|null  $isCli  True only when an interactive console entrypoint says so. Null takes the value the entrypoint declared when it built this factory.
     * @return Claw
     */
    public function build(?bool $isCli = null): Claw
    {
        $provider = (string) ($this->saved['provider'] ?? '');
        $model = (string) ($this->saved['model'] ?? '');
        $apiKey = (string) ($this->saved['api_key'] ?? '');
        $maxIterations = (int) (($this->saved['max_iterations'] ?? 0) ?: ClawConfig::DEFAULT_MAX_ITERATIONS);
        $memoryDriver = self::DEFAULT_MEMORY_DRIVER;

        $msgRaw = $this->saved['store_messages'] ?? null;
        $persistMsgs = $msgRaw !== null
            ? in_array($msgRaw, ['1', 'true', 'yes', 'on'], true)
            : true;

        if ($provider === 'ollama') {
            $apiKey = '';
        }

        Bootstrap::activateFromSettings($this->saved);

        $systemPrompt = (string) ($this->saved['system_prompt'] ?? '');

        $memory = MemoryRegistry::build($memoryDriver);

        if (! $persistMsgs) {
            $memory = new PrivacyAwareMemory($memory, false);
        }

        $tools = $this->buildTools(isCli: $isCli);

        $builder = Claw::builder()
            ->apiKey($apiKey)
            ->provider($provider)
            ->model($model)
            ->systemPrompt($systemPrompt)
            ->storeMessages($persistMsgs)
            ->maxIterations($maxIterations)
            ->maxToolsPerTurn(ToolProfileResolver::maxTools(ToolProfileResolver::resolve($provider, $model)))
            ->tools($tools)
            ->shellAllowlist((array) ($this->config['shell_allowlist'] ?? []))
            ->memory($memory)
            ->skills($this->resolveSkills())
            ->approvalGate(new CliApprovalGate);

        $override = $this->customProviderOverride($provider, $apiKey, $model, $systemPrompt);
        if ($override !== null) {
            $builder->providerOverride($override);
        }

        foreach ($this->resolveRemoteSkillUrls() as $url) {
            $builder->withRemoteSkills($url);
        }

        $engine = $builder->build();

        if ((bool) ($this->config['events_bridge'] ?? true)) {
            (new HookEventBridge(
                static function (string $event, array $ctx): void {
                    if (! class_exists('\Hook')) {
                        return;
                    }
                    try {
                        \Hook::exec(
                            'actionPhpClaw'.str_replace('.', '', ucwords($event, '.')),
                            ['event' => $event, 'context' => $ctx],
                        );
                    } catch (\Throwable) {
                    }
                },
            ))->register();
        }

        return $engine;
    }

    /**
     * Build all registered PS + extra tools.
     *
     * @param  bool  $applyProfile  Apply the configured tool deny list.
     * @param  bool|null  $isCli  True only when an interactive console entrypoint says so. Null takes the value the entrypoint declared when it built this factory.
     * @return array<ToolInterface>
     */
    public function buildTools(bool $applyProfile = true, ?bool $isCli = null): array
    {
        $isCli = $isCli ?? $this->isConsole;

        $extra = $this->fireExtraEvent('phpclaw/extra/tools', []);
        $extra = array_values(array_filter(
            $extra,
            static fn ($t): bool => $t instanceof ToolInterface,
        ));

        $tools = array_merge($extra, [
            new PsProductTool($this->db, $this->tablePrefix),
            new PsOrderTool($this->db, $this->tablePrefix),
            new PsCustomerTool($this->db, $this->tablePrefix),
            new PsCategoryTool($this->db, $this->tablePrefix),
            new PsManufacturerTool($this->db, $this->tablePrefix),
            new PsCartTool($this->db, $this->tablePrefix),
            new PsStockTool($this->db, $this->tablePrefix),
            new PsCouponTool($this->db, $this->tablePrefix),
            new PsModuleTool($this->db, $this->tablePrefix),
            new PsReportTool($this->db, $this->tablePrefix),
            new PsConfigTool($this->db, $this->tablePrefix),
            new PsEmployeeTool($this->db, $this->tablePrefix),
            new DatabaseTool($this->db, $this->tablePrefix, $isCli),
            new LogTool(defined('_PS_ROOT_DIR_') ? _PS_ROOT_DIR_ : ''),
        ]);

        if (class_exists(ToolCatalogue::class)) {
            $workspaceRoot = (string) (($this->saved['workspace_root'] ?? '') ?: ($this->config['workspace_root'] ?? ''));

            foreach (ToolCatalogue::instantiateDefaults([
                'workspaceRoot' => $workspaceRoot !== '' ? $workspaceRoot : null,
                'allowlist' => (array) ($this->config['shell_allowlist'] ?? []),
                'allowPhpWrite' => $isCli,
            ]) as $tool) {
                $tools[] = $tool;
            }
        }

        if (! $applyProfile) {
            return $tools;
        }

        ['deny' => $deny, 'groups' => $groups] = $this->denyConfig();

        return ToolProfileResolver::filter($tools, $deny, $groups);
    }

    /**
     * Resolve the configured tool_deny list and the fixed group definitions used to expand it.
     *
     * @return array{deny: string[], groups: array<string, string[]>}
     */
    public function denyConfig(): array
    {
        return [
            'deny' => (array) ($this->config['tool_deny'] ?? []),
            'groups' => [
                'group:commerce' => ['ps_product', 'ps_order', 'ps_customer', 'ps_cart', 'ps_coupon'],
                'group:catalog' => ['ps_category', 'ps_manufacturer', 'ps_stock'],
                'group:system' => ['database', 'ps_log', 'ps_config', 'ps_module', 'ps_employee', 'ps_report'],
            ],
        ];
    }

    /**
     * Fire a phpClaw extension event via PrestaShop hooks and return the merged bucket.
     *
     * @deprecated Kept public for backward compatibility with third-party PS modules
     *             that may call EngineFactory::fireExtra directly. Use
     *             {@see PsHookBridge::fireExtra()} instead.
     *
     * @param  string  $eventName  e.g. `phpclaw/extra/tools`
     * @param  array<mixed>  $bucket  Initial accumulator.
     * @return array<mixed>
     */
    public static function fireExtra(string $eventName, array $bucket): array
    {
        return PsHookBridge::fireExtra($eventName, $bucket);
    }

    /**
     * Build an OpenAI-compatible provider override for the `custom` provider slot.
     *
     * @param  string  $provider  Resolved provider slug.
     * @param  string  $apiKey  Resolved API key.
     * @param  string  $model  Resolved model id.
     * @param  string  $systemPrompt  Resolved system prompt.
     * @return ?ProviderInterface
     */
    private function customProviderOverride(
        string $provider,
        string $apiKey,
        string $model,
        string $systemPrompt,
    ): ?ProviderInterface {
        if ($provider !== 'custom') {
            return null;
        }

        $baseUrl = trim((string) ($this->saved['base_url'] ?? ''));
        if ($baseUrl === '' || ! $this->isAllowedProviderUrl($baseUrl)) {
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

    /**
     * Register guards declared in the config `guards` array.
     *
     * @return void
     */
    private function registerConfigGuards(): void
    {
        foreach ((array) ($this->config['guards'] ?? []) as $entry) {
            if (! is_array($entry) || ! isset($entry['class']) || ! is_string($entry['class'])) {
                continue;
            }
            if (! class_exists($entry['class'])) {
                continue;
            }
            GuardRegistry::register(new $entry['class'], (int) ($entry['priority'] ?? 10), replace: true);
        }
    }

    /**
     * Register hook listeners declared in the config `hooks` array.
     *
     * @return void
     */
    private function registerConfigHooks(): void
    {
        foreach ((array) ($this->config['hooks'] ?? []) as $entry) {
            if (! is_array($entry) || ! isset($entry['event'], $entry['handler'])) {
                continue;
            }
            if (! is_string($entry['event']) || $entry['event'] === '') {
                continue;
            }
            HookRegistry::on($entry['event'], $entry['handler'], (int) ($entry['priority'] ?? 10));
        }
    }

    /**
     * Register skills from config into the SkillRegistry at boot.
     *
     * @return void
     */
    private function registerSkills(): void
    {
        foreach ($this->resolveSkills() as $skill) {
            SkillRegistry::register($skill);
        }
    }

    /**
     * Resolve skills from the config `skills` array via the core SkillResolver canonical.
     *
     * @return SkillInterface[]
     */
    private function resolveSkills(): array
    {
        return SkillResolver::resolve((array) ($this->config['skills'] ?? []));
    }

    /**
     * Extract the saved remote skill collection URLs, trimmed and non-empty.
     *
     * @return list<string>
     */
    private function resolveRemoteSkillUrls(): array
    {
        $urls = $this->saved['remote_skill_urls'] ?? [];

        if (! is_array($urls)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn ($url): string => is_string($url) ? trim($url) : '', $urls),
            static fn (string $url): bool => $url !== ''
        ));
    }

    /**
     * Register guards contributed by installed modules via `phpclaw/extra/guards`.
     *
     * @return void
     */
    private function registerExtraGuards(): void
    {
        foreach ($this->fireExtraEvent('phpclaw/extra/guards', []) as $guard) {
            if ($guard instanceof GuardInterface) {
                GuardRegistry::register($guard, replace: true);
            }
        }
    }

    /**
     * Subscribe hook handlers contributed by installed modules via `phpclaw/extra/hooks`.
     *
     * @return void
     */
    private function registerExtraHooks(): void
    {
        foreach ($this->fireExtraEvent('phpclaw/extra/hooks', []) as $entry) {
            if (! is_array($entry) || ! isset($entry['event'], $entry['handler'])) {
                continue;
            }
            if (! is_string($entry['event']) || $entry['event'] === '') {
                continue;
            }
            HookRegistry::on($entry['event'], $entry['handler'], (int) ($entry['priority'] ?? 10));
        }
    }

    /**
     * Register skills contributed by installed modules via `phpclaw/extra/skills`.
     *
     * @return void
     */
    private function registerExtraSkills(): void
    {
        foreach ($this->fireExtraEvent('phpclaw/extra/skills', []) as $skill) {
            if ($skill instanceof SkillInterface) {
                SkillRegistry::register($skill);
            }
        }
    }

    /**
     * Register memory drivers contributed by installed modules via `phpclaw/extra/memory`.
     *
     * @return void
     */
    private function registerExtraMemory(): void
    {
        foreach ($this->fireExtraEvent('phpclaw/extra/memory', []) as $slug => $factory) {
            if (is_string($slug) && $slug !== '' && is_callable($factory)) {
                MemoryRegistry::register($slug, $factory);
            }
        }
    }

    /**
     * Register providers contributed by installed modules via `phpclaw/extra/providers`.
     *
     * @return void
     */
    private function registerExtraProviders(): void
    {
        foreach ($this->fireExtraEvent('phpclaw/extra/providers', []) as $slug => $entry) {
            if (! is_string($slug) || $slug === '' || ! is_array($entry)) {
                continue;
            }
            $class = $entry['class'] ?? '';
            if (! is_string($class) || ! class_exists($class)) {
                continue;
            }
            if (! ProviderRegistry::has($slug)) {
                try {
                    ProviderRegistry::register($slug, $class);
                } catch (\Throwable) {
                }
            }
        }
    }

    /**
     * Delegate to PsHookBridge to fire a phpClaw extension event.
     *
     * @param  string  $eventName  e.g. `phpclaw/extra/tools`
     * @param  array<mixed>  $bucket  Initial accumulator.
     * @return array<mixed>
     */
    private function fireExtraEvent(string $eventName, array $bucket): array
    {
        return PsHookBridge::fireExtra($eventName, $bucket);
    }
}
