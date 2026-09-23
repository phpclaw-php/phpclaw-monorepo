<?php

declare(strict_types=1);

namespace PhpClaw\Drupal;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelInterface;
use PhpClaw\AutoDiscovery\Bootstrap;
use PhpClaw\AutoDiscovery\ComposerExtras;
use PhpClaw\Drupal\EventBridge\PhpClawEvent;
use PhpClaw\Drupal\Memory\CacheMemory;
use PhpClaw\Drupal\Memory\DrupalDbConversationMemory;
use PhpClaw\Drupal\Memory\DrupalDbMemory;
use PhpClaw\Drupal\Memory\DrupalDbRouterMemory;
use PhpClaw\Drupal\Memory\FileRouterMemory;
use PhpClaw\Guards\Contracts\GuardInterface;
use PhpClaw\Guards\GuardRegistry;
use PhpClaw\Hooks\HookEventBridge;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Memory\Contracts\MemoryInterface;
use PhpClaw\Memory\MemoryRegistry;
use PhpClaw\Providers\Contracts\ProviderInterface;
use PhpClaw\Providers\OpenAIPresets;
use PhpClaw\Providers\ProviderCatalogue;
use PhpClaw\Providers\ProviderRegistry;
use PhpClaw\Skills\Contracts\SkillInterface;
use PhpClaw\Skills\SkillRegistry;
use PhpClaw\Skills\SkillResolver;
use PhpClaw\Tools\Contracts\ConfigurableToolInterface;
use PhpClaw\Tools\Contracts\ToolInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Wires phpClaw's static registries from Drupal's config and DI container.
 */
final class PhpClawRegistrar
{
    private array $externalTools = [];

    private array $externalGuards = [];

    private array $externalSkills = [];

    private array $externalMemoryDrivers = [];

    private array $externalHooks = [];

    private bool $booted = false;

    /**
     * Create the registrar and immediately boot all registries.
     *
     * @param  ConfigFactoryInterface  $configFactory  Drupal config factory
     * @param  Connection  $database  Drupal database connection
     * @param  CacheBackendInterface  $cacheBackend  Default Drupal cache backend
     * @param  TimeInterface  $time  Drupal time service
     * @param  ContainerInterface  $container  Drupal service container for runtime service resolution
     * @param  EventDispatcherInterface|null  $eventDispatcher  Event dispatcher (nullable)
     * @param  LoggerChannelInterface|null  $logger  phpClaw logger channel (nullable for test compatibility)
     * @return void
     */
    public function __construct(
        private readonly ConfigFactoryInterface $configFactory,
        private readonly Connection $database,
        private readonly CacheBackendInterface $cacheBackend,
        private readonly TimeInterface $time,
        private readonly ContainerInterface $container,
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
        private readonly ?LoggerChannelInterface $logger = null,
    ) {}

    /**
     * Return external tools collected from phpclaw.tool-tagged services.
     *
     * @return ToolInterface[]
     */
    public function getExternalTools(): array
    {
        return $this->externalTools;
    }

    /**
     * Module-tagged guards captured during boot (for the Guide listing).
     *
     * @return GuardInterface[]
     */
    public function getExternalGuards(): array
    {
        return $this->externalGuards;
    }

    /**
     * Module-tagged skills captured during boot.
     *
     * @return SkillInterface[]
     */
    public function getExternalSkills(): array
    {
        return $this->externalSkills;
    }

    /**
     * Module-tagged memory drivers captured during boot, as name => class.
     *
     * @return array<string, string>
     */
    public function getExternalMemoryDrivers(): array
    {
        return $this->externalMemoryDrivers;
    }

    /**
     * Module-tagged hook listeners captured during boot.
     *
     * @return list<array{event: string, priority: int, class: string}>
     */
    public function getExternalHooks(): array
    {
        return $this->externalHooks;
    }

