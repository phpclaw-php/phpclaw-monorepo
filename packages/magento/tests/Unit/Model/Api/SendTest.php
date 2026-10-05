<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Tests\Unit\Model\Api;

use Magento\Framework\Exception\InputException;
use Magento\Framework\Webapi\Exception as WebapiException;
use PhpClaw\Agent\AgentResponse;
use PhpClaw\Agent\Conversation;
use PhpClaw\Agent\ConversationTurn;
use PhpClaw\Contracts\ClawInterface as PhpClawInterface;
use PhpClaw\Exceptions\GuardException;
use PhpClaw\Exceptions\MaxIterationsException;
use PhpClaw\Exceptions\ProviderException;
use PhpClaw\Exceptions\TokenBudgetExceededException;
use PhpClaw\Magento\Factory\PhpClawFactoryInterface;
use PhpClaw\Magento\Model\Api\Send;
use PhpClaw\Magento\Model\IdentityResolver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class SendTest extends TestCase
{
    private PhpClawFactoryInterface&MockObject $phpClawFactory;

    private PhpClawInterface&MockObject $engine;

    private LoggerInterface&MockObject $logger;

    private IdentityResolver&MockObject $identity;

    private Send $model;

    protected function setUp(): void
    {
        $this->engine = $this->createMock(PhpClawInterface::class);
        $this->phpClawFactory = $this->createMock(PhpClawFactoryInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->phpClawFactory->method('create')->willReturn($this->engine);

        $this->identity = $this->createMock(IdentityResolver::class);

        $this->model = new Send($this->phpClawFactory, $this->logger, $this->identity);
    }

    private function makeResponse(string $text = 'ok'): AgentResponse
    {
        return new AgentResponse(text: $text, provider: 'anthropic', model: 'claude-haiku-4-5-20251001', iterations: 2);
    }

    private function makeConversation(string $id = 'conv-id-01234567890123'): Conversation
    {
        return new Conversation(id: $id, history: [], createdAt: new \DateTimeImmutable);
    }

    private function makeTurn(string $text = 'ok'): ConversationTurn
    {
        return new ConversationTurn(
            response: $this->makeResponse($text),
            conversation: $this->makeConversation(),
        );
    }

    public function test_it_returns_response_object_on_success(): void
    {
        $this->engine->method('conversation')->willReturn($this->makeConversation());
        $this->engine->method('sendInConversation')->willReturn($this->makeTurn('hello back'));

        $result = $this->model->send('hello');

        self::assertSame('hello back', $result->getText());
        self::assertSame('anthropic', $result->getProvider());
        self::assertSame('claude-haiku-4-5-20251001', $result->getModel());
        self::assertSame(2, $result->getIterations());
        self::assertIsInt($result->getTokens());
    }

    public function test_it_throws_on_empty_message(): void
    {
        $this->expectException(InputException::class);

        $this->model->send('');
    }

    public function test_it_throws_on_whitespace_only_message(): void
    {
        $this->expectException(InputException::class);

        $this->model->send('   ');
    }

    public function test_it_does_not_call_factory_on_empty_message(): void
    {
        $this->phpClawFactory->expects(self::never())->method('create');

        try {
            $this->model->send('');
        } catch (InputException) {
        }
    }

    public function test_guard_exception_maps_to_422(): void
    {
        $this->engine->method('conversation')->willReturn($this->makeConversation());
        $this->engine->method('sendInConversation')->willThrowException(new GuardException('injection'));

        try {
            $this->model->send('bad input');
            self::fail('Expected WebapiException was not thrown.');
        } catch (WebapiException $e) {
            self::assertSame(422, $e->getHttpCode());
            self::assertSame('Request blocked by security guard.', $e->getMessage());
        }
    }

    public function test_provider_exception_maps_to_sanitised_500(): void
    {
        $this->engine->method('conversation')->willReturn($this->makeConversation());
        $this->engine->method('sendInConversation')->willThrowException(new ProviderException('API error'));
        $this->logger->expects(self::once())->method('error');

        try {
            $this->model->send('hello');
            self::fail('Expected WebapiException was not thrown.');
        } catch (WebapiException $e) {
            self::assertSame(500, $e->getHttpCode());
            self::assertStringNotContainsString('API error', $e->getMessage());
        }
    }

    public function test_max_iterations_exception_maps_to_sanitised_500(): void
    {
        $this->engine->method('conversation')->willReturn($this->makeConversation());
        $this->engine->method('sendInConversation')->willThrowException(new MaxIterationsException('hit cap'));

        try {
            $this->model->send('loop');
            self::fail('Expected WebapiException was not thrown.');
        } catch (WebapiException $e) {
            self::assertSame(500, $e->getHttpCode());
        }
    }

    public function test_a_web_api_caller_that_is_not_an_admin_user_is_refused_with_403(): void
    {
        $this->identity->method('isNonAdminApiCaller')->willReturn(true);
        $this->phpClawFactory->expects(self::never())->method('create');

        try {
            $this->model->send('list orders');
            self::fail('A non-admin Web API caller must be refused.');
        } catch (WebapiException $e) {
            self::assertSame(403, $e->getHttpCode());
        }
    }

    public function test_a_spent_token_budget_is_a_422_with_the_budget_message(): void
    {
        $this->engine->method('conversation')->willReturn($this->makeConversation());
        $this->engine->method('sendInConversation')->willThrowException(new TokenBudgetExceededException(1200, 1));
        $this->logger->expects(self::never())->method('error');

        try {
            $this->model->send('Write a long story');
            self::fail('Expected WebapiException was not thrown.');
        } catch (WebapiException $e) {
            self::assertSame(422, $e->getHttpCode());
            self::assertSame('Token budget reached for this run.', $e->getMessage());
        }
    }

    public function test_a_provider_rate_limit_is_a_429_with_the_rate_limit_message(): void
    {
        $this->engine->method('conversation')->willReturn($this->makeConversation());
        $this->engine->method('sendInConversation')->willThrowException(new ProviderException('rate limit wait exceeded', statusCode: 429));
        $this->logger->expects(self::never())->method('error');

        try {
            $this->model->send('hello');
            self::fail('Expected WebapiException was not thrown.');
        } catch (WebapiException $e) {
            self::assertSame(429, $e->getHttpCode());
            self::assertSame('Rate limit reached, try again shortly.', $e->getMessage());
        }
    }
}
