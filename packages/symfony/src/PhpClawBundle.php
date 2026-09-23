<?php

declare(strict_types=1);

namespace PhpClaw\Symfony;

use PhpClaw\AutoDiscovery\Bootstrap;
use PhpClaw\ClawConfig;
use PhpClaw\Cloud\CloudManager;
use PhpClaw\Config\ToolConfig;
use PhpClaw\Exceptions\PhpClawException;
use PhpClaw\Guards\GuardRegistry;
use PhpClaw\Hooks\HookEventBridge;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Hooks\LifecycleEvent;
use PhpClaw\Memory\Contracts\MemoryInterface;
use PhpClaw\Memory\MemoryRegistry;
use PhpClaw\Providers\ProviderRegistry;
use PhpClaw\Skills\Contracts\SkillInterface;
use PhpClaw\Skills\SkillCatalogue;
use PhpClaw\Skills\SkillRegistry;
use PhpClaw\Skills\SkillResolver;
use PhpClaw\Symfony\DependencyInjection\RemoveDoctrineServicesPass;
use PhpClaw\Symfony\Events\PhpClawEvent;
use PhpClaw\Symfony\Extension\PhpClawExtensions;
use PhpClaw\Symfony\Http\StreamEventBridge;
use PhpClaw\Symfony\Memory\CacheMemory;
use PhpClaw\Symfony\Memory\DoctrineConversationMemory;
use PhpClaw\Symfony\Memory\DoctrineMemory;
use PhpClaw\Symfony\Memory\DoctrineRouterMemory;
use PhpClaw\Symfony\Support\ArgumentFreeConstructor;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Symfony bundle entry point for phpClaw.
 */
final class PhpClawBundle extends AbstractBundle
{
    private const DEFAULT_PRIORITY = 10;

    private const OWNERSHIP_AWARE_DRIVERS = ['doctrine', 'doctrine_conversation'];

    private static bool $eventBridgeRegistered = false;

    private static ?ContainerInterface $bootedContainer = null;

    protected string $extensionAlias = 'phpclaw';

