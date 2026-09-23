<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Component\Administrator\Engine;

use Joomla\CMS\Application\ConsoleApplication;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Event\GenericEvent;
use PhpClaw\AutoDiscovery\Bootstrap;
use PhpClaw\Cloud\CloudManager;
use PhpClaw\Guards\GuardRegistry;
use PhpClaw\Hooks\HookEventBridge;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Joomla\Component\Administrator\Database\PhpClawTables;
use PhpClaw\Joomla\Component\Administrator\Memory\JoomlaDbConversationMemory;
use PhpClaw\Joomla\Component\Administrator\Memory\JoomlaDbMemory;
use PhpClaw\Joomla\Component\Administrator\Memory\JoomlaDbRouterMemory;
use PhpClaw\Memory\FileMemory;
use PhpClaw\Memory\MemoryRegistry;
use PhpClaw\Providers\OpenAIPresets;
use PhpClaw\Providers\ProviderRegistry;
use PhpClaw\Skills\SkillCatalogue;
use PhpClaw\Skills\SkillRegistry;
use PhpClaw\Skills\SkillResolver;

/**
 * Wires all registries (guards, memory drivers, hooks, skills, cloud) from the plugin config.
 */
final class EngineBootstrapper
{
    private const MEMORY_DRIVER_JOOMLA = 'joomladb';

    private const MEMORY_DRIVER_FILE = 'file';

    private const FILE_STORAGE_SUBPATH = '/components/com_phpclaw/storage/phpclaw';

    private const REQUIRED_TABLES = [
        PhpClawTables::CONVERSATIONS_TABLE,
        PhpClawTables::MESSAGES_TABLE,
        PhpClawTables::MEMORY_TABLE,
    ];

    private const INSTALL_SQL_PATH = '/components/com_phpclaw/sql/install.mysql.utf8.sql';

    /**
     * Ensure the phpClaw tables exist, then boot all registries from the given config.
     *
     * @param  PhpClawConfig  $config
     * @return void
     */
    public function boot(PhpClawConfig $config): void
    {
        $this->ensureTables();

        PluginHelper::importPlugin('system');

        (new HookEventBridge(
            static function (string $event, array $ctx): void {
                try {
                    Factory::getApplication()
                        ->getDispatcher()
                        ->dispatch(self::phpClawEventName($event), new GenericEvent('phpclaw.'.$event, $ctx));
                } catch (\Throwable) {
                }
            },
        ))->register();

        Bootstrap::boot();

        GuardRegistry::registerDefaults();
        $this->registerMemoryDrivers($config);
        $this->registerConfigGuards($config);
        $this->registerConfigHooks($config);
        $this->registerConfigSkills($config);
        $this->registerCatalogueSkills();

        Bootstrap::activateFromSettings([
            'provider' => $config->provider,
            'model' => $config->model,
            'api_key' => $config->apiKey,
            'base_url' => $config->baseUrl,
            'store_messages' => $config->storeMessages,
            'system_prompt' => $config->systemPrompt,
            'max_iterations' => $config->maxIterations,
            'cloud_key' => $config->cloudKey,
            'cloud_disable' => $config->cloudDisable,
            'guards' => $config->guards,
            'hooks' => $config->hooks,
            'skills' => $config->skills,
        ]);
        $this->registerExtraProviders();

        $this->bootCloud($config);
    }

    /**
     * Map a core lifecycle event name to its Joomla onPhpClaw* dispatch name.
     *
     * @param  string  $event  Core lifecycle event name (e.g. tool.after).
     * @return string Joomla event name (e.g. onPhpClawToolAfter).
     */
    private static function phpClawEventName(string $event): string
    {
        return 'onPhpClaw'.str_replace(['.', '_'], '', ucwords($event, '._'));
    }

