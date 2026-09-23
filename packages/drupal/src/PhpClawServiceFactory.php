<?php

declare(strict_types=1);

namespace PhpClaw\Drupal;

use Drupal\Core\Config\ImmutableConfig;
use PhpClaw\Agent\CliApprovalGate;
use PhpClaw\Claw;
use PhpClaw\ClawConfig;
use PhpClaw\Config\ToolConfig;
use PhpClaw\Drupal\Service\DrupalAgentContext;
use PhpClaw\Drupal\Support\CloudDisableParser;
use PhpClaw\Drupal\Support\Defaults;
use PhpClaw\Drupal\Tools\DatabaseTool;
use PhpClaw\Drupal\Tools\DrupalBlockTool;
use PhpClaw\Drupal\Tools\DrupalCacheTool;
use PhpClaw\Drupal\Tools\DrupalConfigTool;
use PhpClaw\Drupal\Tools\DrupalContentModerationTool;
use PhpClaw\Drupal\Tools\DrupalCronTool;
use PhpClaw\Drupal\Tools\DrupalEntityTool;
use PhpClaw\Drupal\Tools\DrupalMediaTool;
use PhpClaw\Drupal\Tools\DrupalMenuTool;
use PhpClaw\Drupal\Tools\DrupalModuleTool;
use PhpClaw\Drupal\Tools\DrupalPathAliasTool;
use PhpClaw\Drupal\Tools\DrupalUserRoleTool;
use PhpClaw\Drupal\Tools\DrupalViewsTool;
use PhpClaw\Drupal\Tools\DrupalWebformTool;
use PhpClaw\Drupal\Tools\LogTool;
use PhpClaw\Memory\MemoryRegistry;
use PhpClaw\Memory\PrivacyAwareMemory;
use PhpClaw\Providers\OpenAIPresets;
use PhpClaw\Providers\OpenAIProvider;
use PhpClaw\Skills\SkillCatalogue;
use PhpClaw\Tools\Contracts\ToolInterface;
use PhpClaw\Tools\HttpTool;
use PhpClaw\Tools\ShellTool;
use PhpClaw\Tools\ToolCatalogue;
use PhpClaw\Tools\ToolProfileResolver;
use PhpClaw\Tools\ToolRegistry;

/**
 * Factory that builds PhpClaw instances from Drupal's config system.
 */
