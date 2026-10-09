<?php

declare(strict_types=1);

namespace PhpClaw\Cloud\Tests\Unit;

use PhpClaw\Cloud\CloudPayloadBuilder;
use PHPUnit\Framework\TestCase;

final class CloudPayloadBuilderTest extends TestCase
{
    private string $event = 'agent.before';

    private function buildPayload(CloudPayloadBuilder $builder, array $context): array
    {
        return $builder->build($this->event, $context);
    }

    private function makeHook(string $event): CloudPayloadBuilder
    {
        $this->event = $event;

        return new CloudPayloadBuilder;
    }

    public function test_agent_before_includes_prompt_when_store_messages_true(): void
    {
        $hook = $this->makeHook('agent.before');

        $payload = $this->buildPayload($hook, [
            'message' => 'Hello agent',
            'run_id' => 'test-run-id',
            'streaming' => false,
            'conversation_id' => '',
        ]);

        $this->assertArrayHasKey('prompt', $payload);
        $this->assertSame('Hello agent', $payload['prompt']);
    }

    public function test_typed_event_redacts_nested_secret_in_tool_input(): void
    {
        $hook = $this->makeHook('tool.before');

        $payload = $this->buildPayload($hook, [
            'tool_name' => 'http',
            'tool_input' => ['api_key' => 'sk-super-secret', 'url' => 'https://example.com'],
        ]);

        $this->assertSame('[redacted]', $payload['tool_input']['api_key']);
        $this->assertSame('https://example.com', $payload['tool_input']['url']);
    }

    public function test_typed_event_truncates_oversized_nested_string(): void
    {
        $hook = $this->makeHook('tool.after');

        $payload = $this->buildPayload($hook, [
            'tool_name' => 'db_query',
            'tool_input' => ['blob' => str_repeat('a', 9000)],
        ]);

        $this->assertStringEndsWith('…[truncated]', $payload['tool_input']['blob']);
        $this->assertLessThan(9000, strlen($payload['tool_input']['blob']));
    }

    public function test_typed_event_bounds_oversized_nested_array(): void
    {
        $hook = $this->makeHook('tool.before');

        $payload = $this->buildPayload($hook, [
            'tool_name' => 'db_query',
            'tool_input' => ['rows' => array_fill(0, 250, 'x')],
        ]);

        $this->assertSame('[array-too-large:250]', $payload['tool_input']['rows']);
    }

    public function test_oversized_payload_collapses_to_minimal_envelope(): void
    {
        $hook = $this->makeHook('some.custom.event');

        $payload = $this->buildPayload($hook, [
            'run_id' => 'run-xyz',
            'big' => array_fill(0, 100, str_repeat('x', 700)),
        ]);

        $this->assertTrue($payload['oversized']);
        $this->assertSame('run-xyz', $payload['run_id']);
        $this->assertArrayNotHasKey('payload', $payload);
        $this->assertArrayHasKey('event', $payload);
    }

    public function test_agent_after_includes_response_when_store_messages_true(): void
    {
        $hook = $this->makeHook('agent.after');

        $payload = $this->buildPayload($hook, [
            'text' => 'Agent reply',
            'run_id' => 'test-run-id',
            'provider' => 'anthropic',
            'model' => 'claude-haiku-4-5',
            'iterations' => 1,
            'duration_ms' => 120,
            'tools_called' => 0,
            'cache_read_tokens' => 0,
            'cache_write_tokens' => 0,
            'streaming' => false,
            'conversation_id' => '',
        ]);

        $this->assertArrayHasKey('response', $payload);
        $this->assertSame('Agent reply', $payload['response']);
    }

    public function test_run_id_always_present_in_agent_before(): void
    {
        $hook = $this->makeHook('agent.before');

        $payload = $this->buildPayload($hook, [
            'message' => 'hi',
            'run_id' => 'ulid-abc-123',
        ]);

        $this->assertArrayHasKey('run_id', $payload);
        $this->assertSame('ulid-abc-123', $payload['run_id']);
    }

