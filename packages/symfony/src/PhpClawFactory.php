<?php

declare(strict_types=1);

namespace PhpClaw\Symfony;

use Doctrine\DBAL\Connection;
use PhpClaw\Agent\CliApprovalGate;
use PhpClaw\Claw as PhpClaw;
use PhpClaw\ClawBuilder;
use PhpClaw\ClawConfig;
use PhpClaw\Contracts\ClawInterface as PhpClawInterface;
use PhpClaw\Memory\Contracts\MemoryInterface;
use PhpClaw\Memory\PrivacyAwareMemory;
use PhpClaw\Providers\Contracts\ProviderInterface;
use PhpClaw\Providers\OpenAIPresets;
use PhpClaw\Providers\OpenAIProvider;
use PhpClaw\Skills\Contracts\SkillInterface;
use PhpClaw\Skills\SkillResolver;
use PhpClaw\Support\Log;
use PhpClaw\Symfony\Console\ConsoleContext;
use PhpClaw\Symfony\Extension\PhpClawExtensions;
use PhpClaw\Symfony\Support\ArgumentFreeConstructor;
use PhpClaw\Symfony\Tools\DatabaseTool;
use PhpClaw\Symfony\Tools\LogTool;
use PhpClaw\Symfony\Tools\TestRunTool;
use PhpClaw\Tools\Contracts\ToolInterface;
use PhpClaw\Tools\FileEditTool;
use PhpClaw\Tools\FileReadTool;
use PhpClaw\Tools\FileWriteTool;
use PhpClaw\Tools\ShellTool;
use PhpClaw\Tools\ToolCatalogue;
use PhpClaw\Tools\ToolProfileResolver;
use PhpClaw\Tools\ToolRegistry;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Psr16Cache;

/**
 * Factory that builds a fully-wired PhpClaw engine from Symfony DI parameters.
 */
final class PhpClawFactory
{
    public const DURABLE_ENGINE = 'phpclaw.durable_engine';

    public const TERMINAL_ENGINE = 'phpclaw.terminal_engine';

    public const TOOL_GROUPS = [
        'group:system' => ['db_query', 'read_log', 'http_request', 'file_read', 'file_write', 'shell_exec'],
    ];

