<?php

declare(strict_types=1);

namespace PhpClaw\Cloud\Tests\Unit;

use PhpClaw\Cloud\CloudPayloadBuilder;
use PhpClaw\Cloud\CloudWebhookHook;
use PhpClaw\Hooks\Contracts\HookInterface;
use PhpClaw\Hooks\HookRegistry;
use PHPUnit\Framework\TestCase;

final class CloudWebhookHookTest extends TestCase
{
    protected function setUp(): void
    {
        HookRegistry::reset();
    }

    protected function tearDown(): void
    {
        HookRegistry::reset();
    }

    public function test_implements_hook_interface(): void
    {
        $this->assertInstanceOf(HookInterface::class, new CloudWebhookHook('key', 'agent.before'));
    }

    public function test_handle_does_not_throw_when_api_is_unreachable(): void
    {
        $this->expectNotToPerformAssertions();

        $hook = new CloudWebhookHook('test-key', 'agent.before');
        $hook->handle(['streaming' => false]);
    }

    public function test_handle_does_not_throw_for_any_event(): void
    {
        $this->expectNotToPerformAssertions();

        $events = [
            'agent.before', 'agent.iteration', 'agent.after', 'agent.max_iterations',
            'tool.before', 'tool.after', 'tool.error',
            'memory.read', 'memory.write', 'memory.forget',
            'guard.blocked', 'provider.request', 'provider.response',
        ];

        foreach ($events as $event) {
            $hook = new CloudWebhookHook('test-key', $event);
            $hook->handle([]);
        }
    }

    public function test_handle_does_not_throw_with_empty_context(): void
    {
        $this->expectNotToPerformAssertions();

        $hook = new CloudWebhookHook('test-key', 'tool.before');
        $hook->handle([]);
    }

    private function buildPayload(string $event, array $context): array
    {
        return (new CloudPayloadBuilder)->build($event, $context);
    }

    public function test_payload_always_includes_event_and_ts(): void
    {
        $payload = $this->buildPayload('agent.before', ['streaming' => false]);

        $this->assertArrayHasKey('event', $payload);
        $this->assertArrayHasKey('ts', $payload);
        $this->assertSame('agent.before', $payload['event']);
    }