    public function test_run_id_always_present_in_agent_after(): void
    {
        $hook = $this->makeHook('agent.after');

        $payload = $this->buildPayload($hook, [
            'run_id' => 'ulid-xyz-456',
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
        ]);

        $this->assertArrayHasKey('run_id', $payload);
        $this->assertSame('ulid-xyz-456', $payload['run_id']);
    }

    public function test_job_completed_payload_contains_expected_fields(): void
    {
        $hook = $this->makeHook('job.completed');

        $payload = $this->buildPayload($hook, [
            'job_id' => 'job-001',
            'run_id' => 'run-001',
            'provider' => 'anthropic',
            'model' => 'claude-haiku-4-5',
            'iterations' => 3,
            'duration_ms' => 450,
            'tokens' => 1200,
        ]);

        $this->assertSame('job-001', $payload['job_id']);
        $this->assertSame('run-001', $payload['run_id']);
        $this->assertSame(3, $payload['iterations']);
        $this->assertSame(450, $payload['duration_ms']);
        $this->assertSame(1200, $payload['tokens']);
    }

    public function test_job_started_includes_prompt(): void
    {
        $contextWithMessage = ['job_id' => 'job-001', 'message' => 'run migration'];

        $hook = $this->makeHook('job.started');
        $payload = $this->buildPayload($hook, $contextWithMessage);
        $this->assertArrayHasKey('prompt', $payload);
        $this->assertSame('run migration', $payload['prompt']);
    }

