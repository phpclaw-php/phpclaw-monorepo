<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Pipeline\Steps;

use PhpClaw\Claw;
use PhpClaw\Exceptions\PipelineException;
use PhpClaw\Exceptions\StructuredOutputException;
use PhpClaw\Guards\GuardRegistry;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Pipeline\Steps\StructuredStep;
use PhpClaw\Providers\OpenAIProvider;
use PhpClaw\Skills\SkillRegistry;
use PhpClaw\Tests\Unit\Flow\Support\ScriptedHttpClient;
use PHPUnit\Framework\TestCase;

final class StructuredStepTest extends TestCase
{
    private const SCHEMA = [
        'type' => 'object',
        'properties' => ['order_id' => ['type' => 'string'], 'total' => ['type' => 'number']],
        'required' => ['order_id', 'total'],
        'additionalProperties' => false,
    ];

    private ScriptedHttpClient $http;

    private StructuredStep $step;

    protected function setUp(): void
    {
        HookRegistry::reset();
        GuardRegistry::reset();
        SkillRegistry::reset();

        $this->http = new ScriptedHttpClient;
        $provider = new OpenAIProvider(apiKey: 'test-key', http: $this->http, model: 'llama-3.1-8b-instant', name: 'groq');
        $claw = Claw::builder()->provider('groq')->model('llama-3.1-8b-instant')->providerOverride($provider)->maxParseRetries(0)->build();
        $this->step = new StructuredStep($claw, self::SCHEMA);
    }

    protected function tearDown(): void
    {
        HookRegistry::reset();
        GuardRegistry::reset();
        SkillRegistry::reset();
    }

    private function queueToolCall(array $arguments): void
    {
        $this->http->queuePostResponse([
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => [[
                        'id' => 'call_1',
                        'type' => 'function',
                        'function' => ['name' => 'respond_with_schema', 'arguments' => (string) json_encode($arguments)],
                    ]],
                ],
            ]],
            'usage' => ['prompt_tokens' => 30, 'completion_tokens' => 10],
        ]);
    }

    public function test_run_returns_the_validated_data_array(): void
    {
        $this->queueToolCall(['order_id' => 'A-1042', 'total' => 87.5]);

        $this->assertSame(['order_id' => 'A-1042', 'total' => 87.5], $this->step->run('Order A-1042, total $87.50.'));
        $this->assertSame(self::SCHEMA, $this->http->postBodies[0]['tools'][0]['function']['parameters']);
    }

    public function test_run_lets_the_engine_reject_a_reply_that_breaks_the_schema(): void
    {
        $this->queueToolCall(['order_id' => 'A-1042']);

        $this->expectException(StructuredOutputException::class);

        $this->step->run('Order A-1042, total $87.50.');
    }

    public function test_run_throws_before_any_provider_call_when_the_input_is_not_text(): void
    {
        try {
            $this->step->run(['order' => 'A-1042']);
            $this->fail('Expected a PipelineException.');
        } catch (PipelineException $exception) {
            $this->assertSame(StructuredStep::class.' expects a string or Stringable input, got array.', $exception->getMessage());
        }

        $this->assertSame(0, $this->http->postCallCount);
    }
}
