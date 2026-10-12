<?php

declare(strict_types=1);

namespace PhpClaw\Drupal\Tests\Unit;

use PhpClaw\Drupal\PhpClawServiceFactory;
use PhpClaw\Drupal\Tests\Unit\Fixtures\BuildsAgentContext;
use PhpClaw\Memory\MemoryRegistry;
use PhpClaw\Providers\ProviderRegistry;
use PHPUnit\Framework\TestCase;

final class PhpClawServiceFactorySessionTest extends TestCase
{
    use BuildsAgentContext;

    protected function tearDown(): void
    {
        ProviderRegistry::reset();
        MemoryRegistry::reset();
    }

    public function test_create_builds_the_saved_provider_and_answers_a_turn(): void
    {
        $claw = PhpClawServiceFactory::create($this->agentContext());

        self::assertNotNull($claw);
        $turn = $claw->sendInConversation($claw->conversation(), 'hello');
        self::assertSame('reply to hello', $turn->response->text);
        self::assertSame('scripted', $claw->config()->providerName);
    }

    public function test_create_for_session_uses_the_provider_and_model_given(): void
    {
        ProviderRegistry::register('other', static fn () => new Fixtures\ScriptedProvider);

        $claw = PhpClawServiceFactory::createForSession($this->agentContext(), provider: 'other', model: 'other-model');

        self::assertNotNull($claw);
        self::assertSame('other', $claw->config()->providerName);
        self::assertSame('other-model', $claw->config()->model);
    }

    public function test_create_for_session_with_only_a_model_keeps_the_saved_provider(): void
    {
        $claw = PhpClawServiceFactory::createForSession($this->agentContext(), model: 'bigger-model');

        self::assertSame('scripted', $claw?->config()->providerName);
        self::assertSame('bigger-model', $claw?->config()->model);
    }

    public function test_create_for_session_without_overrides_uses_the_saved_settings(): void
    {
        $claw = PhpClawServiceFactory::createForSession($this->agentContext());

        self::assertSame('scripted', $claw?->config()->providerName);
        self::assertSame('scripted-model', $claw?->config()->model);
    }

    public function test_create_for_session_returns_null_for_a_provider_that_cannot_be_built(): void
    {
        self::assertNull(PhpClawServiceFactory::createForSession($this->agentContext(), provider: 'no-such-provider-slug'));
    }

    public function test_a_session_provider_drops_the_saved_custom_base_url(): void
    {
        $claw = PhpClawServiceFactory::createForSession(
            $this->agentContext(['provider' => 'custom', 'base_url' => 'https://llm.example.com/v1', 'api_key' => 'sk-test']),
            provider: 'scripted',
        );

        self::assertSame('scripted', $claw?->config()->providerName);
        self::assertSame('scripted', $claw?->config()->buildProvider()->name());
    }
}
