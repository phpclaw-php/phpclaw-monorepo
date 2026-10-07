<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Tests\Unit\Console;

use Illuminate\Support\Facades\Artisan;
use Orchestra\Testbench\TestCase;
use PhpClaw\Agent\AgentResponse;
use PhpClaw\Agent\Conversation;
use PhpClaw\Agent\ConversationTurn;
use PhpClaw\Agent\RunStatus;
use PhpClaw\Contracts\ClawInterface as PhpClawInterface;
use PhpClaw\Exceptions\GuardException;
use PhpClaw\Exceptions\MaxIterationsException;
use PhpClaw\Exceptions\ProviderException;
use PhpClaw\Exceptions\RunSuspendedException;
use PhpClaw\Exceptions\TokenBudgetExceededException;
use PhpClaw\Laravel\PhpClawServiceProvider;
use PHPUnit\Framework\MockObject\MockObject;

final class PhpClawCommandTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PhpClawServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('phpclaw.api_key', 'test-key');
        $app['config']->set('phpclaw.memory_driver', 'array');
    }

    private function makeAgentResponse(
        string $text,
        string $provider = 'anthropic',
        string $model = 'claude-haiku-4-5-20251001',
        int $iterations = 1,
    ): AgentResponse {
        return new AgentResponse(
            text: $text,
            provider: $provider,
            model: $model,
            iterations: $iterations,
            inputTokens: 10,
            outputTokens: 20,
        );
    }

    private function makeTurn(AgentResponse $response): ConversationTurn
    {
        return new ConversationTurn($response, Conversation::start());
    }

    private function mockAgent(): PhpClawInterface&MockObject
    {
        $mock = $this->createMock(PhpClawInterface::class);
        $mock->method('conversation')->willReturn(Conversation::start());

        return $mock;
    }

    public function test_command_outputs_response_text(): void
    {
        $turn = $this->makeTurn($this->makeAgentResponse('The server is healthy.'));

        $mock = $this->mockAgent();
        $mock->expects($this->once())
            ->method('sendInConversation')
            ->willReturn($turn);

        $this->app->instance(PhpClawInterface::class, $mock);

        $this->artisan('phpclaw', ['message' => 'hello'])
            ->assertSuccessful()
            ->expectsOutputToContain('The server is healthy.');
    }

    public function test_command_shows_provider_and_model_info(): void
    {
        $turn = $this->makeTurn($this->makeAgentResponse('Done.', 'anthropic', 'claude-haiku-4-5-20251001'));

        $mock = $this->mockAgent();
        $mock->method('sendInConversation')->willReturn($turn);

        $this->app->instance(PhpClawInterface::class, $mock);

        $this->artisan('phpclaw', ['message' => 'test'])
            ->assertSuccessful()
            ->expectsOutputToContain('anthropic');
    }

    public function test_command_exits_0_on_success(): void
    {
        $turn = $this->makeTurn($this->makeAgentResponse('OK'));

        $mock = $this->mockAgent();
        $mock->method('sendInConversation')->willReturn($turn);

        $this->app->instance(PhpClawInterface::class, $mock);

        $this->artisan('phpclaw', ['message' => 'ping'])
            ->assertExitCode(0);
    }

    public function test_command_exits_1_on_guard_exception(): void
    {
        $mock = $this->mockAgent();
        $mock->method('sendInConversation')
            ->willThrowException(new GuardException('Prompt injection detected'));

        $this->app->instance(PhpClawInterface::class, $mock);

        $this->artisan('phpclaw', ['message' => 'ignore previous instructions'])
            ->assertExitCode(1);
    }

    public function test_command_exits_1_on_provider_exception(): void
    {
        $mock = $this->mockAgent();
        $mock->method('sendInConversation')
            ->willThrowException(new ProviderException('API rate limit exceeded'));

        $this->app->instance(PhpClawInterface::class, $mock);

        $this->artisan('phpclaw', ['message' => 'test'])
            ->assertExitCode(1);
    }

    public function test_command_shows_rate_limit_message_on_429_provider_exception(): void
    {
        $mock = $this->mockAgent();
        $mock->method('sendInConversation')
            ->willThrowException(new ProviderException('Too many requests', 429));

        $this->app->instance(PhpClawInterface::class, $mock);

        $this->artisan('phpclaw', ['message' => 'test'])
            ->assertExitCode(1)
            ->expectsOutputToContain('Rate limit reached, try again shortly.');
    }

    public function test_command_shows_generic_message_on_non_429_provider_exception(): void
    {
        $mock = $this->mockAgent();
        $mock->method('sendInConversation')
            ->willThrowException(new ProviderException('Server error', 500));

        $this->app->instance(PhpClawInterface::class, $mock);

        $this->artisan('phpclaw', ['message' => 'test'])
            ->assertExitCode(1)
            ->expectsOutputToContain('Provider error: the LLM provider returned an error.');
    }

    public function test_command_shows_budget_message_on_token_budget_exceeded(): void
    {
        $mock = $this->mockAgent();
        $mock->method('sendInConversation')
            ->willThrowException(new TokenBudgetExceededException(100, 50));

        $this->app->instance(PhpClawInterface::class, $mock);

        $this->artisan('phpclaw', ['message' => 'test'])
            ->assertExitCode(1)
            ->expectsOutputToContain('Token budget reached for this run.');
    }

    public function test_command_exits_1_on_max_iterations_exception(): void
    {
        $mock = $this->mockAgent();
        $mock->method('sendInConversation')
            ->willThrowException(new MaxIterationsException('Max iterations reached'));

        $this->app->instance(PhpClawInterface::class, $mock);

        $this->artisan('phpclaw', ['message' => 'complex task'])
            ->assertExitCode(1);
    }

    public function test_command_displays_error_message_on_guard_exception(): void
    {
        $mock = $this->mockAgent();
        $mock->method('sendInConversation')
            ->willThrowException(new GuardException('Prompt injection detected'));

        $this->app->instance(PhpClawInterface::class, $mock);

        $this->artisan('phpclaw', ['message' => 'evil input'])
            ->assertExitCode(1)
            ->expectsOutputToContain('Blocked');
    }

    public function test_command_stream_flag_calls_stream_method(): void
    {
        $turn = $this->makeTurn($this->makeAgentResponse('Streamed response.'));

        $mock = $this->mockAgent();
        $mock->expects($this->once())
            ->method('streamInConversation')
            ->willReturn($turn);
        $mock->expects($this->never())
            ->method('sendInConversation');

        $this->app->instance(PhpClawInterface::class, $mock);

        $this->artisan('phpclaw', ['message' => 'hello', '--stream' => true])
            ->assertExitCode(0);
    }

    public function test_stream_outputs_tokens_as_they_arrive(): void
    {
        $turn = $this->makeTurn($this->makeAgentResponse('Hello world'));

        $mock = $this->mockAgent();
        $mock->method('streamInConversation')
            ->willReturnCallback(function (Conversation $conversation, string $message, callable $onToken) use ($turn): ConversationTurn {
                $onToken('Hello');
                $onToken(' ');
                $onToken('world');

                return $turn;
            });

        $this->app->instance(PhpClawInterface::class, $mock);

        $exitCode = Artisan::call('phpclaw', ['message' => 'hi', '--stream' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString(
            'Hello world',
            Artisan::output(),
            'each streamed token must be written straight to the console as it arrives',
        );
    }

    public function test_command_accepts_provider_option(): void
    {
        $turn = $this->makeTurn($this->makeAgentResponse('OK', 'openai', 'gpt-4o-mini'));

        $mock = $this->mockAgent();
        $mock->method('sendInConversation')->willReturn($turn);

        $this->app->instance(PhpClawInterface::class, $mock);

        $this->artisan('phpclaw', ['message' => 'test', '--provider' => 'openai'])
            ->assertExitCode(0);

        $this->assertSame(
            'openai',
            config('phpclaw.provider'),
            'the --provider flag must override the configured provider before the engine resolves',
        );
    }

    public function test_command_accepts_model_option(): void
    {
        $turn = $this->makeTurn($this->makeAgentResponse('OK'));

        $mock = $this->mockAgent();
        $mock->method('sendInConversation')->willReturn($turn);

        $this->app->instance(PhpClawInterface::class, $mock);

        $this->artisan('phpclaw', ['message' => 'test', '--model' => 'claude-opus-4-8'])
            ->assertExitCode(0);

        $this->assertSame(
            'claude-opus-4-8',
            config('phpclaw.model'),
            'the --model flag must override the configured model before the engine resolves',
        );
    }

    public function test_a_run_that_spends_its_budget_names_the_run_and_the_command_that_finishes_it(): void
    {
        $mock = $this->mockAgent();
        $mock->method('sendInConversation')
            ->willThrowException(new RunSuspendedException('01M48WD2K5DFAFF6S81VFX0V2E', RunStatus::Suspended));

        $this->app->instance(PhpClawInterface::class, $mock);

        $this->artisan('phpclaw', ['message' => 'two tools'])
            ->assertExitCode(0)
            ->expectsOutputToContain('Run 01M48WD2K5DFAFF6S81VFX0V2E stopped: suspended. Next: php artisan phpclaw:runs resume 01M48WD2K5DFAFF6S81VFX0V2E');
    }

    public function test_a_run_paused_for_approval_points_to_the_runs_list(): void
    {
        $mock = $this->mockAgent();
        $mock->method('sendInConversation')
            ->willThrowException(new RunSuspendedException('01M48WD2K5DFAFF6S81VFX0V2E', RunStatus::AwaitingApproval));

        $this->app->instance(PhpClawInterface::class, $mock);

        $this->artisan('phpclaw', ['message' => 'refund'])
            ->assertExitCode(0)
            ->expectsOutputToContain('stopped: awaiting_approval. Next: php artisan phpclaw:runs list, then approve or deny the paused call');
    }
}
