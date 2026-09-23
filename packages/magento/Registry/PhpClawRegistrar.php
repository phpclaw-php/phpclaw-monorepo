<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Registry;

use Magento\Framework\DataObject;
use Magento\Framework\DataObjectFactory;
use Magento\Framework\Event\ManagerInterface;
use PhpClaw\AutoDiscovery\Bootstrap;
use PhpClaw\Guards\GuardRegistry;
use PhpClaw\Hooks\Contracts\HookInterface;
use PhpClaw\Hooks\HookEventBridge;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Magento\Memory\ResourceMemory;
use PhpClaw\Memory\MemoryRegistry;
use PhpClaw\Providers\OpenAIPresets;
use PhpClaw\Providers\ProviderRegistry;
use PhpClaw\Skills\Contracts\SkillInterface;
use PhpClaw\Skills\SkillCatalogue;
use PhpClaw\Skills\SkillRegistry;
use PhpClaw\Tools\Contracts\ToolInterface;
use Psr\Log\LoggerInterface;

/**
 * Wires phpClaw's static registries from Magento's config and DI container.
 */
// non-final: Magento interceptor required
class PhpClawRegistrar
{
    private array $extraTools = [];

    private array $externalSkills = [];

    private array $externalGuards = [];

    private array $externalMemoryDrivers = [];

    private array $externalHooks = [];

    /**
     * Bind the memory driver and event manager this registrar boots the registries with.
     *
     * @param  ResourceMemory  $resourceMemory  Injected by ObjectManager (uses ResourceConnection).
     * @param  ManagerInterface  $eventManager  Magento event dispatcher used to bridge phpClaw lifecycle events.
     * @param  DataObjectFactory  $dataObjectFactory  Magento DataObject factory for building event transport objects.
     * @param  array  $guards  Injected via di.xml: [['class' => FQCN, 'priority' => int]]
     * @param  array  $hooks  Injected via di.xml: [['event' => string, 'handler' => callable, 'priority' => int]]
     * @param  LoggerInterface|null  $logger  PSR-3 logger used to surface a module-supplied registration that failed.
     * @return void
     */
    public function __construct(
        private readonly ResourceMemory $resourceMemory,
        private readonly ManagerInterface $eventManager,
        private readonly DataObjectFactory $dataObjectFactory,
        private readonly array $guards = [],
        private readonly array $hooks = [],
        private readonly ?LoggerInterface $logger = null,
    ) {
        $this->boot();
    }

    /**
     * Return extra tool instances collected from phpclaw_extra_tools observers.
     *
     * @return ToolInterface[]
     */
    public function getExtraTools(): array
    {
        return $this->extraTools;
    }

    /**
     * Return external skills captured during boot, for the Guide listing.
     *
     * @return list<array{name: string, label: string, keywords: list<string>, class: string}>
     */
    public function getExternalSkills(): array
    {
        return $this->externalSkills;
    }

    /**
     * Return external guards captured during boot, for the Guide listing.
     *
     * @return list<array{name: string, label: string, priority: int, enabled_by_default: bool, class: string}>
     */
    public function getExternalGuards(): array
    {
        return $this->externalGuards;
    }

    /**
     * Return external memory drivers captured during boot, for the Guide listing.
     *
     * @return list<array{driver: string, label: string, class: string}>
     */
    public function getExternalMemoryDrivers(): array
    {
        return $this->externalMemoryDrivers;
    }

    /**
     * Return external hook listeners captured during boot, for the Guide listing.
     *
     * @return list<array{event: string, name: string, priority: int, enabled_by_default: bool, class: string}>
     */
    public function getExternalHooks(): array
    {
        return $this->externalHooks;
    }

