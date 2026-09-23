<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Component\Administrator\Model;

use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use PhpClaw\AutoDiscovery\DiscoveryCache;
use PhpClaw\Joomla\Component\Administrator\Engine\EngineFactory;
use PhpClaw\Joomla\Component\Administrator\Engine\JoomlaEventDispatcher;
use PhpClaw\Joomla\Component\Administrator\Engine\PhpClawConfig;
use PhpClaw\Joomla\Component\Administrator\Engine\ToolBuilder;
use PhpClaw\Joomla\Component\Administrator\Model\Concerns\ReadsPhpClawParams;
use PhpClaw\Providers\ProviderCatalogue;
use PhpClaw\Skills\SkillRegistry;
use PhpClaw\Tools\ToolRegistry;

/**
 * Guide model - provides data for the guide/documentation view.
 */
final class GuideModel extends BaseDatabaseModel
{
    use ReadsPhpClawParams;

    private const CORE_TOOL_NAMESPACE = 'PhpClaw\\Tools\\';

    private const TOOL_LANG_KEYS = [
        'joomla_articles' => 'COM_PHPCLAW_TOOL_ARTICLE',
        'joomla_categories' => 'COM_PHPCLAW_TOOL_CATEGORY',
        'joomla_users' => 'COM_PHPCLAW_TOOL_USER',
        'joomla_extensions' => 'COM_PHPCLAW_TOOL_EXTENSION',
        'joomla_database_query' => 'COM_PHPCLAW_TOOL_DATABASE',
        'joomla_zip_extension' => 'COM_PHPCLAW_TOOL_ZIP',
    ];

    /**
     * Build one Joomla-native tool row from its translated label, description and example prompts.
     *
     * @param  string  $name  Registered tool name, for example joomla_articles.
     * @return array{name: string, description: string, prompts: string}
     */
    private static function translatedToolRow(string $name): array
    {
        $key = self::TOOL_LANG_KEYS[$name];

        return [
            'name' => Text::_($key),
            'description' => Text::_($key.'_DESC'),
            'prompts' => Text::_($key.'_PROMPTS'),
        ];
    }

