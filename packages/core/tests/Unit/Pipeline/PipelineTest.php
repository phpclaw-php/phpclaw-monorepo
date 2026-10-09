<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Pipeline;

use PhpClaw\Claw;
use PhpClaw\Exceptions\PhpClawException;
use PhpClaw\Exceptions\PipelineException;
use PhpClaw\Guards\GuardRegistry;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Pipeline\Contracts\PipelineStepInterface;
use PhpClaw\Pipeline\Pipeline;
use PhpClaw\Pipeline\Steps\ClawStep;
use PhpClaw\Pipeline\Steps\PromptStep;
use PhpClaw\Pipeline\Steps\StructuredStep;
use PhpClaw\Providers\OpenAIProvider;
use PhpClaw\Skills\SkillRegistry;
use PhpClaw\Tests\Unit\Flow\Support\ScriptedHttpClient;
use PHPUnit\Framework\TestCase;

final class PipelineTest extends TestCase
{
    protected function setUp(): void
    {
        HookRegistry::reset();
        GuardRegistry::reset();
        SkillRegistry::reset();
    }

    protected function tearDown(): void
    {
        HookRegistry::reset();
        GuardRegistry::reset();
        SkillRegistry::reset();
    }

    private function appendStep(string $suffix): PipelineStepInterface
    {
        return new class($suffix) implements PipelineStepInterface
        {
            public function __construct(private readonly string $suffix) {}

            public function run(mixed $input): mixed
            {
                return $input.$this->suffix;
            }
        };
    }

    private function clawReplying(ScriptedHttpClient $http): Claw
    {
        $provider = new OpenAIProvider(apiKey: 'test-key', http: $http, model: 'llama-3.1-8b-instant', name: 'groq');

        return Claw::builder()->provider('groq')->model('llama-3.1-8b-instant')->providerOverride($provider)->build();
    }

    private function openAiTextResponse(string $text): array
    {
        return [
            'choices' => [['message' => ['role' => 'assistant', 'content' => $text]]],
            'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 5],
        ];
    }

