<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Memory;

use PhpClaw\Memory\ArrayMemory;
use PhpClaw\Memory\Contracts\MemoryInterface;
use PhpClaw\Memory\PrivacyAwareMemory;
use PHPUnit\Framework\TestCase;

final class PrivacyAwareMemoryTest extends TestCase
{
    public function test_implements_memory_interface(): void
    {
        $wrapper = new PrivacyAwareMemory(new ArrayMemory, true);
        $this->assertInstanceOf(MemoryInterface::class, $wrapper);
    }

    public function test_set_is_noop_when_store_messages_is_off(): void
    {
        $inner = new ArrayMemory;
        $wrapper = new PrivacyAwareMemory($inner, storeMessages: false);

        $wrapper->set('key1', 'value1');

        $this->assertNull($inner->get('key1'));
        $this->assertFalse($inner->has('key1'));
    }

    public function test_set_delegates_when_store_messages_is_on(): void
    {
        $inner = new ArrayMemory;
        $wrapper = new PrivacyAwareMemory($inner, storeMessages: true);

        $wrapper->set('key1', 'value1');

        $this->assertSame('value1', $inner->get('key1'));
        $this->assertTrue($inner->has('key1'));
    }

    public function test_get_always_delegates_regardless_of_flag(): void
    {
        $inner = new ArrayMemory;
        $inner->set('preset', 'preset-value');

        $offWrapper = new PrivacyAwareMemory($inner, storeMessages: false);
        $onWrapper = new PrivacyAwareMemory($inner, storeMessages: true);

        $this->assertSame('preset-value', $offWrapper->get('preset'));
        $this->assertSame('preset-value', $onWrapper->get('preset'));
    }

    public function test_forget_always_delegates(): void
    {
        $inner = new ArrayMemory;
        $inner->set('to-forget', 'gone');

        $wrapper = new PrivacyAwareMemory($inner, storeMessages: false);
        $wrapper->forget('to-forget');

        $this->assertNull($inner->get('to-forget'));
    }

    public function test_flush_always_delegates(): void
    {
        $inner = new ArrayMemory;
        $inner->set('a', 1);
        $inner->set('b', 2);

        $wrapper = new PrivacyAwareMemory($inner, storeMessages: false);
        $wrapper->flush();

        $this->assertSame([], $inner->all());
    }

    public function test_all_always_delegates(): void
    {
        $inner = new ArrayMemory;
        $inner->set('a', 1);
        $inner->set('b', 2);

        $wrapper = new PrivacyAwareMemory($inner, storeMessages: false);

        $this->assertSame(['a' => 1, 'b' => 2], $wrapper->all());
    }

    public function test_has_always_delegates(): void
    {
        $inner = new ArrayMemory;
        $inner->set('there', 1);

        $wrapper = new PrivacyAwareMemory($inner, storeMessages: false);

        $this->assertTrue($wrapper->has('there'));
        $this->assertFalse($wrapper->has('not-there'));
    }

    public function test_set_with_namespace_is_gated_when_off(): void
    {
        $inner = new ArrayMemory;
        $wrapper = new PrivacyAwareMemory($inner, storeMessages: false);

        $wrapper->set('k', 'v', 'conversations');

        $this->assertNull($inner->get('k', 'conversations'));
    }

    public function test_set_with_namespace_passes_through_when_on(): void
    {
        $inner = new ArrayMemory;
        $wrapper = new PrivacyAwareMemory($inner, storeMessages: true);

        $wrapper->set('k', 'v', 'conversations');

        $this->assertSame('v', $inner->get('k', 'conversations'));
    }

    public function test_set_with_ttl_passes_through_when_on(): void
    {
        $inner = new ArrayMemory;
        $wrapper = new PrivacyAwareMemory($inner, storeMessages: true);

        $wrapper->set('k', 'v', 'default', 60);

        $this->assertSame('v', $inner->get('k'));
    }

    public function test_set_with_ttl_is_gated_when_off(): void
    {
        $inner = new ArrayMemory;
        $wrapper = new PrivacyAwareMemory($inner, storeMessages: false);

        $wrapper->set('k', 'v', 'default', 60);

        $this->assertNull($inner->get('k'));
    }

    public function test_store_messages_accessor_returns_constructor_flag(): void
    {
        $on = new PrivacyAwareMemory(new ArrayMemory, true);
        $off = new PrivacyAwareMemory(new ArrayMemory, false);

        $this->assertTrue($on->storeMessages());
        $this->assertFalse($off->storeMessages());
    }

    public function test_inner_accessor_returns_wrapped_driver(): void
    {
        $inner = new ArrayMemory;
        $wrapper = new PrivacyAwareMemory($inner, true);

        $this->assertSame($inner, $wrapper->inner());
    }

    public function test_round_trip_when_on_preserves_complex_values(): void
    {
        $inner = new ArrayMemory;
        $wrapper = new PrivacyAwareMemory($inner, true);

        $payload = ['role' => 'user', 'content' => 'hello', 'tags' => ['a', 'b']];
        $wrapper->set('msg1', $payload);

        $this->assertSame($payload, $wrapper->get('msg1'));
    }
}