    /**
     * Registers a compiler pass that removes Doctrine memory services when the DBAL service is not registered.
     *
     * @param  ContainerBuilder  $container
     * @return void
     */
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new RemoveDoctrineServicesPass);
    }

    /**
     * Declares the bundle's configuration tree.
     *
     * @param  DefinitionConfigurator  $definition
     * @return void
     */
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
            ->scalarNode('api_key')->defaultValue('')->end()
            ->scalarNode('provider')->defaultValue('')->end()
            ->scalarNode('model')->defaultValue('')->end()
            ->booleanNode('store_messages')->defaultTrue()->end()
            ->integerNode('max_iterations')->defaultValue(ClawConfig::DEFAULT_MAX_ITERATIONS)->end()
            ->scalarNode('memory_driver')
            ->defaultValue('doctrine')
            ->end()
            ->scalarNode('base_url')->defaultValue('')->end()
            ->booleanNode('require_chat_role')->defaultFalse()->end()
            ->arrayNode('worker_commands')
            ->scalarPrototype()->end()
            ->defaultValue([])
            ->end()
            ->scalarNode('workspace_root')
            ->defaultValue('%kernel.project_dir%/var/phpclaw')
            ->end()
            ->arrayNode('shell_allowlist')
            ->scalarPrototype()->end()
            ->defaultValue(ToolConfig::DEFAULT_SHELL_ALLOWLIST)
            ->end()
            ->arrayNode('tools')
            ->scalarPrototype()->end()
            ->defaultValue([])
            ->end()
            ->arrayNode('tool_deny')
            ->scalarPrototype()->end()
            ->defaultValue([])
            ->end()
            ->scalarNode('system_prompt')->defaultValue('')->end()
            ->integerNode('max_tokens')->defaultValue(0)->end()
            ->booleanNode('prompt_cache')->defaultFalse()->end()
            ->integerNode('thinking_budget')->defaultValue(0)->end()
            ->booleanNode('event_bridge')->defaultTrue()->end()
            ->scalarNode('cloud_key')->defaultValue('')->end()
            ->scalarNode('cloud_signing_secret')->defaultValue('')->end()
            ->arrayNode('cloud_disable')->scalarPrototype()->end()->defaultValue([])->end()
            ->arrayNode('guards')
            ->arrayPrototype()
            ->children()
            ->scalarNode('class')->isRequired()->end()
            ->integerNode('priority')->defaultValue(self::DEFAULT_PRIORITY)->end()
            ->end()
            ->end()
            ->defaultValue([])
            ->end()
            ->arrayNode('hooks')
            ->arrayPrototype()
            ->children()
            ->scalarNode('event')->isRequired()->end()
            ->variableNode('handler')->isRequired()->end()
            ->integerNode('priority')->defaultValue(self::DEFAULT_PRIORITY)->end()
            ->end()
            ->end()
            ->defaultValue([])
            ->end()
            ->arrayNode('skills')
            ->variablePrototype()->end()
            ->defaultValue([])
            ->end()
            ->scalarNode('remote_skill_urls')->defaultValue('')->end()
            ->arrayNode('api')
            ->addDefaultsIfNotSet()
            ->children()
            ->booleanNode('enabled')->defaultTrue()->end()
            ->end()
            ->end()
            ->end();
    }

    /**
     * Imports services.yaml and publishes every config node as a container parameter.
     *
     * @param  array<string, mixed>  $config
     * @param  ContainerConfigurator  $configurator
     * @param  ContainerBuilder  $container
     * @return void
     */
    public function loadExtension(array $config, ContainerConfigurator $configurator, ContainerBuilder $container): void
    {
        $configurator->import($this->getPath().'/config/services.yaml');

        $container->setParameter('phpclaw.api_key', $config['api_key']);
        $container->setParameter('phpclaw.provider', $config['provider']);
        $container->setParameter('phpclaw.model', $config['model']);
        $container->setParameter('phpclaw.store_messages', $config['store_messages']);
        $container->setParameter('phpclaw.max_iterations', $config['max_iterations']);
        $container->setParameter('phpclaw.memory_driver', $config['memory_driver']);
        $container->setParameter('phpclaw.base_url', $config['base_url']);
        $container->setParameter('phpclaw.require_chat_role', $config['require_chat_role']);
        $container->setParameter('phpclaw.worker_commands', $config['worker_commands']);
        $container->setParameter('phpclaw.workspace_root', $config['workspace_root']);
        $container->setParameter('phpclaw.shell_allowlist', $config['shell_allowlist']);
        $container->setParameter('phpclaw.guards', $config['guards']);
        $container->setParameter('phpclaw.hooks', $config['hooks']);
        $container->setParameter('phpclaw.tools', $config['tools']);
        $container->setParameter('phpclaw.tool_deny', $config['tool_deny']);
        $container->setParameter('phpclaw.system_prompt', $config['system_prompt']);
        $container->setParameter('phpclaw.max_tokens', $config['max_tokens']);
        $container->setParameter('phpclaw.prompt_cache', $config['prompt_cache']);
        $container->setParameter('phpclaw.thinking_budget', $config['thinking_budget']);
        $container->setParameter('phpclaw.event_bridge', $config['event_bridge']);
        $container->setParameter('phpclaw.cloud_key', $config['cloud_key']);
        $container->setParameter('phpclaw.cloud_signing_secret', $config['cloud_signing_secret']);
        $container->setParameter('phpclaw.cloud_disable', $config['cloud_disable']);
        $container->setParameter('phpclaw.skills', $config['skills']);
        $container->setParameter('phpclaw.remote_skill_urls', $config['remote_skill_urls']);
        $container->setParameter('phpclaw.api.enabled', $config['api']['enabled']);
    }

    /**
     * Register all six catalogues and wire runtime registries after container compilation.
     *
     * @return void
     */
    public function boot(): void
    {
        parent::boot();

        Bootstrap::boot();

        $extensions = $this->container->get(PhpClawExtensions::class);
        $this->dispatchBootingEvent($extensions);

        GuardRegistry::registerDefaults();
        $this->assertOwnershipEnforceable();
        $this->bootMemoryRegistry();
        $this->bootGuardRegistry();
        $this->bootHookRegistry();
        $this->bootSkillRegistry();
        $this->bootExtensions($extensions);
        $this->bootStreamBridge();
        $this->bootEventBridge();

        SkillCatalogue::activateDefaults();

        Bootstrap::activateFromSettings([]);

        if ((bool) $this->container->getParameter('phpclaw.store_messages')) {
            CloudManager::boot(
                (string) $this->container->getParameter('phpclaw.cloud_key'),
                (array) $this->container->getParameter('phpclaw.cloud_disable'),
                (string) $this->container->getParameter('phpclaw.cloud_signing_secret'),
            );
        }

        self::$bootedContainer = $this->container;
    }

    /**
     * Container from the most recently completed boot, for the standalone phpclaw() helper; null when no bundle has booted in this process.
     *
     * @return ContainerInterface|null
     */
    public static function bootedContainer(): ?ContainerInterface
    {
        return self::$bootedContainer;
    }

    /**
     * Clears the recorded booted container, so tests can assert the no-container fallback in isolation.
     *
     * @return void
     */
    public static function resetBootedContainer(): void
    {
        self::$bootedContainer = null;
    }

    /**
     * Registers the bundle templates/ path under the @PhpClaw Twig namespace.
     *
     * @param  ContainerConfigurator  $configurator
     * @param  ContainerBuilder  $container
     * @return void
     */
    public function prependExtension(ContainerConfigurator $configurator, ContainerBuilder $container): void
    {
        if ($container->hasExtension('twig')) {
            $configurator->extension('twig', [
                'paths' => [
                    $this->getPath().'/templates' => 'PhpClaw',
                ],
            ]);
        }
    }

    /**
     * Resets the event-bridge registration flag so the bridge can be re-registered in tests.
     *
     * @return void
     */
    public static function resetEventBridge(): void
    {
        self::$eventBridgeRegistered = false;
    }

    /**
     * Refuse to boot the REST API on a memory driver that cannot enforce conversation ownership.
     *
     * @return void
     *
     * @throws PhpClawException When the REST API is enabled with a non-Doctrine memory driver.
     */
    private function assertOwnershipEnforceable(): void
    {
        if (! (bool) $this->container->getParameter('phpclaw.api.enabled')) {
            return;
        }

        $driver = (string) $this->container->getParameter('phpclaw.memory_driver');

        if (in_array($driver, self::OWNERSHIP_AWARE_DRIVERS, true)) {
            return;
        }

        throw new PhpClawException(
            "The REST API is enabled with memory_driver '{$driver}', which cannot enforce conversation ownership. Set memory_driver to 'doctrine' or 'doctrine_conversation', or set api.enabled to false.",
        );
    }

    /**
     * Registers Doctrine and Cache memory drivers into MemoryRegistry.
     *
     * @return void
     */
    private function bootMemoryRegistry(): void
    {
        if ($this->container->has(DoctrineMemory::class)) {
            MemoryRegistry::register('doctrine', function (): DoctrineRouterMemory {
                $kv = $this->container->get(DoctrineMemory::class);
                $conv = $this->container->get(DoctrineConversationMemory::class);

                return new DoctrineRouterMemory($conv, $kv);
            });

            MemoryRegistry::register('doctrine_kv', fn (): DoctrineMemory => $this->container->get(DoctrineMemory::class));

            MemoryRegistry::register('doctrine_conversation', fn (): DoctrineConversationMemory => $this->container->get(DoctrineConversationMemory::class));
        }

        if ($this->container->has(CacheInterface::class)) {
            MemoryRegistry::register('cache', fn (): CacheMemory => new CacheMemory(
                $this->container->get(CacheInterface::class),
            ));
        }

        $driver = (string) $this->container->getParameter('phpclaw.memory_driver');

        if (! MemoryRegistry::has($driver)) {
            return;
        }

        $this->container->set(
            MemoryInterface::class,
            MemoryRegistry::build($driver),
        );
    }

    /**
     * Registers guards from config into GuardRegistry in declared priority order.
     *
     * @return void
     */
    private function bootGuardRegistry(): void
    {
        $guards = (array) $this->container->getParameter('phpclaw.guards');

        foreach ($guards as $entry) {
            if (! is_array($entry) || ! isset($entry['class']) || ! is_string($entry['class'])) {
                continue;
            }

            if (! class_exists($entry['class'])) {
                continue;
            }

            $priority = (int) ($entry['priority'] ?? self::DEFAULT_PRIORITY);

            $instance = $this->container->has($entry['class'])
                ? $this->container->get($entry['class'])
                : new $entry['class'];

            GuardRegistry::register($instance, $priority, replace: true);
        }
    }

    /**
     * Registers hooks from config into HookRegistry.
     *
     * @return void
     */
    private function bootHookRegistry(): void
    {
        $hooks = (array) $this->container->getParameter('phpclaw.hooks');

        foreach ($hooks as $entry) {
            if (! is_array($entry) || ! isset($entry['event'], $entry['handler'])) {
                continue;
            }

            if (! is_string($entry['event']) || $entry['event'] === '') {
                continue;
            }

            $priority = (int) ($entry['priority'] ?? self::DEFAULT_PRIORITY);
            HookRegistry::on($entry['event'], $entry['handler'], $priority);
        }
    }

    /**
     * Resolves and registers skills from config into SkillRegistry.
     *
     * @return void
     */
    private function bootSkillRegistry(): void
    {
        $skills = (array) $this->container->getParameter('phpclaw.skills');

        foreach ($this->resolveSkills($skills) as $skill) {
            SkillRegistry::register($skill);
        }
    }

    /**
     * Resolve config skill entries (inline / file / class) into SkillInterface instances.
     *
     * @param  array<int, mixed>  $entries
     * @return SkillInterface[]
     */
    private function resolveSkills(array $entries): array
    {
        $resolved = [];
        $delegated = [];

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            if (! isset($entry['class']) || ! is_string($entry['class'])) {
                $delegated[] = $entry;

                continue;
            }

            $instance = $this->container->has($entry['class'])
                ? $this->container->get($entry['class'])
                : ArgumentFreeConstructor::make($entry['class']);

            if ($instance instanceof SkillInterface) {
                $resolved[] = $instance;
            }
        }

        return array_merge($resolved, SkillResolver::resolve($delegated));
    }

    /**
     * Registers StreamEventBridge once at boot for the tool.before and tool.after events.
     *
     * @return void
     */
    private function bootStreamBridge(): void
    {
        $bridge = $this->container->get(StreamEventBridge::class);

        HookRegistry::on(LifecycleEvent::ToolBefore->value, $bridge);
        HookRegistry::on(LifecycleEvent::ToolAfter->value, $bridge);
    }

    /**
     * Wires the core canonical HookEventBridge into Symfony's EventDispatcher when event_bridge is enabled.
     *
     * @return void
     */
    private function bootEventBridge(): void
    {
        if (! (bool) $this->container->getParameter('phpclaw.event_bridge')) {
            return;
        }

        if (self::$eventBridgeRegistered) {
            return;
        }

        $dispatcher = $this->eventDispatcher();
        if ($dispatcher === null) {
            return;
        }

        (new HookEventBridge(
            static function (string $event, array $ctx) use ($dispatcher): void {
                $dispatcher->dispatch(new PhpClawEvent($event, $ctx), 'phpclaw.'.$event);
            },
        ))->register();

        self::$eventBridgeRegistered = true;
    }

    /**
     * Dispatch the phpclaw.booting event so tagged listeners can append to the extensions bag.
     *
     * @param  PhpClawExtensions  $extensions  Mutable extensions bag passed to listeners.
     * @return void
     */
    private function dispatchBootingEvent(PhpClawExtensions $extensions): void
    {
        $dispatcher = $this->eventDispatcher();
        if ($dispatcher === null) {
            return;
        }

        $dispatcher->dispatch($extensions, 'phpclaw.booting');
    }

    /**
     * Resolve the framework event dispatcher, preferring the always-public 'event_dispatcher' service.
     *
     * @return ?EventDispatcherInterface
     */
    private function eventDispatcher(): ?EventDispatcherInterface
    {
        foreach (['event_dispatcher', EventDispatcherInterface::class] as $id) {
            if ($this->container->has($id)) {
                $dispatcher = $this->container->get($id);
                if ($dispatcher instanceof EventDispatcherInterface) {
                    return $dispatcher;
                }
            }
        }

        return null;
    }

    /**
     * Wire all six extension buckets from the bag into their respective registries.
     *
     * @param  PhpClawExtensions  $extensions  Bag populated by phpclaw.booting listeners.
     * @return void
     */
    private function bootExtensions(PhpClawExtensions $extensions): void
    {
        foreach ($extensions->guards as $guard) {
            GuardRegistry::register($guard, replace: true);
        }

        foreach ($extensions->hooks as $hook) {
            if (! isset($hook['event'], $hook['handler'])) {
                continue;
            }
            HookRegistry::on($hook['event'], $hook['handler'], (int) ($hook['priority'] ?? self::DEFAULT_PRIORITY));
        }

        foreach ($extensions->skills as $skill) {
            SkillRegistry::register($skill);
        }

        foreach ($extensions->memory as $slug => $factory) {
            MemoryRegistry::register($slug, $factory);
        }

        foreach ($extensions->providers as $slug => $entry) {
            $class = $entry['class'] ?? '';
            if (! is_string($class) || ! class_exists($class)) {
                continue;
            }
            try {
                ProviderRegistry::register($slug, $class);
            } catch (\Throwable) {
            }
        }
    }
}
