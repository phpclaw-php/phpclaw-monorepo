<?php

declare(strict_types=1);

namespace PhpClaw\Symfony;

use Doctrine\DBAL\Connection;
use PhpClaw\Agent\CliApprovalGate;
use PhpClaw\Claw as PhpClaw;
use PhpClaw\Contracts\ClawInterface as PhpClawInterface;
use PhpClaw\Memory\Contracts\MemoryInterface;
use PhpClaw\Memory\PrivacyAwareMemory;
use PhpClaw\Providers\Contracts\ProviderInterface;
use PhpClaw\Providers\OpenAIPresets;
use PhpClaw\Providers\OpenAIProvider;
use PhpClaw\Skills\Contracts\SkillInterface;
use PhpClaw\Skills\SkillResolver;
use PhpClaw\Symfony\Console\ConsoleContext;
use PhpClaw\Symfony\Extension\PhpClawExtensions;
use PhpClaw\Symfony\Support\ArgumentFreeConstructor;
use PhpClaw\Symfony\Tools\DatabaseTool;
use PhpClaw\Symfony\Tools\LogTool;
use PhpClaw\Tools\Contracts\ToolInterface;
use PhpClaw\Tools\FileReadTool;
use PhpClaw\Tools\FileWriteTool;
use PhpClaw\Tools\ShellTool;
use PhpClaw\Tools\ToolCatalogue;
use PhpClaw\Tools\ToolProfileResolver;
use PhpClaw\Tools\ToolRegistry;

/**
 * Factory that builds a fully-wired PhpClaw engine from Symfony DI parameters.
 */
final class PhpClawFactory
{
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
     * @param  string  $remoteSkillUrls  Comma-separated HTTPS SKILL.md / JSON URLs loaded at build (always-on, keyword-matched).
     * @param  PhpClawExtensions  $extensions  Runtime extensions bag.
     * @param  Connection|null  $connection  Doctrine DBAL connection for DatabaseTool, or null when absent.
     * @param  bool|null  $console  Console context override; null defers to the ConsoleContext service.
     * @param  ConsoleContext|null  $consoleContext  Marks an interactive console run; null is treated as not console.
     * @param  SymfonyIdentityResolver|null  $identity  Names the acting user for the tool capability guard.
     * @param  bool  $requireChatRole  True when the host app requires ROLE_PHPCLAW_CHAT rather than any authenticated user.
     * @param  string  $projectRoot  Absolute Symfony kernel project directory, independent of process CWD (MCP/console spawns may not set one).
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
    ) {}

    /**
     * Build a fully-configured engine instance.
     *
     * @return PhpClawInterface
     */
    public function create(): PhpClawInterface
    {
        $memory = new PrivacyAwareMemory($this->memory, $this->storeMessages);

        $isConsole = $this->console ?? ($this->consoleContext?->isConsole() ?? false);

        $builder = PhpClaw::builder()
            ->apiKey($this->apiKey)
            ->provider($this->provider)
            ->model($this->model)
            ->storeMessages($this->storeMessages)
            ->maxIterations($this->maxIterations)
            ->maxToolsPerTurn(ToolProfileResolver::maxTools(ToolProfileResolver::resolve($this->provider, $this->model)))
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

        $override = $this->buildCustomProviderOverride();
        if ($override !== null) {
            $builder->providerOverride($override);
        }

        $builder->approvalGate(new CliApprovalGate);

        return $builder->build();
    }

    /**
     * Build a ToolRegistry populated with the resolved tools, for the MCP server. PHP-write is
     * force-denied because MCP installs no approval gate; resolveTools() already applies tool_deny.
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
     * @return ?ProviderInterface
     */
    private function buildCustomProviderOverride(): ?ProviderInterface
    {
        if ($this->provider !== 'custom') {
            return null;
        }

        $baseUrl = trim($this->baseUrl);

        if ($baseUrl === '' || ! $this->isAllowedProviderUrl($baseUrl)) {
            return null;
        }

        return new OpenAIProvider(
            apiKey: $this->apiKey,
            model: $this->model,
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
