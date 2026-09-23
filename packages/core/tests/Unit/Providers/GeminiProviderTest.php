<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Providers;

use PhpClaw\Agent\Message;
use PhpClaw\Http\RawHttpClient;
use PhpClaw\Http\StreamParser;
use PhpClaw\Providers\GeminiProvider;
use PhpClaw\Providers\Tools\WebSearch;
use PHPUnit\Framework\TestCase;

final class GeminiProviderTest extends TestCase
{
    private RawHttpClient $mockHttp;

    private GeminiProvider $provider;

    protected function setUp(): void
    {
        $this->mockHttp = $this->createMock(RawHttpClient::class);
        $this->provider = new GeminiProvider(
            apiKey: 'AIza-test-key',
            http: $this->mockHttp,
            parser: new StreamParser,
        );
    }

    public function test_name_returns_gemini(): void
    {
        $this->assertSame('gemini', $this->provider->name());
    }

    public function test_model_returns_default_gemini_flash(): void
    {
        $this->assertSame('gemini-3.5-flash-lite', $this->provider->model());
    }

    public function test_model_can_be_overridden(): void
    {
        $provider = new GeminiProvider(
            apiKey: 'AIza-test',
            http: $this->mockHttp,
            parser: new StreamParser,
            model: 'gemini-1.5-pro',
        );

        $this->assertSame('gemini-1.5-pro', $provider->model());
    }

    public function test_send_returns_text_response(): void
    {
        $this->mockHttp->method('post')->willReturn([
            'candidates' => [[
                'content' => [
                    'role' => 'model',
                    'parts' => [['text' => 'Hello from Gemini!']],
                ],
                'finishReason' => 'STOP',
            ]],
            'usageMetadata' => [
                'promptTokenCount' => 8,
                'candidatesTokenCount' => 4,
            ],
        ]);

        $result = $this->provider->send([Message::user('Hi')]);

        $this->assertSame('text', $result['type']);
        $this->assertSame('Hello from Gemini!', $result['text']);
        $this->assertSame(8, $result['input_tokens']);
        $this->assertSame(4, $result['output_tokens']);
    }

    public function test_send_returns_tool_use_batch_for_single_call(): void
    {
        $this->mockHttp->method('post')->willReturn([
            'candidates' => [[
                'content' => [
                    'role' => 'model',
                    'parts' => [[
                        'functionCall' => [
                            'name' => 'shell_exec',
                            'args' => ['command' => 'ls -la'],
                        ],
                    ]],
                ],
                'finishReason' => 'STOP',
            ]],
            'usageMetadata' => [
                'promptTokenCount' => 15,
                'candidatesTokenCount' => 6,
            ],
        ]);

        $result = $this->provider->send([Message::user('List files')]);

        $this->assertSame('tool_use_batch', $result['type']);
        $this->assertCount(1, $result['calls']);
        $this->assertNotEmpty($result['calls'][0]['tool_use_id']);
        $this->assertSame('shell_exec', $result['calls'][0]['tool_name']);
        $this->assertSame(['command' => 'ls -la'], $result['calls'][0]['tool_input']);
    }

    public function test_send_returns_tool_use_batch_for_multiple_calls(): void
    {
        $this->mockHttp->method('post')->willReturn([
            'candidates' => [[
                'content' => [
                    'role' => 'model',
                    'parts' => [
                        ['functionCall' => ['name' => 'shell_exec', 'args' => ['command' => 'pwd']]],
                        ['functionCall' => ['name' => 'file_read',  'args' => ['path' => 'app.log']]],
                    ],
                ],
                'finishReason' => 'STOP',
            ]],
            'usageMetadata' => [
                'promptTokenCount' => 20,
                'candidatesTokenCount' => 10,
            ],
        ]);

        $result = $this->provider->send([Message::user('Do two things')]);

        $this->assertSame('tool_use_batch', $result['type']);
        $this->assertCount(2, $result['calls']);
        $this->assertSame('shell_exec', $result['calls'][0]['tool_name']);
        $this->assertSame('file_read', $result['calls'][1]['tool_name']);
        $this->assertSame(['path' => 'app.log'], $result['calls'][1]['tool_input']);
    }

