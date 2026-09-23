<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Component\Administrator\Engine;

use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Registry\Registry;
use PhpClaw\Agent\CliApprovalGate;
use PhpClaw\Claw;
use PhpClaw\Memory\MemoryRegistry;
use PhpClaw\Memory\PrivacyAwareMemory;
use PhpClaw\Providers\Contracts\ProviderInterface;
use PhpClaw\Providers\OpenAIPresets;
use PhpClaw\Providers\OpenAIProvider;
use PhpClaw\Skills\SkillResolver;
use PhpClaw\Tools\ToolProfileResolver;

/**
 * Shared factory to build a PhpClaw engine from Joomla plugin params.
 */
final class EngineFactory
{
    private const PLUGIN_FOLDER = 'system';

    private const PLUGIN_ELEMENT = 'phpclaw';

    private const PROVIDER_CUSTOM = 'custom';

    private const PROVIDER_OLLAMA = 'ollama';

    private const MEMORY_DRIVER_JOOMLA = 'joomladb';

    /**
     * Create a new EngineFactory instance.
     *
     * @param  EngineBootstrapper  $bootstrapper  Registry bootstrapper (injectable for tests).
     * @param  ToolBuilder  $toolBuilder  Tool assembler (injectable for tests).
     */
    public function __construct(
        private readonly EngineBootstrapper $bootstrapper = new EngineBootstrapper,
        private readonly ToolBuilder $toolBuilder = new ToolBuilder,
    ) {}

    /**
     * Build a PhpClaw engine instance from the given plugin params.
     *
     * @param  Registry  $params  Joomla plugin parameters containing provider, model, and feature config.
     * @param  bool|null  $isCli  True to permit PHP-file writes (interactive Joomla CLI). Null auto-detects via PHP_SAPI.
     * @return Claw
     */
    public function build(Registry $params, ?bool $isCli = null): Claw
    {
        $isCli = $isCli ?? (\PHP_SAPI === 'cli');
        $config = PhpClawConfig::fromRegistry($params);

        $this->bootstrapper->boot($config);

        $memory = $this->buildMemory($config);
        $tools = $this->toolBuilder->build($config, allowPhpWrite: $isCli);

        $builder = Claw::builder()
            ->apiKey($config->apiKey)
            ->provider($config->provider)
            ->model($config->model)
            ->systemPrompt($config->systemPrompt)
            ->storeMessages($config->storeMessages)
            ->maxIterations($config->maxIterations)
            ->maxToolsPerTurn(ToolProfileResolver::maxTools(ToolProfileResolver::resolve($config->provider, $config->model)))
            ->shellAllowlist($config->shellAllowlist)
            ->memory($memory)
            ->tools($tools)
            ->skills(SkillResolver::resolve(json_decode($config->skills, true) ?? []))
            ->approvalGate(new CliApprovalGate);

        $providerOverride = self::customProviderOverride($config);
        if ($providerOverride !== null) {
            $builder->providerOverride($providerOverride);
        }

        foreach ($config->remoteSkillUrls as $url) {
            $builder->withRemoteSkills($url);
        }

        return $builder->build();
    }

    /**
     * Get plugin params from the Joomla extensions table.
     *
     * @return Registry
     */
    public static function getPluginParams(): Registry
    {
        $plugin = PluginHelper::getPlugin(self::PLUGIN_FOLDER, self::PLUGIN_ELEMENT);

        if (! $plugin) {
            return new Registry;
        }

        return new Registry($plugin->params ?? '{}');
    }

    /**
     * Build the wrapped memory driver for the agent.
     *
     * @param  PhpClawConfig  $config
     * @return PrivacyAwareMemory
     */
    private function buildMemory(PhpClawConfig $config): PrivacyAwareMemory
    {
        $rawMemory = MemoryRegistry::build(self::MEMORY_DRIVER_JOOMLA);

        return new PrivacyAwareMemory($rawMemory, $config->storeMessages);
    }

    /**
     * Build a pre-configured OpenAIProvider when Base URL points the provider somewhere else.
     * Applies to the OpenAI-compatible providers only; native providers are left alone.
     *
     * @param  PhpClawConfig  $config
     * @return ?ProviderInterface
     */
    private static function customProviderOverride(PhpClawConfig $config): ?ProviderInterface
    {
        if (! in_array($config->provider, [self::PROVIDER_CUSTOM, self::PROVIDER_OLLAMA], true)) {
            return null;
        }

        $baseUrl = trim($config->baseUrl);

        if ($baseUrl === '' || ! PhpClawConfig::isAllowedProviderUrl($baseUrl)) {
            return null;
        }

        $preset = OpenAIPresets::find($config->provider);

        return new OpenAIProvider(
            apiKey: $config->apiKey,
            model: $config->model !== '' ? $config->model : (string) ($preset['model'] ?? ''),
            systemPrompt: $config->systemPrompt,
            endpoint: $baseUrl,
            name: $config->provider,
            authStyle: (string) ($preset['auth'] ?? OpenAIPresets::AUTH_BEARER),
        );
    }
}