    /**
     * Joomla table prefix from config, or empty string when the DB connection is unavailable.
     *
     * @return string
     */
    public function getTablePrefix(): string
    {
        try {
            return $this->getDatabase()->getPrefix();
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Get registered tools from the engine's ToolRegistry.
     *
     * @return list<array{name: string, description: string, prompts: string}>
     */
    public function getRegisteredTools(): array
    {
        $registry = $this->buildRegistry();

        if ($registry === null) {
            return self::fallbackTools();
        }

        $tools = $registry->all();

        $known = [];
        $extra = [];

        foreach ($tools as $tool) {
            if (str_starts_with($tool::class, self::CORE_TOOL_NAMESPACE)) {
                continue;
            }

            $name = $tool->name();
            $row = isset(self::TOOL_LANG_KEYS[$name])
                ? self::translatedToolRow($name)
                : ['name' => $name, 'description' => $tool->description(), 'prompts' => ''];

            if (isset(self::TOOL_LANG_KEYS[$name])) {
                $known[$name] = $row;
            } else {
                $extra[] = $row;
            }
        }

        $ordered = [];
        foreach (array_keys(self::TOOL_LANG_KEYS) as $name) {
            if (isset($known[$name])) {
                $ordered[] = $known[$name];
            }
        }

        $rows = array_merge($ordered, $extra);

        return $rows === [] ? self::fallbackTools() : $rows;
    }

    /**
     * Live provider list for the guide's providers tab (presets, discovered classes, custom registrations).
     *
     * @return list<array{name: string, key: string, models: string}>
     */
    public function getProviders(): array
    {
        try {
            $catalogue = ProviderCatalogue::all();
        } catch (\Throwable) {
            return [];
        }

        $meta = [
            'anthropic' => ['name' => 'Anthropic', 'models' => 'claude-sonnet-5, claude-opus-4-8, claude-haiku-4-5-20251001'],
            'openai' => ['name' => 'OpenAI',    'models' => 'gpt-4o, gpt-4o-mini, gpt-4.1'],
            'groq' => ['name' => 'Groq',      'models' => 'llama-3.3-70b-versatile, llama-3.1-8b-instant'],
            'gemini' => ['name' => 'Gemini',    'models' => 'gemini-3.5-flash-lite, gemini-3.5-flash, gemini-2.5-pro'],
            'mistral' => ['name' => 'Mistral',   'models' => 'mistral-large-latest, mistral-small-latest'],
            'deepseek' => ['name' => 'DeepSeek',  'models' => 'deepseek-flash, deepseek-chat, deepseek-reasoner'],
            'ollama' => ['name' => 'Ollama',    'models' => Text::_('COM_PHPCLAW_GUIDE_PROVIDER_OLLAMA_LOCAL')],
            'custom' => ['name' => 'Custom',    'models' => 'Any OpenAI-compatible endpoint. Set Base URL in Settings.'],
        ];

        $rows = [];

        foreach ($catalogue as $slug => $info) {
            $slug = (string) $slug;
            $m = $meta[$slug] ?? [];
            $rows[] = [
                'name' => (string) ($m['name'] ?? $info['label'] ?: ucfirst($slug)),
                'key' => $slug,
                'models' => (string) ($m['models'] ?? ''),
            ];
        }

        return $rows;
    }

    /**
     * Core-namespace tools from the built registry, for the guide's tools tab.
     *
     * @return list<array{tool: string, description: string, deprecated: bool}>
     */
    public function getCoreUtilityTools(): array
    {
        try {
            $cache = DiscoveryCache::load();
        } catch (\Throwable) {
            $cache = ['tools' => []];
        }

        $rows = [];

        foreach ($this->buildRegistry()?->all() ?? [] as $tool) {
            $class = $tool::class;

            if (! str_starts_with($class, self::CORE_TOOL_NAMESPACE)) {
                continue;
            }

            $attr = $cache['tools'][$class] ?? [];
            $base = strrchr($class, '\\');
            $rows[] = [
                'tool' => $base === false ? $class : substr($base, 1),
                'description' => (string) ($attr['description'] ?? $tool->description()),
                'deprecated' => ! empty($attr['deprecated']),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => strcmp($a['tool'], $b['tool']));

        return $rows;
    }

    /**
     * Build the same ToolRegistry the engine builds, or null when the container is unavailable,
     * so both Guide tool tables list exactly the tools the agent can call.
     *
     * @return ToolRegistry|null
     */
    private function buildRegistry(): ?ToolRegistry
    {
        try {
            $config = PhpClawConfig::fromRegistry(EngineFactory::getPluginParams());
            $registry = new ToolRegistry;
            $registry->register(
                (new ToolBuilder)->build($config, applyProfile: false),
                $config->toolDeny,
                ToolBuilder::TOOL_GROUPS,
            );

            return $registry;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Auto-discovered memory drivers formatted for the guide view's memory tab.
     *
     * @return list<array{driver: string, label: string, source: string, class: string}>
     */
    public function getDiscoveredMemoryDrivers(): array
    {
        try {
            $cache = DiscoveryCache::load();
        } catch (\Throwable) {
            return [];
        }

        $records = [];

        foreach ($cache['memory'] ?? [] as $class => $attr) {
            $class = (string) $class;
            $records[] = [
                'driver' => (string) ($attr['driver'] ?? ''),
                'label' => (string) ($attr['label'] ?? $class),
                'source' => str_starts_with($class, 'PhpClaw\\Joomla') ? 'joomla' : 'core',
                'class' => $class,
            ];
        }

        foreach (self::pluginEvent('onPhpClawExtraMemoryDrivers', 'drivers') as $slug => $factory) {
            if (! is_string($slug)) {
                continue;
            }
            $records[] = [
                'driver' => $slug,
                'label' => $slug,
                'source' => 'joomla',
                'class' => '',
            ];
        }

        return $records;
    }

    /**
     * Auto-discovered guards formatted for the guide view's guards tab.
     *
     * @return list<array{name: string, label: string, priority: int, enabled_by_default: bool, source: string, class: string}>
     */
    public function getDiscoveredGuards(): array
    {
        try {
            $cache = DiscoveryCache::load();
        } catch (\Throwable) {
            return [];
        }

        $records = [];

        foreach ($cache['guards'] ?? [] as $class => $attr) {
            $class = (string) $class;
            $records[] = [
                'name' => (string) ($attr['name'] ?? ''),
                'label' => (string) ($attr['label'] ?? $class),
                'priority' => (int) ($attr['priority'] ?? 0),
                'enabled_by_default' => (bool) ($attr['enabledByDefault'] ?? false),
                'source' => str_starts_with($class, 'PhpClaw\\Joomla') ? 'joomla' : 'core',
                'class' => $class,
            ];
        }

        foreach (self::pluginEvent('onPhpClawExtraGuards', 'guards') as $guard) {
            if (! is_array($guard) || ! isset($guard['class'])) {
                continue;
            }
            $class = (string) $guard['class'];
            $records[] = [
                'name' => self::shortName($class),
                'label' => self::shortName($class),
                'priority' => (int) ($guard['priority'] ?? 0),
                'enabled_by_default' => true,
                'source' => 'joomla',
                'class' => $class,
            ];
        }

        return $records;
    }

    /**
     * Auto-discovered registered hook listeners formatted for the guide view's hooks tab.
     *
     * @return list<array{event: string, name: string, priority: int, enabled_by_default: bool, source: string, class: string}>
     */
    public function getDiscoveredHooks(): array
    {
        try {
            $cache = DiscoveryCache::load();
        } catch (\Throwable) {
            return [];
        }

        $records = [];

        foreach ($cache['hooks'] ?? [] as $class => $listeners) {
            $class = (string) $class;
            $source = str_starts_with($class, 'PhpClaw\\Joomla') ? 'joomla' : 'core';

            foreach ((array) $listeners as $listener) {
                $records[] = [
                    'event' => (string) ($listener['event'] ?? ''),
                    'name' => (string) ($listener['name'] ?? ''),
                    'priority' => (int) ($listener['priority'] ?? 0),
                    'enabled_by_default' => (bool) ($listener['enabledByDefault'] ?? false),
                    'source' => $source,
                    'class' => $class,
                ];
            }
        }

        foreach (self::pluginEvent('onPhpClawExtraHooks', 'hooks') as $listener) {
            if (! is_array($listener) || ! isset($listener['event'])) {
                continue;
            }
            $handler = $listener['handler'] ?? null;
            $hClass = is_array($handler) ? ($handler[0] ?? '') : $handler;
            $hClass = is_object($hClass) ? $hClass::class : (string) $hClass;
            $hMethod = is_array($handler) ? (string) ($handler[1] ?? '') : '';
            $records[] = [
                'event' => (string) $listener['event'],
                'name' => $hMethod !== '' ? $hMethod : self::shortName($hClass),
                'priority' => (int) ($listener['priority'] ?? 10),
                'enabled_by_default' => true,
                'source' => 'joomla',
                'class' => $hClass,
            ];
        }

        return $records;
    }

    /**
     * Auto-discovered skills formatted for the guide view's skills tab.
     *
     * @return list<array{name: string, label: string, keywords: list<string>, source: string, class: string}>
     */
    public function getDiscoveredSkills(): array
    {
        try {
            $cache = DiscoveryCache::load();
        } catch (\Throwable) {
            return [];
        }

        $records = [];

        foreach ($cache['skills'] ?? [] as $class => $attr) {
            $class = (string) $class;
            $records[] = [
                'name' => (string) ($attr['name'] ?? ''),
                'label' => (string) ($attr['label'] ?? $class),
                'keywords' => array_values(array_map('strval', (array) ($attr['keywords'] ?? []))),
                'source' => str_starts_with($class, 'PhpClaw\\Joomla') ? 'joomla' : 'core',
                'class' => $class,
            ];
        }

        foreach (self::pluginEvent('onPhpClawExtraSkills', 'skills') as $skill) {
            if (! is_object($skill)) {
                continue;
            }
            $class = $skill::class;
            $name = method_exists($skill, 'name') ? (string) $skill->name() : self::shortName($class);
            $keywords = method_exists($skill, 'tags') ? array_map('strval', (array) $skill->tags()) : [];
            $records[] = [
                'name' => $name,
                'label' => $name,
                'keywords' => array_values($keywords),
                'source' => 'joomla',
                'class' => $class,
            ];
        }

        return $records;
    }

    /**
     * Configured `remote_skill_urls`, or [] when none are set.
     *
     * @return list<string>
     */
    public function getRemoteSkillUrls(): array
    {
        try {
            $raw = EngineFactory::getPluginParams()->get('remote_skill_urls', '');
        } catch (\Throwable) {
            return [];
        }

        return PhpClawConfig::filterRemoteSkillUrls($raw);
    }

    /**
     * Skills registered via `remote_skill_urls`, diffed against discovered and plugin-extra skill names since AutoDiscovery never sees skills loaded at engine-build time.
     *
     * @return list<array{name: string, description: string, keywords: list<string>}>
     */
    public function getRemoteSkills(): array
    {
        $urls = $this->getRemoteSkillUrls();

        if ($urls === []) {
            return [];
        }

        try {
            (new EngineFactory)->build(EngineFactory::getPluginParams());
        } catch (\Throwable) {
            return [];
        }

        if (! class_exists(SkillRegistry::class)) {
            return [];
        }

        $known = [];
        foreach ($this->getDiscoveredSkills() as $s) {
            $known[$s['name']] = true;
        }

        $records = [];
        foreach (SkillRegistry::all() as $skill) {
            if (isset($known[$skill->name()])) {
                continue;
            }
            $records[] = [
                'name' => $skill->name(),
                'description' => $skill->description(),
                'keywords' => array_map('strval', $skill->tags()),
            ];
        }

        return $records;
    }

    /**
     * Fallback list of built-in tools when the engine cannot be built.
     *
     * @return list<array{name: string, description: string, prompts: string}>
     */
    private static function fallbackTools(): array
    {
        $rows = [];

        foreach (array_keys(self::TOOL_LANG_KEYS) as $name) {
            $rows[] = self::translatedToolRow($name);
        }

        return $rows;
    }

    /**
     * Plugin-contributed extras for a subsystem via its `onPhpClawExtra*` event.
     *
     * @param  string  $event
     * @param  string  $key
     * @return array<int|string, mixed>
     */
    private static function pluginEvent(string $event, string $key): array
    {
        $value = [];
        JoomlaEventDispatcher::fire($event, $key, $value);

        return is_array($value) ? $value : [];
    }

    /**
     * Class basename for a fully-qualified class string.
     *
     * @param  string  $class
     * @return string
     */
    private static function shortName(string $class): string
    {
        $pos = strrchr($class, '\\');

        return $pos === false ? $class : substr($pos, 1);
    }
}