    /**
     * Constructs the factory with all engine configuration resolved from the DI container.
     *
     * @param  string  $apiKey  Provider API key.
     * @param  string  $provider  Provider slug (openai, anthropic, etc.).
     * @param  string  $model  Model identifier.
     * @param  bool  $storeMessages  Whether to persist message content.
     * @param  int  $maxIterations  Maximum agent loop iterations.
     * @param  string[]  $shellAllowlist  Allowed shell commands for ShellTool.
     * @param  string[]  $tools  Tool class-strings to register.
     * @param  MemoryInterface  $memory  Configured memory driver.
     * @param  string  $workspaceRoot  Workspace root path for file tools.
     * @param  string  $systemPrompt  Optional system prompt.
     * @param  int  $maxTokens  Max output tokens (0 = provider default).
     * @param  bool  $promptCache  Whether to enable prompt caching.
     * @param  int  $thinkingBudget  Extended thinking token budget (0 = off).
     * @param  mixed[]  $skills  Skill definitions to resolve.
     * @param  string  $baseUrl  Base URL for the 'custom' provider.
     * @param  string[]  $toolDeny  Tool names to exclude from the registry.
     * @param  string  $remoteSkillUrls  Comma-separated HTTPS SKILL.md / JSON URLs loaded at build (always-on, picked by name).
     * @param  PhpClawExtensions  $extensions  Runtime extensions bag.
     * @param  Connection|null  $connection  Doctrine DBAL connection for DatabaseTool, or null when absent.
     * @param  bool|null  $console  Console context override; null defers to the ConsoleContext service.
     * @param  ConsoleContext|null  $consoleContext  Marks an interactive console run; null is treated as not console.
     * @param  SymfonyIdentityResolver|null  $identity  Names the acting user for the tool capability guard.
     * @param  bool  $requireChatRole  True when the host app requires ROLE_PHPCLAW_CHAT rather than any authenticated user.
     * @param  string  $projectRoot  Absolute Symfony kernel project directory, independent of process CWD (MCP/console spawns may not set one).
     * @param  string  $fallbackProvider  Fallback provider slug tried when the primary fails; empty disables it.
     * @param  string  $fallbackModel  Fallback provider model override.
     * @param  string  $fallbackApiKey  Fallback provider API key.
     * @param  int  $rateLimitRpm  Outbound requests per minute, clamped 0-600; 0 disables the limit.
     * @param  bool  $responseCache  Whether to cache provider responses on the shared cache pool.
     * @param  int  $responseCacheTtl  Response cache lifetime in seconds, clamped 60-86400.
     * @param  int  $maxTokenBudget  Total token spend ceiling per run; 0 disables the budget.
     * @param  CacheItemPoolInterface|null  $cachePool  Shared PSR-6 cache pool backing the rate limit and response cache; null disables both.
     * @param  bool  $durableRuns  Save every run after each step and let web and worker runs pause for approval; needs store_messages.
     * @param  int  $durableStepBudget  Steps one process runs before the run is suspended; 0 = no limit.
     * @param  int|null  $durableDeadlineSeconds  Seconds one process runs before the run is suspended; null = half of max_execution_time.
     * @param  string  $environment  Kernel environment; `prod` refuses the console-only test_run tool.
     */
    public function __construct(
        private readonly string $apiKey,
        private readonly string $provider,
        private readonly string $model,
        private readonly bool $storeMessages,
        private readonly int $maxIterations,
        private readonly array $shellAllowlist,
        private readonly array $tools,
        private readonly MemoryInterface $memory,
        private readonly string $workspaceRoot,
        private readonly string $systemPrompt,
        private readonly int $maxTokens,
        private readonly bool $promptCache,
        private readonly int $thinkingBudget,
        private readonly array $skills = [],
        private readonly string $baseUrl = '',
        private readonly array $toolDeny = [],
        private readonly string $remoteSkillUrls = '',
        private readonly PhpClawExtensions $extensions = new PhpClawExtensions,
        private readonly ?Connection $connection = null,
        private readonly ?bool $console = null,
        private readonly ?ConsoleContext $consoleContext = null,
        private readonly ?SymfonyIdentityResolver $identity = null,
        private readonly bool $requireChatRole = false,
        private readonly string $projectRoot = '',
        private readonly string $fallbackProvider = '',
        private readonly string $fallbackModel = '',
        private readonly string $fallbackApiKey = '',
        private readonly int $rateLimitRpm = 0,
        private readonly bool $responseCache = false,
        private readonly int $responseCacheTtl = 3600,
        private readonly int $maxTokenBudget = 0,
        private readonly ?CacheItemPoolInterface $cachePool = null,
        private readonly bool $durableRuns = false,
        private readonly int $durableStepBudget = 0,
        private readonly ?int $durableDeadlineSeconds = null,
        private readonly string $environment = '',
    ) {}

    /**
     * Build a fully-configured engine instance.
     *
     * @return PhpClawInterface
     */
    public function create(): PhpClawInterface
    {
        return $this->build(terminalApproval: null);
    }

    /**
     * Build the engine that always pauses for approval, for resuming and deciding saved runs from any process.
     *
     * @return PhpClawInterface
     */
    public function createDurable(): PhpClawInterface
    {
        return $this->build(terminalApproval: false);
    }

    /**
     * Build the engine for the console commands, which keeps the Y/n prompt; a chat session may swap provider or model.
     *
     * @param  string  $provider  Provider for this session; '' keeps the configured one.
     * @param  string  $model  Model for this session; '' keeps the configured one.
     * @return PhpClawInterface
     */
    public function createForTerminal(string $provider = '', string $model = ''): PhpClawInterface
    {
        return $this->build(terminalApproval: true, provider: $provider, model: $model);
    }

