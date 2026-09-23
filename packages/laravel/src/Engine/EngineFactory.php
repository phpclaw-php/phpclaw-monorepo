<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Engine;

use Illuminate\Contracts\Foundation\Application;
use PhpClaw\Agent\CliApprovalGate;
use PhpClaw\Claw as PhpClaw;
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
use PhpClaw\Tools\FileReadTool;
use PhpClaw\Tools\FileWriteTool;
use PhpClaw\Tools\ShellTool;
use PhpClaw\Tools\ToolCatalogue;
use PhpClaw\Tools\ToolProfileResolver;

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
     * @return PhpClawInterface
     */
    public static function build(Application $app): PhpClawInterface
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

        $builder->approvalGate(new CliApprovalGate);

        return $builder->build();
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
                default => $app->make($class),
            };
        }

        $discoveredConfig = [
            'workspaceRoot' => (string) config('phpclaw.workspace_root', storage_path('phpclaw')),
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
