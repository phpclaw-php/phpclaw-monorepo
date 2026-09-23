<?php

declare(strict_types=1);

namespace PhpClaw\Laravel;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Console\AboutCommand as LaravelAboutCommand;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Laravel\Telescope\Telescope;
use PhpClaw\AutoDiscovery\Bootstrap;
use PhpClaw\Claw as PhpClaw;
use PhpClaw\Cloud\CloudManager;
use PhpClaw\Contracts\ClawInterface as PhpClawInterface;
use PhpClaw\Guards\Contracts\GuardInterface;
use PhpClaw\Guards\GuardRegistry;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Hooks\LifecycleEvent;
use PhpClaw\Laravel\Console\AboutCommand;
use PhpClaw\Laravel\Console\GuideCommand;
use PhpClaw\Laravel\Console\JobsListCommand;
use PhpClaw\Laravel\Console\JobsStatusCommand;
use PhpClaw\Laravel\Console\McpServerCommand;
use PhpClaw\Laravel\Console\PhpClawCommand;
use PhpClaw\Laravel\Console\StatsCommand;
use PhpClaw\Laravel\Engine\EngineFactory;
use PhpClaw\Laravel\Events\HookEventBridge;
use PhpClaw\Laravel\Extension\PhpClawExtensions;
use PhpClaw\Laravel\Http\Middleware\AuthenticatePhpClawApi;
use PhpClaw\Laravel\Http\Middleware\EnforceConversationOwnership;
use PhpClaw\Laravel\Http\Middleware\ForceJsonResponse;
use PhpClaw\Laravel\Http\StreamEventBridge;
use PhpClaw\Laravel\Memory\CacheMemory;
use PhpClaw\Laravel\Memory\DatabaseConversationMemory;
use PhpClaw\Laravel\Memory\DatabaseMemory;
use PhpClaw\Laravel\Memory\DatabaseRouterMemory;
use PhpClaw\Laravel\Telescope\PhpClawWatcher;
use PhpClaw\Memory\Contracts\MemoryInterface;
use PhpClaw\Memory\FileMemory;
use PhpClaw\Memory\MemoryRegistry;
use PhpClaw\Providers\ProviderRegistry;
use PhpClaw\Skills\SkillCatalogue;
use PhpClaw\Skills\SkillRegistry;
use PhpClaw\Skills\SkillResolver;
use PhpClaw\Tools\ToolRegistry;

/**
 * Laravel Service Provider for phpClaw.
 */
final class PhpClawServiceProvider extends ServiceProvider
{
    private static bool $eventBridgeRegistered = false;

    /**
     * Reset the event-bridge registration flag so the bridge can be re-registered in tests.
     *
     * @return void
     */
    public static function resetEventBridge(): void
    {
        self::$eventBridgeRegistered = false;
    }

    /**
     * Register package bindings into the service container.
     *
     * @return void
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/phpclaw.php', 'phpclaw');

        $this->app->singleton(MemoryInterface::class, function (): MemoryInterface {
            $driver = (string) config('phpclaw.memory_driver', 'eloquent');

            if ($driver === 'file') {
                return new FileMemory(storage_path('phpclaw/memory'));
            }

            return MemoryRegistry::build($driver);
        });

        $this->app->singleton(PhpClawInterface::class, fn (): PhpClawInterface => EngineFactory::build($this->app));

        $this->app->alias(PhpClawInterface::class, PhpClaw::class);

        $this->app->singleton(ToolRegistry::class, function (): ToolRegistry {
            $registry = new ToolRegistry;
            $registry->register(
                EngineFactory::resolveTools($this->app, forceAllowPhpWrite: false),
                deny: (array) config('phpclaw.tool_deny', []),
                groups: EngineFactory::TOOL_GROUPS,
            );

            return $registry;
        });

        $this->app->singleton(QueueManager::class, function (): QueueManager {
            return new QueueManager(
                memory: $this->app->make(MemoryInterface::class),
            );
        });
    }

    /**
     * Bootstrap package services, publish assets, and register commands.
     *
     * @return void
     */
    public function boot(): void
    {
        $this->publishes(
            paths: [__DIR__.'/../config/phpclaw.php' => config_path('phpclaw.php')],
            groups: 'phpclaw-config',
        );

        $this->registerManageAllAbility();

        $router = $this->app->make('router');
        $router->aliasMiddleware('phpclaw.json', ForceJsonResponse::class);
        $router->aliasMiddleware('phpclaw.api', AuthenticatePhpClawApi::class);
        $router->aliasMiddleware('phpclaw.owns', EnforceConversationOwnership::class);

        if ((bool) config('phpclaw.api.enabled', true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
        }

        $this->publishes(
            paths: [__DIR__.'/../routes/api.php' => base_path('routes/phpclaw-api.php')],
            groups: 'phpclaw-routes',
        );

        $this->publishes(
            paths: [__DIR__.'/../database/migrations' => database_path('migrations')],
            groups: 'phpclaw-migrations',
        );

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                PhpClawCommand::class,
                McpServerCommand::class,
                StatsCommand::class,
                AboutCommand::class,
                GuideCommand::class,
                JobsListCommand::class,
                JobsStatusCommand::class,
            ]);

