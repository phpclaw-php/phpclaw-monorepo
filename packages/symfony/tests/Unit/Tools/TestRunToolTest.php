<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Tests\Unit\Tools;

use PhpClaw\Symfony\Console\ConsoleContext;
use PhpClaw\Symfony\Tools\TestRunTool;
use PhpClaw\Tools\ToolMutability;
use PHPUnit\Framework\TestCase;

final class TestRunToolTest extends TestCase
{
    private string $project = '';

    private array $calls = [];

    protected function setUp(): void
    {
        $this->calls = [];
        $this->project = sys_get_temp_dir().'/phpclaw-sf-tests-'.uniqid();
        mkdir($this->project.'/vendor/bin', 0777, true);
        touch($this->project.'/vendor/bin/phpunit');
    }

    protected function tearDown(): void
    {
        @unlink($this->project.'/vendor/bin/phpunit');
        @rmdir($this->project.'/vendor/bin');
        @rmdir($this->project.'/vendor');
        @rmdir($this->project);
    }

    private function tool(int $exitCode = 0, string $output = 'OK (3 tests, 5 assertions)', string $environment = 'dev', bool $console = true, string $project = ''): TestRunTool
    {
        $context = new ConsoleContext;
        if ($console) {
            $context->markConsole();
        }

        return new TestRunTool(
            projectRoot: $project !== '' ? $project : $this->project,
            environment: $environment,
            console: $context,
            runner: function (array $command, string $cwd) use ($exitCode, $output): array {
                $this->calls[] = ['command' => $command, 'cwd' => $cwd];

                return [$exitCode, $output];
            },
        );
    }

    private function execute(TestRunTool $tool, array $input): array
    {
        return json_decode($tool->execute($input), associative: true);
    }

    public function test_test_run_runs_only_the_fixed_test_command(): void
    {
        $result = $this->execute($this->tool(), ['filter' => 'App\\Tests\\KernelTest::test_boot']);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['data']['passed']);
        $this->assertSame([[
            'command' => [PHP_BINARY, $this->project.'/vendor/bin/phpunit', '--filter=App\\Tests\\KernelTest::test_boot'],
            'cwd' => $this->project,
        ]], $this->calls);
    }

    public function test_test_run_without_a_filter_runs_the_whole_suite(): void
    {
        $this->execute($this->tool(), []);

        $this->assertSame([PHP_BINARY, $this->project.'/vendor/bin/phpunit'], $this->calls[0]['command']);
    }

    public function test_test_run_reports_failing_tests(): void
    {
        $result = $this->execute($this->tool(1, 'FAILURES! Tests: 3, Failures: 1.'), []);

        $this->assertFalse($result['data']['passed']);
        $this->assertSame(1, $result['data']['exit_code']);
    }

    public function test_test_run_keeps_only_the_end_of_a_long_output(): void
    {
        $result = $this->execute($this->tool(0, str_repeat('x', 20000).'FINAL LINE'), []);

        $this->assertLessThanOrEqual(8192, strlen($result['data']['output']));
        $this->assertStringEndsWith('FINAL LINE', $result['data']['output']);
    }

    public function test_test_run_rejects_a_filter_with_shell_characters(): void
    {
        foreach (['KernelTest; id', '$(id)', 'Kernel Test', 'a|b', "a\nb", '--group=x'] as $filter) {
            $result = $this->execute($this->tool(), ['filter' => $filter]);

            $this->assertSame('INVALID_ARGUMENT', $result['error']['code'], $filter);
        }

        $this->assertSame([], $this->calls);
    }

    public function test_test_run_rejects_an_unknown_argument(): void
    {
        $result = $this->execute($this->tool(), ['command' => 'id']);

        $this->assertFalse($result['success']);
        $this->assertSame([], $this->calls);
    }

    public function test_test_run_needs_approval_every_time(): void
    {
        $this->assertTrue(ToolMutability::isApprovalRequired($this->tool(), []));
        $this->assertTrue(ToolMutability::isApprovalRequired($this->tool(), ['filter' => 'KernelTest']));
    }

    public function test_test_run_is_refused_in_production(): void
    {
        $result = $this->execute($this->tool(environment: 'prod'), []);

        $this->assertSame('PRODUCTION', $result['error']['code']);
        $this->assertSame([], $this->calls);
    }

    public function test_test_run_is_refused_outside_the_console(): void
    {
        $result = $this->execute($this->tool(console: false), []);

        $this->assertSame('CONSOLE_ONLY', $result['error']['code']);
        $this->assertSame([], $this->calls);
    }

    public function test_test_run_says_when_phpunit_is_not_installed(): void
    {
        $empty = sys_get_temp_dir().'/phpclaw-sf-empty-'.uniqid();
        mkdir($empty);

        $result = $this->execute($this->tool(project: $empty), []);
        rmdir($empty);

        $this->assertSame('NOT_INSTALLED', $result['error']['code']);
        $this->assertSame([], $this->calls);
    }

    public function test_test_run_describes_itself_to_the_model(): void
    {
        $tool = new TestRunTool;

        $this->assertSame('test_run', $tool->name());
        $this->assertStringContainsString('vendor/bin/phpunit', $tool->description());
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
