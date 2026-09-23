<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Hooks;

use PhpClaw\Hooks\Contracts\HookInterface;
use PhpClaw\Hooks\DebugLogHook;
use PhpClaw\Hooks\HookRegistry;
use PHPUnit\Framework\TestCase;

final class DebugLogHookTest extends TestCase
{
    private string $logFile;

    protected function setUp(): void
    {
        HookRegistry::reset();
        $this->logFile = sys_get_temp_dir().DIRECTORY_SEPARATOR.'phpclaw_hook_test_'.uniqid().'.log';
    }

    protected function tearDown(): void
    {
        HookRegistry::reset();
        if (file_exists($this->logFile)) {
            unlink($this->logFile);
        }
    }

    private function readLastLine(): array
    {
        $this->assertFileExists($this->logFile);
        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($this->logFile))));
        $this->assertNotEmpty($lines, 'No lines written to log file');

        $decoded = json_decode(end($lines), associative: true);
        $this->assertIsArray($decoded, 'Log line is not valid JSON');

        return [(string) $decoded['event'], (array) ($decoded['context'] ?? [])];
    }

    public function test_implements_hook_interface(): void
    {
        $this->assertInstanceOf(HookInterface::class, new DebugLogHook($this->logFile));
    }

    public function test_accessors_return_constructor_values(): void
    {
        $hook = new DebugLogHook($this->logFile, 'custom.event');

        $this->assertSame($this->logFile, $hook->logFile());
        $this->assertSame('custom.event', $hook->eventName());
    }

    public function test_handle_writes_json_line_with_event_label_and_context(): void
    {
        $hook = new DebugLogHook($this->logFile, 'agent.after');
        $hook->handle(['iterations' => 3, 'provider' => 'anthropic']);

        [$event, $ctx] = $this->readLastLine();

        $this->assertSame('agent.after', $event);
        $this->assertSame(3, $ctx['iterations']);
        $this->assertSame('anthropic', $ctx['provider']);
    }

    public function test_handle_writes_iso8601_timestamp(): void
    {
        $hook = new DebugLogHook($this->logFile);
        $hook->handle([]);

        $raw = (string) file_get_contents($this->logFile);
        $decoded = json_decode(trim($raw), associative: true);

        $this->assertIsArray($decoded);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+\-]\d{2}:\d{2}$/',
            (string) $decoded['ts'],
        );
    }

    public function test_handle_appends_instead_of_overwriting(): void
    {
        $hook = new DebugLogHook($this->logFile, 'e');

        $hook->handle(['n' => 1]);
        $hook->handle(['n' => 2]);
        $hook->handle(['n' => 3]);

        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($this->logFile))));

        $this->assertCount(3, $lines);
    }

    public function test_handle_encodes_empty_context_as_object(): void
    {
        $hook = new DebugLogHook($this->logFile);
        $hook->handle([]);

        $raw = (string) file_get_contents($this->logFile);
        $this->assertStringContainsString('"context":[]', $raw);
    }

    public function test_handle_never_throws_on_unwritable_path(): void
    {
        $hook = new DebugLogHook('/nonexistent/dir/that/cannot/be/created/log.txt');

        $hook->handle(['any' => 'context']);
        $this->addToAssertionCount(1);
    }

    public function test_handle_is_invoked_when_registered_event_fires(): void
    {
        $hook = new DebugLogHook($this->logFile, 'tool.before');
        HookRegistry::on('tool.before', $hook);

        HookRegistry::fire('tool.before', ['tool_name' => 'shell_exec']);

        [$event, $ctx] = $this->readLastLine();

        $this->assertSame('tool.before', $event);
        $this->assertSame('shell_exec', $ctx['tool_name']);
    }

    public function test_multiple_instances_can_log_to_different_files(): void
    {
        $logA = sys_get_temp_dir().DIRECTORY_SEPARATOR.'phpclaw_hook_a_'.uniqid().'.log';
        $logB = sys_get_temp_dir().DIRECTORY_SEPARATOR.'phpclaw_hook_b_'.uniqid().'.log';

        try {
            $hookA = new DebugLogHook($logA, 'agent.before');
            $hookB = new DebugLogHook($logB, 'agent.after');

            HookRegistry::on('agent.before', $hookA);
            HookRegistry::on('agent.after', $hookB);

            HookRegistry::fire('agent.before', ['message' => 'hi']);
            HookRegistry::fire('agent.after', ['text' => 'ok']);

            $this->assertFileExists($logA);
            $this->assertFileExists($logB);
            $this->assertStringContainsString('agent.before', (string) file_get_contents($logA));
            $this->assertStringContainsString('agent.after', (string) file_get_contents($logB));
            $this->assertStringNotContainsString('agent.after', (string) file_get_contents($logA));
        } finally {
            @unlink($logA);
            @unlink($logB);
        }
    }
}