    public function test_payload_ts_is_iso8601(): void
    {
        $payload = $this->buildPayload('agent.before', []);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+\-]\d{2}:\d{2}$/',
            (string) $payload['ts'],
        );
    }

    public function test_agent_before_includes_has_conversation_and_streaming(): void
    {
        $payload = $this->buildPayload('agent.before', [
            'conversation_id' => 'abc123',
            'streaming' => true,
        ]);

        $this->assertTrue($payload['has_conversation']);
        $this->assertTrue($payload['streaming']);
    }

    public function test_agent_before_has_conversation_false_when_id_empty(): void
    {
        $payload = $this->buildPayload('agent.before', ['conversation_id' => '']);
        $this->assertFalse($payload['has_conversation']);
    }

    public function test_agent_before_has_conversation_false_when_id_missing(): void
    {
        $payload = $this->buildPayload('agent.before', []);
        $this->assertFalse($payload['has_conversation']);
    }

    public function test_agent_before_streaming_defaults_to_false(): void
    {
        $payload = $this->buildPayload('agent.before', []);
        $this->assertFalse($payload['streaming']);
    }

    public function test_agent_iteration_includes_iteration(): void
    {
        $payload = $this->buildPayload('agent.iteration', ['iteration' => 3]);
        $this->assertSame(3, $payload['iteration']);
    }

    public function test_agent_iteration_returns_null_when_iteration_missing(): void
    {
        $payload = $this->buildPayload('agent.iteration', []);
        $this->assertNull($payload['iteration']);
    }

    public function test_agent_after_includes_provider_model_iterations(): void
    {
        $payload = $this->buildPayload('agent.after', [
            'provider' => 'anthropic',
            'model' => 'claude-haiku-4-5-20251001',
            'iterations' => 5,
            'duration_ms' => 2500,
            'tools_called' => 2,
        ]);

        $this->assertSame('anthropic', $payload['provider']);
        $this->assertSame('claude-haiku-4-5-20251001', $payload['model']);
        $this->assertSame(5, $payload['iterations']);
        $this->assertSame(2500, $payload['duration_ms']);
        $this->assertSame(2, $payload['tools_called']);
    }

    public function test_agent_max_iterations_uses_same_payload_as_agent_after(): void
    {
        $after = $this->buildPayload('agent.after', ['provider' => 'openai', 'model' => 'gpt-4o-mini', 'iterations' => 10]);
        $maxIter = $this->buildPayload('agent.max_iterations', ['provider' => 'openai', 'model' => 'gpt-4o-mini', 'iterations' => 10]);

        $this->assertArrayHasKey('provider', $maxIter);
        $this->assertArrayHasKey('iterations', $maxIter);
        $this->assertSame($after['provider'], $maxIter['provider']);
        $this->assertSame($after['iterations'], $maxIter['iterations']);
    }

    public function test_tool_before_includes_tool_name(): void
    {
        $payload = $this->buildPayload('tool.before', ['tool_name' => 'shell_exec']);
        $this->assertSame('shell_exec', $payload['tool_name']);
    }

    public function test_tool_before_returns_null_tool_name_when_missing(): void
    {
        $payload = $this->buildPayload('tool.before', []);
        $this->assertNull($payload['tool_name']);
    }

    public function test_tool_after_includes_tool_name_and_duration(): void
    {
        $payload = $this->buildPayload('tool.after', ['tool_name' => 'http_request', 'duration_ms' => 150]);

        $this->assertSame('http_request', $payload['tool_name']);
        $this->assertSame(150, $payload['duration_ms']);
    }

    public function test_tool_error_includes_tool_name_and_error(): void
    {
        $payload = $this->buildPayload('tool.error', ['tool_name' => 'file_read', 'error' => 'Permission denied']);

        $this->assertSame('file_read', $payload['tool_name']);
        $this->assertSame('Permission denied', $payload['error']);
    }

    public function test_memory_read_includes_namespace_and_key(): void
    {
        $payload = $this->buildPayload('memory.read', ['namespace' => 'default', 'key' => 'last_run']);

        $this->assertSame('default', $payload['namespace']);
        $this->assertSame('last_run', $payload['key']);
    }

    public function test_memory_write_uses_same_payload_as_memory_read(): void
    {
        $payload = $this->buildPayload('memory.write', ['namespace' => 'ns', 'key' => 'k']);

        $this->assertArrayHasKey('namespace', $payload);
        $this->assertArrayHasKey('key', $payload);
    }

    public function test_memory_forget_uses_same_payload_as_memory_read(): void
    {
        $payload = $this->buildPayload('memory.forget', ['namespace' => 'ns', 'key' => 'k']);

        $this->assertArrayHasKey('namespace', $payload);
        $this->assertArrayHasKey('key', $payload);
    }

    public function test_guard_blocked_includes_reason(): void
    {
        $payload = $this->buildPayload('guard.blocked', ['reason' => 'Injection detected']);
        $this->assertSame('Injection detected', $payload['reason']);
    }

    public function test_guard_blocked_returns_null_reason_when_missing(): void
    {
        $payload = $this->buildPayload('guard.blocked', []);
        $this->assertNull($payload['reason']);
    }

    public function test_provider_request_includes_provider_and_model(): void
    {
        $payload = $this->buildPayload('provider.request', ['provider' => 'groq', 'model' => 'llama-3.1-8b-instant']);

        $this->assertSame('groq', $payload['provider']);
        $this->assertSame('llama-3.1-8b-instant', $payload['model']);
    }

    public function test_provider_response_includes_token_counts_and_duration(): void
    {
        $payload = $this->buildPayload('provider.response', [
            'provider' => 'anthropic',
            'model' => 'claude-haiku-4-5-20251001',
            'input_tokens' => 100,
            'output_tokens' => 50,
            'duration_ms' => 800,
        ]);

        $this->assertSame(100, $payload['input_tokens']);
        $this->assertSame(50, $payload['output_tokens']);
        $this->assertSame(800, $payload['duration_ms']);
    }

    public function test_unknown_event_falls_through_to_generic_payload(): void
    {
        $payload = $this->buildPayload('custom.event', [
            'run_id' => 'run-1',
            'provider' => 'openai',
            'model' => 'gpt-4o',
        ]);

        $this->assertSame('custom.event', $payload['event']);
        $this->assertArrayHasKey('payload', $payload);
        $this->assertSame('run-1', $payload['run_id']);
        $this->assertSame('openai', $payload['payload']['provider']);
        $this->assertSame('gpt-4o', $payload['payload']['model']);
    }

    public function test_str_helper_returns_string_or_null(): void
    {
        $method = new \ReflectionMethod(CloudPayloadBuilder::class, 'str');

        $this->assertSame('hello', $method->invoke(null, ['k' => 'hello'], 'k'));
        $this->assertNull($method->invoke(null, [], 'k'));
    }

    public function test_int_helper_returns_int_or_null(): void
    {
        $method = new \ReflectionMethod(CloudPayloadBuilder::class, 'int');

        $this->assertSame(42, $method->invoke(null, ['n' => 42], 'n'));
        $this->assertNull($method->invoke(null, [], 'n'));
    }

    public function test_bool_helper_returns_bool_with_default_false(): void
    {
        $method = new \ReflectionMethod(CloudPayloadBuilder::class, 'bool');

        $this->assertTrue($method->invoke(null, ['flag' => true], 'flag'));
        $this->assertFalse($method->invoke(null, [], 'flag'));
        $this->assertTrue($method->invoke(null, [], 'flag', true));
    }

    public function test_hook_is_invokable_via_registry(): void
    {
        $this->expectNotToPerformAssertions();

        $hook = new CloudWebhookHook('test-key', 'tool.before');
        HookRegistry::on('tool.before', $hook);
        HookRegistry::fire('tool.before', ['tool_name' => 'shell_exec']);
    }

    public function test_multiple_hooks_for_different_events_can_coexist(): void
    {
        $this->expectNotToPerformAssertions();

        HookRegistry::on('agent.before', new CloudWebhookHook('key', 'agent.before'));
        HookRegistry::on('agent.after', new CloudWebhookHook('key', 'agent.after'));

        HookRegistry::fire('agent.before', ['streaming' => false]);
        HookRegistry::fire('agent.after', ['provider' => 'anthropic', 'iterations' => 1]);
    }

    public function test_agent_error_includes_class_and_error(): void
    {
        $p = $this->buildPayload('agent.error', [
            'run_id' => 'r1',
            'error' => 'boom',
            'class' => 'RuntimeException',
            'message' => 'q',
        ]);
        $this->assertSame('boom', $p['error']);
        $this->assertSame('RuntimeException', $p['class']);
        $this->assertSame('q', $p['message']);
        $this->assertSame('q', $p['prompt']);
    }

    public function test_provider_retry_includes_attempt_and_error(): void
    {
        $p = $this->buildPayload('provider.retry', ['attempt' => 2, 'error' => 'rate-limit']);
        $this->assertSame(2, $p['attempt']);
        $this->assertSame('rate-limit', $p['error']);
    }

    public function test_provider_cache_hit_includes_token_counts(): void
    {
        $p = $this->buildPayload('provider.cache_hit', [
            'cache_read_tokens' => 200,
            'cache_write_tokens' => 50,
        ]);
        $this->assertSame(200, $p['cache_read_tokens']);
        $this->assertSame(50, $p['cache_write_tokens']);
    }

    public function test_provider_error_includes_iteration(): void
    {
        $p = $this->buildPayload('provider.error', ['error' => '500', 'iteration' => 3]);
        $this->assertSame('500', $p['error']);
        $this->assertSame(3, $p['iteration']);
    }

    public function test_provider_token_only_emits_provider_and_model(): void
    {
        $p = $this->buildPayload('provider.token', ['provider' => 'anthropic', 'model' => 'haiku', 'token' => 'sensitive']);
        $this->assertArrayNotHasKey('token', $p);
        $this->assertSame('anthropic', $p['provider']);
    }

    public function test_tool_not_found_includes_available_tools(): void
    {
        $p = $this->buildPayload('tool.not_found', [
            'tool_name' => 'fake',
            'available_tools' => ['real_a', 'real_b'],
        ]);
        $this->assertSame(['real_a', 'real_b'], $p['available_tools']);
    }

    public function test_context_overflow_includes_history_limits(): void
    {
        $p = $this->buildPayload('context.overflow', [
            'history_length' => 200,
            'max_history_length' => 100,
        ]);
        $this->assertSame(200, $p['history_length']);
        $this->assertSame(100, $p['max_history_length']);
    }

    public function test_guard_rate_limit_includes_caller_count_window(): void
    {
        $p = $this->buildPayload('guard.rate_limit_exceeded', [
            'caller_id' => 'ip-1',
            'count' => 11,
            'max_requests' => 10,
            'window_seconds' => 60,
        ]);
        $this->assertSame(11, $p['count']);
        $this->assertSame(60, $p['window_seconds']);
    }

    public function test_guard_tool_output_redacted_includes_pattern(): void
    {
        $p = $this->buildPayload('guard.tool_output_redacted', [
            'tool_name' => 'shell',
            'pattern' => 'ignore previous',
        ]);
        $this->assertSame('ignore previous', $p['pattern']);
    }

    public function test_guard_output_php_tag_removed_includes_tag(): void
    {
        $p = $this->buildPayload('guard.output_php_tag_removed', ['tag' => '<?php']);
        $this->assertSame('<?php', $p['tag']);
    }

    public function test_guard_output_function_redacted_includes_function(): void
    {
        $p = $this->buildPayload('guard.output_function_redacted', ['function' => 'eval(']);
        $this->assertSame('eval(', $p['function']);
    }

    public function test_conversation_start_includes_metadata_and_run_id(): void
    {
        $p = $this->buildPayload('conversation.start', [
            'run_id' => 'run-123',
            'conversation_id' => 'c1',
            'metadata' => ['user' => 'alice'],
        ]);
        $this->assertSame(['user' => 'alice'], $p['metadata']);
        $this->assertSame('run-123', $p['run_id']);
    }

    public function test_conversation_end_with_store_includes_message_and_response(): void
    {
        $p = $this->buildPayload('conversation.end', [
            'conversation_id' => 'c1',
            'turn_count' => 3,
            'message' => 'hi',
            'response' => 'hello',
        ]);
        $this->assertSame('hi', $p['message']);
        $this->assertSame('hello', $p['response']);
        $this->assertSame('hello', $p['text']);
        $this->assertSame('hi', $p['prompt']);
    }

    public function test_stream_start_with_store_includes_message(): void
    {
        $p = $this->buildPayload('stream.start', [
            'provider' => 'anthropic',
            'model' => 'haiku',
            'message' => 'hi',
        ]);
        $this->assertSame('hi', $p['message']);
        $this->assertSame('hi', $p['prompt']);
    }

    public function test_stream_end_includes_chars_and_duration(): void
    {
        $p = $this->buildPayload('stream.end', [
            'provider' => 'anthropic',
            'duration_ms' => 1000,
            'chars' => 250,
            'message' => 'q',
        ]);
        $this->assertSame(250, $p['chars']);
        $this->assertSame(1000, $p['duration_ms']);
        $this->assertSame('q', $p['message']);
    }

    public function test_stream_abort_includes_error(): void
    {
        $p = $this->buildPayload('stream.abort', ['error' => 'client-disconnect']);
        $this->assertSame('client-disconnect', $p['error']);
    }

    public function test_shell_denied_includes_cmd_name_and_reason(): void
    {
        $p = $this->buildPayload('shell.denied', [
            'command' => 'rm -rf /',
            'cmd_name' => 'rm',
            'reason' => 'not in allowlist',
            'file' => 'shell.php',
        ]);
        $this->assertSame('rm', $p['cmd_name']);
        $this->assertSame('not in allowlist', $p['reason']);
    }

    public function test_shell_exec_includes_command_and_cmd_name(): void
    {
        $p = $this->buildPayload('shell.exec', ['command' => 'ls -la', 'cmd_name' => 'ls']);
        $this->assertSame('ls -la', $p['command']);
        $this->assertSame('ls', $p['cmd_name']);
    }

    public function test_job_started_with_store_includes_message(): void
    {
        $p = $this->buildPayload('job.started', ['job_id' => 'j1', 'message' => 'process']);
        $this->assertSame('j1', $p['job_id']);
        $this->assertSame('process', $p['message']);
    }

    public function test_job_completed_includes_tokens_and_duration(): void
    {
        $p = $this->buildPayload('job.completed', [
            'job_id' => 'j1',
            'tokens' => 500,
            'duration_ms' => 2000,
            'iterations' => 3,
        ]);
        $this->assertSame(500, $p['tokens']);
        $this->assertSame(2000, $p['duration_ms']);
    }

    public function test_job_failed_includes_class(): void
    {
        $p = $this->buildPayload('job.failed', [
            'job_id' => 'j1',
            'error' => 'oom',
            'class' => 'OutOfMemoryError',
        ]);
        $this->assertSame('OutOfMemoryError', $p['class']);
    }

    public function test_handle_short_circuits_on_suppressed_event(): void
    {
        $this->expectNotToPerformAssertions();
        $hook = new CloudWebhookHook('test-key', 'provider.token');
        $hook->handle(['provider' => 'anthropic']);
    }

    public function test_handle_short_circuits_on_suppressed_event_via_wildcard(): void
    {
        $this->expectNotToPerformAssertions();
        $hook = new CloudWebhookHook('test-key', '__any__');
        $hook->handle(['event' => 'provider.token', 'provider' => 'x']);
    }

    public function test_event_getter_returns_configured_event_name(): void
    {
        $hook = new CloudWebhookHook('test-key', 'agent.after');
        $this->assertSame('agent.after', $hook->event());
    }

    public function test_event_getter_returns_wildcard_marker_when_configured(): void
    {
        $hook = new CloudWebhookHook('test-key', '__any__');
        $this->assertSame('__any__', $hook->event());
    }

    public function test_generic_sanitiser_handles_nested_secret_keys(): void
    {
        $p = $this->buildPayload('x.custom', [
            'outer' => ['password' => 'inner-secret', 'safe' => 'visible'],
        ]);
        $this->assertSame('[redacted]', $p['payload']['outer']['password']);
        $this->assertSame('visible', $p['payload']['outer']['safe']);
    }

    public function test_generic_sanitiser_redacts_case_insensitive(): void
    {
        $p = $this->buildPayload('x.custom', [
            'PASSWORD' => 'p',
            'APIKey' => 'sk',
            'Authorization' => 'Bearer x',
        ]);
        $this->assertSame('[redacted]', $p['payload']['PASSWORD']);
        $this->assertSame('[redacted]', $p['payload']['APIKey']);
        $this->assertSame('[redacted]', $p['payload']['Authorization']);
    }

    public function test_wildcard_falls_back_to_unknown_event_marker(): void
    {
        $p = $this->buildPayload('', ['some' => 'data']);
        $this->assertSame('unknown', $p['event']);
    }
}
