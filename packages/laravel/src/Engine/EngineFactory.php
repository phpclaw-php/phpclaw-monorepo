<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Engine;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Log;
use PhpClaw\Agent\CliApprovalGate;
use PhpClaw\Claw as PhpClaw;
use PhpClaw\ClawBuilder;
use PhpClaw\ClawConfig;
use PhpClaw\Contracts\ClawInterface as PhpClawInterface;
use PhpClaw\Laravel\Extension\PhpClawExtensions;
use PhpClaw\Laravel\LaravelConsole;
use PhpClaw\Memory\Contracts\MemoryInterface;
use PhpClaw\Memory\PrivacyAwareMemory;
use PhpClaw\Providers\Contracts\ProviderInterface;
use PhpClaw\Providers\OpenAIPresets;
use PhpClaw\Providers\OpenAIProvider;
use PhpClaw\Skills\SkillResolver;
use PhpClaw\Tools\Contracts\ToolInterface;
use PhpClaw\Tools\FileEditTool;
use PhpClaw\Tools\FileReadTool;
use PhpClaw\Tools\FileWriteTool;
use PhpClaw\Tools\ShellTool;
use PhpClaw\Tools\ToolCatalogue;
use PhpClaw\Tools\ToolProfileResolver;
use PhpClaw\Tools\ToolRegistry;

/**
 * Builds a fully-wired phpClaw engine instance from Laravel config.
 */
final class EngineFactory
{
    public const TOOL_GROUPS = [
        'group:system' => [
            'db_query', 'read_log', 'http_request', 'file_read', 'file_write', 'shell_exec',
            'route_list', 'config_get', 'cache_inspect', 'queue_status',
        ],
        'group:content' => [],
        'group:admin' => [],
    ];

    private const CUSTOM_PROVIDER = 'custom';

    private const MAX_ITERATIONS = 50;

    private const MAX_SYSTEM_PROMPT_LENGTH = 8000;

    private const MAX_REMOTE_SKILL_URLS = 50;

    private const MAX_RATE_LIMIT_RPM = 600;

    private const MIN_RESPONSE_CACHE_TTL = 60;

    private const MAX_RESPONSE_CACHE_TTL = 86_400;

    private const DEFAULT_RESPONSE_CACHE_TTL = 3600;

    /**
     * Build a fully-configured engine from the application container and config.
     *
     * @param  Application  $app
     * @param  bool|null  $terminalApproval  True keeps the Y/n terminal prompt, false pauses runs for a later decision; null follows the console.
     * @return PhpClawInterface
     */
    public static function build(Application $app, ?bool $terminalApproval = null): PhpClawInterface
    {
        $memory = new PrivacyAwareMemory(
            $app->make(MemoryInterface::class),
            (bool) config('phpclaw.store_messages', true),
        );

        $override = self::buildCustomProviderOverride();

        $providerSlug = (string) config('phpclaw.provider', '');
        if ($providerSlug === self::CUSTOM_PROVIDER && $override === null) {
            $providerSlug = '';
        }

        $builder = PhpClaw::builder()
            ->apiKey((string) config('phpclaw.api_key', ''))
            ->provider($providerSlug)
            ->model((string) config('phpclaw.model', ''))
            ->storeMessages((bool) config('phpclaw.store_messages', true))
            ->maxIterations(max(1, min(self::MAX_ITERATIONS, (int) config('phpclaw.max_iterations', ClawConfig::DEFAULT_MAX_ITERATIONS))))
            ->maxToolsPerTurn(self::resolveMaxToolsPerTurn($providerSlug))
            ->alwaysIncludeTools((array) config('phpclaw.always_include_tools', []))
            ->tools(self::resolveTools($app))
            ->shellAllowlist((array) config('phpclaw.shell_allowlist', []))
            ->memory($memory)
            ->systemPrompt(self::systemPrompt())
            ->maxTokens((int) config('phpclaw.max_tokens', 0))
            ->promptCache((bool) config('phpclaw.prompt_cache', false))
            ->thinkingBudget((int) config('phpclaw.thinking_budget', 0))
            ->skills(SkillResolver::resolve((array) config('phpclaw.skills', [])));

        $remoteSkillUrls = array_slice((array) config('phpclaw.remote_skill_urls', []), 0, self::MAX_REMOTE_SKILL_URLS);
        foreach ($remoteSkillUrls as $url) {
            if (is_string($url) && trim($url) !== '') {
                $builder->withRemoteSkills(trim($url));
            }
        }

        if ($override !== null) {
            $builder->providerOverride($override);
        }

        self::applyApprovalMode($builder, $terminalApproval ?? LaravelConsole::isInteractive($app));

        self::applyAgentPrimitives($builder, $app, $providerSlug);

        return $builder->build();
    }