    /**
     * Build the engine; a null $terminalApproval keeps the Y/n prompt only in an interactive console.
     *
     * @param  bool|null  $terminalApproval  True keeps the Y/n prompt and the console tool rights, false pauses runs for a later decision.
     * @param  string  $provider  Provider to use; '' uses the configured one.
     * @param  string  $model  Model to use; '' uses the configured one.
     * @return PhpClawInterface
     */
    private function build(?bool $terminalApproval, string $provider = '', string $model = ''): PhpClawInterface
    {
        $provider = $provider !== '' ? $provider : $this->provider;
        $model = $model !== '' ? $model : $this->model;
        $memory = new PrivacyAwareMemory($this->memory, $this->storeMessages);

        $isConsole = $terminalApproval === true || ($this->console ?? ($this->consoleContext?->isConsole() ?? false));

        $builder = PhpClaw::builder()
            ->apiKey($this->apiKey)
            ->provider($provider)
            ->model($model)
            ->storeMessages($this->storeMessages)
            ->maxIterations($this->maxIterations)
            ->maxToolsPerTurn(ToolProfileResolver::maxTools(ToolProfileResolver::resolve($provider, $model)))
            ->tools(ToolProfileResolver::filter($this->resolveTools($isConsole)))
            ->shellAllowlist($this->shellAllowlist)
            ->memory($memory)
            ->systemPrompt(mb_substr($this->systemPrompt, 0, 8000))
            ->maxTokens($this->maxTokens)
            ->promptCache($this->promptCache)
            ->thinkingBudget($this->thinkingBudget)
            ->skills($this->resolveSkills());

        $remoteSkillUrls = array_slice(array_filter(array_map('trim', explode(',', $this->remoteSkillUrls))), 0, 50);
        foreach ($remoteSkillUrls as $url) {
            $builder->withRemoteSkills($url);
        }

        $override = $this->buildCustomProviderOverride($provider, $model);
        if ($override !== null) {
            $builder->providerOverride($override);
        }

        $this->applyApprovalMode($builder, $terminalApproval ?? $isConsole);

        $this->applyAgentPrimitives($builder, $provider);

        return $builder->build();
    }

    /**
     * Keep the Y/n terminal gate, or with durable_runs and store_messages on, let web and worker runs pause.
     *
     * @param  ClawBuilder  $builder
     * @param  bool  $terminalApproval  True for an interactive console, which keeps the Y/n prompt.
     * @return void
     */
    private function applyApprovalMode(ClawBuilder $builder, bool $terminalApproval): void
    {
        if (! $this->durableRuns) {
            $builder->approvalGate(new CliApprovalGate);

            return;
        }

        if (! $this->storeMessages) {
            Log::warning('phpClaw: durable_runs needs store_messages, so durable runs stay off.');
            $builder->approvalGate(new CliApprovalGate);

            return;
        }

        $builder->durableRuns(max(0, $this->durableStepBudget), $this->durableDeadlineSeconds);

        if ($terminalApproval) {
            $builder->approvalGate(new CliApprovalGate);

            return;
        }

        $builder->withSuspendableApproval();
    }

    /**
     * Apply the fallback provider, outbound rate limit, response cache, and token budget primitives; each stays off unless the site owner set it.
     *
     * @param  ClawBuilder  $builder
     * @param  string  $provider  Primary provider the engine is built with.
     * @return void
     */
    private function applyAgentPrimitives(ClawBuilder $builder, string $provider): void
    {
        $resolvedPrimary = $provider !== '' ? $provider : (new ClawConfig)->providerName;
        $fallbackCompatible = $this->fallbackProvider !== ''
            && $this->fallbackProvider !== 'custom'
            && ToolRegistry::toolFormat($this->fallbackProvider) === ToolRegistry::toolFormat($resolvedPrimary);

        if ($fallbackCompatible) {
            $builder->withFallback($this->fallbackProvider, $this->fallbackModel, $this->fallbackApiKey);
        }

        $rateLimit = max(0, min(600, $this->rateLimitRpm));
        $needsCache = $rateLimit > 0 || $this->responseCache;
        $cache = $needsCache && $this->cachePool !== null ? new Psr16Cache($this->cachePool) : null;

        if ($rateLimit > 0 && $cache !== null) {
            $builder->rateLimit($rateLimit, store: $cache);
        }

        if ($this->responseCache && $cache !== null) {
            $ttl = max(60, min(86_400, $this->responseCacheTtl));
            $builder->responseCache($cache, $ttl);
        }

        $tokenBudget = max(0, $this->maxTokenBudget);

        if ($tokenBudget > 0) {
            $builder->maxTokenBudget($tokenBudget);
        }
    }

    /**
     * Build the MCP server's ToolRegistry from the resolved tools, with PHP writes off since MCP has no approval gate.
     *
     * @return ToolRegistry
     */
    public function createToolRegistry(): ToolRegistry
    {
        $registry = new ToolRegistry;
        $registry->register($this->resolveTools(false));

        return $registry;
    }

