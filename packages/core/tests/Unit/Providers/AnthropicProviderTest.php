<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Providers;

use PhpClaw\Agent\Message;
use PhpClaw\Exceptions\ProviderException;
use PhpClaw\Http\RawHttpClient;
use PhpClaw\Http\StreamParser;
use PhpClaw\Providers\AnthropicProvider;
use PhpClaw\Providers\Tools\WebSearch;
use PHPUnit\Framework\TestCase;

final class AnthropicProviderTest extends TestCase
{
    private RawHttpClient $mockHttp;

    private AnthropicProvider $provider;

    protected function setUp(): void
    {
        $this->mockHttp = $this->createMock(RawHttpClient::class);
        $this->provider = new AnthropicProvider(
            apiKey: 'sk-ant-test',
            http: $this->mockHttp,
            parser: new StreamParser,
        );
    }

    public function test_name_returns_anthropic(): void
    {
        $this->assertSame('anthropic', $this->provider->name());
    }

    public function test_model_returns_default_haiku(): void
    {
        $this->assertSame('claude-haiku-4-5-20251001', $this->provider->model());
    }

    public function test_send_returns_text_response(): void
    {
        $this->mockHttp->method('post')->willReturn([
            'content' => [['type' => 'text', 'text' => 'Hello!']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ]);

        $result = $this->provider->send([Message::user('Hi')]);

        $this->assertSame('text', $result['type']);
        $this->assertSame('Hello!', $result['text']);
        $this->assertSame(10, $result['input_tokens']);
        $this->assertSame(5, $result['output_tokens']);
    }

    public function test_send_unrecognised_response_error_names_keys_not_body(): void
    {
        $this->mockHttp->method('post')->willReturn([
            'content' => [['type' => 'redacted_block', 'data' => 'private model output']],
            'stop_reason' => 'max_tokens',
        ]);

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('Unexpected Anthropic response structure (keys: content, stop_reason).');

        $this->provider->send([Message::user('Hi')]);
    }

    public function test_send_returns_tool_use_batch_for_single_call(): void
    {
        $this->mockHttp->method('post')->willReturn([
            'content' => [
                [
                    'type' => 'tool_use',
                    'id' => 'toolu_01',
                    'name' => 'shell_exec',
                    'input' => ['command' => 'ls'],
                ],
            ],
            'stop_reason' => 'tool_use',
            'usage' => ['input_tokens' => 20, 'output_tokens' => 8],
        ]);

        $result = $this->provider->send([Message::user('List files')]);

        $this->assertSame('tool_use_batch', $result['type']);
        $this->assertCount(1, $result['calls']);
        $this->assertSame('toolu_01', $result['calls'][0]['tool_use_id']);
        $this->assertSame('shell_exec', $result['calls'][0]['tool_name']);
        $this->assertSame(['command' => 'ls'], $result['calls'][0]['tool_input']);
        $this->assertSame(20, $result['input_tokens']);
        $this->assertSame(8, $result['output_tokens']);
    }

    public function test_send_returns_tool_use_batch_for_multiple_calls(): void
    {
        $this->mockHttp->method('post')->willReturn([
            'content' => [
                [
                    'type' => 'tool_use',
                    'id' => 'toolu_01',
                    'name' => 'shell_exec',
                    'input' => ['command' => 'ls'],
                ],
                [
                    'type' => 'tool_use',
                    'id' => 'toolu_02',
                    'name' => 'file_read',
                    'input' => ['path' => 'app.log'],
                ],
            ],
            'stop_reason' => 'tool_use',
            'usage' => ['input_tokens' => 30, 'output_tokens' => 12],
        ]);

        $result = $this->provider->send([Message::user('Do two things')]);

        $this->assertSame('tool_use_batch', $result['type']);
        $this->assertCount(2, $result['calls']);
        $this->assertSame('toolu_01', $result['calls'][0]['tool_use_id']);
        $this->assertSame('shell_exec', $result['calls'][0]['tool_name']);
        $this->assertSame('toolu_02', $result['calls'][1]['tool_use_id']);
        $this->assertSame('file_read', $result['calls'][1]['tool_name']);
    }

    public function test_send_formats_user_message_correctly(): void
    {
        $capturedBody = null;

        $this->mockHttp->method('post')
            ->willReturnCallback(function (string $url, array $headers, array $body) use (&$capturedBody): array {
                $capturedBody = $body;

                return [
                    'content' => [['type' => 'text', 'text' => 'ok']],
                    'stop_reason' => 'end_turn',
                    'usage' => ['input_tokens' => 5, 'output_tokens' => 2],
                ];
            });

        $this->provider->send([Message::user('Test message')]);

        $this->assertNotNull($capturedBody);
        $this->assertSame('claude-haiku-4-5-20251001', $capturedBody['model']);
        $this->assertSame('user', $capturedBody['messages'][0]['role']);
        $this->assertSame('Test message', $capturedBody['messages'][0]['content']);
    }

    public function test_send_includes_tools_in_request_when_provided(): void
    {
        $capturedBody = null;

        $this->mockHttp->method('post')
            ->willReturnCallback(function (string $url, array $headers, array $body) use (&$capturedBody): array {
                $capturedBody = $body;

                return [
                    'content' => [['type' => 'text', 'text' => 'done']],
                    'stop_reason' => 'end_turn',
                    'usage' => ['input_tokens' => 5, 'output_tokens' => 2],
                ];
            });

        $tools = [
            ['name' => 'shell_exec', 'description' => 'Run a shell command', 'input_schema' => []],
        ];

        $this->provider->send([Message::user('Run ls')], $tools);

        $this->assertArrayHasKey('tools', $capturedBody);
        $this->assertSame('shell_exec', $capturedBody['tools'][0]['name']);
    }

    public function test_send_does_not_include_tools_key_when_empty(): void
    {
        $capturedBody = null;

        $this->mockHttp->method('post')
            ->willReturnCallback(function (string $url, array $headers, array $body) use (&$capturedBody): array {
                $capturedBody = $body;

                return [
                    'content' => [['type' => 'text', 'text' => 'done']],
                    'stop_reason' => 'end_turn',
                    'usage' => ['input_tokens' => 5, 'output_tokens' => 2],
                ];
            });

        $this->provider->send([Message::user('Hello')]);

        $this->assertArrayNotHasKey('tools', $capturedBody);
    }

    public function test_send_formats_batch_as_assistant_content_and_user_results(): void
    {
        $capturedBody = null;

        $this->mockHttp->method('post')
            ->willReturnCallback(function (string $url, array $headers, array $body) use (&$capturedBody): array {
                $capturedBody = $body;

                return [
                    'content' => [['type' => 'text', 'text' => 'done']],
                    'stop_reason' => 'end_turn',
                    'usage' => ['input_tokens' => 5, 'output_tokens' => 2],
                ];
            });

        $batch = Message::toolBatch(
            calls: [
                ['tool_use_id' => 'toolu_01', 'tool_name' => 'shell_exec', 'tool_input' => ['command' => 'ls']],
                ['tool_use_id' => 'toolu_02', 'tool_name' => 'file_read',  'tool_input' => ['path' => 'app.log']],
            ],
            results: ['toolu_01' => 'file.txt', 'toolu_02' => 'log content'],
        );

        $this->provider->send([Message::user('Run two tools'), $batch]);

        $formatted = $capturedBody['messages'];

        $this->assertSame('assistant', $formatted[1]['role']);
        $this->assertSame('tool_use', $formatted[1]['content'][0]['type']);
        $this->assertSame('toolu_01', $formatted[1]['content'][0]['id']);
        $this->assertSame('tool_use', $formatted[1]['content'][1]['type']);
        $this->assertSame('toolu_02', $formatted[1]['content'][1]['id']);

        $this->assertSame('user', $formatted[2]['role']);
        $this->assertSame('tool_result', $formatted[2]['content'][0]['type']);
        $this->assertSame('toolu_01', $formatted[2]['content'][0]['tool_use_id']);
        $this->assertSame('file.txt', $formatted[2]['content'][0]['content']);
        $this->assertSame('tool_result', $formatted[2]['content'][1]['type']);
        $this->assertSame('toolu_02', $formatted[2]['content'][1]['tool_use_id']);
    }

    public function test_send_formats_tool_result_as_user_message(): void
    {
        $capturedBody = null;

        $this->mockHttp->method('post')
            ->willReturnCallback(function (string $url, array $headers, array $body) use (&$capturedBody): array {
                $capturedBody = $body;

                return [
                    'content' => [['type' => 'text', 'text' => 'done']],
                    'stop_reason' => 'end_turn',
                    'usage' => ['input_tokens' => 5, 'output_tokens' => 2],
                ];
            });

        $messages = [
            Message::user('Run ls'),
            Message::toolUse('toolu_01', 'shell_exec', ['command' => 'ls']),
            Message::toolResult('toolu_01', 'shell_exec', 'file1.txt'),
        ];

        $this->provider->send($messages);

        $this->assertNotNull($capturedBody);
        $formatted = $capturedBody['messages'];

        $lastMsg = end($formatted);
        $this->assertSame('user', $lastMsg['role']);
        $this->assertSame('tool_result', $lastMsg['content'][0]['type']);
        $this->assertSame('toolu_01', $lastMsg['content'][0]['tool_use_id']);
    }

    public function test_stream_calls_on_token_for_each_chunk(): void
    {
        $receivedTokens = [];

        $this->mockHttp->method('stream')
            ->willReturnCallback(function (string $url, array $headers, array $body, callable $onChunk): void {
                $onChunk('data: {"type":"content_block_delta","delta":{"type":"text_delta","text":"Hello"}}');
                $onChunk('data: {"type":"content_block_delta","delta":{"type":"text_delta","text":" world"}}');
                $onChunk('data: [DONE]');
            });

        $result = $this->provider->stream(
            [Message::user('hi')],
            function (string $token) use (&$receivedTokens): void {
                $receivedTokens[] = $token;
            }
        );

        $this->assertSame(['Hello', ' world'], $receivedTokens);
        $this->assertSame('Hello world', $result);
    }

    public function test_provider_exception_propagates_from_http(): void
    {
        $this->mockHttp->method('post')
            ->willThrowException(new ProviderException('HTTP 401: Unauthorized'));

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessageMatches('/401/');

        $this->provider->send([Message::user('Test')]);
    }

    public function test_model_constants_have_correct_values(): void
    {
        $this->assertSame('claude-haiku-4-5-20251001', AnthropicProvider::MODEL_HAIKU);
        $this->assertSame('claude-sonnet-5', AnthropicProvider::MODEL_SONNET);
        $this->assertSame('claude-opus-4-8', AnthropicProvider::MODEL_OPUS);
    }

    public function test_send_includes_system_field_when_system_prompt_set(): void
    {
        $capturedBody = null;
        $provider = new AnthropicProvider(
            apiKey: 'sk-ant-test',
            http: $this->mockHttp,
            parser: new StreamParser,
            systemPrompt: 'You are a senior Laravel engineer.',
        );

        $this->mockHttp->method('post')
            ->willReturnCallback(function (string $url, array $headers, array $body) use (&$capturedBody): array {
                $capturedBody = $body;

                return [
                    'content' => [['type' => 'text', 'text' => 'ok']],
                    'stop_reason' => 'end_turn',
                    'usage' => ['input_tokens' => 5, 'output_tokens' => 2],
                ];
            });

        $provider->send([Message::user('Hello')]);

        $this->assertArrayHasKey('system', $capturedBody);
        $this->assertSame('You are a senior Laravel engineer.', $capturedBody['system']);
    }

    public function test_send_omits_system_field_when_system_prompt_empty(): void
    {
        $capturedBody = null;

        $this->mockHttp->method('post')
            ->willReturnCallback(function (string $url, array $headers, array $body) use (&$capturedBody): array {
                $capturedBody = $body;

                return [
                    'content' => [['type' => 'text', 'text' => 'ok']],
                    'stop_reason' => 'end_turn',
                    'usage' => ['input_tokens' => 5, 'output_tokens' => 2],
                ];
            });

        $this->provider->send([Message::user('Hello')]);

        $this->assertArrayNotHasKey('system', $capturedBody);
    }

    public function test_send_uses_haiku_default_max_tokens_when_zero(): void
    {
        $capturedBody = null;

        $this->mockHttp->method('post')
            ->willReturnCallback(function (string $url, array $headers, array $body) use (&$capturedBody): array {
                $capturedBody = $body;

                return [
                    'content' => [['type' => 'text', 'text' => 'ok']],
                    'stop_reason' => 'end_turn',
                    'usage' => ['input_tokens' => 5, 'output_tokens' => 2],
                ];
            });

        $this->provider->send([Message::user('Hello')]);

        $this->assertSame(8192, $capturedBody['max_tokens']);
    }

    public function test_send_uses_explicit_max_tokens_when_set(): void
    {
        $capturedBody = null;
        $provider = new AnthropicProvider(
            apiKey: 'sk-ant-test',
            http: $this->mockHttp,
            parser: new StreamParser,
            maxTokens: 2000,
        );

        $this->mockHttp->method('post')
            ->willReturnCallback(function (string $url, array $headers, array $body) use (&$capturedBody): array {
                $capturedBody = $body;

                return [
                    'content' => [['type' => 'text', 'text' => 'ok']],
                    'stop_reason' => 'end_turn',
                    'usage' => ['input_tokens' => 5, 'output_tokens' => 2],
                ];
            });

        $provider->send([Message::user('Hello')]);

        $this->assertSame(2000, $capturedBody['max_tokens']);
    }

    public function test_send_bumps_max_tokens_above_thinking_budget(): void
    {
        $capturedBody = null;
        $provider = new AnthropicProvider(
            apiKey: 'sk-ant-test',
            http: $this->mockHttp,
            parser: new StreamParser,
            model: AnthropicProvider::MODEL_SONNET,
            maxTokens: 1000,
            thinkingBudget: 5000,
        );

        $this->mockHttp->method('post')
            ->willReturnCallback(function (string $url, array $headers, array $body) use (&$capturedBody): array {
                $capturedBody = $body;

                return [
                    'content' => [['type' => 'text', 'text' => 'ok']],
                    'stop_reason' => 'end_turn',
                    'usage' => ['input_tokens' => 5, 'output_tokens' => 2],
                ];
            });

        $provider->send([Message::user('Hello')]);

        $this->assertSame(6000, $capturedBody['max_tokens']);
    }

    public function test_send_adds_prompt_cache_beta_header_when_enabled(): void
    {
        $capturedHeaders = null;
        $provider = new AnthropicProvider(
            apiKey: 'sk-ant-test',
            http: $this->mockHttp,
            parser: new StreamParser,
            systemPrompt: 'You are helpful.',
            promptCache: true,
        );

        $this->mockHttp->method('post')
            ->willReturnCallback(function (string $url, array $headers, array $body) use (&$capturedHeaders): array {
                $capturedHeaders = $headers;

                return [
                    'content' => [['type' => 'text', 'text' => 'ok']],
                    'stop_reason' => 'end_turn',
                    'usage' => ['input_tokens' => 5, 'output_tokens' => 2],
                ];
            });

        $provider->send([Message::user('Hello')]);

        $this->assertArrayHasKey('anthropic-beta', $capturedHeaders);
        $this->assertStringContainsString('prompt-caching-2024-07-31', $capturedHeaders['anthropic-beta']);
    }

    public function test_send_wraps_system_in_cache_control_block_when_prompt_cache_enabled(): void
    {
        $capturedBody = null;
        $provider = new AnthropicProvider(
            apiKey: 'sk-ant-test',
            http: $this->mockHttp,
            parser: new StreamParser,
            systemPrompt: 'You are helpful.',
            promptCache: true,
        );

        $this->mockHttp->method('post')
            ->willReturnCallback(function (string $url, array $headers, array $body) use (&$capturedBody): array {
                $capturedBody = $body;

                return [
                    'content' => [['type' => 'text', 'text' => 'ok']],
                    'stop_reason' => 'end_turn',
                    'usage' => ['input_tokens' => 5, 'output_tokens' => 2],
                ];
            });

        $provider->send([Message::user('Hello')]);

        $system = $capturedBody['system'];
        $this->assertIsArray($system);
        $this->assertSame('text', $system[0]['type']);
        $this->assertSame('You are helpful.', $system[0]['text']);
        $this->assertSame(['type' => 'ephemeral'], $system[0]['cache_control']);
    }

    public function test_send_adds_cache_control_to_last_tool_when_prompt_cache_enabled(): void
    {
        $capturedBody = null;
        $provider = new AnthropicProvider(
            apiKey: 'sk-ant-test',
            http: $this->mockHttp,
            parser: new StreamParser,
            systemPrompt: 'You are helpful.',
            promptCache: true,
        );

        $this->mockHttp->method('post')
            ->willReturnCallback(function (string $url, array $headers, array $body) use (&$capturedBody): array {
                $capturedBody = $body;

                return [
                    'content' => [['type' => 'text', 'text' => 'ok']],
                    'stop_reason' => 'end_turn',
                    'usage' => ['input_tokens' => 5, 'output_tokens' => 2],
                ];
            });

        $tools = [
            ['name' => 'tool_a', 'description' => 'A', 'input_schema' => []],
            ['name' => 'tool_b', 'description' => 'B', 'input_schema' => []],
        ];

        $provider->send([Message::user('Use tools')], $tools);

        $sentTools = $capturedBody['tools'];
        $this->assertArrayNotHasKey('cache_control', $sentTools[0]);
        $this->assertArrayHasKey('cache_control', $sentTools[1]);
        $this->assertSame(['type' => 'ephemeral'], $sentTools[1]['cache_control']);
    }

    public function test_send_does_not_add_cache_header_when_prompt_cache_disabled(): void
    {
        $capturedHeaders = null;

        $this->mockHttp->method('post')
            ->willReturnCallback(function (string $url, array $headers, array $body) use (&$capturedHeaders): array {
                $capturedHeaders = $headers;

                return [
                    'content' => [['type' => 'text', 'text' => 'ok']],
                    'stop_reason' => 'end_turn',
                    'usage' => ['input_tokens' => 5, 'output_tokens' => 2],
                ];
            });

        $this->provider->send([Message::user('Hello')]);

        $this->assertArrayNotHasKey('anthropic-beta', $capturedHeaders);
    }

    public function test_send_adds_thinking_beta_header_when_thinking_budget_set(): void
    {
        $capturedHeaders = null;
        $provider = new AnthropicProvider(
            apiKey: 'sk-ant-test',
            http: $this->mockHttp,
            parser: new StreamParser,
            thinkingBudget: 5000,
        );

        $this->mockHttp->method('post')
            ->willReturnCallback(function (string $url, array $headers, array $body) use (&$capturedHeaders): array {
                $capturedHeaders = $headers;

                return [
                    'content' => [['type' => 'text', 'text' => 'ok']],
                    'stop_reason' => 'end_turn',
                    'usage' => ['input_tokens' => 5, 'output_tokens' => 2],
                ];
            });

        $provider->send([Message::user('Hello')]);

        $this->assertArrayHasKey('anthropic-beta', $capturedHeaders);
        $this->assertStringContainsString('interleaved-thinking-2025-05-14', $capturedHeaders['anthropic-beta']);
    }

    public function test_send_includes_thinking_block_in_body_when_thinking_budget_set(): void
    {
        $capturedBody = null;
        $provider = new AnthropicProvider(
            apiKey: 'sk-ant-test',
            http: $this->mockHttp,
            parser: new StreamParser,
            thinkingBudget: 5000,
        );

        $this->mockHttp->method('post')
            ->willReturnCallback(function (string $url, array $headers, array $body) use (&$capturedBody): array {
                $capturedBody = $body;

                return [
                    'content' => [['type' => 'text', 'text' => 'ok']],
                    'stop_reason' => 'end_turn',
                    'usage' => ['input_tokens' => 5, 'output_tokens' => 2],
                ];
            });

        $provider->send([Message::user('Hello')]);

        $this->assertArrayHasKey('thinking', $capturedBody);
        $this->assertSame('enabled', $capturedBody['thinking']['type']);
        $this->assertSame(5000, $capturedBody['thinking']['budget_tokens']);
    }

    public function test_send_does_not_include_thinking_block_below_min_budget(): void
    {
        $capturedBody = null;
        $provider = new AnthropicProvider(
            apiKey: 'sk-ant-test',
            http: $this->mockHttp,
            parser: new StreamParser,
            thinkingBudget: 500,
        );

        $this->mockHttp->method('post')
            ->willReturnCallback(function (string $url, array $headers, array $body) use (&$capturedBody): array {
                $capturedBody = $body;

                return [
                    'content' => [['type' => 'text', 'text' => 'ok']],
                    'stop_reason' => 'end_turn',
                    'usage' => ['input_tokens' => 5, 'output_tokens' => 2],
                ];
            });

        $provider->send([Message::user('Hello')]);

        $this->assertArrayNotHasKey('thinking', $capturedBody);
    }

    public function test_send_extracts_thinking_text_from_response(): void
    {
        $this->mockHttp->method('post')->willReturn([
            'content' => [
                ['type' => 'thinking', 'thinking' => 'Step 1: analyze. Step 2: conclude.'],
                ['type' => 'text',     'text' => 'The answer is 42.'],
            ],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 20, 'output_tokens' => 10],
        ]);

        $result = $this->provider->send([Message::user('Hard question')]);

        $this->assertSame('text', $result['type']);
        $this->assertSame('The answer is 42.', $result['text']);
        $this->assertSame('Step 1: analyze. Step 2: conclude.', $result['thinking']);
    }