    /**
     * Boot all phpClaw registries in the required order.
     *
     * @return void
     */
    public function boot(): void
    {
        if ($this->booted) {
            return;
        }
        $this->booted = true;

        $config = $this->configFactory->get('phpclaw.settings');

        $this->bootMemoryRegistry();

        Bootstrap::boot();

        $this->bootProviderRegistry();

        $this->bootGuardRegistry($config);
        $this->bootHookRegistry($config);
        $this->bootSkillRegistry($config);
        $this->bootEventBridge();
        $this->bootExternalTools();
        $this->bootExtrasTools($config);
        $this->bootExternalProviders();
        $this->bootExternalMemoryDrivers();
        $this->bootExternalGuards();
        $this->bootExternalSkills();
        $this->bootExternalHookListeners();

        Bootstrap::activateFromSettings(
            (array) $config->get(),
        );
    }

    /**
     * Register memory drivers (database, file, cache) with MemoryRegistry.
     *
     * @return void
     */
    private function bootMemoryRegistry(): void
    {
        $db = $this->database;

        MemoryRegistry::register('database', fn () => new DrupalDbRouterMemory(
            DrupalDbConversationMemory::createScoped(
                $db,
                $this->configFactory,
                $this->logger,
            ),
            new DrupalDbMemory($db, $this->time, $this->logger),
        ));

        MemoryRegistry::register('file', fn () => new FileRouterMemory);

        MemoryRegistry::register('cache', fn () => new CacheMemory(
            $this->cacheBackend,
            $this->time,
        ));
    }

    /**
     * Register extra providers from the ProviderCatalogue if available.
     *
     * @return void
     */
    private function bootProviderRegistry(): void
    {
        if (! class_exists(ProviderCatalogue::class)) {
            return;
        }

        $presets = class_exists(OpenAIPresets::class)
            ? array_keys(OpenAIPresets::all())
            : [];
        $natives = class_exists(Bootstrap::class)
            ? array_keys(Bootstrap::providers())
            : [];
        $skip = array_flip(array_merge($presets, $natives));

        foreach (ProviderCatalogue::all() as $key => $info) {
            if (isset($skip[$key])) {
                continue;
            }
            if (class_exists($info['class']) && ! ProviderRegistry::has($key)) {
                ProviderRegistry::register($key, $info['class']);
            }
        }
    }

    /**
     * Register default guards and any custom guards from Drupal config.
     *
     * @param  ImmutableConfig  $config  Resolved phpclaw.settings config.
     * @return void
     */
    private function bootGuardRegistry(ImmutableConfig $config): void
    {
        GuardRegistry::registerDefaults();
        $guards = (array) ($config->get('guards') ?? []);

        foreach ($guards as $entry) {
            if (! is_array($entry) || ! isset($entry['class']) || ! is_string($entry['class'])) {
                continue;
            }

            if (! class_exists($entry['class'])) {
                continue;
            }

            if (! is_a($entry['class'], GuardInterface::class, true)) {
                continue;
            }

            try {
                $ref = new \ReflectionClass($entry['class']);
                $ctor = $ref->getConstructor();
                if ($ctor !== null && $ctor->getNumberOfRequiredParameters() > 0) {
                    continue;
                }
                $guard = new $entry['class'];
            } catch (\Throwable $e) {
                $this->logger?->warning('Custom guard @class failed to construct: @exception', [
                    '@class' => $entry['class'],
                    '@exception' => $e::class,
                ]);

                continue;
            }

            $priority = (int) ($entry['priority'] ?? 10);
            GuardRegistry::register($guard, $priority, replace: true);
        }
    }

    /**
     * Register skills from Drupal config into SkillRegistry.
     *
     * @param  ImmutableConfig  $config  Resolved phpclaw.settings config.
     * @return void
     */
    private function bootSkillRegistry(ImmutableConfig $config): void
    {
        $skills = SkillResolver::resolve((array) ($config->get('skills') ?? []));

        foreach ($skills as $skill) {
            SkillRegistry::register($skill);
        }
    }