    /**
     * Build an OpenAIProvider override for the 'custom' provider with a validated base_url.
     *
     * @param  string|null  $provider  Primary provider the engine is built with; null uses the configured one.
     * @param  string|null  $model  Model the engine is built with; null uses the configured one.
     * @return ?ProviderInterface
     */
    private function buildCustomProviderOverride(?string $provider = null, ?string $model = null): ?ProviderInterface
    {
        if (($provider ?? $this->provider) !== 'custom') {
            return null;
        }

        $baseUrl = trim($this->baseUrl);

        if ($baseUrl === '' || ! $this->isAllowedProviderUrl($baseUrl)) {
            return null;
        }

        return new OpenAIProvider(
            apiKey: $this->apiKey,
            model: $model ?? $this->model,
            systemPrompt: $this->systemPrompt,
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
     * Resolve config skills via the canonical SkillResolver, dropping classes it could not construct.
     *
     * @return SkillInterface[]
     */
    private function resolveSkills(): array
    {
        $safe = array_values(array_filter(
            $this->skills,
            static fn (mixed $entry): bool => ! is_array($entry)
                || ! isset($entry['class'])
                || ArgumentFreeConstructor::accepts($entry['class']),
        ));

        return SkillResolver::resolve($safe);
    }

    /**
     * Resolve tool class-strings and extension tool instances into the final tool list.
     *
     * @param  bool  $allowPhpWrite  True to permit FileWriteTool to write .php files (console only).
     * @return ToolInterface[]
     */
    private function resolveTools(bool $allowPhpWrite): array
    {
        $resolved = [];
        $seen = [];

        foreach ($this->tools as $class) {
            if (! is_string($class) || ! class_exists($class)) {
                continue;
            }

            if (isset($seen[$class])) {
                continue;
            }

            $seen[$class] = true;

            $tool = match ($class) {
                ShellTool::class => new ShellTool(allowlist: $this->shellAllowlist),
                FileReadTool::class => new FileReadTool(workspaceRoot: $this->workspaceRoot),
                FileWriteTool::class => new FileWriteTool(workspaceRoot: $this->workspaceRoot, allowPhpWrite: $allowPhpWrite),
                FileEditTool::class => new FileEditTool(workspaceRoot: $this->workspaceRoot),
                TestRunTool::class => new TestRunTool(
                    projectRoot: $this->projectRoot,
                    environment: $this->environment,
                    console: $this->consoleContext,
                    identity: $this->identity,
                    requireChatRole: $this->requireChatRole,
                ),
                DatabaseTool::class => $this->connection !== null
                    ? new DatabaseTool($this->connection, $this->consoleContext, $this->identity, $this->requireChatRole)
                    : null,
                LogTool::class => new LogTool(
                    $this->projectRoot !== '' ? $this->projectRoot.'/var/log' : '',
                    $this->consoleContext,
                    $this->identity,
                    $this->requireChatRole,
                ),
                default => ArgumentFreeConstructor::make($class),
            };

            if ($tool === null) {
                continue;
            }

            $resolved[] = $tool;
        }

        $discoveredConfig = [
            'workspaceRoot' => $this->workspaceRoot,
            'allowlist' => $this->shellAllowlist,
            'allowPhpWrite' => $allowPhpWrite,
        ];

        if ($this->projectRoot !== '') {
            $discoveredConfig['projectRoot'] = $this->projectRoot;
        }

        foreach (ToolCatalogue::instantiateDefaults($discoveredConfig) as $tool) {
            $class = $tool::class;
            if (! isset($seen[$class])) {
                $seen[$class] = true;
                $resolved[] = $tool;
            }
        }

        foreach ($this->extensions->tools as $tool) {
            if (! $tool instanceof ToolInterface) {
                continue;
            }
            $class = $tool::class;
            if (! isset($seen[$class])) {
                $seen[$class] = true;
                $resolved[] = $tool;
            }
        }

        $denyNames = ToolProfileResolver::resolveNames($this->toolDeny, self::TOOL_GROUPS);

        return array_values(array_filter(
            $resolved,
            fn (ToolInterface $t): bool => ! in_array($t->name(), $denyNames, true),
        ));
    }
}