    /**
     * Run all registry bootstrap steps.
     *
     * @return void
     */
    private function boot(): void
    {
        if (class_exists(Bootstrap::class)) {
            Bootstrap::boot();
        }

        $this->bootMemoryRegistry();
        $this->bootGuardRegistry();
        $this->bootHookRegistry();
        $this->bootSkillCatalogue();

        $this->dispatchExtraTools();
        $this->dispatchExtraSkills();
        $this->dispatchExtraGuards();
        $this->dispatchExtraHooks();
        $this->dispatchExtraMemory();
        $this->dispatchExtraProviders();
    }

    /**
     * Register ResourceMemory under the 'resource' key in MemoryRegistry.
     *
     * @return void
     */
    private function bootMemoryRegistry(): void
    {
        $driver = $this->resourceMemory;
        MemoryRegistry::register('resource', fn () => $driver);
    }

    /**
     * Register default guards and any additional guards supplied via di.xml.
     *
     * @return void
     */
    private function bootGuardRegistry(): void
    {
        GuardRegistry::registerDefaults();
        foreach ($this->guards as $entry) {
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
     * Activate all discovered skills so every #[Skill] is always-on and keyword-matched per turn.
     *
     * @return void
     */
    private function bootSkillCatalogue(): void
    {
        if (! class_exists(SkillCatalogue::class)) {
            return;
        }

        SkillCatalogue::activateDefaults();
    }

    /**
     * Wire the canonical HookEventBridge and any di.xml-injected hooks into HookRegistry.
     *
     * @return void
     */
    private function bootHookRegistry(): void
    {
        $manager = $this->eventManager;
        (new HookEventBridge(
            static function (string $event, array $ctx) use ($manager): void {
                $manager->dispatch('phpclaw_'.str_replace('.', '_', $event), ['data' => $ctx]);
            },
        ))->register();

        foreach ($this->hooks as $entry) {
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
     * Dispatch an observer event carrying a fresh transport and return the collected entries.
     *
     * @param  string  $event  Magento event name to dispatch.
     * @param  string  $key  Transport data key that observers populate.
     * @return array<int|string, mixed> Entries observers placed on the transport.
     */
    private function collectExtras(string $event, string $key): array
    {
        $transport = $this->dataObjectFactory->create([$key => []]);
        $this->eventManager->dispatch($event, ['transport' => $transport]);

        return (array) $transport->getData($key);
    }

    /**
     * Dispatch phpclaw_extra_tools to collect ToolInterface instances from observers.
     *
     * @return void
     */
    private function dispatchExtraTools(): void
    {
        foreach ($this->collectExtras('phpclaw_extra_tools', 'tools') as $entry) {
            if ($entry instanceof ToolInterface) {
                $this->extraTools[] = $entry;
            } elseif (is_string($entry) && class_exists($entry) && is_a($entry, ToolInterface::class, true)) {
                $this->extraTools[] = new $entry;
            }
        }
    }

    /**
     * Dispatch phpclaw_extra_skills and register observer-supplied SkillInterface instances.
     *
     * @return void
     */
    private function dispatchExtraSkills(): void
    {
        foreach ($this->collectExtras('phpclaw_extra_skills', 'skills') as $entry) {
            $instance = null;
            if ($entry instanceof SkillInterface) {
                $instance = $entry;
            } elseif (is_string($entry) && class_exists($entry) && is_a($entry, SkillInterface::class, true)) {
                $instance = new $entry;
            }

            if ($instance === null) {
                continue;
            }

            SkillRegistry::register($instance);

            $name = (string) $instance->name();
            $keywords = array_values(array_map('strval', (array) $instance->tags()));
            $this->externalSkills[] = [
                'name' => $name,
                'label' => $name,
                'keywords' => $keywords,
                'class' => $instance::class,
            ];
        }
    }

    /**
     * Dispatch phpclaw_extra_guards and register observer-supplied guard class entries.
     *
     * @return void
     */
    private function dispatchExtraGuards(): void
    {
        foreach ($this->collectExtras('phpclaw_extra_guards', 'guards') as $entry) {
            if (! is_array($entry) || ! isset($entry['class']) || ! is_string($entry['class'])) {
                continue;
            }

            if (! class_exists($entry['class'])) {
                continue;
            }

            $priority = (int) ($entry['priority'] ?? 10);
            $instance = new $entry['class'];
            GuardRegistry::register($instance, $priority, replace: true);

            $shortName = (new \ReflectionClass($instance))->getShortName();
            $this->externalGuards[] = [
                'name' => $shortName,
                'label' => $shortName,
                'priority' => $priority,
                'enabled_by_default' => true,
                'class' => $instance::class,
            ];
        }
    }

    /**
     * Dispatch phpclaw_extra_hooks and register observer-supplied hook entries into HookRegistry.
     *
     * @return void
     */
    private function dispatchExtraHooks(): void
    {
        foreach ($this->collectExtras('phpclaw_extra_hooks', 'hooks') as $entry) {
            if (! is_array($entry) || ! isset($entry['event'], $entry['handler'])) {
                continue;
            }

            if (! is_string($entry['event']) || $entry['event'] === '') {
                continue;
            }

            $handler = $entry['handler'];
            if (! is_callable($handler) && ! ($handler instanceof HookInterface)) {
                continue;
            }

            if (! ($handler instanceof \Closure) && ! ($handler instanceof HookInterface)) {
                $handler = static function (array $ctx) use ($handler): void {
                    $handler($ctx);
                };
            }

            $priority = (int) ($entry['priority'] ?? 10);
            HookRegistry::on($entry['event'], $handler, $priority);

            $handlerClass = $handler::class;
            $this->externalHooks[] = [
                'event' => $entry['event'],
                'name' => (new \ReflectionClass($handlerClass))->getShortName(),
                'priority' => $priority,
                'enabled_by_default' => true,
                'class' => $handlerClass,
            ];
        }
    }

    /**
     * Dispatch phpclaw_extra_memory and register observer-supplied memory driver factories.
     *
     * @return void
     */
    private function dispatchExtraMemory(): void
    {
        foreach ($this->collectExtras('phpclaw_extra_memory', 'drivers') as $slug => $factory) {
            if (! is_string($slug) || $slug === '') {
                continue;
            }

            if (! is_callable($factory) && ! is_string($factory)) {
                continue;
            }

            try {
                MemoryRegistry::register($slug, $factory);
                $factoryClass = is_string($factory) ? $factory : (is_object($factory) ? $factory::class : '');
                $this->externalMemoryDrivers[] = [
                    'driver' => $slug,
                    'label' => $slug,
                    'class' => $factoryClass,
                ];
            } catch (\Throwable $e) {
                $this->logger?->warning('phpclaw: a module-supplied memory driver was rejected', ['driver' => $slug, 'exception' => $e]);
            }
        }
    }

    /**
     * Dispatch phpclaw_extra_providers and register observer-supplied LLM provider entries.
     *
     * @return void
     */
    private function dispatchExtraProviders(): void
    {
        $entries = $this->collectExtras('phpclaw_extra_providers', 'providers');
        $hasPresets = class_exists(OpenAIPresets::class);
        $natives = class_exists(Bootstrap::class) ? Bootstrap::providers() : [];

        foreach ($entries as $slug => $entry) {
            if (! is_string($slug) || $slug === '') {
                continue;
            }

            if ($hasPresets && OpenAIPresets::has($slug)) {
                continue;
            }

            if (isset($natives[$slug])) {
                continue;
            }

            if (! is_array($entry) || ! isset($entry['class']) || ! is_string($entry['class'])) {
                continue;
            }

            if (! class_exists($entry['class'])) {
                continue;
            }

            try {
                ProviderRegistry::register($slug, $entry['class']);
            } catch (\Throwable $e) {
                $this->logger?->warning('phpclaw: a module-supplied provider was rejected', ['provider' => $slug, 'exception' => $e]);
            }
        }
    }
}