    /**
     * Wire the event dispatcher to phpClaw hooks via HookEventBridge.
     *
     * @return void
     */
    private function bootEventBridge(): void
    {
        if ($this->eventDispatcher !== null) {
            $dispatcher = $this->eventDispatcher;
            (new HookEventBridge(
                static function (string $event, array $ctx) use ($dispatcher): void {
                    $dispatcher->dispatch(new PhpClawEvent($ctx), 'phpclaw.'.$event);
                },
            ))->register();
        }
    }

    /**
     * Register custom hooks from Drupal config into HookRegistry.
     *
     * @param  ImmutableConfig  $config  Resolved phpclaw.settings config.
     * @return void
     */
    private function bootHookRegistry(ImmutableConfig $config): void
    {
        $hooks = (array) ($config->get('hooks') ?? []);

        foreach ($hooks as $entry) {
            if (! is_array($entry) || ! isset($entry['event'], $entry['handler'])) {
                continue;
            }

            if (! is_string($entry['event']) || $entry['event'] === '') {
                continue;
            }

            if (! is_callable($entry['handler'])) {
                continue;
            }

            $priority = (int) ($entry['priority'] ?? 10);
            HookRegistry::on($entry['event'], $entry['handler'], $priority);
        }
    }

    /**
     * Register external tools tagged phpclaw.tool.
     *
     * @return void
     */
    private function bootExternalTools(): void
    {
        $this->bootTaggedServices(
            tagSuffix: 'phpclaw_tool',
            interface: ToolInterface::class,
            tagLabel: 'phpclaw.tool',
            onValid: function (object $service): void {
                $this->externalTools[] = $service;
            },
        );
    }

    /**
     * Append tools declared in installed phpClaw Composer extras packages to the external tool list.
     *
     * @param  ImmutableConfig  $config  Resolved phpclaw.settings config.
     * @return void
     */
    private function bootExtrasTools(ImmutableConfig $config): void
    {
        $saved = (array) $config->get();

        foreach (ComposerExtras::tools() as $info) {
            $class = $info['class'] ?? null;
            if (! is_string($class) || ! class_exists($class)) {
                continue;
            }

            if (! self::isZeroArgConstructible($class)) {
                continue;
            }

            $tool = new $class;
            if (! $tool instanceof ToolInterface) {
                continue;
            }

            if ($tool instanceof ConfigurableToolInterface) {
                try {
                    $tool->configure($saved);
                } catch (\Throwable) {
                    continue;
                }
                if (! $tool->isConfigured()) {
                    continue;
                }
            }

            $this->externalTools[] = $tool;
        }
    }

    /**
     * Discover providers tagged with phpclaw.provider and register them.
     *
     * @return void
     */
    private function bootExternalProviders(): void
    {
        $this->bootTaggedServices(
            tagSuffix: 'phpclaw_provider',
            interface: ProviderInterface::class,
            tagLabel: 'phpclaw.provider',
            onValid: static function (object $service): void {
                if (! ProviderRegistry::has($service->name())) {
                    ProviderRegistry::register($service->name(), static fn () => $service);
                }
            },
        );
    }