    public function test_send_formats_user_message_as_user_role(): void
    {
        $capturedBody = null;

        $this->mockHttp->method('post')
            ->willReturnCallback(function (string $url, array $headers, array $body) use (&$capturedBody): array {
                $capturedBody = $body;

                return [
                    'candidates' => [[
                        'content' => ['parts' => [['text' => 'ok']]],
                        'finishReason' => 'STOP',
                    ]],
                    'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1],
                ];
            });

        $this->provider->send([Message::user('hello')]);

        $this->assertSame('user', $capturedBody['contents'][0]['role']);
        $this->assertSame('hello', $capturedBody['contents'][0]['parts'][0]['text']);
    }

    public function test_send_formats_assistant_message_as_model_role(): void
    {
        $capturedBody = null;

        $this->mockHttp->method('post')
            ->willReturnCallback(function (string $url, array $headers, array $body) use (&$capturedBody): array {
                $capturedBody = $body;

                return [
                    'candidates' => [[
                        'content' => ['parts' => [['text' => 'ok']]],
                        'finishReason' => 'STOP',
                    ]],
                    'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1],
                ];
            });

        $this->provider->send([
            Message::user('hello'),
            Message::assistant('hi there'),
        ]);

        $this->assertSame('model', $capturedBody['contents'][1]['role']);
        $this->assertSame('hi there', $capturedBody['contents'][1]['parts'][0]['text']);
    }

    public function test_send_formats_tool_use_as_model_function_call(): void
    {
        $capturedBody = null;

        $this->mockHttp->method('post')
            ->willReturnCallback(function (string $url, array $headers, array $body) use (&$capturedBody): array {
                $capturedBody = $body;

                return [
                    'candidates' => [[
                        'content' => ['parts' => [['text' => 'done']]],
                        'finishReason' => 'STOP',
                    ]],
                    'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1],
                ];
            });

        $this->provider->send([
            Message::user('run ls'),
            Message::toolUse('gemini-001', 'shell_exec', ['command' => 'ls']),
            Message::toolResult('gemini-001', 'shell_exec', 'file.txt'),
        ]);

        $toolUseMsg = $capturedBody['contents'][1];
        $this->assertSame('model', $toolUseMsg['role']);
        $this->assertArrayHasKey('functionCall', $toolUseMsg['parts'][0]);
        $this->assertSame('shell_exec', $toolUseMsg['parts'][0]['functionCall']['name']);

        $toolResultMsg = $capturedBody['contents'][2];
        $this->assertSame('user', $toolResultMsg['role']);
        $this->assertArrayHasKey('functionResponse', $toolResultMsg['parts'][0]);
        $this->assertSame('shell_exec', $toolResultMsg['parts'][0]['functionResponse']['name']);
        $this->assertSame('file.txt', $toolResultMsg['parts'][0]['functionResponse']['response']['content']);
    }

    public function test_send_formats_tools_as_function_declarations(): void
    {
        $capturedBody = null;

        $this->mockHttp->method('post')
            ->willReturnCallback(function (string $url, array $headers, array $body) use (&$capturedBody): array {
                $capturedBody = $body;

                return [
                    'candidates' => [[
                        'content' => ['parts' => [['text' => 'ok']]],
                        'finishReason' => 'STOP',
                    ]],
                    'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1],
                ];
            });

        $tools = [[
            'type' => 'function',
            'function' => [
                'name' => 'shell_exec',
                'description' => 'Run shell',
                'parameters' => ['type' => 'object'],
            ],
        ]];

        $this->provider->send([Message::user('Do it')], $tools);

        $this->assertArrayHasKey('tools', $capturedBody);
        $this->assertArrayHasKey('functionDeclarations', $capturedBody['tools'][0]);
        $decl = $capturedBody['tools'][0]['functionDeclarations'][0];
        $this->assertSame('shell_exec', $decl['name']);
        $this->assertSame('Run shell', $decl['description']);
    }

    public function test_send_omits_tools_key_when_no_tools(): void
    {
        $capturedBody = null;

        $this->mockHttp->method('post')
            ->willReturnCallback(function (string $url, array $headers, array $body) use (&$capturedBody): array {
                $capturedBody = $body;

                return [
                    'candidates' => [['content' => ['parts' => [['text' => 'ok']]], 'finishReason' => 'STOP']],
                    'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1],
                ];
            });

        $this->provider->send([Message::user('hi')]);

        $this->assertArrayNotHasKey('tools', $capturedBody);
    }

