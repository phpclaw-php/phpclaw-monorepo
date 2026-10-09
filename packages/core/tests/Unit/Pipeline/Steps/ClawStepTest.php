<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Pipeline\Steps;

use PhpClaw\Claw;
use PhpClaw\Exceptions\PipelineException;
use PhpClaw\Guards\GuardRegistry;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Pipeline\Steps\ClawStep;
use PhpClaw\Providers\OpenAIProvider;
use PhpClaw\Skills\SkillRegistry;
use PhpClaw\Tests\Unit\Flow\Support\ScriptedHttpClient;
use PHPUnit\Framework\TestCase;
use Stringable;

final class ClawStepTest extends TestCase
{
    private ScriptedHttpClient $http;

    private ClawStep $step;

    protected function setUp(): void
    {
        HookRegistry::reset();
        GuardRegistry::reset();
        SkillRegistry::reset();

        $this->http = new ScriptedHttpClient;
        $provider = new OpenAIProvider(apiKey: 'test-key', http: $this->http, model: 'llama-3.1-8b-instant', name: 'groq');
        $claw = Claw::builder()->provider('groq')->model('llama-3.1-8b-instant')->providerOverride($provider)->build();
        $this->step = new ClawStep($claw);
    }

    protected function tearDown(): void
    {
        HookRegistry::reset();
        GuardRegistry::reset();
        SkillRegistry::reset();
    }

    private function queueReply(string $text): void
    {
        $this->http->queuePostResponse([
            'choices' => [['message' => ['role' => 'assistant', 'content' => $text]]],
            'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 4],
        ]);
    }

    private function lastSentMessage(): string
    {
        $messages = $this->http->postBodies[0]['messages'];

        return $messages[array_key_last($messages)]['content'];
    }

    public function test_run_sends_a_string_input_and_returns_the_reply_text(): void
    {
        $this->queueReply('Order A-1042 ships tomorrow.');

        $this->assertSame('Order A-1042 ships tomorrow.', $this->step->run('When does A-1042 ship?'));
        $this->assertSame('When does A-1042 ship?', $this->lastSentMessage());
    }

    public function test_run_sends_a_stringable_input_as_its_string_form(): void
    {
        $this->queueReply('Noted.');
        $input = new class implements Stringable
        {
            public function __toString(): string
            {
                return 'Log this note.';
            }
        };

        $this->assertSame('Noted.', $this->step->run($input));
        $this->assertSame('Log this note.', $this->lastSentMessage());
    }

    public function test_run_throws_before_any_provider_call_when_the_input_is_not_text(): void
    {
        try {
            $this->step->run(42);
            $this->fail('Expected a PipelineException.');
        } catch (PipelineException $exception) {
            $this->assertSame(ClawStep::class.' expects a string or Stringable input, got int.', $exception->getMessage());
        }

        $this->assertSame(0, $this->http->postCallCount);
    }

    public function test_stream_passes_every_token_to_the_callback_and_returns_the_full_reply(): void
    {
        $this->http->queueStreamLines([
            'data: {"choices":[{"delta":{"content":"Ships "}}]}',
            'data: {"choices":[{"delta":{"content":"tomorrow."}}]}',
            'data: [DONE]',
        ]);
        $tokens = [];

        $reply = $this->step->stream('When does A-1042 ship?', static function (string $token) use (&$tokens): void {
            $tokens[] = $token;
        });

        $this->assertSame(['Ships ', 'tomorrow.'], $tokens);
        $this->assertSame('Ships tomorrow.', $reply);
    }

    public function test_stream_throws_before_any_provider_call_when_the_input_is_not_text(): void
    {
        try {
            $this->step->stream(['not' => 'text'], static function (string $token): void {});
            $this->fail('Expected a PipelineException.');
        } catch (PipelineException $exception) {
            $this->assertSame(ClawStep::class.' expects a string or Stringable input, got array.', $exception->getMessage());
        }

        $this->assertSame(0, $this->http->streamCallCount);
        $this->assertSame(0, $this->http->postCallCount);
    }
}