    /**
     * Resolve the acting user id from the Joomla session.
     *
     * Console runs have no logged-in identity, so they always resolve to the unowned sentinel.
     *
     * @return int
     */
    private static function resolveActingUserId(): int
    {
        try {
            $app = Factory::getApplication();

            if ($app instanceof ConsoleApplication) {
                return 0;
            }

            return (int) ($app->getIdentity()?->id ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Whether the acting identity may reach every user's conversations.
     *
     * @return bool
     */
    private static function resolveManageAll(): bool
    {
        try {
            $app = Factory::getApplication();

            if ($app instanceof ConsoleApplication) {
                return false;
            }

            return (bool) $app->getIdentity()?->authorise('phpclaw.chat.manageall', 'com_phpclaw');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Register both memory drivers (joomladb + file) with MemoryRegistry.
     *
     * @param  PhpClawConfig  $config
     * @return void
     */
    private function registerMemoryDrivers(PhpClawConfig $config): void
    {
        $storeMessages = $config->storeMessages;

        MemoryRegistry::register(
            self::MEMORY_DRIVER_JOOMLA,
            static fn (): JoomlaDbRouterMemory => new JoomlaDbRouterMemory(
                new JoomlaDbConversationMemory(
                    JoomlaEventDispatcher::db(),
                    $storeMessages,
                    self::resolveActingUserId(),
                    self::resolveManageAll(),
                ),
                new JoomlaDbMemory(JoomlaEventDispatcher::db()),
            ),
        );

        $fileStorageDir = JPATH_ADMINISTRATOR.self::FILE_STORAGE_SUBPATH;

        MemoryRegistry::register(
            self::MEMORY_DRIVER_FILE,
            static fn (): FileMemory => new FileMemory($fileStorageDir),
        );

        $extra = [];
        JoomlaEventDispatcher::fire('onPhpClawExtraMemoryDrivers', 'drivers', $extra);

        foreach ($extra as $slug => $factory) {
            if (is_string($slug) && is_callable($factory)) {
                MemoryRegistry::register($slug, $factory);
            }
        }
    }

    /**
     * Dispatch the cloud-transport bootstrap with all config pulled from plugin config.
     *
     * @param  PhpClawConfig  $config
     * @return void
     */
    private function bootCloud(PhpClawConfig $config): void
    {
        if (! $config->storeMessages || $config->cloudKey === '' || ! class_exists(CloudManager::class)) {
            return;
        }

        CloudManager::boot(
            $config->cloudKey,
            $config->cloudDisable,
            $config->cloudSigningSecret,
        );
    }

    /**
     * Ensure phpClaw database tables exist.
     *
     * @return void
     */
    private function ensureTables(): void
    {
        try {
            $db = JoomlaEventDispatcher::db();
            $prefix = $db->getPrefix();

            $missing = false;

            foreach (self::REQUIRED_TABLES as $table) {
                $db->setQuery('SHOW TABLES LIKE '.$db->quote(str_replace('#__', $prefix, $table)));

                if ($db->loadResult() === null) {
                    $missing = true;
                    break;
                }
            }

            if (! $missing) {
                return;
            }

            $sqlFile = JPATH_ADMINISTRATOR.self::INSTALL_SQL_PATH;
            if (! file_exists($sqlFile)) {
                return;
            }

            $sql = str_replace('#__', $prefix, (string) file_get_contents($sqlFile));

            foreach (array_filter(explode(';', $sql)) as $statement) {
                $statement = trim($statement);
                if ($statement === '') {
                    continue;
                }
                $db->setQuery($statement);
                $db->execute();
            }
        } catch (\Throwable $e) {
            error_log('phpClaw: ensureTables failed - '.$e->getMessage());
        }
    }

    /**
     * Register custom guards from the plugin's JSON guards config.
     *
     * @param  PhpClawConfig  $config
     * @return void
     */
    private function registerConfigGuards(PhpClawConfig $config): void
    {
        $guards = self::decodeJsonList($config->guards);
        JoomlaEventDispatcher::fire('onPhpClawExtraGuards', 'guards', $guards);

        foreach ($guards as $entry) {
            if (! is_array($entry) || ! isset($entry['class']) || ! is_string($entry['class'])) {
                continue;
            }
            if (! class_exists($entry['class'])) {
                continue;
            }

            $priority = (int) ($entry['priority'] ?? 10);
            $ctor = (new \ReflectionClass($entry['class']))->getConstructor();
            if ($ctor !== null && $ctor->getNumberOfRequiredParameters() > 0) {
                continue;
            }
            GuardRegistry::register(new $entry['class'], $priority, replace: true);
        }
    }

    /**
     * Register custom hooks from the plugin's JSON hooks config.
     *
     * @param  PhpClawConfig  $config
     * @return void
     */
    private function registerConfigHooks(PhpClawConfig $config): void
    {
        $hooks = self::decodeJsonList($config->hooks);
        JoomlaEventDispatcher::fire('onPhpClawExtraHooks', 'hooks', $hooks);

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
     * Register custom skills from the plugin's JSON skills config into SkillRegistry.
     *
     * @param  PhpClawConfig  $config
     * @return void
     */
    private function registerConfigSkills(PhpClawConfig $config): void
    {
        $skills = SkillResolver::resolve(self::decodeJsonList($config->skills));
        JoomlaEventDispatcher::fire('onPhpClawExtraSkills', 'skills', $skills);

        foreach ($skills as $skill) {
            SkillRegistry::register($skill);
        }
    }

    /**
     * Activate every discovered #[Skill] catalogue entry; skills are always-on and the matcher gates injection per message.
     *
     * @return void
     */
    private function registerCatalogueSkills(): void
    {
        SkillCatalogue::activateDefaults();
    }

    /**
     * Register custom LLM providers from the `onPhpClawExtraProviders` event.
     *
     * @return void
     */
    private function registerExtraProviders(): void
    {
        $providers = [];
        JoomlaEventDispatcher::fire('onPhpClawExtraProviders', 'providers', $providers);

        $hasPresets = class_exists(OpenAIPresets::class);
        $natives = Bootstrap::providers();

        foreach ($providers as $slug => $entry) {
            if (! is_string($slug) || ! is_array($entry) || ! isset($entry['class'])) {
                continue;
            }
            if ($hasPresets && OpenAIPresets::has($slug)) {
                continue;
            }
            if (isset($natives[$slug])) {
                continue;
            }
            if (! is_string($entry['class']) || ! class_exists($entry['class'])) {
                continue;
            }
            try {
                ProviderRegistry::register($slug, $entry['class']);
            } catch (\Throwable) {
            }
        }
    }

    /**
     * JSON-decode a string into a list of entries; returns `[]` for invalid input.
     *
     * @param  string  $raw
     * @return array<int, mixed>
     */
    private static function decodeJsonList(string $raw): array
    {
        $decoded = json_decode($raw, associative: true);

        return is_array($decoded) ? $decoded : [];
    }
}
