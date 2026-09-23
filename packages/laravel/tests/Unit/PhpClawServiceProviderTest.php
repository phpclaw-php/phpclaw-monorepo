<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Tests\Unit;

use Illuminate\Contracts\Console\Kernel;
use Orchestra\Testbench\TestCase;
use PhpClaw\Contracts\ClawInterface as PhpClawInterface;
use PhpClaw\Laravel\PhpClawServiceProvider;
use PhpClaw\Laravel\QueueManager;
use PhpClaw\Skills\SkillRegistry;
use PhpClaw\Tools\ShellTool;
use PhpClaw\Tools\ToolRegistry;

final class PhpClawServiceProviderTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PhpClawServiceProvider::class];
    }

    protected function setUp(): void
    {
        SkillRegistry::reset();
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        SkillRegistry::reset();
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('phpclaw.api_key', 'test-api-key-for-unit-tests');
        $app['config']->set('phpclaw.memory_driver', 'array');
    }

    public function test_phpclaw_is_bound_in_container(): void
    {
        $instance = $this->app->make(PhpClawInterface::class);

        $this->assertInstanceOf(PhpClawInterface::class, $instance);
    }

    public function test_phpclaw_is_registered_as_singleton(): void
    {
        $first = $this->app->make(PhpClawInterface::class);
        $second = $this->app->make(PhpClawInterface::class);

        $this->assertSame($first, $second);
    }

    public function test_config_is_merged(): void
    {
        $this->assertNotNull(config('phpclaw'));
        $this->assertTrue(config('phpclaw.store_messages'));
    }

    public function test_cloud_signing_secret_config_defaults_to_empty(): void
    {
        $config = require __DIR__.'/../../config/phpclaw.php';

        $this->assertArrayHasKey('cloud_signing_secret', $config);
        $this->assertSame('', (string) $config['cloud_signing_secret']);
    }

    public function test_store_messages_is_true_by_default(): void
    {
        $config = require __DIR__.'/../../config/phpclaw.php';

        $this->assertTrue((bool) $config['store_messages']);
    }

    public function test_config_is_published_with_correct_tag(): void
    {
        $publishes = PhpClawServiceProvider::pathsToPublish(
            PhpClawServiceProvider::class,
            'phpclaw-config',
        );

        $this->assertNotEmpty($publishes, 'phpclaw-config publish group should not be empty');

        $destinations = array_values($publishes);
        $hasConfigFile = false;
        foreach ($destinations as $dest) {
            if (str_contains($dest, 'phpclaw.php')) {
                $hasConfigFile = true;
                break;
            }
        }

        $this->assertTrue($hasConfigFile, 'Expected phpclaw.php in published config paths');
    }

    public function test_migrations_are_published_with_correct_tag(): void
    {
        $publishes = PhpClawServiceProvider::pathsToPublish(
            PhpClawServiceProvider::class,
            'phpclaw-migrations',
        );

        $this->assertCount(1, $publishes);
        $this->assertStringEndsWith('database/migrations', (string) array_key_first($publishes));
        $this->assertStringContainsString('migrations', (string) reset($publishes));
    }

    public function test_artisan_commands_are_registered(): void
    {
        $kernel = $this->app->make(Kernel::class);
        $commands = $kernel->all();

        $this->assertArrayHasKey('phpclaw', $commands);
    }

    public function test_max_iterations_defaults_to_20(): void
    {
        $config = require __DIR__.'/../../config/phpclaw.php';

        $this->assertSame(20, (int) $config['max_iterations']);
    }

    public function test_memory_driver_defaults_to_database(): void
    {
        $config = require __DIR__.'/../../config/phpclaw.php';

        $this->assertSame('database', $config['memory_driver']);
    }

    public function test_queue_manager_is_bound_as_singleton(): void
    {
        $first = $this->app->make(QueueManager::class);
        $second = $this->app->make(QueueManager::class);

        $this->assertInstanceOf(QueueManager::class, $first);
        $this->assertSame($first, $second);
    }

    public function test_tool_registry_is_bound_as_singleton(): void
    {
        $first = $this->app->make(ToolRegistry::class);
        $second = $this->app->make(ToolRegistry::class);

        $this->assertInstanceOf(ToolRegistry::class, $first);
        $this->assertSame($first, $second);
    }

    public function test_tool_registry_is_populated_from_config_tools(): void
    {
        $this->app['config']->set('phpclaw.tools', [ShellTool::class]);
        $this->app->forgetInstance(ToolRegistry::class);

        $registry = $this->app->make(ToolRegistry::class);

        $this->assertTrue(
            $registry->has('shell_exec'),
            'ToolRegistry resolved from the container should expose tools listed in config(phpclaw.tools)',
        );
    }

    public function test_oss8_config_keys_have_documented_defaults(): void
    {
        $config = require __DIR__.'/../../config/phpclaw.php';

        $this->assertSame('', $config['system_prompt']);
        $this->assertSame(0, $config['max_tokens']);
        $this->assertFalse($config['prompt_cache']);
        $this->assertSame(0, $config['thinking_budget']);
    }

    public function test_phpclaw_resolves_when_all_oss8_keys_set_to_non_defaults(): void
    {
        $this->app['config']->set('phpclaw.system_prompt', 'You are a Laravel engineer.');
        $this->app['config']->set('phpclaw.max_tokens', 512);
        $this->app['config']->set('phpclaw.prompt_cache', true);
        $this->app['config']->set('phpclaw.thinking_budget', 2048);

        $this->app->forgetInstance(PhpClawInterface::class);

        $config = $this->app->make(PhpClawInterface::class)->config();

        $this->assertStringEndsWith(
            'You are a Laravel engineer.',
            $config->systemPrompt,
            'the configured prompt is appended to the core ReAct preamble',
        );
        $this->assertSame(512, $config->maxTokens);
        $this->assertTrue($config->promptCache);
        $this->assertSame(2048, $config->thinkingBudget);
    }

    public function test_console_registers_exactly_the_seven_shipped_commands(): void
    {
        $commands = $this->app[Kernel::class]->all();

        $ours = array_values(array_filter(
            array_keys($commands),
            static fn (string $name): bool => str_starts_with($name, 'phpclaw'),
        ));
        sort($ours);

        self::assertSame([
            'phpclaw',
            'phpclaw:about',
            'phpclaw:guide',
            'phpclaw:jobs:list',
            'phpclaw:jobs:status',
            'phpclaw:mcp-server',
            'phpclaw:stats',
        ], $ours);
    }
}