    public function test_send_url_contains_api_key_as_query_param(): void
    {
        $capturedUrl = null;

        $this->mockHttp->method('post')
            ->willReturnCallback(function (string $url, array $headers, array $body) use (&$capturedUrl): array {
                $capturedUrl = $url;

                return [
                    'candidates' => [['content' => ['parts' => [['text' => 'ok']]], 'finishReason' => 'STOP']],
                    'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1],
                ];
            });

        $this->provider->send([Message::user('hi')]);

        $this->assertStringContainsString('?key=AIza-test-key', $capturedUrl);
        $this->assertStringContainsString('gemini-3.5-flash-lite', $capturedUrl);
        $this->assertStringContainsString(':generateContent', $capturedUrl);
    }

    public function test_stream_calls_on_token_for_each_chunk(): void
    {
        $received = [];

        $this->mockHttp->method('stream')
            ->willReturnCallback(function (string $url, array $headers, array $body, callable $onChunk): void {
                $onChunk('data: {"candidates":[{"content":{"parts":[{"text":"Hello"}]}}]}');
                $onChunk('data: {"candidates":[{"content":{"parts":[{"text":" world"}]}}]}');
            });

        $result = $this->provider->stream(
            [Message::user('hi')],
            function (string $token) use (&$received): void {
                $received[] = $token;
            }
        );

        $this->assertSame(['Hello', ' world'], $received);
        $this->assertSame('Hello world', $result);
    }

    public function test_send_does_not_produce_numeric_content_type_header(): void
    {
        $capturedHeaders = null;

        $this->mockHttp->method('post')
            ->willReturnCallback(function (string $url, array $headers, array $body) use (&$capturedHeaders): array {
                $capturedHeaders = $headers;

                return [
                    'candidates' => [['content' => ['parts' => [['text' => 'ok']]], 'finishReason' => 'STOP']],
                    'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1],
                ];
            });

        $this->provider->send([Message::user('hi')]);

        $this->assertArrayNotHasKey(0, $capturedHeaders);
        $this->assertArrayNotHasKey('0', $capturedHeaders);
    }

    public function test_send_includes_system_instruction_when_system_prompt_set(): void
    {
        $capturedBody = null;
        $provider = new GeminiProvider(
            apiKey: 'AIza-test-key',
            http: $this->mockHttp,
            parser: new StreamParser,
            systemPrompt: 'You are a helpful Gemini assistant.',
        );

        $this->mockHttp->method('post')
            ->willReturnCallback(function (string $url, array $headers, array $body) use (&$capturedBody): array {
                $capturedBody = $body;

                return [
                    'candidates' => [['content' => ['parts' => [['text' => 'ok']]], 'finishReason' => 'STOP']],
                    'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1],
                ];
            });

        $provider->send([Message::user('Hello')]);

        $this->assertArrayHasKey('systemInstruction', $capturedBody);
        $this->assertSame(
            'You are a helpful Gemini assistant.',
            $capturedBody['systemInstruction']['parts'][0]['text'],
        );
    }

    public function test_send_omits_system_instruction_when_system_prompt_empty(): void
    {
        $capturedBody = null;

        $this->mockHttp->method('post')
            ->willReturnCallback(function (string $url, array $headers, array $body) use (&$capturedBody): array {
                $capturedBody = $body;

                return [
                    'candidates' => [['content' => ['parts' => [['text' => 'ok']]], 'finishReason' => 'STOP']],
                    'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1],
                ];
            });

        $this->provider->send([Message::user('Hello')]);

        $this->assertArrayNotHasKey('systemInstruction', $capturedBody);
    }

    public function test_send_includes_max_output_tokens_in_generation_config(): void
    {
        $capturedBody = null;
        $provider = new GeminiProvider(
            apiKey: 'AIza-test-key',
            http: $this->mockHttp,
            parser: new StreamParser,
            maxTokens: 1024,
        );

        $this->mockHttp->method('post')
            ->willReturnCallback(function (string $url, array $headers, array $body) use (&$capturedBody): array {
                $capturedBody = $body;

                return [
                    'candidates' => [['content' => ['parts' => [['text' => 'ok']]], 'finishReason' => 'STOP']],
                    'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1],
                ];
            });

        $provider->send([Message::user('Hello')]);

        $this->assertArrayHasKey('generationConfig', $capturedBody);
        $this->assertSame(1024, $capturedBody['generationConfig']['maxOutputTokens']);
    }

