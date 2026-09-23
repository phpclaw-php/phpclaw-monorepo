<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Agent;

use PhpClaw\Agent\CliApprovalGate;
use PhpClaw\Agent\Contracts\ApprovalGateInterface;
use PhpClaw\Exceptions\HumanDeniedException;
use PhpClaw\Tools\Contracts\MutatingToolInterface;
use PhpClaw\Tools\Contracts\ToolInterface;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class CliApprovalGateTest extends TestCase
{
    private CliApprovalGate $gate;

    protected function setUp(): void
    {
        $this->gate = new CliApprovalGate;
    }

    public function test_gate_implements_contract(): void
    {
        $this->assertInstanceOf(ApprovalGateInterface::class, $this->gate);
    }

    public function test_read_only_tools_and_unknown_tools_pass_without_prompt(): void
    {
        $this->gate->check('file_read', ['path' => 'x'], $this->readTool('file_read'));
        $this->gate->check('project_info', [], $this->readTool('project_info'));
        $this->gate->check('anything', [], null);

        $this->addToAssertions();
    }

    public function test_mutating_tool_denies_when_non_interactive(): void
    {
        $this->expectException(HumanDeniedException::class);
        $this->gate->check('file_write', ['path' => 'a.txt', 'content' => 'x'], $this->mutatingTool('file_write'));
    }

    public function test_gating_is_by_capability_not_name(): void
    {
        $this->gate->check('file_write', [], $this->readTool('file_write'));

        $denied = false;
        try {
            $this->gate->check('totally_custom', [], $this->mutatingTool('totally_custom'));
        } catch (HumanDeniedException) {
            $denied = true;
        }

        $this->assertTrue($denied, 'any MutatingToolInterface tool must be gated regardless of name');
    }

    private function mutatingTool(string $name): ToolInterface
    {
        return new class($name) implements MutatingToolInterface, ToolInterface
        {
            public function __construct(private string $n) {}

            public function name(): string
            {
                return $this->n;
            }

            public function description(): string
            {
                return $this->n;
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function execute(array $input): string
            {
                return '';
            }
        };
    }

    private function readTool(string $name): ToolInterface
    {
        return new class($name) implements ToolInterface
        {
            public function __construct(private string $n) {}

            public function name(): string
            {
                return $this->n;
            }

            public function description(): string
            {
                return $this->n;
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function execute(array $input): string
            {
                return '';
            }
        };
    }

    private function addToAssertions(): void
    {
        $this->assertTrue(true);
    }

    private function describe(string $tool, array $input): string
    {
        return (string) (new ReflectionMethod($this->gate, 'describe'))->invoke($this->gate, $tool, $input);
    }

    public function test_describe_file_write_shows_path_and_byte_count(): void
    {
        $out = $this->describe('file_write', ['path' => 'a/b.txt', 'content' => 'hello']);

        self::assertStringContainsString('a/b.txt', $out);
        self::assertStringContainsString('5 bytes', $out);
    }

    public function test_describe_file_edit_shows_old_and_new(): void
    {
        $out = $this->describe('file_edit', ['file' => 'x.php', 'old_str' => 'FOO', 'new_str' => 'BAR']);

        self::assertStringContainsString('x.php', $out);
        self::assertStringContainsString('FOO', $out);
        self::assertStringContainsString('BAR', $out);
    }

    public function test_describe_shell_exec_shows_command(): void
    {
        self::assertStringContainsString('ls -la', $this->describe('shell_exec', ['command' => 'ls -la']));
    }

    public function test_describe_zip_package_shows_source_and_output(): void
    {
        $out = $this->describe('zip_package', ['source_dir' => 'plugin', 'output_name' => 'myplugin']);

        self::assertStringContainsString('plugin', $out);
        self::assertStringContainsString('myplugin.zip', $out);
    }

    public function test_describe_unknown_tool_falls_back_to_json(): void
    {
        self::assertStringContainsString('foo', $this->describe('mystery', ['foo' => 'bar']));
    }
}
