<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Tests\Unit;

use PhpClaw\Agent\CliApprovalGate;
use PhpClaw\Contracts\ClawInterface;
use PhpClaw\Memory\ArrayMemory;
use PhpClaw\Symfony\PhpClawFactory;
use PhpClaw\Tools\FileWriteTool;
use PHPUnit\Framework\TestCase;

final class PhpWriteApprovalGateTest extends TestCase
{
    public function test_console_context_enables_php_write_and_wires_approval_gate(): void
    {
        $engine = $this->makeEngine(console: true);

        $this->assertInstanceOf(CliApprovalGate::class, $this->approvalGate($engine));
        $this->assertTrue($this->fileWriteAllowsPhp($engine));
    }

    public function test_http_context_disables_php_write_but_still_wires_approval_gate(): void
    {
        $engine = $this->makeEngine(console: false);

        $this->assertInstanceOf(CliApprovalGate::class, $this->approvalGate($engine));
        $this->assertFalse($this->fileWriteAllowsPhp($engine));
    }

    public function test_mcp_tool_registry_forces_php_write_off_even_under_console(): void
    {
        $factory = new PhpClawFactory(
            apiKey: 'sk-test',
            provider: 'openai',
            model: '',
            storeMessages: false,
            maxIterations: 10,
            shellAllowlist: ['ls'],
            tools: [FileWriteTool::class],
            memory: new ArrayMemory,
            workspaceRoot: sys_get_temp_dir(),
            systemPrompt: '',
            maxTokens: 0,
            promptCache: false,
            thinkingBudget: 0,
            console: true,
        );

        $registry = $factory->createToolRegistry();

        $found = false;
        foreach ($registry->all() as $tool) {
            if ($tool instanceof FileWriteTool) {
                $found = true;
                $this->assertFalse((bool) $this->readProperty($tool, 'allowPhpWrite'), 'MCP tool registry must never allow PHP writes.');
            }
        }
        $this->assertTrue($found, 'FileWriteTool not present in the MCP tool registry.');
    }

    private function makeEngine(bool $console): ClawInterface
    {
        return (new PhpClawFactory(
            apiKey: 'sk-test',
            provider: 'openai',
            model: '',
            storeMessages: false,
            maxIterations: 10,
            shellAllowlist: ['ls'],
            tools: [FileWriteTool::class],
            memory: new ArrayMemory,
            workspaceRoot: sys_get_temp_dir(),
            systemPrompt: '',
            maxTokens: 0,
            promptCache: false,
            thinkingBudget: 0,
            console: $console,
        ))->create();
    }

    private function approvalGate(ClawInterface $engine): ?object
    {
        return $this->readProperty($this->readProperty($engine, 'config'), 'approvalGate');
    }

    private function fileWriteAllowsPhp(ClawInterface $engine): bool
    {
        $config = $this->readProperty($engine, 'config');

        foreach ((array) $this->readProperty($config, 'tools') as $tool) {
            if ($tool instanceof FileWriteTool) {
                return (bool) $this->readProperty($tool, 'allowPhpWrite');
            }
        }

        $this->fail('FileWriteTool not present in the built engine.');
    }

    private function readProperty(object $object, string $name): mixed
    {
        return (new \ReflectionProperty($object, $name))->getValue($object);
    }
}