    public function test_send_includes_default_max_output_tokens_when_max_tokens_zero(): void
    {
        $capturedBody = null;

        $this->mockHttp->method('post')
            ->willReturnCallback(function (string $url, array $headers, array $body) use (&$capturedBody): array {
                $capturedBody = $body;

                return [
                    'candidates' => [['content' => ['parts' => [['text' => 'ok']]], 'finishReason' => 'STOP']],
                    'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1],
                ];
            });

        $this->provider->send([Message::user('Hello')]);

        $this->assertArrayHasKey('generationConfig', $capturedBody);
        $this->assertGreaterThan(0, $capturedBody['generationConfig']['maxOutputTokens']);
    }

    public function test_stream_url_contains_stream_endpoint_and_key(): void
    {
        $capturedUrl = null;

        $this->mockHttp->method('stream')
            ->willReturnCallback(function (string $url, array $headers, array $body, callable $onChunk) use (&$capturedUrl): void {
                $capturedUrl = $url;
            });

        $this->provider->stream([Message::user('hi')], fn (string $t) => null);

        $this->assertStringContainsString(':streamGenerateContent', $capturedUrl);
        $this->assertStringContainsString('key=AIza-test-key', $capturedUrl);
    }

    public function test_web_search_tool_options_encodes_as_json_object(): void
    {
        $entry = $this->provider->webSearchToolOptions(new WebSearch);

        $this->assertSame('{"google_search":{}}', json_encode($entry));
    }

    public function test_with_provider_tools_returns_clone(): void
    {
        $clone = $this->provider->withProviderTools([new WebSearch]);

        $this->assertNotSame($this->provider, $clone);
    }

    public function test_send_appends_google_search_element_on_gemini_2_model(): void
    {
        $capturedBody = null;
        $this->mockHttp->method('post')
            ->willReturnCallback(function (string $url, array $headers, array $body) use (&$capturedBody): array {
                $capturedBody = $body;

                return [
                    'candidates' => [[
                        'content' => ['role' => 'model', 'parts' => [['text' => 'ok']]],
                        'finishReason' => 'STOP',
                    ]],
                    'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1],
                ];
            });

        $provider = (new GeminiProvider(
            apiKey: 'AIza-test',
            http: $this->mockHttp,
            parser: new StreamParser,
            model: 'gemini-2.0-flash',
        ))->withProviderTools([new WebSearch]);

        $provider->send([Message::user('hi')]);

        $this->assertCount(1, $capturedBody['tools']);
        $this->assertArrayHasKey('google_search', $capturedBody['tools'][0]);
    }

    public function test_send_skips_google_search_when_function_tools_present(): void
    {
        $capturedBody = null;
        $this->mockHttp->method('post')
            ->willReturnCallback(function (string $url, array $headers, array $body) use (&$capturedBody): array {
                $capturedBody = $body;

                return [
                    'candidates' => [[
                        'content' => ['role' => 'model', 'parts' => [['text' => 'ok']]],
                        'finishReason' => 'STOP',
                    ]],
                    'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1],
                ];
            });

        $provider = (new GeminiProvider(
            apiKey: 'AIza-test',
            http: $this->mockHttp,
            parser: new StreamParser,
            model: 'gemini-2.0-flash',
        ))->withProviderTools([new WebSearch]);

        $reactTool = ['type' => 'function', 'function' => ['name' => 'shell_exec', 'description' => 'Run a command', 'parameters' => ['type' => 'object']]];

        $provider->send([Message::user('hi')], [$reactTool]);

        $this->assertCount(1, $capturedBody['tools']);
        $this->assertArrayHasKey('functionDeclarations', $capturedBody['tools'][0]);
        $this->assertStringNotContainsString('google_search', json_encode($capturedBody['tools']));
    }

