<?php

declare(strict_types=1);

namespace PhpClaw\OpenCart\Factory;

use PhpClaw\AutoDiscovery\Bootstrap;
use PhpClaw\ClawConfig;
use PhpClaw\Contracts\ClawInterface;
use PhpClaw\Guards\Contracts\GuardInterface;
use PhpClaw\Guards\GuardRegistry;
use PhpClaw\Guards\RateLimitGuard;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Memory\MemoryRegistry;
use PhpClaw\OpenCart\Contracts\OcDbInterface;
use PhpClaw\OpenCart\Engine\OcEngineFactory;
use PhpClaw\OpenCart\Memory\OcDbConversationMemory;
use PhpClaw\OpenCart\Memory\OcDbMemory;
use PhpClaw\OpenCart\Memory\OcRouterMemory;
use PhpClaw\OpenCart\Memory\OcSettingMemory;
use PhpClaw\OpenCart\OcEventFirer;
use PhpClaw\OpenCart\OcTablePrefix;
use PhpClaw\Providers\ProviderRegistry;
use PhpClaw\Skills\Contracts\SkillInterface;
use PhpClaw\Skills\SkillRegistry;

/**
 * Builds a configured PhpClaw instance for OpenCart 3/4 (library / standalone path).
 */
final class PhpClawFactory implements PhpClawFactoryInterface
{
    private const API_KEY_ENVS = [
        'ANTHROPIC_API_KEY',
        'OPENAI_API_KEY',
        'GROQ_API_KEY',
        'GEMINI_API_KEY',
        'MISTRAL_API_KEY',
        'DEEPSEEK_API_KEY',
    ];

    private ?OcDbInterface $db = null;

    private OcEventFirer $eventFirer;

    private readonly OcEngineFactory $engineFactory;

    /**
     * Bootstrap the canonical OpenCart engine factory.
     *
     * @return void
     */
    public function __construct()
    {
        $this->engineFactory = new OcEngineFactory;
    }

    /**
     * Build a configured Claw engine from OpenCart constants and the given handles.
     *
     * @param  string|null  $tablePrefix  OpenCart table prefix; defaults to DB_PREFIX, or 'oc_' when undefined.
     * @param  object|null  $registry  OpenCart Registry (provides `event` for extension hooks).
     * @param  OcDbInterface|null  $db  Optional OC native DB handle, required to enable the 'oc_router' memory driver.
     * @return ClawInterface
     */
    public function create(
        ?string $tablePrefix = null,
        ?object $registry = null,
        ?OcDbInterface $db = null,
    ): ClawInterface {
        $console = defined('PHPCLAW_OC_CONSOLE') && constant('PHPCLAW_OC_CONSOLE') === true;

        $resolvedPrefix = OcTablePrefix::resolve($tablePrefix);
        $this->db = $db;
        $this->eventFirer = new OcEventFirer($registry);
        $storeMessages = filter_var(self::configString('PHPCLAW_STORE_MESSAGES', '1'), FILTER_VALIDATE_BOOLEAN);
        $this->bootRegistries($resolvedPrefix, $storeMessages);

        $config = require __DIR__.'/../../config/phpclaw.php';

        $saved = [
            'provider' => self::configString('PHPCLAW_PROVIDER', ''),
            'model' => self::configString('PHPCLAW_MODEL', ''),
            'api_key' => $this->resolveApiKey(),
            'store_messages' => self::configString('PHPCLAW_STORE_MESSAGES', '1'),
            'max_iterations' => self::configString('PHPCLAW_MAX_ITERATIONS', (string) ClawConfig::DEFAULT_MAX_ITERATIONS),
            'base_url' => self::configString('PHPCLAW_BASE_URL', ''),
            'system_prompt' => self::configString('PHPCLAW_SYSTEM_PROMPT', ''),
        ];

        return $this->engineFactory->build(
            $config,
            $saved,
            $db,
            $resolvedPrefix,
            $this->eventFirer,
            callerMayUseModule: $console,
            mayQueryRaw: $console,
        );
    }