    public function test_send_thinking_is_null_when_no_thinking_block(): void
    {
        $this->mockHttp->method('post')->willReturn([
            'content' => [['type' => 'text', 'text' => 'Simple answer.']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 5, 'output_tokens' => 3],
        ]);

        $result = $this->provider->send([Message::user('Simple question')]);

        $this->assertNull($result['thinking']);
    }

    public function test_send_extracts_cache_read_tokens_from_response(): void
    {
        $this->mockHttp->method('post')->willReturn([
            'content' => [['type' => 'text', 'text' => 'ok']],
            'stop_reason' => 'end_turn',
            'usage' => [
                'input_tokens' => 100,
                'output_tokens' => 20,
                'cache_read_input_tokens' => 80,
                'cache_creation_input_tokens' => 0,
            ],
        ]);

        $result = $this->provider->send([Message::user('Cached call')]);

        $this->assertSame(80, $result['cache_read_tokens']);
    }

    public function test_send_extracts_cache_write_tokens_from_response(): void
    {
        $this->mockHttp->method('post')->willReturn([
            'content' => [['type' => 'text', 'text' => 'ok']],
            'stop_reason' => 'end_turn',
            'usage' => [
                'input_tokens' => 100,
                'output_tokens' => 20,
                'cache_read_input_tokens' => 0,
                'cache_creation_input_tokens' => 100,
            ],
        ]);

        $result = $this->provider->send([Message::user('First call, builds cache')]);

        $this->assertSame(100, $result['cache_write_tokens']);
    }

