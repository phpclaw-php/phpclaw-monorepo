<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Factory;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\AuthorizationInterface;
use PhpClaw\Agent\CliApprovalGate;
use PhpClaw\Claw;
use PhpClaw\Contracts\ClawInterface as PhpClawInterface;
use PhpClaw\Magento\Memory\RouterMemory;
use PhpClaw\Magento\Model\Config;
use PhpClaw\Magento\Model\IdentityResolver;
use PhpClaw\Magento\Registry\PhpClawRegistrar;
use PhpClaw\Magento\Service\CsvList;
use PhpClaw\Magento\Service\UrlSafety;
use PhpClaw\Magento\Tools\DatabaseTool;
use PhpClaw\Magento\Tools\LogTool;
use PhpClaw\Magento\Tools\MagentoCacheTool;
use PhpClaw\Magento\Tools\MagentoCategoryTool;
use PhpClaw\Magento\Tools\MagentoCustomerTool;
use PhpClaw\Magento\Tools\MagentoInventoryTool;
use PhpClaw\Magento\Tools\MagentoOrderTool;
use PhpClaw\Magento\Tools\MagentoProductTool;
use PhpClaw\Magento\Tools\MagentoReportTool;
use PhpClaw\Magento\Tools\MagentoStoreTool;
use PhpClaw\Memory\Contracts\MemoryInterface;
use PhpClaw\Memory\PrivacyAwareMemory;
use PhpClaw\Providers\OpenAIPresets;
use PhpClaw\Providers\OpenAIProvider;
use PhpClaw\Tools\Contracts\ToolInterface;
use PhpClaw\Tools\HttpTool;
use PhpClaw\Tools\ShellTool;
use PhpClaw\Tools\ToolCatalogue;
use PhpClaw\Tools\ToolProfileResolver;

/**
 * Factory that builds a configured phpClaw agent instance for Magento.
 */
// non-final: Magento interceptor required
class PhpClawFactory implements PhpClawFactoryInterface
{
    public const TOOL_GROUPS = [
        'group:commerce' => ['magento_products', 'magento_orders', 'magento_customer', 'magento_inventory'],
        'group:catalog' => ['magento_categories', 'magento_stores', 'magento_report'],
        'group:system' => ['magento_cache', 'db_query', 'read_log'],
    ];

    /**
     * Bind the config reader and registrar this factory builds agents from.
     *
     * @param  Config  $config  Admin configuration reader.
     * @param  PhpClawRegistrar  $registrar  Bootstraps phpClaw's static registries on first injection.
     * @param  ResourceConnection  $resourceConnection  Magento DB connection provider forwarded to DB-backed tools.
     * @param  TypeListInterface  $cacheTypeList  Magento cache type registry forwarded to MagentoCacheTool.
     * @param  RouterMemory  $routerMemory  Namespace-routing memory driver wired by di.xml.
     * @param  AuthorizationInterface  $authorization
     * @return void
     */
    public function __construct(
        private readonly Config $config,
        private readonly PhpClawRegistrar $registrar,
        private readonly ResourceConnection $resourceConnection,
        private readonly TypeListInterface $cacheTypeList,
        private readonly RouterMemory $routerMemory,
        private readonly AuthorizationInterface $authorization,
        private readonly IdentityResolver $identity,
    ) {}

    /**
     * Resolve the active memory backend.
     *
     * @return MemoryInterface Configured memory driver.
     */
    public function resolveMemory(): MemoryInterface
    {
        return $this->routerMemory;
    }

    /**
     * Build a fully-configured PhpClaw instance from admin settings.
     *
     * @return PhpClawInterface Configured and ready-to-use agent instance.
     */
    public function create(): PhpClawInterface
    {
        $memory = $this->resolveMemory();
        if (! $this->config->isStoreMessages()) {
            $memory = $this->wrapWithoutMessagePersistence($memory);
        }

        $tools = $this->assembleTools($this->isConsole());

        return $this->buildClaw($memory, $tools);
    }

    /**
     * Expose the resolved tool list for the MCP server registry. MCP dispatches tool calls directly,
     * bypassing the approval-gate loop, so PHP-file writes are always denied here regardless of console detection.
     *
     * @return array<int, ToolInterface>
     */
    public function buildTools(): array
    {
        return $this->assembleTools(false);
    }

    /**
     * Expose every tool this adapter registers, before the provider profile filter narrows them.
     *
     * @return array<int, ToolInterface>
     */
    public function registeredTools(): array
    {
        $byName = [];

        foreach ($this->defaultTools() as $tool) {
            $byName[$tool->name()] = $tool;
        }

        return array_values($this->mergeExtraTools($this->mergeCoreTools($byName, false)));
    }

    /**
     * Construct the Magento-native and core leaf tools registered by default.
     *
     * @return array<int, ToolInterface>
     */
    protected function defaultTools(): array
    {
        $tools = [
            new MagentoProductTool($this->resourceConnection, $this->identity, $this->authorization),
            new MagentoInventoryTool($this->resourceConnection, $this->identity, $this->authorization),
            new MagentoCategoryTool($this->resourceConnection, $this->identity, $this->authorization),
            new MagentoStoreTool($this->resourceConnection, $this->identity, $this->authorization),
            new MagentoReportTool($this->resourceConnection, $this->identity, $this->authorization),
            new MagentoCacheTool($this->cacheTypeList, $this->identity, $this->authorization),
            new LogTool($this->identity, $this->authorization),
            new HttpTool,
            new MagentoOrderTool($this->resourceConnection, $this->identity, $this->authorization),
            new MagentoCustomerTool($this->resourceConnection, $this->identity, $this->authorization),
            new DatabaseTool($this->resourceConnection, $this->identity, $this->authorization),
            new ShellTool(allowlist: $this->config->getShellAllowlist()),
        ];

        return $tools;
    }