    /**
     * Discover memory drivers tagged with phpclaw.memory_driver and register them.
     *
     * @return void
     */
    private function bootExternalMemoryDrivers(): void
    {
        $this->bootTaggedServices(
            tagSuffix: 'phpclaw_memory_driver',
            interface: MemoryInterface::class,
            tagLabel: 'phpclaw.memory_driver',
            onValid: function (object $service): void {
                $shortName = (new \ReflectionClass($service))->getShortName();
                $name = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $shortName));
                $name = str_replace('_memory', '', $name);
                $this->externalMemoryDrivers[$name] = $service::class;
                if (! MemoryRegistry::has($name)) {
                    MemoryRegistry::register($name, static fn () => $service);
                }
            },
        );
    }

    /**
     * Discover guards tagged with phpclaw.guard and register them.
     *
     * @return void
     */
    private function bootExternalGuards(): void
    {
        $this->bootTaggedServices(
            tagSuffix: 'phpclaw_guard',
            interface: GuardInterface::class,
            tagLabel: 'phpclaw.guard',
            onValid: function (object $service): void {
                $this->externalGuards[] = $service;
                GuardRegistry::register($service, GuardRegistry::DEFAULT_PRIORITY, replace: true);
            },
        );
    }

    /**
     * Discover skills tagged with phpclaw.skill and register them.
     *
     * @return void
     */
    private function bootExternalSkills(): void
    {
        $this->bootTaggedServices(
            tagSuffix: 'phpclaw_skill',
            interface: SkillInterface::class,
            tagLabel: 'phpclaw.skill',
            onValid: function (object $service): void {
                $this->externalSkills[] = $service;
                SkillRegistry::register($service);
            },
        );
    }

    /**
     * Discover hook listeners tagged with phpclaw.hook_listener and register them.
     *
     * @return void
     */
    private function bootExternalHookListeners(): void
    {
        $entries = $this->container->hasParameter('phpclaw.tagged_services.hook_listeners')
            ? (array) $this->container->getParameter('phpclaw.tagged_services.hook_listeners')
            : [];

        foreach ($entries as $entry) {
            if (! is_array($entry) || ! isset($entry['service_id'], $entry['event'])) {
                continue;
            }

            $serviceId = (string) $entry['service_id'];
            $event = (string) $entry['event'];
            $priority = (int) ($entry['priority'] ?? HookRegistry::DEFAULT_PRIORITY);

            try {
                $listener = $this->container->get($serviceId);
                if (! method_exists($listener, 'handle')) {
                    $this->logger?->warning(
                        'Service @id tagged phpclaw.hook_listener has no handle() method.',
                        ['@id' => $serviceId]
                    );

                    continue;
                }
                HookRegistry::on($event, [$listener, 'handle'], $priority);
                $this->externalHooks[] = ['event' => $event, 'priority' => $priority, 'class' => $listener::class];
            } catch (\Throwable $e) {
                $this->logger?->error(
                    'Failed to boot phpclaw.hook_listener @id: @class',
                    ['@id' => $serviceId, '@class' => $e::class]
                );
            }
        }
    }

    /**
     * Resolve each tagged service ID, validate the interface, and invoke the callback on every valid service.
     *
     * @param  string  $tagSuffix  Underscored tag suffix, e.g. 'phpclaw_tool'.
     * @param  string  $interface  Fully-qualified interface class-string to check.
     * @param  string  $tagLabel  Human-readable tag name used in log messages.
     * @param  callable  $onValid  Called with the resolved service when valid.
     * @return void
     */
    private function bootTaggedServices(
        string $tagSuffix,
        string $interface,
        string $tagLabel,
        callable $onValid,
    ): void {
        foreach ($this->getTaggedIds($tagSuffix) as $serviceId) {
            try {
                $service = $this->container->get($serviceId);
                if (! $service instanceof $interface) {
                    $this->logger?->warning(
                        'Service @id tagged @tag does not implement the required interface.',
                        ['@id' => $serviceId, '@tag' => $tagLabel]
                    );

                    continue;
                }
                $onValid($service);
            } catch (\Throwable $e) {
                $this->logger?->error(
                    'Failed to boot @tag service @id: @class',
                    ['@tag' => $tagLabel, '@id' => $serviceId, '@class' => $e::class]
                );
            }
        }
    }

    /**
     * Return the list of service IDs for a pre-compiled tag parameter.
     *
     * @param  string  $tagSuffix  Underscored tag suffix, e.g. 'phpclaw_tool'.
     * @return string[]
     */
    private function getTaggedIds(string $tagSuffix): array
    {
        $param = 'phpclaw.tagged_services.'.$tagSuffix;

        return $this->container->hasParameter($param)
            ? (array) $this->container->getParameter($param)
            : [];
    }

    /**
     * Check whether a class can be safely instantiated with no constructor arguments.
     *
     * @param  class-string  $class  Fully-qualified class name to inspect.
     * @return bool True when the class has no constructor or one with no required parameters.
     */
    private static function isZeroArgConstructible(string $class): bool
    {
        $ctor = (new \ReflectionClass($class))->getConstructor();

        return $ctor === null || $ctor->getNumberOfRequiredParameters() === 0;
    }
}