final class PhpClawServiceFactory
{
    /**
     * Full agent with all tools, or null if the current settings can't build one.
     *
     * @param  DrupalAgentContext  $ctx  All Drupal dependencies bundled as a context object.
     * @return Claw|null Configured agent instance with all tools, or null if the current settings can't build one.
     */
    public static function create(DrupalAgentContext $ctx): ?Claw
    {

        $config = $ctx->configFactory->get('phpclaw.settings');

        $rawAllowlist = (array) ($config->get('shell_allowlist') ?? []);
        $shellAllowlist = $rawAllowlist !== [] ? $rawAllowlist : ToolConfig::DEFAULT_SHELL_ALLOWLIST;

        $tools = [
            new ShellTool(allowlist: $shellAllowlist),
            new HttpTool,
            ...self::buildCommonTools($ctx),
        ];

        if (class_exists(ToolCatalogue::class)) {
            $byName = [];
            foreach ($tools as $t) {
                $byName[$t->name()] = $t;
            }
            foreach (ToolCatalogue::instantiateDefaults([
                'allowlist' => $shellAllowlist,
                'workspaceRoot' => null,
                'allowPhpWrite' => DrupalConsole::isActive(),
            ]) as $coreTool) {
                $byName[$coreTool->name()] ??= $coreTool;
            }
            $tools = array_values($byName);
        }

        try {
            return self::build($config, $tools, $shellAllowlist);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Chat agent for admin UI (no Shell or HTTP tools), or null if the current settings can't build one.
     *
     * @param  DrupalAgentContext  $ctx  All Drupal dependencies bundled as a context object.
     * @return Claw|null Configured agent instance with content-safe tools only, or null if the current settings can't build one.
     */
    public static function createChat(DrupalAgentContext $ctx): ?Claw
    {
        $config = $ctx->configFactory->get('phpclaw.settings');

        $tools = self::buildCommonTools($ctx);

        try {
            return self::build($config, $tools);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Build a ToolRegistry with the full resolved tool set, for the MCP server, with PHP writes denied.
     *
     * @param  DrupalAgentContext  $ctx  All Drupal dependencies bundled as a context object.
     * @return ToolRegistry Registry populated with the same tools the CLI agent exposes.
     */
    public static function buildToolRegistry(DrupalAgentContext $ctx): ToolRegistry
    {
        $config = $ctx->configFactory->get('phpclaw.settings');

        $rawAllowlist = (array) ($config->get('shell_allowlist') ?? []);
        $shellAllowlist = $rawAllowlist !== [] ? $rawAllowlist : ToolConfig::DEFAULT_SHELL_ALLOWLIST;

        $tools = [
            new ShellTool(allowlist: $shellAllowlist),
            new HttpTool,
            ...self::buildCommonTools($ctx),
        ];

        if (class_exists(ToolCatalogue::class)) {
            $byName = [];
            foreach ($tools as $t) {
                $byName[$t->name()] = $t;
            }
            foreach (ToolCatalogue::instantiateDefaults([
                'allowlist' => $shellAllowlist,
                'workspaceRoot' => null,
                'allowPhpWrite' => false,
            ]) as $coreTool) {
                $byName[$coreTool->name()] ??= $coreTool;
            }
            $tools = array_values($byName);
        }

        $deny = (array) ($config->get('tool_deny') ?? []);
        $groups = self::toolGroups($tools);

        $registry = new ToolRegistry;
        $registry->register($tools, $deny, $groups);

        return $registry;
    }

    /**
     * Tool groups, narrowed to the tools this site actually offers.
     *
     * @param  array<int, ToolInterface>  $tools  The tools this site offers.
     * @return array<string, array<int, string>> Group name to member tool names.
     */
    private static function toolGroups(array $tools): array
    {
        $available = array_map(static fn (ToolInterface $tool): string => $tool->name(), $tools);

        $groups = [
            'group:content' => ['drupal_entity', 'drupal_moderation', 'drupal_media', 'drupal_webform'],
            'group:system' => ['drupal_modules', 'drupal_cron', 'drupal_cache', 'drupal_config'],
            'group:nav' => ['drupal_menus', 'drupal_path_aliases', 'drupal_blocks', 'drupal_views'],
        ];

        foreach ($groups as $name => $members) {
            $groups[$name] = array_values(array_intersect($members, $available));
        }

        return array_filter($groups, static fn (array $members): bool => $members !== []);
    }

    /**
     * Build a Claw instance from resolved config, tools, and shell allowlist.
     *
     * @param  ImmutableConfig  $config  Immutable Drupal config object.
     * @param  array  $tools  Resolved tool instances.
     * @param  array  $shellAllowlist  Shell command allowlist.
     * @return Claw Fully configured agent instance.
     */
    private static function build(
        ImmutableConfig $config,
        array $tools,
        array $shellAllowlist = [],
    ): Claw {
        $provider = (string) ($config->get('provider') ?? '');
        $model = (string) ($config->get('model') ?? '');

        $apiKey = (string) ($config->get('api_key') ?? '');

        $storeMessages = (bool) ($config->get('store_messages') ?? Defaults::STORE_MESSAGES);
        $maxIterations = (int) ($config->get('max_iterations') ?? ClawConfig::DEFAULT_MAX_ITERATIONS);
        $memoryDriver = 'database';
        $memory = MemoryRegistry::build($memoryDriver);

        $memory = new PrivacyAwareMemory($memory, $storeMessages);

        $groups = self::toolGroups($tools);
        $deny = (array) ($config->get('tool_deny') ?? []);
        $tools = ToolProfileResolver::filter($tools, $deny, $groups);

        $cloudKey = (string) ($config->get('cloud_key') ?? '');
        $cloudSigningSecret = (string) ($config->get('cloud_signing_secret') ?? '');
        $cloudDisable = CloudDisableParser::parse($config->get('cloud_disable') ?? '');

        $systemPrompt = (string) ($config->get('system_prompt') ?? '');

        $builder = Claw::builder()
            ->apiKey($apiKey)
            ->provider($provider)
            ->model($model)
            ->systemPrompt($systemPrompt)
            ->storeMessages($storeMessages)
            ->maxIterations($maxIterations > 0 ? $maxIterations : ClawConfig::DEFAULT_MAX_ITERATIONS)
            ->maxToolsPerTurn(ToolProfileResolver::maxTools(ToolProfileResolver::resolve($provider, $model)))
            ->shellAllowlist($shellAllowlist)
            ->cloudKey($storeMessages ? $cloudKey : '')
            ->cloudSigningSecret($cloudSigningSecret)
            ->cloudDisable($cloudDisable)
            ->memory($memory)
            ->tools($tools)
            ->skills(SkillCatalogue::activateDefaults());

        foreach (array_filter(array_map('trim', explode(',', (string) ($config->get('remote_skill_urls') ?? '')))) as $url) {
            $builder->withRemoteSkills($url);
        }

        $override = self::customProviderOverride($provider, $apiKey, $model, $systemPrompt, $config);
        if ($override !== null) {
            $builder->providerOverride($override);
        }

        $builder->approvalGate(new CliApprovalGate);

        return $builder->build();
    }

    /**
     * Build the 15 common Drupal tools shared by both create() and createChat().
     *
     * @param  DrupalAgentContext  $ctx  All Drupal dependencies bundled as a context object.
     * @return array<int, ToolInterface>
     */
    private static function buildCommonTools(DrupalAgentContext $ctx): array
    {
        $whenModule = static fn (string $module, callable $make): array => $ctx->moduleHandler->moduleExists($module)
            ? [$make()]
            : [];

        return [
            new DrupalEntityTool($ctx->entityTypeManager, $ctx->moduleHandler),
            new DrupalConfigTool($ctx->configFactory),
            new DatabaseTool($ctx->database),
            new DrupalModuleTool($ctx->moduleHandler, $ctx->moduleExtensionList),
            new DrupalCronTool($ctx->state, $ctx->time, $ctx->queueFactory, $ctx->queueWorkerManager),
            new DrupalUserRoleTool($ctx->database, $ctx->entityTypeManager),
            new DrupalPathAliasTool($ctx->database),
            new DrupalCacheTool($ctx->database),
            ...$whenModule('dblog', static fn (): LogTool => new LogTool($ctx->database, $ctx->moduleHandler)),
            ...$whenModule('menu_link_content', static fn (): DrupalMenuTool => new DrupalMenuTool($ctx->database, $ctx->entityTypeManager, $ctx->moduleHandler)),
            ...$whenModule('file', static fn (): DrupalMediaTool => new DrupalMediaTool($ctx->database, $ctx->moduleHandler)),
            ...$whenModule('views', static fn (): DrupalViewsTool => new DrupalViewsTool($ctx->moduleHandler, $ctx->entityTypeManager)),
            ...$whenModule('block', static fn (): DrupalBlockTool => new DrupalBlockTool($ctx->entityTypeManager, $ctx->moduleHandler)),
            ...$whenModule('content_moderation', static fn (): DrupalContentModerationTool => new DrupalContentModerationTool($ctx->database, $ctx->moduleHandler)),
            ...$whenModule('webform', static fn (): DrupalWebformTool => new DrupalWebformTool($ctx->database, $ctx->moduleHandler, $ctx->entityTypeManager)),
            ...$ctx->registrar->getExternalTools(),
        ];
    }

    /**
     * Build an OpenAIProvider override when provider=custom and base_url is a valid http(s) URL.
     *
     * @param  string  $provider  Already-resolved provider slug.
     * @param  string  $apiKey  Already-resolved API key.
     * @param  string  $model  Already-resolved model string.
     * @param  string  $systemPrompt  Already-resolved system prompt.
     * @param  ImmutableConfig  $config  Config object (used only to read base_url).
     * @return ?OpenAIProvider
     */
    private static function customProviderOverride(
        string $provider,
        string $apiKey,
        string $model,
        string $systemPrompt,
        ImmutableConfig $config,
    ): ?OpenAIProvider {
        $baseUrl = trim((string) ($config->get('base_url') ?? ''));

        if ($provider !== 'custom' || $baseUrl === '') {
            return null;
        }

        if (! self::isAllowedProviderUrl($baseUrl)) {
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
}