    /**
     * Whether this run is a console command rather than an HTTP request.
     *
     * @return bool True in Magento console commands; false in Adminhtml web requests.
     */
    protected function isConsole(): bool
    {
        return $this->identity->runningInConsole();
    }

    /**
     * Decorate a memory driver so message content is never persisted.
     *
     * @param  MemoryInterface  $memory  Memory driver to wrap.
     * @return MemoryInterface Privacy-aware decorator around $memory.
     */
    private function wrapWithoutMessagePersistence(MemoryInterface $memory): MemoryInterface
    {
        return new PrivacyAwareMemory($memory, false);
    }

    /**
     * Resolve the default tool set keyed by name, then apply the profile filter.
     *
     * @param  bool  $allowPhpWrite  True to permit file_write to write .php/.phtml/.phar files.
     * @return array<int, ToolInterface>
     */
    private function assembleTools(bool $allowPhpWrite): array
    {
        $byName = [];

        foreach ($this->defaultTools() as $tool) {
            $byName[$tool->name()] = $tool;
        }

        return $this->resolveProfile($byName, $allowPhpWrite);
    }

    /**
     * Merge core defaults and extra tools onto the default set, then apply the profile filter.
     *
     * @param  array<string, ToolInterface>  $byName  Default tools keyed by name.
     * @param  bool  $allowPhpWrite  True to permit file_write to write .php/.phtml/.phar files.
     * @return array<int, ToolInterface>
     */
    private function resolveProfile(array $byName, bool $allowPhpWrite): array
    {
        $byName = $this->mergeCoreTools($byName, $allowPhpWrite);
        $byName = $this->mergeExtraTools($byName);

        return ToolProfileResolver::filter(
            array_values($byName),
            $this->config->getToolDeny(),
            self::TOOL_GROUPS,
        );
    }

    /**
     * Merge core catalogue tools in, keeping any same-named Magento-native tool already present.
     *
     * @param  array<string, ToolInterface>  $byName  Tools keyed by name.
     * @param  bool  $allowPhpWrite  True to permit file_write to write .php/.phtml/.phar files.
     * @return array<string, ToolInterface>
     */
    private function mergeCoreTools(array $byName, bool $allowPhpWrite): array
    {
        if (class_exists(ToolCatalogue::class)) {
            foreach (ToolCatalogue::instantiateDefaults([
                'allowlist' => $this->config->getShellAllowlist(),
                'workspaceRoot' => null,
                'allowPhpWrite' => $allowPhpWrite,
            ]) as $coreTool) {
                $byName[$coreTool->name()] ??= $coreTool;
            }
        }

        return $byName;
    }

    /**
     * Merge module-registered extra tools in, overriding any same-named tool.
     *
     * @param  array<string, ToolInterface>  $byName  Tools keyed by name.
     * @return array<string, ToolInterface>
     */
    private function mergeExtraTools(array $byName): array
    {
        foreach ($this->registrar->getExtraTools() as $extraTool) {
            $byName[$extraTool->name()] = $extraTool;
        }

        return $byName;
    }

    /**
     * Construct the final Claw agent from resolved memory, tools, and config.
     *
     * @param  MemoryInterface  $memory  Resolved (and optionally privacy-wrapped) memory driver.
     * @param  array<int, ToolInterface>  $tools  Filtered tool list for the current provider/model.
     * @return PhpClawInterface Fully configured agent ready for use.
     */
    private function buildClaw(MemoryInterface $memory, array $tools): PhpClawInterface
    {
        $builder = Claw::builder()
            ->apiKey($this->config->getApiKey())
            ->provider($this->config->getProvider())
            ->model($this->config->getModel())
            ->cloudKey($this->config->isStoreMessages() ? $this->config->getCloudKey() : '')
            ->cloudDisable(CsvList::parse($this->config->getCloudDisable()))
            ->cloudSigningSecret($this->config->getCloudSigningSecret())
            ->storeMessages($this->config->isStoreMessages())
            ->maxIterations($this->config->getMaxIterations())
            ->maxToolsPerTurn(ToolProfileResolver::maxTools(ToolProfileResolver::resolve($this->config->getProvider(), $this->config->getModel())))
            ->tools($tools)
            ->memory($memory)
            ->systemPrompt($this->config->getSystemPrompt());

        foreach ($this->config->getRemoteSkillUrls() as $url) {
            $url = trim($url);
            if ($url !== '') {
                $builder->withRemoteSkills($url);
            }
        }

        $override = $this->customProviderOverride();
        if ($override !== null) {
            $builder->providerOverride($override);
        }

        $builder->approvalGate(new CliApprovalGate);

        return $builder->build();
    }

    /**
     * Build an OpenAIProvider override when provider=custom and base_url is a valid URL.
     *
     * @return ?OpenAIProvider
     */
    private function customProviderOverride(): ?OpenAIProvider
    {
        $provider = $this->config->getProvider();
        $baseUrl = trim($this->config->getBaseUrl());

        if ($provider !== 'custom' || $baseUrl === '') {
            return null;
        }

        if (! preg_match('~^https://~i', $baseUrl) || UrlSafety::isInternalUrl($baseUrl)) {
            return null;
        }

        return new OpenAIProvider(
            apiKey: $this->config->getApiKey(),
            model: $this->config->getModel(),
            systemPrompt: $this->config->getSystemPrompt(),
            endpoint: $baseUrl,
            name: 'custom',
            authStyle: OpenAIPresets::AUTH_BEARER,
        );
    }
}
