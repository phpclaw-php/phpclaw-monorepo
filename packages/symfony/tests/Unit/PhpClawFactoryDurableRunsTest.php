<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Tests\Unit;

use PhpClaw\Agent\CliApprovalGate;
use PhpClaw\Agent\SuspendableApprovalGate;
use PhpClaw\Claw;
use PhpClaw\Exceptions\ToolException;
use PhpClaw\Memory\ArrayMemory;
use PhpClaw\Symfony\PhpClawFactory;
use PhpClaw\Tools\FileWriteTool;
use PHPUnit\Framework\TestCase;

final class PhpClawFactoryDurableRunsTest extends TestCase
{
    private function factory(bool $durableRuns, bool $storeMessages = true, bool $console = false, string $workspace = ''): PhpClawFactory
    {
        return new PhpClawFactory(
            apiKey: 'sk-test',
            provider: 'openai',
            model: '',
            storeMessages: $storeMessages,
            maxIterations: 10,
            shellAllowlist: ['ls'],
            tools: [FileWriteTool::class],
            memory: new ArrayMemory,
            workspaceRoot: $workspace === '' ? sys_get_temp_dir() : $workspace,
            systemPrompt: '',
            maxTokens: 0,
            promptCache: false,
            thinkingBudget: 0,
            console: $console,
            durableRuns: $durableRuns,
            durableStepBudget: 3,
            durableDeadlineSeconds: 40,
        );
    }

    private function config(Claw $claw): object
    {
        return (new \ReflectionProperty($claw, 'config'))->getValue($claw);
    }

    public function test_off_keeps_the_terminal_gate_and_no_durable_runs(): void
    {
        $config = $this->config($this->factory(durableRuns: false)->create());

        self::assertInstanceOf(CliApprovalGate::class, $config->approvalGate);
        self::assertNull($config->durableRuns);
    }

    public function test_on_without_store_messages_stays_off_and_logs_a_warning(): void
    {
        $log = tempnam(sys_get_temp_dir(), 'phpclaw-log');
        $previous = ini_set('error_log', (string) $log);

        try {
            $config = $this->config($this->factory(durableRuns: true, storeMessages: false)->create());
        } finally {
            ini_set('error_log', (string) $previous);
        }

        self::assertInstanceOf(CliApprovalGate::class, $config->approvalGate);
        self::assertNull($config->durableRuns);
        self::assertStringContainsString('durable_runs needs store_messages', (string) file_get_contents((string) $log));
        unlink((string) $log);
    }

    public function test_on_in_a_web_request_pauses_for_approval_with_the_configured_budget(): void
    {
        $config = $this->config($this->factory(durableRuns: true)->create());

        self::assertInstanceOf(SuspendableApprovalGate::class, $config->approvalGate);
        self::assertSame(3, $config->durableRuns->steps);
        self::assertSame(40, $config->durableRuns->deadlineSeconds);
    }

    public function test_on_in_an_interactive_console_keeps_the_terminal_prompt_but_runs_durably(): void
    {
        $config = $this->config($this->factory(durableRuns: true, console: true)->create());

        self::assertInstanceOf(CliApprovalGate::class, $config->approvalGate);
        self::assertNotNull($config->durableRuns);
    }

    public function test_the_durable_engine_pauses_even_in_a_console(): void
    {
        $config = $this->config($this->factory(durableRuns: true, console: true)->createDurable());

        self::assertInstanceOf(SuspendableApprovalGate::class, $config->approvalGate);
    }

    public function test_the_terminal_engine_prompts_even_outside_a_marked_console(): void
    {
        $config = $this->config($this->factory(durableRuns: true)->createForTerminal());

        self::assertInstanceOf(CliApprovalGate::class, $config->approvalGate);
        self::assertSame(3, $config->durableRuns->steps);
    }

    private function writePhpFileWith(PhpClawFactory $factory, string $workspace): bool
    {
        foreach ($this->config($factory->createForTerminal())->tools as $tool) {
            if ($tool instanceof FileWriteTool) {
                $tool->execute(['path' => 'terminal.php', 'content' => 'x']);
            }
        }

        return is_file($workspace.'/terminal.php');
    }

    public function test_the_terminal_engine_may_write_php_files_with_durable_runs_on_or_off(): void
    {
        foreach ([true, false] as $durableRuns) {
            $workspace = sys_get_temp_dir().'/phpclaw-terminal-'.uniqid();
            mkdir($workspace);

            self::assertTrue($this->writePhpFileWith($this->factory(durableRuns: $durableRuns, workspace: $workspace), $workspace));
        }
    }

    public function test_the_web_engine_still_refuses_php_files(): void
    {
        $workspace = sys_get_temp_dir().'/phpclaw-web-'.uniqid();
        mkdir($workspace);
        $this->expectException(ToolException::class);

        foreach ($this->config($this->factory(durableRuns: false, workspace: $workspace)->create())->tools as $tool) {
            if ($tool instanceof FileWriteTool) {
                $tool->execute(['path' => 'web.php', 'content' => 'x']);
            }
        }
    }
}