    private function openAiToolCallResponse(array $toolInput): array
    {
        return [
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => [[
                        'id' => 'call_1',
                        'type' => 'function',
                        'function' => ['name' => 'respond_with_schema', 'arguments' => (string) json_encode($toolInput)],
                    ]],
                ],
            ]],
            'usage' => ['prompt_tokens' => 30, 'completion_tokens' => 10],
        ];
    }

    public function test_invoke_runs_every_step_in_the_order_it_was_piped(): void
    {
        $pipeline = Pipeline::make()
            ->pipe($this->appendStep('-a'))
            ->pipe($this->appendStep('-b'))
            ->pipe($this->appendStep('-c'));

        $this->assertSame('start-a-b-c', $pipeline->invoke('start'));
    }

    public function test_invoke_on_an_empty_pipeline_returns_the_input_unchanged(): void
    {
        $input = ['text' => 'good morning'];

        $this->assertSame($input, Pipeline::make()->invoke($input));
    }

    public function test_pipe_accepts_a_plain_callable_as_a_step(): void
    {
        $pipeline = Pipeline::make()
            ->pipe(static fn (string $text): string => strtoupper($text))
            ->pipe('trim');

        $this->assertSame('HELLO', $pipeline->invoke('  hello  '));
    }

    public function test_pipe_returns_a_new_pipeline_and_leaves_the_original_unchanged(): void
    {
        $base = Pipeline::make()->pipe($this->appendStep('-a'));

        $extended = $base->pipe($this->appendStep('-b'));

        $this->assertNotSame($base, $extended);
        $this->assertSame('x-a', $base->invoke('x'));
        $this->assertSame('x-a-b', $extended->invoke('x'));
    }

    public function test_a_prompt_and_claw_pipeline_sends_the_filled_prompt_and_returns_the_reply_text(): void
    {
        $http = new ScriptedHttpClient;
        $http->queuePostResponse($this->openAiTextResponse("  bonjour \n"));

        $translate = Pipeline::make()
            ->pipe(new PromptStep('Translate to French, reply with only the translation: {text}'))
            ->pipe(new ClawStep($this->clawReplying($http)))
            ->pipe(static fn (string $reply): string => trim($reply));

        $this->assertSame('bonjour', $translate->invoke(['text' => 'good morning']));
        $this->assertSame(1, $http->postCallCount);
        $sentMessages = $http->postBodies[0]['messages'];
        $this->assertSame(
            'Translate to French, reply with only the translation: good morning',
            $sentMessages[array_key_last($sentMessages)]['content'],
        );
    }

    public function test_a_prompt_and_structured_pipeline_returns_schema_validated_data(): void
    {
        $http = new ScriptedHttpClient;
        $http->queuePostResponse($this->openAiToolCallResponse(['order_id' => 'A-1042', 'total' => 87.5]));
        $schema = [
            'type' => 'object',
            'properties' => ['order_id' => ['type' => 'string'], 'total' => ['type' => 'number']],
            'required' => ['order_id', 'total'],
            'additionalProperties' => false,
        ];

        $extract = Pipeline::make()
            ->pipe(new PromptStep('Extract the order id and total from: {email}'))
            ->pipe(new StructuredStep($this->clawReplying($http), $schema));

        $data = $extract->invoke(['email' => 'My order A-1042 for $87.50 has not shipped.']);

        $this->assertSame(['order_id' => 'A-1042', 'total' => 87.5], $data);
        $this->assertSame($schema, $http->postBodies[0]['tools'][0]['function']['parameters']);
    }

    public function test_a_step_given_the_wrong_input_type_stops_the_pipeline_with_a_phpclaw_exception(): void
    {
        $http = new ScriptedHttpClient;
        $pipeline = Pipeline::make()->pipe(new ClawStep($this->clawReplying($http)));

        try {
            $pipeline->invoke(['not' => 'a message']);
            $this->fail('Expected a PipelineException.');
        } catch (PhpClawException $exception) {
            $this->assertInstanceOf(PipelineException::class, $exception);
        }

        $this->assertSame(0, $http->postCallCount);
    }

    private function streamingClaw(ScriptedHttpClient $http): Claw
    {
        $provider = new OpenAIProvider(apiKey: 'test-key', http: $http, model: 'gpt-4o-mini');

        return Claw::builder()->provider('openai')->model('gpt-4o-mini')->providerOverride($provider)->build();
    }

    public function test_batch_returns_one_output_per_input_keeping_the_keys(): void
    {
        $pipeline = Pipeline::make()->pipe($this->appendStep('-done'));

        $this->assertSame(
            ['first' => 'a-done', 'second' => 'b-done'],
            $pipeline->batch(['first' => 'a', 'second' => 'b']),
        );
    }

    public function test_batch_of_no_inputs_returns_an_empty_list(): void
    {
        $this->assertSame([], Pipeline::make()->pipe($this->appendStep('-x'))->batch([]));
    }

    public function test_batch_stops_at_the_first_failing_input(): void
    {
        $seen = [];
        $pipeline = Pipeline::make()->pipe(static function (string $input) use (&$seen): string {
            $seen[] = $input;
            if ($input === 'bad') {
                throw new PipelineException('bad input');
            }

            return $input;
        });

        try {
            $pipeline->batch(['ok', 'bad', 'never']);
            $this->fail('Expected a PipelineException.');
        } catch (PipelineException) {
            $this->assertSame(['ok', 'bad'], $seen);
        }
    }

    public function test_stream_runs_the_earlier_steps_then_streams_the_last_claw_step(): void
    {
        $http = new ScriptedHttpClient;
        $http->queueStreamLines([
            'data: {"choices":[{"delta":{"content":"Bon"}}]}',
            'data: {"choices":[{"delta":{"content":"jour"}}]}',
            'data: [DONE]',
        ]);
        $tokens = [];

        $reply = Pipeline::make()
            ->pipe(new PromptStep('Translate to French: {text}'))
            ->pipe(new ClawStep($this->streamingClaw($http)))
            ->stream(['text' => 'good morning'], static function (string $token) use (&$tokens): void {
                $tokens[] = $token;
            });

        $this->assertSame(['Bon', 'jour'], $tokens);
        $this->assertSame('Bonjour', $reply);
        $this->assertSame(1, $http->streamCallCount);
        $this->assertSame(0, $http->postCallCount);
        $messages = $http->streamBodies[0]['messages'];
        $this->assertSame('Translate to French: good morning', $messages[array_key_last($messages)]['content']);
    }

    public function test_stream_throws_before_running_any_step_when_the_last_step_is_not_a_claw_step(): void
    {
        $ran = false;
        $pipeline = Pipeline::make()
            ->pipe(static function (string $input) use (&$ran): string {
                $ran = true;

                return $input;
            })
            ->pipe(static fn (string $input): string => trim($input));

        try {
            $pipeline->stream('hello', static function (string $token): void {});
            $this->fail('Expected a PipelineException.');
        } catch (PipelineException $exception) {
            $this->assertSame('Pipeline::stream() needs a ClawStep as the last step.', $exception->getMessage());
        }

        $this->assertFalse($ran);
    }

    public function test_stream_on_an_empty_pipeline_throws(): void
    {
        $this->expectException(PipelineException::class);
        $this->expectExceptionMessage('Pipeline::stream() needs a ClawStep as the last step.');

        Pipeline::make()->stream('hello', static function (string $token): void {});
    }
}
