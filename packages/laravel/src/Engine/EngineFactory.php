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
use PhpClaw\Skills\Contracts\SkillInterface;
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
        if ($providerSlug === 'custom' && $override === null) {
            $providerSlug = '';
        }

        $builder = PhpClaw::builder()
            ->apiKey((string) config('phpclaw.api_key', ''))
            ->provider($providerSlug)
            ->model((string) config('phpclaw.model', ''))
            ->storeMessages((bool) config('phpclaw.store_messages', true))
            ->maxIterations(max(1, min(50, (int) config('phpclaw.max_iterations', ClawConfig::DEFAULT_MAX_ITERATIONS))))
            ->maxToolsPerTurn(ToolProfileResolver::maxTools(ToolProfileResolver::resolve($providerSlug, (string) config('phpclaw.model', ''))))
            ->tools(self::resolveTools($app))
            ->shellAllowlist((array) config('phpclaw.shell_allowlist', []))
            ->memory($memory)
            ->systemPrompt(mb_substr((string) config('phpclaw.system_prompt', ''), 0, 8000))
            ->maxTokens((int) config('phpclaw.max_tokens', 0))
            ->promptCache((bool) config('phpclaw.prompt_cache', false))
            ->thinkingBudget((int) config('phpclaw.thinking_budget', 0))
            ->skills(self::resolveSkills());

        $remoteSkillUrls = array_slice((array) config('phpclaw.remote_skill_urls', []), 0, 50);
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
        $resolvedPrimary = $primaryProvider !== '' ? $primaryProvider : (new ClawConfig)->providerName;
        $fallbackProvider = (string) config('phpclaw.fallback_provider', '');
        $fallbackCompatible = $fallbackProvider !== ''
            && $fallbackProvider !== 'custom'
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

        $rateLimit = max(0, min(600, (int) config('phpclaw.rate_limit_rpm', 0)));
        $cacheOn = (bool) config('phpclaw.response_cache', false);

        if ($rateLimit > 0 || $cacheOn) {
            $store = $app->make('cache')->store();

            if ($rateLimit > 0) {
                $builder->rateLimit($rateLimit, store: $store);
            }

            if ($cacheOn) {
                $ttl = max(60, min(86_400, (int) config('phpclaw.response_cache_ttl', 3600)));
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
                FileReadTool::class => new FileReadTool(
                    workspaceRoot: (string) config('phpclaw.workspace_root', storage_path('phpclaw')),
                ),
                FileWriteTool::class => new FileWriteTool(
                    workspaceRoot: (string) config('phpclaw.workspace_root', storage_path('phpclaw')),
                    allowPhpWrite: $allowPhpWrite,
                ),
                FileEditTool::class => new FileEditTool(
                    workspaceRoot: (string) config('phpclaw.workspace_root', storage_path('phpclaw')),
                ),
                default => $app->make($class),
            };
        }

        $discoveredConfig = [
            'workspaceRoot' => (string) config('phpclaw.workspace_root', storage_path('phpclaw')),
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
        if ((string) config('phpclaw.provider', '') !== 'custom') {
            return null;
        }

        $baseUrl = trim((string) config('phpclaw.base_url', ''));

        if ($baseUrl === '' || ! self::isAllowedProviderUrl($baseUrl)) {
            return null;
        }

        return new OpenAIProvider(
            apiKey: (string) config('phpclaw.api_key', ''),
            model: (string) config('phpclaw.model', ''),
            systemPrompt: mb_substr((string) config('phpclaw.system_prompt', ''), 0, 8000),
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
     * Resolve skills from config/phpclaw.php `skills` array via the canonical SkillResolver.
     *
     * @return SkillInterface[]
     */
    private static function resolveSkills(): array
    {
        return SkillResolver::resolve((array) config('phpclaw.skills', []));
    }
}