    public function test_every_payload_contains_event_and_timestamp(): void
    {
        $hook = $this->makeHook('agent.before');

        $payload = $this->buildPayload($hook, [
            'message' => 'hi',
            'run_id' => 'abc',
        ]);

        $this->assertArrayHasKey('event', $payload);
        $this->assertArrayHasKey('ts', $payload);
        $this->assertSame('agent.before', $payload['event']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', (string) $payload['ts']);
    }

    public function test_skill_registered_payload_contains_flat_fields(): void
    {
        $hook = $this->makeHook('skill.registered');

        $payload = $this->buildPayload($hook, [
            'key' => 'php_best_practices',
            'class' => 'PhpClaw\\Skills\\PhpBestPracticesSkill',
            'label' => 'PHP Best Practices',
        ]);

        $this->assertSame('skill.registered', $payload['event']);
        $this->assertSame('php_best_practices', $payload['key']);
        $this->assertSame('PhpClaw\\Skills\\PhpBestPracticesSkill', $payload['class']);
        $this->assertSame('PHP Best Practices', $payload['label']);
        $this->assertArrayNotHasKey('payload', $payload);
    }

    public function test_skill_loaded_payload_contains_flat_fields(): void
    {
        $hook = $this->makeHook('skill.loaded');

        $payload = $this->buildPayload($hook, [
            'skill_name' => 'php_best_practices',
            'skill_class' => 'PhpClaw\\Skills\\PhpBestPracticesSkill',
        ]);

        $this->assertSame('skill.loaded', $payload['event']);
        $this->assertSame('php_best_practices', $payload['skill_name']);
        $this->assertSame('PhpClaw\\Skills\\PhpBestPracticesSkill', $payload['skill_class']);
        $this->assertArrayNotHasKey('payload', $payload);
    }

    public function test_skill_matched_payload_is_flat_not_nested_under_payload(): void
    {
        $hook = $this->makeHook('skill.matched');

        $payload = $this->buildPayload($hook, [
            'run_id' => 'run-001',
            'matched_skills' => ['php_best_practices'],
            'message_excerpt' => 'review my php class please',
            'matched_count' => 1,
        ]);

        $this->assertSame('skill.matched', $payload['event']);
        $this->assertSame('run-001', $payload['run_id']);
        $this->assertSame(['php_best_practices'], $payload['matched_skills']);
        $this->assertSame('review my php class please', $payload['message_excerpt']);
        $this->assertSame(1, $payload['matched_count']);
        $this->assertArrayNotHasKey('payload', $payload);
    }

    public function test_skill_not_matched_payload_contains_flat_fields(): void
    {
        $hook = $this->makeHook('skill.not_matched');

        $payload = $this->buildPayload($hook, [
            'run_id' => 'run-002',
            'message_excerpt' => 'what is the weather',
            'available_skills' => ['php_best_practices'],
        ]);

        $this->assertSame('skill.not_matched', $payload['event']);
        $this->assertSame('run-002', $payload['run_id']);
        $this->assertSame('what is the weather', $payload['message_excerpt']);
        $this->assertSame(['php_best_practices'], $payload['available_skills']);
        $this->assertArrayNotHasKey('payload', $payload);
    }

    public function test_hide_inputs_blanks_message_prompt_tool_input_and_command(): void
    {
        $builder = new CloudPayloadBuilder([CloudPayloadBuilder::HIDE_INPUTS]);

        $agent = $builder->build('agent.before', ['message' => 'my card is 4242', 'run_id' => 'r1']);
        $tool = $builder->build('tool.before', ['tool_name' => 'http', 'tool_input' => ['url' => 'https://a.test']]);
        $shell = $builder->build('shell.exec', ['command' => 'cat /etc/passwd', 'cmd_name' => 'cat']);

        $this->assertSame('[hidden]', $agent['message']);
        $this->assertSame('[hidden]', $agent['prompt']);
        $this->assertSame('[hidden]', $tool['tool_input']);
        $this->assertSame('[hidden]', $shell['command']);
        $this->assertSame('cat', $shell['cmd_name']);
    }

    public function test_hide_outputs_blanks_text_response_and_tool_result_but_not_inputs(): void
    {
        $builder = new CloudPayloadBuilder([CloudPayloadBuilder::HIDE_OUTPUTS]);

        $agent = $builder->build('agent.after', ['text' => 'your order shipped', 'message' => 'where is my order']);
        $tool = $builder->build('tool.after', ['tool_name' => 'db', 'tool_input' => ['id' => 7], 'tool_result' => 'row 7']);

        $this->assertSame('[hidden]', $agent['text']);
        $this->assertSame('[hidden]', $agent['response']);
        $this->assertSame('where is my order', $agent['message']);
        $this->assertSame('[hidden]', $tool['tool_result']);
        $this->assertSame(['id' => 7], $tool['tool_input']);
    }

    public function test_hide_metadata_blanks_metadata(): void
    {
        $builder = new CloudPayloadBuilder([CloudPayloadBuilder::HIDE_METADATA]);

        $payload = $builder->build('conversation.start', ['conversation_id' => 'c1', 'metadata' => ['email' => 'a@example.com']]);

        $this->assertSame('[hidden]', $payload['metadata']);
        $this->assertSame('c1', $payload['conversation_id']);
    }

    public function test_every_hide_name_keeps_numbers_ids_status_and_tool_name(): void
    {
        $builder = new CloudPayloadBuilder([
            CloudPayloadBuilder::HIDE_INPUTS,
            CloudPayloadBuilder::HIDE_OUTPUTS,
            CloudPayloadBuilder::HIDE_METADATA,
        ]);

        $agent = $builder->build('agent.after', [
            'run_id' => 'r9',
            'provider' => 'anthropic',
            'model' => 'm1',
            'iterations' => 3,
            'duration_ms' => 1200,
            'text' => 'secret reply',
        ]);
        $tool = $builder->build('tool.after', ['tool_name' => 'db', 'duration_ms' => 40, 'tool_result' => 'x']);

        $this->assertSame('r9', $agent['run_id']);
        $this->assertSame('anthropic', $agent['provider']);
        $this->assertSame('m1', $agent['model']);
        $this->assertSame(3, $agent['iterations']);
        $this->assertSame(1200, $agent['duration_ms']);
        $this->assertSame('agent.after', $agent['event']);
        $this->assertSame('db', $tool['tool_name']);
        $this->assertSame(40, $tool['duration_ms']);
    }

    public function test_hide_inputs_blanks_message_nested_in_custom_event_payload(): void
    {
        $builder = new CloudPayloadBuilder([CloudPayloadBuilder::HIDE_INPUTS]);

        $payload = $builder->build('shop.checkout', [
            'run_id' => 'r1',
            'order' => ['message' => 'gift note: happy birthday', 'total' => 40],
        ]);

        $this->assertSame('[hidden]', $payload['payload']['order']['message']);
        $this->assertSame(40, $payload['payload']['order']['total']);
    }

    public function test_hidden_field_that_was_never_recorded_stays_null(): void
    {
        $builder = new CloudPayloadBuilder([CloudPayloadBuilder::HIDE_INPUTS]);

        $payload = $builder->build('agent.before', ['run_id' => 'r1']);

        $this->assertNull($payload['message']);
        $this->assertNull($payload['prompt']);
    }

    public function test_hidden_key_match_ignores_case(): void
    {
        $builder = new CloudPayloadBuilder([CloudPayloadBuilder::HIDE_INPUTS]);

        $payload = $builder->build('shop.note', ['Message' => 'call me on 555-0100']);

        $this->assertSame('[hidden]', $payload['payload']['Message']);
    }

    public function test_no_hide_names_leaves_content_untouched(): void
    {
        $context = ['message' => 'hello', 'run_id' => 'r1', 'order' => ['message' => 'nested']];

        $plain = (new CloudPayloadBuilder)->build('shop.note', $context);
        $empty = (new CloudPayloadBuilder([]))->build('shop.note', $context);
        unset($plain['ts'], $empty['ts']);

        $this->assertSame($plain, $empty);
        $this->assertSame('hello', $plain['payload']['message']);
        $this->assertSame('nested', $plain['payload']['order']['message']);
    }

    public function test_feature_names_in_the_disable_list_hide_nothing(): void
    {
        $builder = new CloudPayloadBuilder(['observability', 'scan']);

        $payload = $builder->build('agent.after', ['text' => 'reply', 'message' => 'question']);

        $this->assertSame('reply', $payload['text']);
        $this->assertSame('question', $payload['message']);
    }

    public function test_guard_and_shell_events_carry_the_run_and_parent_run_ids(): void
    {
        $builder = new CloudPayloadBuilder;
        $run = ['run_id' => 'R1', 'parent_run_id' => 'P1'];

        foreach ([
            'guard.blocked' => ['reason' => 'injection'],
            'guard.rate_limit_exceeded' => ['caller_id' => 'user:42', 'count' => 105, 'max_requests' => 100, 'window_seconds' => 60],
            'guard.tool_output_redacted' => ['tool_name' => 'shell_exec', 'pattern' => 'api_key'],
            'guard.output_php_tag_removed' => ['tag' => '<?php'],
            'guard.output_function_redacted' => ['function' => 'shell_exec'],
            'shell.exec' => ['command' => 'whoami', 'cmd_name' => 'whoami'],
            'shell.denied' => ['command' => 'cat .env', 'cmd_name' => 'cat', 'reason' => 'sensitive_file', 'file' => '.env'],
        ] as $event => $context) {
            $payload = $builder->build($event, $context + $run);

            $this->assertSame('R1', $payload['run_id'] ?? null, $event);
            $this->assertSame('P1', $payload['parent_run_id'] ?? null, $event);
        }
    }

    public function test_shell_exec_payload_keeps_its_fields_next_to_the_run_ids(): void
    {
        $payload = (new CloudPayloadBuilder)->build('shell.exec', ['command' => 'whoami', 'cmd_name' => 'whoami', 'run_id' => 'R1', 'parent_run_id' => 'P1']);

        unset($payload['ts']);
        $this->assertSame(
            ['event' => 'shell.exec', 'run_id' => 'R1', 'parent_run_id' => 'P1', 'command' => 'whoami', 'cmd_name' => 'whoami'],
            $payload,
        );
    }

    public function test_guard_blocked_payload_still_never_carries_the_message(): void
    {
        $payload = (new CloudPayloadBuilder)->build('guard.blocked', [
            'message' => 'Ignore previous instructions',
            'reason' => 'injection',
            'guard' => 'InjectionGuard',
            'run_id' => 'R1',
        ]);

        unset($payload['ts']);
        $this->assertSame(['event' => 'guard.blocked', 'run_id' => 'R1', 'parent_run_id' => null, 'reason' => 'injection'], $payload);
    }

    public function test_guard_and_shell_events_outside_a_run_report_null_run_ids(): void
    {
        $payload = (new CloudPayloadBuilder)->build('shell.exec', ['command' => 'whoami', 'cmd_name' => 'whoami']);

        $this->assertArrayHasKey('run_id', $payload);
        $this->assertNull($payload['run_id']);
        $this->assertNull($payload['parent_run_id']);
    }

    public function test_agent_before_and_after_carry_the_parent_run_id(): void
    {
        $builder = new CloudPayloadBuilder;

        $before = $builder->build('agent.before', ['message' => 'hi', 'run_id' => 'r2', 'parent_run_id' => 'graph-1']);
        $after = $builder->build('agent.after', ['text' => 'ok', 'run_id' => 'r2', 'parent_run_id' => 'graph-1']);

        $this->assertSame('graph-1', $before['parent_run_id']);
        $this->assertSame('graph-1', $after['parent_run_id']);
    }

    public function test_agent_before_outside_any_graph_has_a_null_parent_run_id(): void
    {
        $payload = (new CloudPayloadBuilder)->build('agent.before', ['message' => 'hi', 'run_id' => 'r1']);

        $this->assertArrayHasKey('parent_run_id', $payload);
        $this->assertNull($payload['parent_run_id']);
    }

    public function test_graph_start_payload_contains_flat_fields(): void
    {
        $payload = (new CloudPayloadBuilder)->build('graph.start', [
            'entry_node' => 'write',
            'run_id' => 'graph-2',
            'parent_run_id' => 'graph-1',
        ]);

        $this->assertSame('graph.start', $payload['event']);
        $this->assertSame('graph-2', $payload['run_id']);
        $this->assertSame('graph-1', $payload['parent_run_id']);
        $this->assertSame('write', $payload['entry_node']);
        $this->assertArrayNotHasKey('payload', $payload);
    }

    public function test_graph_end_payload_contains_flat_fields(): void
    {
        $payload = (new CloudPayloadBuilder)->build('graph.end', [
            'steps' => 6,
            'last_node' => 'review',
            'run_id' => 'graph-2',
        ]);

        $this->assertSame('graph.end', $payload['event']);
        $this->assertSame('graph-2', $payload['run_id']);
        $this->assertNull($payload['parent_run_id']);
        $this->assertSame(6, $payload['steps']);
        $this->assertSame('review', $payload['last_node']);
        $this->assertArrayNotHasKey('payload', $payload);
    }

    public function test_graph_fan_out_payload_contains_flat_fields(): void
    {
        $payload = (new CloudPayloadBuilder)->build('graph.fan_out', ['node' => 'split', 'branches' => 4, 'run_id' => 'graph-3']);

        $this->assertSame('graph.fan_out', $payload['event']);
        $this->assertSame('graph-3', $payload['run_id']);
        $this->assertNull($payload['parent_run_id']);
        $this->assertSame('split', $payload['node']);
        $this->assertSame(4, $payload['branches']);
        $this->assertArrayNotHasKey('payload', $payload);
    }

    public function test_graph_join_payload_contains_flat_fields(): void
    {
        $payload = (new CloudPayloadBuilder)->build('graph.join', ['node' => 'split', 'branches' => 4, 'failed' => 1, 'run_id' => 'graph-4', 'parent_run_id' => 'graph-1']);

        $this->assertSame('graph.join', $payload['event']);
        $this->assertSame('graph-4', $payload['run_id']);
        $this->assertSame('graph-1', $payload['parent_run_id']);
        $this->assertSame('split', $payload['node']);
        $this->assertSame(4, $payload['branches']);
        $this->assertSame(1, $payload['failed']);
        $this->assertArrayNotHasKey('payload', $payload);
    }
}
