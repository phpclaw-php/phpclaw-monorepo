<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Tests\Unit\Command;

use PhpClaw\Agent\AgentResponse;
use PhpClaw\Contracts\ClawInterface as PhpClawInterface;
use PhpClaw\Providers\AnthropicProvider;
use PhpClaw\Providers\ProviderCatalogue;
use PhpClaw\Symfony\Command\AboutCommand;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class AboutCommandTest extends TestCase
{
    private PhpClawInterface&MockObject $phpClaw;

    protected function setUp(): void
    {
        $this->phpClaw = $this->createMock(PhpClawInterface::class);
    }

    private function makeCommand(string $provider = 'anthropic', string $model = '', string $cloudKey = ''): CommandTester
    {
        $cmd = new AboutCommand(
            phpClaw: $this->phpClaw,
            provider: $provider,
            model: $model,
            storeMsg: true,
            maxIter: 20,
            memDriver: 'doctrine',
            cloudKey: $cloudKey,
        );

        return new CommandTester($cmd);
    }

    public function test_it_shows_adapter_info(): void
    {
        $tester = $this->makeCommand('openai', 'gpt-4o');
        $tester->execute([]);

        $display = $tester->getDisplay();
        self::assertStringContainsString('phpClaw', $display);
        self::assertStringContainsString('openai', $display);
        self::assertStringContainsString('gpt-4o', $display);
        self::assertStringContainsString('doctrine', $display);
        self::assertSame(0, $tester->getStatusCode());
    }

    public function test_it_shows_cloud_not_connected_when_key_empty(): void
    {
        $tester = $this->makeCommand(cloudKey: '');
        $tester->execute([]);

        self::assertStringContainsString('not connected', $tester->getDisplay());
    }

    public function test_it_masks_cloud_key(): void
    {
        $tester = $this->makeCommand(cloudKey: 'ABCDEFGHIJKLMNOP1234');
        $tester->execute([]);

        $display = $tester->getDisplay();
        self::assertStringContainsString('****1234', $display);
        self::assertStringNotContainsString('ABCDEFGHIJKLMNOP', $display);
    }

    public function test_it_lists_providers_dynamically(): void
    {
        ProviderCatalogue::register('test_prov_about', 'TestAboutProv', AnthropicProvider::class);

        $tester = $this->makeCommand();
        $tester->execute([]);

        self::assertStringContainsString('test_prov_about', $tester->getDisplay());
    }

    public function test_test_option_sends_probe_and_reports_ok(): void
    {
        $this->phpClaw
            ->expects($this->once())
            ->method('send')
            ->willReturn(new AgentResponse(
                text: 'OK',
                provider: 'anthropic',
                model: 'claude-haiku-4-5-20251001',
                iterations: 1,
                inputTokens: 5,
                outputTokens: 2,
            ));

        $tester = $this->makeCommand();
        $tester->execute(['--test' => true]);

        self::assertStringContainsString('OK', $tester->getDisplay());
        self::assertSame(0, $tester->getStatusCode());
    }

    public function test_test_option_returns_failure_on_exception(): void
    {
        $this->phpClaw
            ->method('send')
            ->willThrowException(new \RuntimeException('Network error'));

        $tester = $this->makeCommand();
        $tester->execute(['--test' => true]);

        self::assertSame(1, $tester->getStatusCode());
    }
}