    /**
     * Keep the Y/n terminal gate, or with durable_runs and store_messages on, let web and worker runs pause.
     *
     * @param  ClawBuilder  $builder
     * @param  bool  $terminalApproval  True for an interactive terminal, which keeps the Y/n prompt.
     * @return void
     */
    private static function applyApprovalMode(ClawBuilder $builder, bool $terminalApproval): void
    {
        if (! (bool) config('phpclaw.durable_runs', false)) {
            $builder->approvalGate(new CliApprovalGate);

            return;
        }

        if (! (bool) config('phpclaw.store_messages', true)) {
            Log::warning('phpClaw: durable_runs needs store_messages, so durable runs stay off.');
            $builder->approvalGate(new CliApprovalGate);

            return;
        }

        $deadline = config('phpclaw.durable_deadline_seconds');
        $builder->durableRuns(max(0, (int) config('phpclaw.durable_step_budget', 0)), is_numeric($deadline) ? (int) $deadline : null);

        if ($terminalApproval) {
            $builder->approvalGate(new CliApprovalGate);

            return;
        }

        $builder->withSuspendableApproval();
    }

    /**
     * Apply the fallback, rate limit, response cache and token budget from config; each stays off unless set.
     *
     * @param  ClawBuilder  $builder
     * @param  Application  $app
     * @param  string  $primaryProvider  Primary provider slug; empty means core auto-detects it from the environment.
     * @return void
     */
    private static function applyAgentPrimitives(ClawBuilder $builder, Application $app, string $primaryProvider): void
    {
        $resolvedPrimary = $primaryProvider === '' ? (new ClawConfig)->providerName : $primaryProvider;
        $fallbackProvider = (string) config('phpclaw.fallback_provider', '');
        $fallbackCompatible = $fallbackProvider !== ''
            && $fallbackProvider !== self::CUSTOM_PROVIDER
            && ToolRegistry::toolFormat($fallbackProvider) === ToolRegistry::toolFormat($resolvedPrimary);

        if ($fallbackCompatible) {
            $builder->withFallback(
                $fallbackProvider,
                (string) config('phpclaw.fallback_model', ''),
                (string) config('phpclaw.fallback_api_key', ''),
            );
        } elseif ($fallbackProvider !== '') {
            Log::warning('phpClaw: fallback_provider is unsupported or incompatible with the primary provider, skipping fallback.', [
                'fallback_provider' => $fallbackProvider,
            ]);
        }

        $rateLimit = max(0, min(self::MAX_RATE_LIMIT_RPM, (int) config('phpclaw.rate_limit_rpm', 0)));
        $cacheOn = (bool) config('phpclaw.response_cache', false);

        if ($rateLimit > 0 || $cacheOn) {
            $store = $app->make('cache')->store();

            if ($rateLimit > 0) {
                $builder->rateLimit($rateLimit, store: $store);
            }

            if ($cacheOn) {
                $ttl = max(self::MIN_RESPONSE_CACHE_TTL, min(self::MAX_RESPONSE_CACHE_TTL, (int) config('phpclaw.response_cache_ttl', self::DEFAULT_RESPONSE_CACHE_TTL)));
                $builder->responseCache($store, $ttl);
            }
        }

        $tokenBudget = max(0, (int) config('phpclaw.max_token_budget', 0));

        if ($tokenBudget > 0) {
            $builder->maxTokenBudget($tokenBudget);
        }
    }