    /**
     * Wire the six phpClaw registries for the standalone factory path.
     *
     * @param  string  $tablePrefix  OC table prefix to pass into memory drivers.
     * @param  bool  $storeMessages  Whether message bodies are persisted.
     * @return void
     */
    private function bootRegistries(string $tablePrefix, bool $storeMessages = true): void
    {
        GuardRegistry::registerDefaults();

        $rateLimit = (int) self::configString('PHPCLAW_RATE_LIMIT_PER_MINUTE', '60');
        if ($rateLimit > 0) {
            GuardRegistry::register(new RateLimitGuard($rateLimit, 60), replace: true);
        }

        $db = $this->db;

        MemoryRegistry::register('oc_setting', static fn () => new OcSettingMemory($db, $tablePrefix));

        MemoryRegistry::register('oc_db', static fn () => new OcDbMemory($db, $tablePrefix));

        MemoryRegistry::register('oc_router', static fn () => new OcRouterMemory(
            new OcDbConversationMemory($db, $tablePrefix, $storeMessages),
            new OcDbMemory($db, $tablePrefix),
        ));

        if ($db !== null) {
            MemoryRegistry::register('opencart', static fn () => new OcDbMemory($db, $tablePrefix));
        }

        Bootstrap::boot();

        $this->registerGuards();
        $this->registerHooks();

        foreach ($this->eventFirer->fire('phpclaw/extra/guards', []) as $guard) {
            if ($guard instanceof GuardInterface) {
                GuardRegistry::register($guard, replace: true);
            }
        }
        foreach ($this->eventFirer->fire('phpclaw/extra/hooks', []) as $entry) {
            if (is_array($entry) && isset($entry['event'], $entry['handler']) && is_string($entry['event'])) {
                HookRegistry::on($entry['event'], $entry['handler'], (int) ($entry['priority'] ?? 10));
            }
        }
        foreach ($this->eventFirer->fire('phpclaw/extra/skills', []) as $skill) {
            if ($skill instanceof SkillInterface) {
                SkillRegistry::register($skill);
            }
        }
        foreach ($this->eventFirer->fire('phpclaw/extra/memory', []) as $slug => $factory) {
            if (is_string($slug) && $slug !== '' && is_callable($factory)) {
                MemoryRegistry::register($slug, $factory);
            }
        }
        foreach ($this->eventFirer->fire('phpclaw/extra/providers', []) as $slug => $entry) {
            if (is_string($slug) && $slug !== '' && is_array($entry)
                && isset($entry['class']) && is_string($entry['class']) && class_exists($entry['class'])
                && ! ProviderRegistry::has($slug)) {
                try {
                    ProviderRegistry::register($slug, $entry['class']);
                } catch (\Throwable) {
                }
            }
        }
    }

    /**
     * Parse the JSON-encoded `PHPCLAW_GUARDS` PHP constant and register each guard.
     *
     * @return void
     */
    private function registerGuards(): void
    {
        $raw = self::configString('PHPCLAW_GUARDS', '[]');
        $guards = json_decode($raw, associative: true) ?? [];

        if (! is_array($guards)) {
            return;
        }

        foreach ($guards as $entry) {
            if (! is_array($entry) || ! isset($entry['class']) || ! is_string($entry['class'])) {
                continue;
            }

            if (! class_exists($entry['class'])) {
                continue;
            }

            $priority = (int) ($entry['priority'] ?? 10);
            GuardRegistry::register(new $entry['class'], $priority, replace: true);
        }
    }

    /**
     * Parse the JSON-encoded `PHPCLAW_HOOKS` PHP constant and subscribe each hook.
     *
     * @return void
     */
    private function registerHooks(): void
    {
        $raw = self::configString('PHPCLAW_HOOKS', '[]');
        $hooks = json_decode($raw, associative: true) ?? [];

        if (! is_array($hooks)) {
            return;
        }

        foreach ($hooks as $entry) {
            if (! is_array($entry) || ! isset($entry['event'], $entry['handler'])) {
                continue;
            }

            if (! is_string($entry['event']) || $entry['event'] === '') {
                continue;
            }

            $priority = (int) ($entry['priority'] ?? 10);
            HookRegistry::on($entry['event'], $entry['handler'], $priority);
        }
    }

    /**
     * Resolve the LLM API key by walking the provider-constant precedence chain.
     *
     * @return string Resolved API key, or empty string when nothing is set.
     */
    private function resolveApiKey(): string
    {
        foreach (self::API_KEY_ENVS as $name) {
            $value = self::configString($name, '');
            if ($value !== '') {
                return $value;
            }
        }

        if (defined('PHPCLAW_API_KEY') && PHPCLAW_API_KEY !== '') {
            return PHPCLAW_API_KEY;
        }

        return '';
    }

    /**
     * Read a configuration value from a PHP constant defined in OpenCart's config.php.
     *
     * @param  string  $name  Constant name to look up.
     * @param  string  $default  Value returned when the constant is undefined.
     * @return string
     */
    private static function configString(string $name, string $default): string
    {
        return defined($name) && (string) constant($name) !== ''
            ? (string) constant($name)
            : $default;
    }
}
