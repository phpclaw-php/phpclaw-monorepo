<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Tests\Unit;

use Orchestra\Testbench\TestCase;
use PhpClaw\Laravel\Engine\EngineFactory;
use PhpClaw\Laravel\PhpClawServiceProvider;
use PhpClaw\Laravel\Tools\DatabaseTool;
use PhpClaw\Laravel\Tools\LogTool;
use PhpClaw\Laravel\Tools\QueueStatusTool;
use PhpClaw\Laravel\Tools\RouteListTool;
use PhpClaw\Skills\SkillRegistry;
use PhpClaw\Tools\Contracts\ToolInterface;
use PhpClaw\Tools\HttpTool;
use PhpClaw\Tools\ShellTool;
use PhpClaw\Tools\ToolRegistry;

final class ToolDenyListTest extends TestCase
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
        $app['config']->set('phpclaw.api_key', 'test-key');
        $app['config']->set('phpclaw.memory_driver', 'array');
    }

    private function resolveEngineToolNames(): array
    {
        $method = new \ReflectionMethod(EngineFactory::class, 'resolveTools');
        $method->setAccessible(true);

        /** @var ToolInterface[] $tools */
        $tools = $method->invoke(null, $this->app);

        return array_map(static fn (ToolInterface $t): string => $t->name(), $tools);
    }

    public function test_empty_deny_passes_all_tools_through(): void
    {
        $this->app['config']->set('phpclaw.tool_deny', []);
        $this->app['config']->set('phpclaw.tools', [
            DatabaseTool::class,
            LogTool::class,
        ]);

        $names = $this->resolveEngineToolNames();

        $this->assertContains('db_query', $names);
        $this->assertContains('read_log', $names);
    }

    public function test_denying_single_tool_name_removes_it(): void
    {
        $this->app['config']->set('phpclaw.tool_deny', ['db_query']);
        $this->app['config']->set('phpclaw.tools', [
            DatabaseTool::class,
            LogTool::class,
        ]);

        $names = $this->resolveEngineToolNames();

        $this->assertNotContains('db_query', $names, 'Denied tool must be removed from the engine tool set.');
        $this->assertContains('read_log', $names, 'Non-denied tool must remain.');
    }

    public function test_denying_group_system_removes_all_its_members(): void
    {
        $this->app['config']->set('phpclaw.tool_deny', ['group:system']);
        $this->app['config']->set('phpclaw.tools', [
            DatabaseTool::class,
            LogTool::class,
            RouteListTool::class,
            QueueStatusTool::class,
        ]);

        $names = $this->resolveEngineToolNames();

        foreach (['db_query', 'read_log', 'route_list', 'queue_status'] as $denied) {
            $this->assertNotContains($denied, $names, "group:system member '{$denied}' must be removed.");
        }
    }

    public function test_denying_group_system_removes_shell_exec_and_http_request_by_their_real_names(): void
    {
        $this->app['config']->set('phpclaw.tool_deny', ['group:system']);
        $this->app['config']->set('phpclaw.tools', [
            ShellTool::class,
            HttpTool::class,
        ]);

        $names = $this->resolveEngineToolNames();

        $this->assertNotContains('shell_exec', $names, 'group:system must deny shell_exec by its real name().');
        $this->assertNotContains('http_request', $names, 'group:system must deny http_request by its real name().');
    }

    public function test_tool_registry_singleton_also_enforces_the_deny_list(): void
    {
        $this->app['config']->set('phpclaw.tool_deny', ['db_query']);
        $this->app['config']->set('phpclaw.tools', [
            DatabaseTool::class,
            LogTool::class,
        ]);

        $registry = $this->app->make(ToolRegistry::class);

        $this->assertFalse($registry->has('db_query'), 'ToolRegistry singleton must also enforce tool_deny.');
        $this->assertTrue($registry->has('read_log'));
    }

    public function test_tool_deny_config_key_defaults_to_an_empty_list(): void
    {
        $this->assertSame([], config('phpclaw.tool_deny'));
    }

    public function test_tool_deny_default_is_empty_array(): void
    {
        $deny = config('phpclaw.tool_deny');

        $this->assertSame([], $deny);
    }
}