    /**
     * Resolve and return the final tool list for the engine.
     *
     * @param  Application  $app
     * @param  bool|null  $forceAllowPhpWrite  Overrides the console-based default when non-null; used by the MCP service provider to force PHP-write off regardless of console-ness. A queue worker is not an interactive console and never enables PHP write.
     * @return ToolInterface[]
     */
    public static function resolveTools(Application $app, ?bool $forceAllowPhpWrite = null): array
    {
        $toolClasses = (array) config('phpclaw.tools', []);
        $allowPhpWrite = $forceAllowPhpWrite ?? LaravelConsole::isInteractive($app);
        $workspaceRoot = (string) config('phpclaw.workspace_root', storage_path('phpclaw'));
        $tools = [];
        $seen = [];

        foreach ($toolClasses as $class) {
            if (! is_string($class) || ! class_exists($class)) {
                continue;
            }

            if (isset($seen[$class])) {
                continue;
            }

            $seen[$class] = true;

            $tools[] = match ($class) {
                ShellTool::class => new ShellTool(
                    allowlist: (array) config('phpclaw.shell_allowlist', []),
                ),
                FileReadTool::class => new FileReadTool(workspaceRoot: $workspaceRoot),
                FileWriteTool::class => new FileWriteTool(workspaceRoot: $workspaceRoot, allowPhpWrite: $allowPhpWrite),
                FileEditTool::class => new FileEditTool(workspaceRoot: $workspaceRoot),
                default => $app->make($class),
            };
        }

        $discoveredConfig = [
            'workspaceRoot' => $workspaceRoot,
            'projectRoot' => base_path(),
            'allowlist' => (array) config('phpclaw.shell_allowlist', []),
            'allowPhpWrite' => $allowPhpWrite,
        ];

        foreach (ToolCatalogue::instantiateDefaults($discoveredConfig) as $tool) {
            $class = $tool::class;
            if (! isset($seen[$class])) {
                $seen[$class] = true;
                $tools[] = $tool;
            }
        }

        if ($app->bound(PhpClawExtensions::class)) {
            $extensions = $app->make(PhpClawExtensions::class);
            foreach ($extensions->tools as $tool) {
                if (! $tool instanceof ToolInterface) {
                    continue;
                }
                $class = $tool::class;
                if (! isset($seen[$class])) {
                    $seen[$class] = true;
                    $tools[] = $tool;
                }
            }
        }

        $deny = (array) config('phpclaw.tool_deny', []);

        return ToolProfileResolver::filter(
            tools: $tools,
            deny: $deny,
            groups: self::TOOL_GROUPS,
        );
    }

    /**
     * Build an OpenAIProvider override for the 'custom' provider with a validated base_url.
     *
     * @return ?ProviderInterface
     */
    private static function buildCustomProviderOverride(): ?ProviderInterface
    {
        if ((string) config('phpclaw.provider', '') !== self::CUSTOM_PROVIDER) {
            return null;
        }

        $baseUrl = trim((string) config('phpclaw.base_url', ''));

        if ($baseUrl === '' || ! self::isAllowedProviderUrl($baseUrl)) {
            return null;
        }

        return new OpenAIProvider(
            apiKey: (string) config('phpclaw.api_key', ''),
            model: (string) config('phpclaw.model', ''),
            systemPrompt: self::systemPrompt(),
            endpoint: $baseUrl,
            name: self::CUSTOM_PROVIDER,
            authStyle: OpenAIPresets::AUTH_BEARER,
        );
    }

    /**
     * Whether a custom-provider base_url is allowed: HTTPS anywhere, plain HTTP only for a loopback host.
     *
     * @param  string  $baseUrl  Trimmed custom-provider endpoint URL.
     * @return bool True for https URLs and for http URLs targeting localhost/127.0.0.1/::1.
     */
    private static function isAllowedProviderUrl(string $baseUrl): bool
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
     * The configured system prompt, cut to MAX_SYSTEM_PROMPT_LENGTH characters.
     *
     * @return string
     */
    private static function systemPrompt(): string
    {
        return mb_substr((string) config('phpclaw.system_prompt', ''), 0, self::MAX_SYSTEM_PROMPT_LENGTH);
    }

    /**
     * Per-turn tool cap: the configured max_tools_per_turn, or the provider and model profile limit when it is 0.
     *
     * @param  string  $providerSlug  Provider in use.
     * @return int Cap passed to the builder.
     */
    private static function resolveMaxToolsPerTurn(string $providerSlug): int
    {
        $configured = (int) config('phpclaw.max_tools_per_turn', 0);

        return $configured === 0
            ? ToolProfileResolver::maxTools(ToolProfileResolver::resolve($providerSlug, (string) config('phpclaw.model', '')))
            : $configured;
    }
}