            $this->bootAboutCommand();
        }

        $this->bootAgentState($this->app);
        $this->bootTelescopeWatcher();
        $this->bootOctaneStateReset();
    }

    /**
     * Surface phpClaw in `php artisan about`, reporting only whether secrets are present.
     *
     * @return void
     */
    private function bootAboutCommand(): void
    {
        if (! class_exists(LaravelAboutCommand::class)) {
            return;
        }

        LaravelAboutCommand::add('phpClaw', static fn (): array => [
            'Provider' => (string) config('phpclaw.provider', '') ?: 'auto-detect',
            'Model' => (string) config('phpclaw.model', '') ?: 'provider default',
            'Memory Driver' => (string) config('phpclaw.memory_driver', 'database'),
            'API Key' => (string) config('phpclaw.api_key', '') !== '' ? 'configured' : 'not configured',
            'Guards' => (string) GuardRegistry::count(),
            'Skills' => (string) SkillRegistry::count(),
            'REST API' => (bool) config('phpclaw.api.enabled', true)
                ? 'enabled at /'.trim((string) config('phpclaw.api.prefix', 'phpclaw'), '/')
                : 'disabled',
            'Cloud' => (string) config('phpclaw.cloud_key', '') !== '' ? 'enabled' : 'disabled',
        ]);
    }

    /**
     * Reset every process-global registry so a request cannot inherit the previous request's state.
     *
     * @return void
     */
    public static function flushAgentState(): void
    {
        HookRegistry::reset();
        MemoryRegistry::reset();
        SkillRegistry::reset();
        ProviderRegistry::reset();

        self::$eventBridgeRegistered = false;
    }

    /**
     * Populate the registries and bridges that make up a booted phpClaw runtime.
     *
     * @param  Application  $app  Container the runtime is bound into.
     * @return void
     */
    private function bootAgentState(Application $app): void
    {
        Bootstrap::boot();

        $extensions = new PhpClawExtensions;
        $app->instance(PhpClawExtensions::class, $extensions);
        $app->make('events')->dispatch('phpclaw.booting', [$extensions]);

        $this->bootRegistries($app, $extensions);

        $this->bootStreamBridge();
        $this->bootEventBridge($app);
    }

    /**
     * Under Octane the provider boots once per worker, so rebuild the runtime on every request.
     *
     * @return void
     */
    private function bootOctaneStateReset(): void
    {
        if (! class_exists('Laravel\\Octane\\Events\\RequestReceived')) {
            return;
        }

        $this->app->make(Dispatcher::class)->listen(
            'Laravel\\Octane\\Events\\RequestReceived',
            static function (): void {
                self::flushAgentState();

                $app = app();
                $provider = new self($app);
                $provider->bootAgentState($app);
            },
        );
    }

    /**
     * Define the manage-all ability, denying by default so the host app opts in explicitly.
     *
     * @return void
     */
    private function registerManageAllAbility(): void
    {
        if (Gate::has(LaravelIdentityResolver::MANAGE_ALL_ABILITY)) {
            return;
        }

        Gate::define(LaravelIdentityResolver::MANAGE_ALL_ABILITY, static fn (): bool => false);
    }

    /**
     * Register the stream event bridge on the tool lifecycle events.
     *
     * @return void
     */
    private function bootStreamBridge(): void
    {
        $bridge = new StreamEventBridge;
        HookRegistry::on(LifecycleEvent::ToolBefore->value, $bridge);
        HookRegistry::on(LifecycleEvent::ToolAfter->value, $bridge);
    }

    /**
     * Bridge HookRegistry events into Laravel's dispatcher unless disabled via config.
     *
     * @param  Application  $app  Container used to resolve the event dispatcher.
     * @return void
     */
    private function bootEventBridge(Application $app): void
    {
        if (! (bool) config('phpclaw.events.bridge', true)) {
            return;
        }

        if (self::$eventBridgeRegistered) {
            return;
        }

        $bridge = new HookEventBridge(events: $app->make(Dispatcher::class));
        $bridge->register();

        self::$eventBridgeRegistered = true;
    }

    /**
     * Register the Telescope watcher when laravel/telescope is installed and enabled.
     *
     * @return void
     */
    private function bootTelescopeWatcher(): void
    {
        if (! class_exists(Telescope::class)) {
            return;
        }

        if (! (bool) config('phpclaw.telescope', true)) {
            return;
        }

        PhpClawWatcher::register($this->app->make(Dispatcher::class));
    }

    /**
     * Populate the guard, memory, hook, skill, and provider registries, then boot cloud.
     *
     * @param  Application  $app  Container used to resolve configured guard classes.
     * @param  PhpClawExtensions  $extensions  Extensions collected from the phpclaw.booting event.
     * @return void
     */
    private function bootRegistries(Application $app, PhpClawExtensions $extensions): void
    {
        GuardRegistry::registerDefaults();

        $this->registerMemoryDrivers($extensions);

        $this->registerGuards($app, $extensions);
        $this->registerHooks($extensions);
        $this->registerSkills($extensions);
        $this->registerProviders($extensions);

        SkillCatalogue::activateDefaults();

        Bootstrap::activateFromSettings([
            'guards_enabled' => [],
            'hooks_enabled' => [],
        ]);

        $this->bootCloud();
    }

    /**
     * Register the built-in database and cache memory drivers plus any from the booting event.
     *
     * @param  PhpClawExtensions  $extensions  Extensions collected from the phpclaw.booting event.
     * @return void
     */
    private function registerMemoryDrivers(PhpClawExtensions $extensions): void
    {
        $router = fn () => new DatabaseRouterMemory(
            new DatabaseConversationMemory,
            new DatabaseMemory,
        );

        MemoryRegistry::register('database', $router);
        MemoryRegistry::register('database_kv', fn () => new DatabaseMemory);
        MemoryRegistry::register('database_conversation', fn () => new DatabaseConversationMemory);

        MemoryRegistry::register('eloquent', $router);
        MemoryRegistry::register('eloquent_kv', fn () => new DatabaseMemory);
        MemoryRegistry::register('eloquent_conversation', fn () => new DatabaseConversationMemory);

        MemoryRegistry::register('cache', fn () => new CacheMemory);

        foreach ($extensions->memory as $slug => $factory) {
            MemoryRegistry::register($slug, $factory);
        }
    }

    /**
     * Boot the cloud manager from config when message storage is enabled.
     *
     * @return void
     */
    private function bootCloud(): void
    {
        if (! (bool) config('phpclaw.store_messages', true)) {
            return;
        }

        CloudManager::boot(
            (string) config('phpclaw.cloud_key', ''),
            (array) config('phpclaw.cloud_disable', []),
            (string) config('phpclaw.cloud_signing_secret', ''),
        );
    }

    /**
     * Register custom guards from config and the extensions bucket.
     *
     * @param  Application  $app  Container used to resolve configured guard classes.
     * @param  PhpClawExtensions  $extensions  Extensions collected from the phpclaw.booting event.
     * @return void
     */
    private function registerGuards(Application $app, PhpClawExtensions $extensions): void
    {
        foreach ($extensions->guards as $guard) {
            GuardRegistry::register($guard);
        }

        $guards = (array) config('phpclaw.guards', []);

        foreach ($guards as $entry) {
            if (! is_array($entry) || ! isset($entry['class']) || ! is_string($entry['class'])) {
                continue;
            }

            if (! class_exists($entry['class'])) {
                continue;
            }

            $priority = (int) ($entry['priority'] ?? 10);
            $instance = $app->make($entry['class']);

            if (! ($instance instanceof GuardInterface)) {
                continue;
            }

            GuardRegistry::register($instance, $priority);
        }
    }

    /**
     * Register custom lifecycle hooks from config and the extensions bucket.
     *
     * @param  PhpClawExtensions  $extensions  Extensions collected from the phpclaw.booting event.
     * @return void
     */
    private function registerHooks(PhpClawExtensions $extensions): void
    {
        foreach ($extensions->hooks as $hook) {
            if (! isset($hook['event'], $hook['handler'])) {
                continue;
            }
            HookRegistry::on($hook['event'], $hook['handler'], $hook['priority'] ?? 10);
        }

        $hooks = (array) config('phpclaw.hooks', []);

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
     * Register skills from config and the extensions bucket into SkillRegistry.
     *
     * @param  PhpClawExtensions  $extensions  Extensions collected from the phpclaw.booting event.
     * @return void
     */
    private function registerSkills(PhpClawExtensions $extensions): void
    {
        foreach ($extensions->skills as $skill) {
            SkillRegistry::register($skill);
        }

        foreach (SkillResolver::resolve((array) config('phpclaw.skills', [])) as $skill) {
            SkillRegistry::register($skill);
        }
    }

    /**
     * Register custom providers from the extensions bucket.
     *
     * @param  PhpClawExtensions  $extensions  Extensions collected from the phpclaw.booting event.
     * @return void
     */
    private function registerProviders(PhpClawExtensions $extensions): void
    {
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
