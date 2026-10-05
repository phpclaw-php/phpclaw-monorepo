<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Providers;

use PhpClaw\Agent\Message;
use PhpClaw\Exceptions\AdapterException;
use PhpClaw\Exceptions\ProviderException;
use PhpClaw\Providers\Contracts\ProviderInterface;
use PhpClaw\Providers\Contracts\SupportsStructuredOutputInterface;
use PhpClaw\Providers\ThrottledProvider;
use PhpClaw\Tests\Unit\Flow\Support\ArrayCache;
use PHPUnit\Framework\TestCase;

final class ThrottledProviderTest extends TestCase
{
    private function makeStructuredInner(bool $supportsSchema): ProviderInterface
    {
        return new class($supportsSchema) implements ProviderInterface, SupportsStructuredOutputInterface
        {
            public ?array $capturedSchema = null;

            public function __construct(private readonly bool $supports) {}

            public function send(array $messages, array $tools = []): array
            {
                return ['type' => 'text', 'text' => 'ok'];
            }

            public function stream(array $messages, callable $onToken): string
            {
                return '';
            }

            public function name(): string
            {
                return 'openai';
            }

            public function model(): string
            {
                return 'gpt-4o-mini';
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

    private function fakeClock(float $startMs = 0.0): object
    {
        return new class($startMs)
        {
            public array $slept = [];

            public float $clockMs;

            public function __construct(float $start)
            {
                $this->clockMs = $start;
            }

            public function now(): float
            {
                return $this->clockMs;
            }

            public function sleep(float $ms): void
            {
                $this->slept[] = $ms;
                $this->clockMs += $ms;
            }
        };
    }

    private function makeInner(string $name = 'openai', string $model = 'gpt-4o-mini'): ProviderInterface
    {
        $inner = $this->createMock(ProviderInterface::class);
        $inner->method('name')->willReturn($name);
        $inner->method('model')->willReturn($model);

        return $inner;
    }

    public function test_constructor_throws_adapter_exception_when_requests_per_minute_is_zero(): void
    {
        $this->expectException(AdapterException::class);

        new ThrottledProvider($this->makeInner(), 0);
    }

    public function test_constructor_throws_adapter_exception_when_requests_per_minute_is_negative(): void
    {
        $this->expectException(AdapterException::class);

        new ThrottledProvider($this->makeInner(), -5);
    }

    public function test_name_and_model_report_the_inner_provider(): void
    {
        $inner = $this->makeInner('groq', 'llama-3.1-8b-instant');
        $throttled = new ThrottledProvider($inner, 60);

        self::assertSame('groq', $throttled->name());
        self::assertSame('llama-3.1-8b-instant', $throttled->model());
    }

    public function test_send_succeeds_immediately_while_the_bucket_has_tokens(): void
    {
        $clock = $this->fakeClock();
        $inner = $this->makeInner();
        $inner->method('send')->willReturn(['type' => 'text', 'text' => 'ok']);
        $throttled = new ThrottledProvider($inner, 2, 30_000, $clock->now(...), $clock->sleep(...));

        $response = $throttled->send([Message::user('hi')]);

        self::assertSame('ok', $response['text']);
        self::assertSame([], $clock->slept);
    }

    public function test_a_third_immediate_call_waits_the_exact_computed_time_then_succeeds(): void
    {
        $clock = $this->fakeClock();
        $inner = $this->makeInner();
        $inner->method('send')->willReturn(['type' => 'text', 'text' => 'ok']);
        $throttled = new ThrottledProvider($inner, 2, 30_000, $clock->now(...), $clock->sleep(...));

        $throttled->send([Message::user('one')]);
        $throttled->send([Message::user('two')]);
        $response = $throttled->send([Message::user('three')]);

        self::assertSame([30_000.0], $clock->slept);
        self::assertSame('ok', $response['text']);
    }

    public function test_the_bucket_refills_after_time_passes_so_a_later_call_does_not_wait(): void
    {
        $clock = $this->fakeClock();
        $inner = $this->makeInner();
        $inner->method('send')->willReturn(['type' => 'text', 'text' => 'ok']);
        $throttled = new ThrottledProvider($inner, 2, 30_000, $clock->now(...), $clock->sleep(...));

        $throttled->send([Message::user('one')]);
        $throttled->send([Message::user('two')]);
        $clock->clockMs += 60_000;
        $throttled->send([Message::user('three')]);

        self::assertSame([], $clock->slept);
    }

    public function test_a_wait_beyond_max_wait_ms_throws_provider_exception_and_never_calls_the_inner_provider(): void
    {
        $clock = $this->fakeClock();
        $inner = $this->makeInner();
        $callCount = 0;
        $inner->method('send')->willReturnCallback(function () use (&$callCount): array {
            $callCount++;

            return ['type' => 'text', 'text' => 'ok'];
        });
        $throttled = new ThrottledProvider($inner, 2, 100, $clock->now(...), $clock->sleep(...));

        $throttled->send([Message::user('one')]);
        $throttled->send([Message::user('two')]);
        self::assertSame(2, $callCount);

        try {
            $throttled->send([Message::user('three')]);
            self::fail('Expected ProviderException.');
        } catch (ProviderException $e) {
            self::assertSame(429, $e->statusCode);
            self::assertStringContainsString('rate limit wait exceeded', $e->getMessage());
        }

        self::assertSame(2, $callCount);
        self::assertSame([], $clock->slept);
    }

    public function test_stream_also_takes_a_token_and_waits_when_the_bucket_is_empty(): void
    {
        $clock = $this->fakeClock();
        $inner = $this->makeInner();
        $inner->method('stream')->willReturn('streamed text');
        $throttled = new ThrottledProvider($inner, 1, 60_000, $clock->now(...), $clock->sleep(...));

        $throttled->stream([Message::user('one')], static function (string $token): void {});
        $text = $throttled->stream([Message::user('two')], static function (string $token): void {});

        self::assertSame([60_000.0], $clock->slept);
        self::assertSame('streamed text', $text);
    }

    public function test_supports_response_schema_delegates_to_the_inner_provider(): void
    {
        $native = new ThrottledProvider($this->makeStructuredInner(true), 60);
        self::assertTrue($native->supportsResponseSchema());

        $nonNative = new ThrottledProvider($this->makeStructuredInner(false), 60);
        self::assertFalse($nonNative->supportsResponseSchema());
    }

    public function test_supports_response_schema_is_false_when_the_inner_provider_does_not_implement_the_interface(): void
    {
        $throttled = new ThrottledProvider($this->makeInner(), 60);

        self::assertFalse($throttled->supportsResponseSchema());
    }

    public function test_with_response_schema_returns_a_different_instance_wrapping_the_inner_clone(): void
    {
        $throttled = new ThrottledProvider($this->makeStructuredInner(true), 60);

        $clone = $throttled->withResponseSchema(['type' => 'object']);

        self::assertNotSame($throttled, $clone);
        self::assertInstanceOf(ThrottledProvider::class, $clone);
    }

    public function test_with_response_schema_leaves_a_non_supporting_inner_provider_unchanged(): void
    {
        $inner = $this->makeInner();
        $inner->method('send')->willReturn(['type' => 'text', 'text' => 'plain']);
        $throttled = new ThrottledProvider($inner, 60);

        $clone = $throttled->withResponseSchema(['type' => 'object']);
        $response = $clone->send([Message::user('hi')]);

        self::assertSame('plain', $response['text']);
    }

    public function test_with_response_schema_shares_the_bucket_so_a_prior_call_still_counts_against_it(): void
    {
        $clock = $this->fakeClock();
        $throttled = new ThrottledProvider($this->makeStructuredInner(true), 1, 0, $clock->now(...), $clock->sleep(...));

        $throttled->send([Message::user('one')]);
        $clone = $throttled->withResponseSchema(['type' => 'object']);

        $this->expectException(ProviderException::class);

        $clone->send([Message::user('two')]);
    }

    public function test_a_second_instance_sharing_a_store_is_limited_by_the_first_instances_call(): void
    {
        $cache = new ArrayCache;
        $clock = $this->fakeClock();
        $inner1 = $this->makeInner();
        $inner1->method('send')->willReturn(['type' => 'text', 'text' => 'one']);
        $inner2 = $this->makeInner();
        $inner2->expects(self::never())->method('send');

        $first = new ThrottledProvider($inner1, 1, 0, $clock->now(...), $clock->sleep(...), store: $cache);
        $first->send([Message::user('one')]);

        $second = new ThrottledProvider($inner2, 1, 0, $clock->now(...), $clock->sleep(...), store: $cache);

        try {
            $second->send([Message::user('two')]);
            self::fail('Expected ProviderException.');
        } catch (ProviderException $e) {
            self::assertStringContainsString('rate limit wait exceeded', $e->getMessage());
        }
    }

    public function test_a_new_instance_refills_from_stored_state_after_the_clock_advances(): void
    {
        $cache = new ArrayCache;
        $clock = $this->fakeClock();
        $inner1 = $this->makeInner();
        $inner1->method('send')->willReturn(['type' => 'text', 'text' => 'one']);
        $inner2 = $this->makeInner();
        $inner2->method('send')->willReturn(['type' => 'text', 'text' => 'two']);

        $first = new ThrottledProvider($inner1, 1, 0, $clock->now(...), $clock->sleep(...), store: $cache);
        $first->send([Message::user('one')]);

        $clock->clockMs += 60_000;
        $second = new ThrottledProvider($inner2, 1, 0, $clock->now(...), $clock->sleep(...), store: $cache);
        $response = $second->send([Message::user('two')]);

        self::assertSame('two', $response['text']);
    }

    public function test_a_missing_stored_key_starts_full_and_the_result_is_written_with_a_120_second_ttl(): void
    {
        $cache = new ArrayCache;
        $clock = $this->fakeClock();
        $inner = $this->makeInner();
        $inner->method('send')->willReturn(['type' => 'text', 'text' => 'ok']);

        $throttled = new ThrottledProvider($inner, 1, 0, $clock->now(...), $clock->sleep(...), store: $cache);
        $response = $throttled->send([Message::user('one')]);

        self::assertSame('ok', $response['text']);
        self::assertSame([120], array_values($cache->setTtls));
    }

    public function test_without_a_store_two_instances_do_not_share_the_bucket(): void
    {
        $clock = $this->fakeClock();
        $inner1 = $this->makeInner();
        $inner1->method('send')->willReturn(['type' => 'text', 'text' => 'one']);
        $inner2 = $this->makeInner();
        $inner2->method('send')->willReturn(['type' => 'text', 'text' => 'two']);

        $first = new ThrottledProvider($inner1, 1, 0, $clock->now(...), $clock->sleep(...));
        $first->send([Message::user('one')]);

        $second = new ThrottledProvider($inner2, 1, 0, $clock->now(...), $clock->sleep(...));
        $response = $second->send([Message::user('two')]);

        self::assertSame('two', $response['text']);
    }

    public function test_with_response_schema_propagates_the_store_so_a_later_instance_sees_the_clones_usage(): void
    {
        $cache = new ArrayCache;
        $inner = $this->makeStructuredInner(true);
        $clockOne = $this->fakeClock();
        $throttled = new ThrottledProvider($inner, 1, 0, $clockOne->now(...), $clockOne->sleep(...), store: $cache);

        $clone = $throttled->withResponseSchema(['type' => 'object']);
        $clone->send([Message::user('one')]);

        $laterInner = $this->makeInner();
        $laterInner->expects(self::never())->method('send');
        $clockTwo = $this->fakeClock();
        $later = new ThrottledProvider($laterInner, 1, 0, $clockTwo->now(...), $clockTwo->sleep(...), store: $cache);

        $this->expectException(ProviderException::class);

        $later->send([Message::user('two')]);
    }
}