    public function test_send_skips_google_search_on_gemini_1x_model(): void
    {
        $capturedBody = null;
        $this->mockHttp->method('post')
            ->willReturnCallback(function (string $url, array $headers, array $body) use (&$capturedBody): array {
                $capturedBody = $body;

                return [
                    'candidates' => [[
                        'content' => ['role' => 'model', 'parts' => [['text' => 'ok']]],
                        'finishReason' => 'STOP',
                    ]],
                    'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1],
                ];
            });

        $legacy = new GeminiProvider(
            apiKey: 'AIza-test-key',
            model: 'gemini-1.5-flash',
            http: $this->mockHttp,
            parser: new StreamParser,
        );

        $legacy->withProviderTools([new WebSearch])->send([Message::user('hi')]);

        $this->assertArrayNotHasKey('tools', $capturedBody);
    }

    public function test_sanitize_collapses_union_type_arrays(): void
    {
        $capturedBody = null;
        $this->mockHttp->method('post')
            ->willReturnCallback(function (string $url, array $headers, array $body) use (&$capturedBody): array {
                $capturedBody = $body;

                return [
                    'candidates' => [[
                        'content' => ['role' => 'model', 'parts' => [['text' => 'ok']]],
                        'finishReason' => 'STOP',
                    ]],
                    'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1],
                ];
            });

        $tool = ['type' => 'function', 'function' => [
            'name' => 'db_query',
            'description' => 'Run a query',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'params' => [
                        'type' => 'array',
                        'items' => ['type' => ['string', 'integer', 'null']],
                    ],
                ],
            ],
        ]];

        $this->provider->send([Message::user('hi')], [$tool]);

        $items = $capturedBody['tools'][0]['functionDeclarations'][0]['parameters']['properties']['params']['items'];
        $this->assertSame('string', $items['type']);
        $this->assertTrue($items['nullable']);
    }

    public function test_sanitize_preserves_property_named_type(): void
    {
        $capturedBody = null;
        $this->mockHttp->method('post')
            ->willReturnCallback(function (string $url, array $headers, array $body) use (&$capturedBody): array {
                $capturedBody = $body;

                return [
                    'candidates' => [[
                        'content' => ['role' => 'model', 'parts' => [['text' => 'ok']]],
                        'finishReason' => 'STOP',
                    ]],
                    'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1],
                ];
            });

        $tool = ['type' => 'function', 'function' => [
            'name' => 'comments',
            'description' => 'List comments',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'status' => ['type' => 'string', 'description' => 'Filter status.'],
                    'type' => ['type' => 'string', 'description' => 'Comment type filter.'],
                ],
            ],
        ]];

        $this->provider->send([Message::user('hi')], [$tool]);

        $props = $capturedBody['tools'][0]['functionDeclarations'][0]['parameters']['properties'];
        $this->assertSame(['type' => 'string', 'description' => 'Comment type filter.'], $props['type']);
    }

    public function test_sanitize_coerces_integer_enum_to_string(): void
    {
        $capturedBody = null;
        $this->mockHttp->method('post')
            ->willReturnCallback(function (string $url, array $headers, array $body) use (&$capturedBody): array {
                $capturedBody = $body;

                return [
                    'candidates' => [[
                        'content' => ['role' => 'model', 'parts' => [['text' => 'ok']]],
                        'finishReason' => 'STOP',
                    ]],
                    'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1],
                ];
            });

        $tool = ['type' => 'function', 'function' => [
            'name' => 'oc_customer',
            'description' => 'Query customers',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'newsletter' => ['type' => 'integer', 'enum' => [0, 1], 'description' => 'Newsletter flag.'],
                ],
            ],
        ]];

        $this->provider->send([Message::user('hi')], [$tool]);

        $prop = $capturedBody['tools'][0]['functionDeclarations'][0]['parameters']['properties']['newsletter'];
        $this->assertSame('string', $prop['type']);
        $this->assertSame(['0', '1'], $prop['enum']);
    }

    public function test_parse_concatenates_multiple_text_parts(): void
    {
        $this->mockHttp->method('post')->willReturn([
            'candidates' => [[
                'content' => [
                    'role' => 'model',
                    'parts' => [['text' => 'PHP 8.4 '], ['text' => 'released November 2024.']],
                ],
                'finishReason' => 'STOP',
            ]],
            'usageMetadata' => ['promptTokenCount' => 5, 'candidatesTokenCount' => 8],
        ]);

        $result = $this->provider->send([Message::user('When was PHP 8.4 released?')]);

        $this->assertSame('text', $result['type']);
        $this->assertSame('PHP 8.4 released November 2024.', $result['text']);
    }
}