    public function test_send_cache_tokens_are_null_when_not_in_response(): void
    {
        $this->mockHttp->method('post')->willReturn([
            'content' => [['type' => 'text', 'text' => 'ok']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ]);

        $result = $this->provider->send([Message::user('No cache')]);

        $this->assertNull($result['cache_read_tokens']);
        $this->assertNull($result['cache_write_tokens']);
    }

    public function test_send_includes_both_beta_headers_when_cache_and_thinking_enabled(): void
    {
        $capturedHeaders = null;
        $provider = new AnthropicProvider(
            apiKey: 'sk-ant-test',
            http: $this->mockHttp,
            parser: new StreamParser,
            systemPrompt: 'You think deeply.',
            promptCache: true,
            thinkingBudget: 2000,
        );

        $this->mockHttp->method('post')
            ->willReturnCallback(function (string $url, array $headers, array $body) use (&$capturedHeaders): array {
                $capturedHeaders = $headers;

                return [
                    'content' => [['type' => 'text', 'text' => 'ok']],
                    'stop_reason' => 'end_turn',
                    'usage' => ['input_tokens' => 5, 'output_tokens' => 2],
                ];
            });

        $provider->send([Message::user('Hello')]);

        $beta = $capturedHeaders['anthropic-beta'];
        $this->assertStringContainsString('prompt-caching-2024-07-31', $beta);
        $this->assertStringContainsString('interleaved-thinking-2025-05-14', $beta);
    }

    public function test_web_search_tool_options_bare(): void
    {
        $entry = $this->provider->webSearchToolOptions(new WebSearch);

        $this->assertSame(['type' => 'web_search_20250305', 'name' => 'web_search'], $entry);
    }

    public function test_web_search_tool_options_with_max_allow_location(): void
    {
        $search = (new WebSearch)->max(5)->allow(['php.net'])->location(city: 'Pune', country: 'IN');

        $entry = $this->provider->webSearchToolOptions($search);

        $this->assertSame('web_search_20250305', $entry['type']);
        $this->assertSame('web_search', $entry['name']);
        $this->assertSame(5, $entry['max_uses']);
        $this->assertSame(['php.net'], $entry['allowed_domains']);
        $this->assertSame(['type' => 'approximate', 'city' => 'Pune', 'country' => 'IN'], $entry['user_location']);
    }

    public function test_with_provider_tools_returns_clone_and_original_unchanged(): void
    {
        $capturedBody = null;
        $this->mockHttp->method('post')
            ->willReturnCallback(function (string $url, array $headers, array $body) use (&$capturedBody): array {
                $capturedBody = $body;

                return [
                    'content' => [['type' => 'text', 'text' => 'ok']],
                    'stop_reason' => 'end_turn',
                    'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
                ];
            });

        $clone = $this->provider->withProviderTools([new WebSearch]);

        $this->assertNotSame($this->provider, $clone);

        $this->provider->send([Message::user('hi')]);
        $this->assertArrayNotHasKey('tools', $capturedBody);

        $clone->send([Message::user('hi')]);
        $this->assertSame('web_search', $capturedBody['tools'][0]['name']);
    }

    public function test_send_appends_web_search_entry_after_react_tools(): void
    {
        $capturedBody = null;
        $this->mockHttp->method('post')
            ->willReturnCallback(function (string $url, array $headers, array $body) use (&$capturedBody): array {
                $capturedBody = $body;

                return [
                    'content' => [['type' => 'text', 'text' => 'ok']],
                    'stop_reason' => 'end_turn',
                    'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
                ];
            });

        $provider = $this->provider->withProviderTools([new WebSearch]);
        $reactTool = ['name' => 'shell_exec', 'description' => 'Run a command', 'input_schema' => ['type' => 'object']];

        $provider->send([Message::user('hi')], [$reactTool]);

        $this->assertCount(2, $capturedBody['tools']);
        $this->assertSame('shell_exec', $capturedBody['tools'][0]['name']);
        $this->assertSame('web_search_20250305', $capturedBody['tools'][1]['type']);
    }

    public function test_send_cache_marker_stays_on_react_tool_not_web_search(): void
    {
        $capturedBody = null;
        $this->mockHttp->method('post')
            ->willReturnCallback(function (string $url, array $headers, array $body) use (&$capturedBody): array {
                $capturedBody = $body;

                return [
                    'content' => [['type' => 'text', 'text' => 'ok']],
                    'stop_reason' => 'end_turn',
                    'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
                ];
            });

        $provider = (new AnthropicProvider(
            apiKey: 'sk-ant-test',
            http: $this->mockHttp,
            parser: new StreamParser,
            promptCache: true,
        ))->withProviderTools([new WebSearch]);

        $reactTool = ['name' => 'shell_exec', 'description' => 'Run a command', 'input_schema' => ['type' => 'object']];

        $provider->send([Message::user('hi')], [$reactTool]);

        $this->assertSame(['type' => 'ephemeral'], $capturedBody['tools'][0]['cache_control']);
        $this->assertArrayNotHasKey('cache_control', $capturedBody['tools'][1]);
    }

    public function test_send_web_search_only_still_sends_tools(): void
    {
        $capturedBody = null;
        $this->mockHttp->method('post')
            ->willReturnCallback(function (string $url, array $headers, array $body) use (&$capturedBody): array {
                $capturedBody = $body;

                return [
                    'content' => [['type' => 'text', 'text' => 'ok']],
                    'stop_reason' => 'end_turn',
                    'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
                ];
            });

        $this->provider->withProviderTools([new WebSearch])->send([Message::user('hi')]);

        $this->assertCount(1, $capturedBody['tools']);
        $this->assertSame('web_search', $capturedBody['tools'][0]['name']);
        $this->assertSame(['type' => 'auto'], $capturedBody['tool_choice']);
    }

    public function test_parse_concatenates_text_blocks_around_web_search_blocks(): void
    {
        $this->mockHttp->method('post')->willReturn([
            'content' => [
                ['type' => 'text', 'text' => 'Let me search. '],
                ['type' => 'server_tool_use', 'id' => 'srvtoolu_01', 'name' => 'web_search', 'input' => ['query' => 'php 8.4']],
                ['type' => 'web_search_tool_result', 'tool_use_id' => 'srvtoolu_01', 'content' => []],
                ['type' => 'text', 'text' => 'PHP 8.4 released November 2024.'],
            ],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 50, 'output_tokens' => 20],
        ]);

        $result = $this->provider->send([Message::user('When was PHP 8.4 released?')]);

        $this->assertSame('text', $result['type']);
        $this->assertSame('Let me search. PHP 8.4 released November 2024.', $result['text']);
        $this->assertArrayNotHasKey('calls', $result);
    }

    public function test_parse_pause_turn_returns_text_without_exception(): void
    {
        $this->mockHttp->method('post')->willReturn([
            'content' => [['type' => 'server_tool_use', 'id' => 'srvtoolu_02', 'name' => 'web_search', 'input' => []]],
            'stop_reason' => 'pause_turn',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 3],
        ]);

        $result = $this->provider->send([Message::user('hi')]);

        $this->assertSame('text', $result['type']);
        $this->assertSame('', $result['text']);
    }
}
