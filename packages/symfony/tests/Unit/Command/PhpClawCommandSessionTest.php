<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Tests\Unit\Command;

use PhpClaw\Agent\AgentResponse;
use PhpClaw\Agent\Conversation;
use PhpClaw\Agent\ConversationTurn;
use PhpClaw\Contracts\ClawInterface as PhpClawInterface;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Symfony\Command\PhpClawCommand;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class PhpClawCommandSessionTest extends TestCase
{
    private string $cacheDir = '';

    private string $projectDir = '';

    protected function setUp(): void
    {
        HookRegistry::reset();
        $this->cacheDir = sys_get_temp_dir().'/phpclaw-sf-cache-'.uniqid();
        $this->projectDir = sys_get_temp_dir().'/phpclaw-sf-project-'.uniqid();
        mkdir($this->cacheDir, 0777, true);
        mkdir($this->projectDir.'/var/phpclaw', 0777, true);
    }

    protected function tearDown(): void
    {
        HookRegistry::reset();
        @unlink($this->stateFile());
        @rmdir($this->cacheDir.'/phpclaw');
        @rmdir($this->cacheDir);
        @rmdir($this->projectDir.'/var/phpclaw');
        @rmdir($this->projectDir.'/var');
        @rmdir($this->projectDir);
    }

    private function stateFile(): string
    {
        return $this->cacheDir.'/phpclaw/.last-conversation';
    }

    private function tester(PhpClawInterface $claw, string $memoryDriver = 'doctrine', string $environment = 'dev', string $workspaceRoot = ''): CommandTester
    {
        $command = new PhpClawCommand(
            $claw,
            memoryDriver: $memoryDriver,
            stateDir: $this->cacheDir,
            environment: $environment,
            workspaceRoot: $workspaceRoot !== '' ? $workspaceRoot : $this->projectDir.'/var/phpclaw',
            projectDir: $this->projectDir,
        );
        $application = new Application;
        method_exists($application, 'addCommand') ? $application->addCommand($command) : $application->add($command);

        return new CommandTester($application->find('phpclaw'));
    }

    private function conversation(string $id): Conversation
    {
        return new Conversation($id, [], new \DateTimeImmutable);
    }

    private function turn(array $tools = [], ?Conversation $conversation = null): ConversationTurn
    {
        return new ConversationTurn(
            new AgentResponse(text: 'Done.', provider: 'ollama', model: 'qwen2.5:7b', iterations: 1, toolsCalled: $tools, inputTokens: 10, outputTokens: 20),
            $conversation ?? Conversation::start(),
        );
    }

    private function agent(?ConversationTurn $turn = null): PhpClawInterface&MockObject
    {
        $mock = $this->createMock(PhpClawInterface::class);
        $mock->method('conversation')->willReturn(Conversation::start());
        $mock->method('sendInConversation')->willReturn($turn ?? $this->turn());

        return $mock;
    }

    private function display(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', str_replace("\n // ", ' ', $tester->getDisplay()));
    }

    public function test_it_lists_tools_called_in_the_summary(): void
    {
        $tester = $this->tester($this->agent($this->turn(['file_read', 'code_search', 'file_read'])));

        $tester->execute(['message' => 'look around']);

        $this->assertStringContainsString('Tools: file_read, code_search', $this->display($tester));
    }

    public function test_it_omits_the_tools_segment_when_no_tool_ran(): void
    {
        $tester = $this->tester($this->agent());

        $tester->execute(['message' => 'hello']);

        $this->assertStringNotContainsString('Tools:', $this->display($tester));
    }

    public function test_the_summary_prints_the_conversation_id(): void
    {
        $tester = $this->tester($this->agent($this->turn([], $this->conversation('01SFCONVERSATION0000000000'))));

        $tester->execute(['message' => 'hello']);

        $this->assertStringContainsString('Conversation: 01SFCONVERSATION0000000000', $this->display($tester));
    }

    public function test_it_resumes_the_conversation_named_by_conv_id(): void
    {
        $stored = $this->conversation('01SFSTORED0000000000000000');
        $mock = $this->createMock(PhpClawInterface::class);
        $mock->expects($this->once())->method('conversation')->with('01SFSTORED0000000000000000')->willReturn($stored);
        $mock->expects($this->once())->method('sendInConversation')->with($stored, 'and then?')->willReturn($this->turn([], $stored));
        $tester = $this->tester($mock);

        $tester->execute(['message' => 'and then?', '--conv-id' => '01SFSTORED0000000000000000']);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringNotContainsString('No stored conversation', $this->display($tester));
    }

    public function test_an_unknown_conv_id_says_that_a_new_conversation_started(): void
    {
        $mock = $this->createMock(PhpClawInterface::class);
        $mock->method('conversation')->willReturn($this->conversation('01SFFRESH00000000000000000'));
        $mock->method('sendInConversation')->willReturn($this->turn());
        $tester = $this->tester($mock);

        $tester->execute(['message' => 'hi', '--conv-id' => '01MISSING']);

        $this->assertStringContainsString('No stored conversation 01MISSING; started 01SFFRESH00000000000000000.', $this->display($tester));
    }

    public function test_it_warns_and_starts_fresh_when_the_memory_driver_is_array(): void
    {
        $mock = $this->createMock(PhpClawInterface::class);
        $mock->expects($this->once())->method('conversation')->with('')->willReturn(Conversation::start());
        $mock->method('sendInConversation')->willReturn($this->turn());
        $tester = $this->tester($mock, memoryDriver: 'array');

        $tester->execute(['message' => 'hi', '--conv-id' => '01ANYTHING']);

        $this->assertStringContainsString('Resuming needs a stored memory driver (memory_driver is array); starting a new conversation.', $this->display($tester));
    }

    public function test_it_writes_the_conversation_id_to_the_state_file(): void
    {
        $tester = $this->tester($this->agent($this->turn([], $this->conversation('01SFWRITTEN000000000000000'))));

        $tester->execute(['message' => 'hi']);

        $this->assertSame('01SFWRITTEN000000000000000', file_get_contents($this->stateFile()));
    }

    public function test_continue_reads_the_state_file(): void
    {
        mkdir(dirname($this->stateFile()), 0777, true);
        file_put_contents($this->stateFile(), '01SFLAST000000000000000000');
        $last = $this->conversation('01SFLAST000000000000000000');
        $mock = $this->createMock(PhpClawInterface::class);
        $mock->expects($this->once())->method('conversation')->with('01SFLAST000000000000000000')->willReturn($last);
        $mock->method('sendInConversation')->willReturn($this->turn([], $last));

        $this->tester($mock)->execute(['message' => 'continue please', '--continue' => true]);
    }

    public function test_conv_id_wins_over_continue(): void
    {
        mkdir(dirname($this->stateFile()), 0777, true);
        file_put_contents($this->stateFile(), '01SFLAST000000000000000000');
        $named = $this->conversation('01SFNAMED00000000000000000');
        $mock = $this->createMock(PhpClawInterface::class);
        $mock->expects($this->once())->method('conversation')->with('01SFNAMED00000000000000000')->willReturn($named);
        $mock->method('sendInConversation')->willReturn($this->turn([], $named));

        $this->tester($mock)->execute(['message' => 'hi', '--continue' => true, '--conv-id' => '01SFNAMED00000000000000000']);
    }

    public function test_continue_falls_back_to_a_new_conversation_when_no_state_file(): void
    {
        $mock = $this->createMock(PhpClawInterface::class);
        $mock->expects($this->once())->method('conversation')->with('')->willReturn(Conversation::start());
        $mock->method('sendInConversation')->willReturn($this->turn());
        $tester = $this->tester($mock);

        $tester->execute(['message' => 'hi', '--continue' => true]);

        $this->assertStringContainsString('No previous conversation to continue; starting a new one.', $this->display($tester));
    }

    private function agentFiringTools(array $events): PhpClawInterface&MockObject
    {
        $mock = $this->createMock(PhpClawInterface::class);
        $mock->method('conversation')->willReturn(Conversation::start());
        $mock->method('sendInConversation')->willReturnCallback(function () use ($events): ConversationTurn {
            foreach ($events as [$event, $context]) {
                HookRegistry::fire($event, $context);
            }

            return $this->turn();
        });

        return $mock;
    }

    public function test_trace_prints_one_line_per_tool_call(): void
    {
        $tester = $this->tester($this->agentFiringTools([
            ['tool.before', ['tool_name' => 'file_write', 'tool_input' => ['path' => 'notes.txt', 'content' => 'hello'], 'iteration' => 1]],
            ['tool.before', ['tool_name' => 'shell_exec', 'tool_input' => ['command' => 'ls -la'], 'iteration' => 2]],
        ]));

        $tester->execute(['message' => 'go', '--trace' => true]);

        $this->assertStringContainsString('> file_write: notes.txt (5 bytes)', $this->display($tester));
        $this->assertStringContainsString('> shell_exec: ls -la', $this->display($tester));
    }

    public function test_trace_never_prints_the_tool_result(): void
    {
        $tester = $this->tester($this->agentFiringTools([
            ['tool.before', ['tool_name' => 'db_query', 'tool_input' => ['sql' => 'select 1'], 'iteration' => 1]],
            ['tool.after', ['tool_name' => 'db_query', 'tool_input' => ['sql' => 'select 1'], 'tool_result' => 'SECRET-ROW-VALUE', 'iteration' => 1]],
        ]));

        $tester->execute(['message' => 'go', '--trace' => true]);

        $this->assertStringContainsString('> db_query', $this->display($tester));
        $this->assertStringNotContainsString('SECRET-ROW-VALUE', $this->display($tester));
        $this->assertStringNotContainsString('select 1', $this->display($tester));
    }

    public function test_trace_truncates_the_input_summary(): void
    {
        $tester = $this->tester($this->agentFiringTools([
            ['tool.before', ['tool_name' => 'shell_exec', 'tool_input' => ['command' => 'echo '.str_repeat('a', 300)], 'iteration' => 1]],
        ]));

        $tester->execute(['message' => 'go', '--trace' => true]);

        $this->assertStringContainsString('> shell_exec: echo '.str_repeat('a', 75).'...', $this->display($tester));
        $this->assertStringNotContainsString(str_repeat('a', 81), $this->display($tester));
    }

    public function test_trace_is_off_without_the_flag(): void
    {
        $tester = $this->tester($this->agentFiringTools([
            ['tool.before', ['tool_name' => 'shell_exec', 'tool_input' => ['command' => 'ls'], 'iteration' => 1]],
        ]));

        $tester->execute(['message' => 'go']);

        $this->assertStringNotContainsString('> shell_exec', $this->display($tester));
    }

    public function test_the_trace_listener_is_removed_when_the_run_ends(): void
    {
        $before = HookRegistry::count('tool.before');

        $this->tester($this->agentFiringTools([]))->execute(['message' => 'go', '--trace' => true]);

        $this->assertSame($before, HookRegistry::count('tool.before'));
    }

    public function test_trace_refuses_a_project_workspace_in_production(): void
    {
        $mock = $this->createMock(PhpClawInterface::class);
        $mock->expects($this->never())->method('sendInConversation');
        $tester = $this->tester($mock, environment: 'prod', workspaceRoot: $this->projectDir);

        $tester->execute(['message' => 'go', '--trace' => true]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('Refused: the workspace is outside var/phpclaw and the kernel environment is prod.', $this->display($tester));
    }

    public function test_trace_runs_in_production_with_the_default_workspace(): void
    {
        $tester = $this->tester($this->agentFiringTools([]), environment: 'prod');

        $tester->execute(['message' => 'go', '--trace' => true]);

        $this->assertSame(0, $tester->getStatusCode());
    }
}
