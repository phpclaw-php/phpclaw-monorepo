<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Tests\Unit\Memory;

use PhpClaw\Memory\ArrayMemory;
use PhpClaw\Memory\PrivacyAwareMemory;
use PHPUnit\Framework\TestCase;

final class PrivacyAwareMemoryWrapTest extends TestCase
{
    public function test_set_is_no_op_when_store_messages_false(): void
    {
        $inner = new ArrayMemory;
        $wrapper = new PrivacyAwareMemory($inner, false);

        $wrapper->set('conv_1', ['history' => [['role' => 'user', 'content' => 'hello']]], 'default');

        $this->assertNull($inner->get('conv_1', 'default'));
    }

    public function test_set_persists_when_store_messages_true(): void
    {
        $inner = new ArrayMemory;
        $wrapper = new PrivacyAwareMemory($inner, true);

        $payload = ['history' => [['role' => 'user', 'content' => 'hello']]];
        $wrapper->set('conv_1', $payload, 'default');

        $this->assertSame($payload, $inner->get('conv_1', 'default'));
    }

    public function test_get_delegates_to_inner_regardless_of_gate(): void
    {
        $inner = new ArrayMemory;
        $inner->set('existing', ['title' => 'test'], 'default');

        $wrapper = new PrivacyAwareMemory($inner, false);

        $this->assertSame(['title' => 'test'], $wrapper->get('existing', 'default'));
    }

    public function test_store_messages_false_blocks_multiple_writes(): void
    {
        $inner = new ArrayMemory;
        $wrapper = new PrivacyAwareMemory($inner, false);

        $wrapper->set('c1', ['role' => 'user', 'content' => 'msg1'], 'default');
        $wrapper->set('c2', ['role' => 'assistant', 'content' => 'msg2'], 'default');

        $this->assertNull($inner->get('c1', 'default'));
        $this->assertNull($inner->get('c2', 'default'));
    }

    public function test_inner_accessor_returns_the_wrapped_driver(): void
    {
        $inner = new ArrayMemory;
        $wrapper = new PrivacyAwareMemory($inner, true);

        $this->assertSame($inner, $wrapper->inner());
    }

    public function test_forget_delegates_to_inner_when_store_messages_false(): void
    {
        $inner = new ArrayMemory;
        $inner->set('del_key', ['data' => 'x'], 'default');

        $wrapper = new PrivacyAwareMemory($inner, false);
        $wrapper->forget('del_key', 'default');

        $this->assertNull($inner->get('del_key', 'default'));
    }
}
