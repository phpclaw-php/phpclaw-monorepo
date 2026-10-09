<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Tests\Unit\Tools;

use Orchestra\Testbench\TestCase;
use PhpClaw\Laravel\Engine\EngineFactory;
use PhpClaw\Laravel\PhpClawServiceProvider;
use PhpClaw\Laravel\Tools\TestRunTool;
use PhpClaw\Skills\SkillRegistry;
use PhpClaw\Tools\ToolMutability;

final class TestRunToolTest extends TestCase
{
    private array $calls = [];

    protected function getPackageProviders($app): array
    {
        return [PhpClawServiceProvider::class];
    }

    protected function setUp(): void
    {
        SkillRegistry::reset();
        parent::setUp();
        $this->calls = [];
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

    private function tool(int $exitCode = 0, string $output = 'Tests: 3 passed'): TestRunTool
    {
        return new TestRunTool(function (array $command, string $cwd) use ($exitCode, $output): array {
            $this->calls[] = ['command' => $command, 'cwd' => $cwd];

            return [$exitCode, $output];
        });
    }

    private function execute(TestRunTool $tool, array $input): array
    {
        return json_decode($tool->execute($input), associative: true);
    }

    public function test_test_run_runs_only_the_fixed_test_command(): void
    {
        $result = $this->execute($this->tool(), ['filter' => 'Tests\\Feature\\UserTest::test_login']);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['data']['passed']);
        $this->assertSame([[
            'command' => [PHP_BINARY, base_path('artisan'), 'test', '--filter=Tests\\Feature\\UserTest::test_login'],
            'cwd' => base_path(),
        ]], $this->calls);
    }

    public function test_test_run_without_a_filter_runs_the_whole_suite(): void
    {
        $this->execute($this->tool(), []);

        $this->assertSame([PHP_BINARY, base_path('artisan'), 'test'], $this->calls[0]['command']);
    }

    public function test_test_run_reports_failing_tests(): void
    {
        $result = $this->execute($this->tool(1, 'Tests: 1 failed, 2 passed'), []);

        $this->assertTrue($result['success']);
        $this->assertFalse($result['data']['passed']);
        $this->assertSame(1, $result['data']['exit_code']);
        $this->assertStringContainsString('1 failed', $result['data']['output']);
    }

    public function test_test_run_keeps_only_the_end_of_a_long_output(): void
    {
        $result = $this->execute($this->tool(0, str_repeat('x', 20000).'FINAL LINE'), []);

        $this->assertLessThanOrEqual(8192, strlen($result['data']['output']));
        $this->assertStringEndsWith('FINAL LINE', $result['data']['output']);
    }

    public function test_test_run_rejects_a_filter_with_shell_characters(): void
    {
        foreach (['UserTest; rm -rf /', '$(id)', 'User Test', 'a|b', "a\nb", '--group=x'] as $filter) {
            $result = $this->execute($this->tool(), ['filter' => $filter]);

            $this->assertFalse($result['success'], $filter);
            $this->assertSame('INVALID_ARGUMENT', $result['error']['code'], $filter);
        }

        $this->assertSame([], $this->calls);
    }

    public function test_test_run_rejects_an_unknown_argument(): void
    {
        $result = $this->execute($this->tool(), ['command' => 'php -r "echo 1;"']);

        $this->assertFalse($result['success']);
        $this->assertSame([], $this->calls);
    }

    public function test_test_run_needs_approval_every_time(): void
    {
        $tool = $this->tool();

        $this->assertTrue(ToolMutability::isApprovalRequired($tool, []));
        $this->assertTrue(ToolMutability::isApprovalRequired($tool, ['filter' => 'UserTest']));
    }

    public function test_test_run_is_refused_in_production(): void
    {
        $this->app['env'] = 'production';

        $result = $this->execute($this->tool(), []);

        $this->assertFalse($result['success']);
        $this->assertSame('PRODUCTION', $result['error']['code']);
        $this->assertSame([], $this->calls);
    }

    public function test_test_run_is_refused_outside_the_console(): void
    {
        $ref = new \ReflectionProperty($this->app, 'isRunningInConsole');
        $ref->setValue($this->app, false);

        $result = $this->execute($this->tool(), []);

        $this->assertFalse($result['success']);
        $this->assertSame('CONSOLE_ONLY', $result['error']['code']);
        $this->assertSame([], $this->calls);
    }

    public function test_test_run_is_off_until_listed_in_the_tools_config(): void
    {
        $names = static fn (array $tools): array => array_map(static fn ($tool): string => $tool->name(), $tools);

        $this->assertNotContains('test_run', $names(EngineFactory::resolveTools($this->app)));

        config(['phpclaw.tools' => [TestRunTool::class]]);

        $this->assertContains('test_run', $names(EngineFactory::resolveTools($this->app)));
    }

    public function test_test_run_describes_itself_to_the_model(): void
    {
        $tool = new TestRunTool;

        $this->assertSame('test_run', $tool->name());
        $this->assertStringContainsString('php artisan test', $tool->description());
        $this->assertSame(['filter'], array_keys($tool->inputSchema()['properties']));
        $this->assertContains('testing', $tool->routingMetadata()->domains);
    }

    public function test_the_default_runner_passes_arguments_without_a_shell(): void
    {
        $runner = (new \ReflectionProperty(TestRunTool::class, 'runner'))->getValue(new TestRunTool);

        [$exitCode, $output] = $runner([PHP_BINARY, '-r', 'echo $argv[1];', 'a;b $(id) | c'], sys_get_temp_dir());

        $this->assertSame(0, $exitCode);
        $this->assertSame('a;b $(id) | c', $output);
    }
}
