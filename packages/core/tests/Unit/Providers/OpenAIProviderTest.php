<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Providers;

use PhpClaw\Agent\Message;
use PhpClaw\Http\RawHttpClient;
use PhpClaw\Http\StreamParser;
use PhpClaw\Providers\OpenAIPresets;
use PhpClaw\Providers\OpenAIProvider;
use PhpClaw\Providers\Tools\WebSearch;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OpenAIProviderTest extends TestCase
{
    private RawHttpClient $mockHttp;

    private OpenAIProvider $provider;

    protected function setUp(): void
    {
        $this->mockHttp = $this->createMock(RawHttpClient::class);
        $this->provider = new OpenAIProvider(
            apiKey: 'sk-openai-test',
            http: $this->mockHttp,
            parser: new StreamParser,
        );
    }

    public function test_name_returns_openai(): void
    {
        $this->assertSame('openai', $this->provider->name());
    }

    public function test_model_returns_default_gpt4o_mini(): void
    {
        $this->assertSame('gpt-4o-mini', $this->provider->model());
    }

    public function test_send_returns_text_response(): void
    {
        $this->mockHttp->method('post')->willReturn([
            'choices' => [
                [
                    'message' => ['role' => 'assistant', 'content' => 'Hello from OpenAI!'],
                    'finish_reason' => 'stop',
                ],
            ],
            'usage' => ['prompt_tokens' => 15, 'completion_tokens' => 7],
        ]);

        $result = $this->provider->send([Message::user('Hi')]);

        $this->assertSame('text', $result['type']);
        $this->assertSame('Hello from OpenAI!', $result['text']);
        $this->assertSame(15, $result['input_tokens']);
        $this->assertSame(7, $result['output_tokens']);
    }

    public function test_send_returns_tool_use_batch_for_single_call(): void
    {
        $this->mockHttp->method('post')->willReturn([
            'choices' => [
                [
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [
                            [
                                'id' => 'call_01',
                                'type' => 'function',
                                'function' => ['name' => 'shell_exec', 'arguments' => '{"command":"ls"}'],
                            ],
                        ],
                    ],
                    'finish_reason' => 'tool_calls',
                ],
            ],
            'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 10],
        ]);

        $result = $this->provider->send([Message::user('List files')]);

        $this->assertSame('tool_use_batch', $result['type']);
        $this->assertCount(1, $result['calls']);
        $this->assertSame('call_01', $result['calls'][0]['tool_use_id']);
        $this->assertSame('shell_exec', $result['calls'][0]['tool_name']);
        $this->assertSame(['command' => 'ls'], $result['calls'][0]['tool_input']);
    }

    public function test_send_returns_tool_use_batch_for_multiple_calls(): void
    {
        $this->mockHttp->method('post')->willReturn([
            'choices' => [
                [
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [
                            [
                                'id' => 'call_01',
                                'type' => 'function',
                                'function' => ['name' => 'shell_exec', 'arguments' => '{"command":"ls"}'],
                            ],
                            [
                                'id' => 'call_02',
                                'type' => 'function',
                                'function' => ['name' => 'file_read', 'arguments' => '{"path":"app.log"}'],
                            ],
                        ],
                    ],
                    'finish_reason' => 'tool_calls',
                ],
            ],
            'usage' => ['prompt_tokens' => 30, 'completion_tokens' => 15],
        ]);

        $result = $this->provider->send([Message::user('Do two things')]);

        $this->assertSame('tool_use_batch', $result['type']);
        $this->assertCount(2, $result['calls']);
        $this->assertSame('call_01', $result['calls'][0]['tool_use_id']);
        $this->assertSame('shell_exec', $result['calls'][0]['tool_name']);
        $this->assertSame('call_02', $result['calls'][1]['tool_use_id']);
        $this->assertSame('file_read', $result['calls'][1]['tool_name']);
        $this->assertSame(['path' => 'app.log'], $result['calls'][1]['tool_input']);
    }

    public function test_send_formats_batch_as_assistant_tool_calls_and_tool_results(): void
    {
        $capturedBody = null;

        $this->mockHttp->method('post')
            ->willReturnCallback(function (string $url, array $headers, array $body) use (&$capturedBody): array {
                $capturedBody = $body;

                return [
                    'choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']],
                    'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 2],
                ];
            });

        $batch = Message::toolBatch(
            calls: [
                ['tool_use_id' => 'call_01', 'tool_name' => 'shell_exec', 'tool_input' => ['command' => 'ls']],
                ['tool_use_id' => 'call_02', 'tool_name' => 'file_read',  'tool_input' => ['path' => 'app.log']],
            ],
            results: ['call_01' => 'file.txt', 'call_02' => 'log content'],
        );

        $this->provider->send([Message::user('Run two tools'), $batch]);

        $formatted = $capturedBody['messages'];

        $this->assertSame('assistant', $formatted[1]['role']);
        $this->assertNull($formatted[1]['content']);
        $this->assertCount(2, $formatted[1]['tool_calls']);
        $this->assertSame('call_01', $formatted[1]['tool_calls'][0]['id']);
        $this->assertSame('shell_exec', $formatted[1]['tool_calls'][0]['function']['name']);
        $this->assertSame('call_02', $formatted[1]['tool_calls'][1]['id']);

        $this->assertSame('tool', $formatted[2]['role']);
        $this->assertSame('call_01', $formatted[2]['tool_call_id']);
        $this->assertSame('file.txt', $formatted[2]['content']);

        $this->assertSame('tool', $formatted[3]['role']);
        $this->assertSame('call_02', $formatted[3]['tool_call_id']);
        $this->assertSame('log content', $formatted[3]['content']);
    }

    public function test_send_formats_tool_call_as_assistant_with_tool_calls(): void
    {
        $capturedBody = null;

        $this->mockHttp->method('post')
            ->willReturnCallback(function (string $url, array $headers, array $body) use (&$capturedBody): array {
                $capturedBody = $body;

                return [
                    'choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']],
                    'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 2],
                ];
            });

        $messages = [
            Message::user('Run ls'),
            Message::toolUse('call_01', 'shell_exec', ['command' => 'ls']),
            Message::toolResult('call_01', 'shell_exec', 'file.txt'),
        ];

        $this->provider->send($messages);

        $formatted = $capturedBody['messages'];

        $this->assertSame('assistant', $formatted[1]['role']);
        $this->assertArrayHasKey('tool_calls', $formatted[1]);
        $this->assertSame('call_01', $formatted[1]['tool_calls'][0]['id']);

        $this->assertSame('tool', $formatted[2]['role']);
        $this->assertSame('call_01', $formatted[2]['tool_call_id']);
        $this->assertSame('file.txt', $formatted[2]['content']);
    }

    public function test_send_includes_tools_and_tool_choice(): void
    {
        $capturedBody = null;

        $this->mockHttp->method('post')
            ->willReturnCallback(function (string $url, array $headers, array $body) use (&$capturedBody): array {
                $capturedBody = $body;

                return [
                    'choices' => [['message' => ['content' => 'done'], 'finish_reason' => 'stop']],
                    'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 2],
                ];
            });

        $tools = [[
            'type' => 'function',
            'function' => ['name' => 'shell_exec', 'description' => 'Run shell', 'parameters' => []],
        ]];

        $this->provider->send([Message::user('Do it')], $tools);

        $this->assertArrayHasKey('tools', $capturedBody);
        $this->assertArrayHasKey('tool_choice', $capturedBody);
        $this->assertSame('auto', $capturedBody['tool_choice']);
    }

    public function test_send_prepends_system_message_when_system_prompt_set(): void
    {
        $capturedBody = null;
        $provider = new OpenAIProvider(
            apiKey: 'sk-openai-test',
            http: $this->mockHttp,
            parser: new StreamParser,
            systemPrompt: 'You are a senior PHP developer.',
        );

        $this->mockHttp->method('post')
            ->willReturnCallback(function (string $url, array $headers, array $body) use (&$capturedBody): array {
                $capturedBody = $body;

                return [
                    'choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']],
                    'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 2],
                ];
            });

        $provider->send([Message::user('Hello')]);

        $messages = $capturedBody['messages'];
        $this->assertSame('system', $messages[0]['role']);
        $this->assertSame('You are a senior PHP developer.', $messages[0]['content']);
        $this->assertSame('user', $messages[1]['role']);
    }

    public function test_send_does_not_prepend_system_message_when_system_prompt_empty(): void
    {
        $capturedBody = null;

        $this->mockHttp->method('post')
            ->willReturnCallback(function (string $url, array $headers, array $body) use (&$capturedBody): array {
                $capturedBody = $body;

                return [
                    'choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']],
                    'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 2],
                ];
            });

        $this->provider->send([Message::user('Hello')]);

        $messages = $capturedBody['messages'];
        $this->assertSame('user', $messages[0]['role']);
    }

    public function test_send_includes_max_tokens_override_in_request_body(): void
    {
        $capturedBody = null;
        $provider = new OpenAIProvider(
            apiKey: 'sk-openai-test',
            http: $this->mockHttp,
            parser: new StreamParser,
            maxTokens: 512,
        );

        $this->mockHttp->method('post')
            ->willReturnCallback(function (string $url, array $headers, array $body) use (&$capturedBody): array {
                $capturedBody = $body;

                return [
                    'choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']],
                    'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 2],
                ];
            });

        $provider->send([Message::user('Hello')]);

        $this->assertSame(512, $capturedBody['max_tokens']);
    }

    public function test_send_uses_default_max_tokens_when_zero(): void
    {
        $capturedBody = null;

        $this->mockHttp->method('post')
            ->willReturnCallback(function (string $url, array $headers, array $body) use (&$capturedBody): array {
                $capturedBody = $body;

                return [
                    'choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']],
                    'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 2],
                ];
            });

        $this->provider->send([Message::user('Hello')]);

        $this->assertSame(4096, $capturedBody['max_tokens']);
    }

    public function test_stream_calls_on_token_for_each_chunk(): void
    {
        $received = [];

        $this->mockHttp->method('stream')
            ->willReturnCallback(function (string $url, array $headers, array $body, callable $onChunk): void {
                $onChunk('data: {"choices":[{"delta":{"content":"Hi"},"index":0}]}');
                $onChunk('data: {"choices":[{"delta":{"content":" there"},"index":0}]}');
                $onChunk('data: [DONE]');
            });

        $result = $this->provider->stream(
            [Message::user('hello')],
            function (string $token) use (&$received): void {
                $received[] = $token;
            }
        );

        $this->assertSame(['Hi', ' there'], $received);
        $this->assertSame('Hi there', $result);
    }

    #[DataProvider('presetProvider')]
    public function test_constructs_from_preset_with_correct_identity_and_endpoint(string $slug): void
    {
        $preset = OpenAIPresets::find($slug);
        $provider = new OpenAIProvider(
            apiKey: 'k',
            http: $this->mockHttp,
            parser: new StreamParser,
            model: $preset['model'],
            endpoint: $preset['baseUrl'],
            name: $slug,
            authStyle: $preset['auth'],
        );

        $this->assertSame($slug, $provider->name());
        $this->assertSame($preset['model'], $provider->model());
        $this->assertSame($preset['baseUrl'], $provider->endpoint());
    }

    public function test_bearer_auth_sends_authorization_header(): void
    {
        $captured = null;
        $this->mockHttp->method('post')
            ->willReturnCallback(function (string $url, array $headers, array $body) use (&$captured): array {
                $captured = $headers;

                return ['choices' => [['message' => ['content' => 'ok']]], 'usage' => []];
            });

        $provider = new OpenAIProvider(apiKey: 'sk-key', http: $this->mockHttp, parser: new StreamParser);
        $provider->send([Message::user('hi')]);

        $this->assertSame('Bearer sk-key', $captured['Authorization']);
    }

    public function test_none_auth_omits_authorization_header_when_key_empty(): void
    {
        $captured = null;
        $this->mockHttp->method('post')
            ->willReturnCallback(function (string $url, array $headers, array $body) use (&$captured): array {
                $captured = $headers;

                return ['choices' => [['message' => ['content' => 'ok']]], 'usage' => []];
            });

        $provider = new OpenAIProvider(
            apiKey: '',
            http: $this->mockHttp,
            parser: new StreamParser,
            authStyle: OpenAIPresets::AUTH_NONE,
        );
        $provider->send([Message::user('hi')]);

        $this->assertArrayNotHasKey('Authorization', $captured);
    }

    public function test_none_auth_sends_authorization_header_when_key_present(): void
    {
        $captured = null;
        $this->mockHttp->method('post')
            ->willReturnCallback(function (string $url, array $headers, array $body) use (&$captured): array {
                $captured = $headers;

                return ['choices' => [['message' => ['content' => 'ok']]], 'usage' => []];
            });

        $provider = new OpenAIProvider(
            apiKey: 'proxy-key',
            http: $this->mockHttp,
            parser: new StreamParser,
            authStyle: OpenAIPresets::AUTH_NONE,
        );
        $provider->send([Message::user('hi')]);

        $this->assertSame('Bearer proxy-key', $captured['Authorization']);
    }

    public static function presetProvider(): array
    {
        return [
            'openai' => ['openai'],
            'groq' => ['groq'],
            'deepseek' => ['deepseek'],
            'mistral' => ['mistral'],
            'ollama' => ['ollama'],
        ];
    }

    public function test_web_search_tool_options_empty_without_location(): void
    {
        $this->assertSame([], $this->provider->webSearchToolOptions(new WebSearch));
    }

    public function test_web_search_tool_options_maps_location(): void
    {
        $search = (new WebSearch)->location(city: 'Pune', country: 'IN');

        $options = $this->provider->webSearchToolOptions($search);

        $this->assertSame(
            ['user_location' => ['type' => 'approximate', 'approximate' => ['city' => 'Pune', 'country' => 'IN']]],
            $options,
        );
    }

    public function test_with_provider_tools_returns_clone(): void
    {
        $clone = $this->provider->withProviderTools([new WebSearch]);

        $this->assertNotSame($this->provider, $clone);
    }

    public function test_send_adds_web_search_options_on_search_preview_model(): void
    {
        $capturedBody = null;
        $this->mockHttp->method('post')
            ->willReturnCallback(function (string $url, array $headers, array $body) use (&$capturedBody): array {
                $capturedBody = $body;

                return [
                    'choices' => [['message' => ['role' => 'assistant', 'content' => 'ok'], 'finish_reason' => 'stop']],
                    'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
                ];
            });

        $provider = (new OpenAIProvider(
            apiKey: 'sk-openai-test',
            http: $this->mockHttp,
            parser: new StreamParser,
            model: 'gpt-4o-search-preview',
        ))->withProviderTools([new WebSearch]);

        $provider->send([Message::user('hi')]);

        $this->assertArrayHasKey('web_search_options', $capturedBody);
        $this->assertSame('{}', json_encode($capturedBody['web_search_options']));
    }

    public function test_send_omits_web_search_options_on_default_model(): void
    {
        $capturedBody = null;
        $this->mockHttp->method('post')
            ->willReturnCallback(function (string $url, array $headers, array $body) use (&$capturedBody): array {
                $capturedBody = $body;

                return [
                    'choices' => [['message' => ['role' => 'assistant', 'content' => 'ok'], 'finish_reason' => 'stop']],
                    'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
                ];
            });

        $this->provider->withProviderTools([new WebSearch])->send([Message::user('hi')]);

        $this->assertArrayNotHasKey('web_search_options', $capturedBody);
    }

    public function test_send_omits_web_search_options_on_preset_style_model(): void
    {
        $capturedBody = null;
        $this->mockHttp->method('post')
            ->willReturnCallback(function (string $url, array $headers, array $body) use (&$capturedBody): array {
                $capturedBody = $body;

                return [
                    'choices' => [['message' => ['role' => 'assistant', 'content' => 'ok'], 'finish_reason' => 'stop']],
                    'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
                ];
            });

        $provider = (new OpenAIProvider(
            apiKey: 'gsk-groq-test',
            http: $this->mockHttp,
            parser: new StreamParser,
            model: 'llama-3.3-70b-versatile',
            name: 'groq',
        ))->withProviderTools([new WebSearch]);

        $provider->send([Message::user('hi')]);

        $this->assertArrayNotHasKey('web_search_options', $capturedBody);
    }

    public function test_host_only_endpoint_gains_the_chat_completions_path(): void
    {
        $provider = new OpenAIProvider(apiKey: 'k', endpoint: 'http://127.0.0.1:11434');

        $this->assertSame('http://127.0.0.1:11434/v1/chat/completions', $provider->endpoint());
    }

    public function test_host_only_endpoint_with_trailing_slash_is_normalised(): void
    {
        $provider = new OpenAIProvider(apiKey: 'k', endpoint: 'http://127.0.0.1:11434/');

        $this->assertSame('http://127.0.0.1:11434/v1/chat/completions', $provider->endpoint());
    }

    public function test_endpoint_with_a_path_is_left_alone(): void
    {
        $provider = new OpenAIProvider(apiKey: 'k', endpoint: 'https://api.groq.com/openai/v1/chat/completions');

        $this->assertSame('https://api.groq.com/openai/v1/chat/completions', $provider->endpoint());
    }

    public function test_empty_endpoint_still_falls_back_to_the_openai_default(): void
    {
        $provider = new OpenAIProvider(apiKey: 'k');

        $this->assertSame(OpenAIProvider::DEFAULT_ENDPOINT, $provider->endpoint());
    }
}
