<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Tests\Unit\Controller\Adminhtml\Settings;

use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use PhpClaw\Agent\AgentResponse;
use PhpClaw\Contracts\ClawInterface as PhpClawInterface;
use PhpClaw\Exceptions\GuardException;
use PhpClaw\Exceptions\ProviderException;
use PhpClaw\Magento\Controller\Adminhtml\Settings\TestConnection;
use PhpClaw\Magento\Factory\PhpClawFactoryInterface;
use PhpClaw\Magento\Model\Config;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class TestConnectionTest extends TestCase
{
    private Context&MockObject $context;

    private JsonFactory&MockObject $jsonFactory;

    private Json&MockObject $jsonResult;

    private PhpClawFactoryInterface&MockObject $phpClawFactory;

    private PhpClawInterface&MockObject $engine;

    private Config&MockObject $config;

    private LoggerInterface&MockObject $logger;

    private TestConnection $controller;

    public ?array $lastSetData = null;

    protected function setUp(): void
    {
        $this->context = $this->createMock(Context::class);
        $this->jsonResult = $this->createMock(Json::class);
        $this->jsonFactory = $this->createMock(JsonFactory::class);
        $this->engine = $this->createMock(PhpClawInterface::class);
        $this->phpClawFactory = $this->createMock(PhpClawFactoryInterface::class);
        $this->config = $this->createMock(Config::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->jsonFactory->method('create')->willReturn($this->jsonResult);
        $this->phpClawFactory->method('create')->willReturn($this->engine);

        $self = $this;
        $this->jsonResult->method('setData')
            ->willReturnCallback(function (array $d) use ($self): Json {
                $self->lastSetData = $d;

                return $self->jsonResult;
            });

        $this->controller = new TestConnection(
            $this->context,
            $this->jsonFactory,
            $this->phpClawFactory,
            $this->config,
            $this->logger,
        );
    }

    private function makeResponse(string $text = 'OK'): AgentResponse
    {
        return new AgentResponse(
            text: $text,
            provider: 'anthropic',
            model: 'claude-haiku-4-5-20251001',
            iterations: 1,
        );
    }

    public function test_execute_returns_ok_true_on_successful_connection(): void
    {
        $this->config->method('getProvider')->willReturn('anthropic');
        $this->config->method('getApiKey')->willReturn('sk-real');
        $this->engine->method('send')->willReturn($this->makeResponse('OK'));

        $result = $this->controller->execute();

        self::assertSame($this->jsonResult, $result);
        self::assertTrue($this->lastSetData['ok']);
        self::assertSame('anthropic', $this->lastSetData['provider']);
        self::assertSame('OK', $this->lastSetData['text']);
    }

    public function test_execute_returns_ok_false_when_provider_is_empty(): void
    {
        $this->config->method('getProvider')->willReturn('');
        $this->config->method('getApiKey')->willReturn('');

        $this->engine->expects(self::never())->method('send');

        $this->controller->execute();

        self::assertFalse($this->lastSetData['ok']);
        self::assertStringContainsString('provider', strtolower($this->lastSetData['message']));
    }

    public function test_execute_returns_ok_false_when_api_key_missing_for_non_ollama(): void
    {
        $this->config->method('getProvider')->willReturn('openai');
        $this->config->method('getApiKey')->willReturn('');

        $this->engine->expects(self::never())->method('send');

        $this->controller->execute();

        self::assertFalse($this->lastSetData['ok']);
        self::assertStringContainsString('API key', $this->lastSetData['message']);
    }

    public function test_execute_skips_api_key_check_for_ollama(): void
    {
        $this->config->method('getProvider')->willReturn('ollama');
        $this->config->method('getApiKey')->willReturn('');
        $this->engine->method('send')->willReturn($this->makeResponse('OK'));

        $this->controller->execute();

        self::assertTrue($this->lastSetData['ok']);
    }

    public function test_execute_returns_ok_false_on_guard_exception(): void
    {
        $this->config->method('getProvider')->willReturn('anthropic');
        $this->config->method('getApiKey')->willReturn('sk-real');
        $this->engine->method('send')->willThrowException(new GuardException('blocked'));

        $this->controller->execute();

        self::assertFalse($this->lastSetData['ok']);
        self::assertStringContainsString('Guard', $this->lastSetData['message']);
    }

    public function test_execute_returns_ok_false_and_logs_on_provider_exception(): void
    {
        $this->config->method('getProvider')->willReturn('anthropic');
        $this->config->method('getApiKey')->willReturn('sk-real');
        $this->engine->method('send')->willThrowException(new ProviderException('API down'));

        $this->logger->expects(self::once())->method('error');

        $this->controller->execute();

        self::assertFalse($this->lastSetData['ok']);
    }

    public function test_execute_returns_ok_false_and_logs_on_unexpected_exception(): void
    {
        $this->config->method('getProvider')->willReturn('anthropic');
        $this->config->method('getApiKey')->willReturn('sk-real');
        $this->engine->method('send')->willThrowException(new \RuntimeException('boom'));

        $this->logger->expects(self::once())->method('error');

        $this->controller->execute();

        self::assertFalse($this->lastSetData['ok']);
    }

    public function test_execute_does_not_leak_exception_message_to_user(): void
    {
        $this->config->method('getProvider')->willReturn('anthropic');
        $this->config->method('getApiKey')->willReturn('sk-real');
        $this->engine->method('send')->willThrowException(new ProviderException('Secret internal detail'));

        $this->controller->execute();

        self::assertStringNotContainsString('Secret internal detail', $this->lastSetData['message']);
    }

    public function test_admin_resource_constant_is_correct(): void
    {
        self::assertSame('PhpClaw_Magento::phpclaw_settings', TestConnection::ADMIN_RESOURCE);
    }
}
