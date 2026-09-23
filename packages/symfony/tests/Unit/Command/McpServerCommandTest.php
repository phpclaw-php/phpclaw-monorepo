<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Tests\Unit\Command;

use PhpClaw\Symfony\Command\McpServerCommand;
use PhpClaw\Tools\ToolRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class McpServerCommandTest extends TestCase
{
    private ToolRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new ToolRegistry;
    }

    private function tester(\Closure $runner): CommandTester
    {
        $command = new McpServerCommand($this->registry, $runner);

        $app = new Application;
        method_exists($app, 'addCommand')
            ? $app->addCommand($command)
            : $app->add($command);

        return new CommandTester($app->find('phpclaw:mcp-server'));
    }

    public function test_command_name_is_phpclaw_mcp_server(): void
    {
        $command = new McpServerCommand($this->registry, fn () => true);
        self::assertSame('phpclaw:mcp-server', $command->getName());
    }

    public function test_it_returns_success_when_runner_returns_true(): void
    {
        $tester = $this->tester(fn () => true);

        $exitCode = $tester->execute([]);

        self::assertSame(0, $exitCode);
    }

    public function test_it_returns_failure_when_runner_returns_false(): void
    {
        $tester = $this->tester(fn () => false);

        $exitCode = $tester->execute([]);

        self::assertSame(1, $exitCode);
    }

    public function test_it_passes_stdio_transport_by_default(): void
    {
        $captured = null;

        $runner = function (ToolRegistry $registry, string $transport) use (&$captured): bool {
            $captured = $transport;

            return true;
        };

        $this->tester($runner)->execute([]);

        self::assertSame('stdio', $captured);
    }

    public function test_it_passes_http_transport_when_option_set(): void
    {
        $captured = null;

        $runner = function (ToolRegistry $registry, string $transport) use (&$captured): bool {
            $captured = $transport;

            return true;
        };

        $this->tester($runner)->execute(['--transport' => 'http']);

        self::assertSame('http', $captured);
    }

    public function test_it_forwards_the_registry_to_the_runner(): void
    {
        $captured = null;

        $runner = function (ToolRegistry $registry) use (&$captured): bool {
            $captured = $registry;

            return true;
        };

        $this->tester($runner)->execute([]);

        self::assertSame($this->registry, $captured);
    }

    public function test_it_writes_error_output_when_runner_emits_error(): void
    {
        $runner = function (ToolRegistry $r, string $t, callable $emit): bool {
            $emit('MCP refused: missing token.', true);

            return false;
        };

        $tester = $this->tester($runner);
        $tester->execute([]);

        self::assertStringContainsString('MCP refused: missing token.', $tester->getDisplay());
    }

    public function test_it_writes_info_output_when_runner_emits_message(): void
    {
        $runner = function (ToolRegistry $r, string $t, callable $emit): bool {
            $emit('phpClaw MCP server started.', false);

            return true;
        };

        $tester = $this->tester($runner);
        $tester->execute([]);

        self::assertStringContainsString('phpClaw MCP server started.', $tester->getDisplay());
    }

    public function test_shortcut_t_accepted_for_transport(): void
    {
        $captured = null;

        $runner = function (ToolRegistry $r, string $transport) use (&$captured): bool {
            $captured = $transport;

            return true;
        };

        $this->tester($runner)->execute(['-t' => 'http']);

        self::assertSame('http', $captured);
    }
}
