<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Providers;

use PhpClaw\Agent\Message;
use PhpClaw\Exceptions\AdapterException;
use PhpClaw\Providers\Contracts\ProviderInterface;
use PhpClaw\Providers\Contracts\SupportsStructuredOutputInterface;
use PhpClaw\Providers\ProviderChain;
use PHPUnit\Framework\TestCase;

final class ProviderChainTest extends TestCase
{
    private function makeProvider(string $name, string $model): ProviderInterface
    {
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('name')->willReturn($name);
        $provider->method('model')->willReturn($model);

        return $provider;
    }

    private function makeStructuredProvider(string $name, string $model, bool $supportsSchema): ProviderInterface
    {
        return new class($name, $model, $supportsSchema) implements ProviderInterface, SupportsStructuredOutputInterface
        {
            public ?array $capturedSchema = null;

            public function __construct(
                private readonly string $name,
                private readonly string $model,
                private readonly bool $supports,
            ) {}

            public function send(array $messages, array $tools = []): array
            {
                return ['type' => 'text', 'text' => (string) json_encode($this->capturedSchema)];
            }

            public function stream(array $messages, callable $onToken): string
            {
                return '';
            }

            public function name(): string
            {
                return $this->name;
            }

            public function model(): string
            {
                return $this->model;
            }

            public function supportsResponseSchema(): bool
            {
                return $this->supports;
            }

            public function withResponseSchema(array $schema): static
            {
                $clone = clone $this;
                $clone->capturedSchema = $schema;

                return $clone;
            }
        };
    }

    public function test_constructor_throws_when_the_provider_list_is_empty(): void
    {
        $this->expectException(AdapterException::class);

        new ProviderChain([]);
    }

    public function test_name_and_model_report_the_first_provider_before_any_call(): void
    {
        $chain = new ProviderChain([
            $this->makeProvider('anthropic', 'claude-sonnet-5'),
            $this->makeProvider('anthropic', 'claude-haiku-4-5-20251001'),
        ]);

        self::assertSame('anthropic', $chain->name());
        self::assertSame('claude-sonnet-5', $chain->model());
    }

    public function test_a_single_provider_chain_serves_every_call_directly(): void
    {
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('name')->willReturn('anthropic');
        $provider->method('model')->willReturn('claude-sonnet-5');
        $provider->method('send')->willReturn(['type' => 'text', 'text' => 'ok']);

        $chain = new ProviderChain([$provider]);

        $response = $chain->send([Message::user('hi')]);

        self::assertSame('ok', $response['text']);
        self::assertSame('anthropic', $chain->name());
        self::assertSame('claude-sonnet-5', $chain->model());
    }

    public function test_supports_response_schema_is_true_only_when_every_provider_supports_it(): void
    {
        $chainAllNative = new ProviderChain([
            $this->makeStructuredProvider('anthropic', 'claude-sonnet-5', true),
            $this->makeStructuredProvider('anthropic', 'claude-opus-5', true),
        ]);
        self::assertTrue($chainAllNative->supportsResponseSchema());

        $chainMixed = new ProviderChain([
            $this->makeStructuredProvider('anthropic', 'claude-sonnet-5', true),
            $this->makeStructuredProvider('anthropic', 'claude-haiku-4-5-20251001', false),
        ]);
        self::assertFalse($chainMixed->supportsResponseSchema());
    }

    public function test_supports_response_schema_is_false_when_a_provider_does_not_implement_the_interface(): void
    {
        $chain = new ProviderChain([
            $this->makeStructuredProvider('anthropic', 'claude-sonnet-5', true),
            $this->makeProvider('anthropic', 'claude-haiku-4-5-20251001'),
        ]);

        self::assertFalse($chain->supportsResponseSchema());
    }

    public function test_with_response_schema_wraps_each_supporting_inner_provider_in_its_own_clone(): void
    {
        $native = $this->makeStructuredProvider('anthropic', 'claude-sonnet-5', true);
        $chain = new ProviderChain([$native]);
        $schema = ['type' => 'object', 'properties' => ['order_id' => ['type' => 'string']]];

        $clone = $chain->withResponseSchema($schema);

        self::assertNotSame($chain, $clone);
        self::assertInstanceOf(ProviderChain::class, $clone);

        $response = $clone->send([Message::user('hi')]);
        self::assertSame((string) json_encode($schema), $response['text']);
    }

    public function test_with_response_schema_leaves_a_non_supporting_inner_provider_unchanged(): void
    {
        $plain = $this->makeProvider('openai', 'gpt-4o-mini');
        $plain->method('send')->willReturn(['type' => 'text', 'text' => 'plain']);
        $chain = new ProviderChain([$plain]);

        $clone = $chain->withResponseSchema(['type' => 'object']);
        $response = $clone->send([Message::user('hi')]);

        self::assertSame('plain', $response['text']);
    }

    public function test_a_chain_of_openai_and_deepseek_is_accepted(): void
    {
        $chain = new ProviderChain([$this->makeProvider('openai', 'gpt-4o-mini'), $this->makeProvider('deepseek', 'deepseek-chat')]);

        self::assertSame('openai', $chain->name());
    }

    public function test_a_chain_of_custom_and_groq_is_accepted(): void
    {
        $chain = new ProviderChain([$this->makeProvider('custom', 'my-model'), $this->makeProvider('groq', 'llama-3.1-8b-instant')]);

        self::assertSame('custom', $chain->name());
    }

    public function test_a_chain_of_anthropic_and_deepseek_is_rejected(): void
    {
        $this->expectException(AdapterException::class);
        $this->expectExceptionMessage('ProviderChain requires every provider to share the same tool format.');

        new ProviderChain([$this->makeProvider('anthropic', 'claude-haiku-4-5-20251001'), $this->makeProvider('deepseek', 'deepseek-chat')]);
    }
}
