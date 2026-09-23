<?php

declare(strict_types=1);

namespace PhpClaw\WordPress\Tests\Unit\Memory;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PhpClaw\WordPress\Memory\FileRouterMemory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FileRouterMemory::class)]
final class FileRouterMemoryTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private string $storageDir;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->storageDir = sys_get_temp_dir().'/phpclaw_test_'.uniqid();
        mkdir($this->storageDir, 0777, true);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();

        if (is_dir($this->storageDir)) {
            array_map('unlink', glob($this->storageDir.'/*') ?: []);
            rmdir($this->storageDir);
        }

        parent::tearDown();
    }

    public function test_store_messages_defaults_to_true_when_not_configured(): void
    {
        Functions\expect('get_option')->zeroOrMoreTimes()->andReturn([]);

        $memory = new FileRouterMemory($this->storageDir);

        $memory->set('conv1', [
            'history' => [['role' => 'user', 'content' => 'Hello AI']],
            'title' => null,
        ], 'conversations');

        $stored = $memory->get('conv1', 'conversations');

        self::assertIsArray($stored);
        self::assertNotEmpty($stored['history'] ?? []);
        self::assertSame('Hello AI', $stored['history'][0]['content']);
    }

    public function test_store_messages_persists_history_when_enabled(): void
    {
        Functions\expect('get_option')
            ->zeroOrMoreTimes()
            ->andReturn(['store_messages' => '1']);

        $memory = new FileRouterMemory($this->storageDir);

        $memory->set('conv2', [
            'history' => [['role' => 'user', 'content' => 'Hello AI']],
            'title' => null,
        ], 'conversations');

        $stored = $memory->get('conv2', 'conversations');

        self::assertIsArray($stored);
        self::assertNotEmpty($stored['history'] ?? []);
        self::assertSame('Hello AI', $stored['history'][0]['content']);
    }

    public function test_it_stores_and_retrieves_non_conversation_values(): void
    {
        Functions\expect('get_option')->zeroOrMoreTimes()->andReturn([]);

        $memory = new FileRouterMemory($this->storageDir);
        $memory->set('mykey', 'myvalue', 'default');

        self::assertSame('myvalue', $memory->get('mykey', 'default'));
    }

    public function test_it_returns_null_for_missing_key(): void
    {
        Functions\expect('get_option')->zeroOrMoreTimes()->andReturn([]);

        $memory = new FileRouterMemory($this->storageDir);

        self::assertNull($memory->get('nonexistent', 'default'));
    }

    public function test_has_returns_true_for_existing_key(): void
    {
        Functions\expect('get_option')->zeroOrMoreTimes()->andReturn([]);

        $memory = new FileRouterMemory($this->storageDir);
        $memory->set('present', true, 'default');

        self::assertTrue($memory->has('present', 'default'));
    }

    public function test_forget_removes_key(): void
    {
        Functions\expect('get_option')->zeroOrMoreTimes()->andReturn([]);

        $memory = new FileRouterMemory($this->storageDir);
        $memory->set('gone', 'value', 'default');
        $memory->forget('gone', 'default');

        self::assertNull($memory->get('gone', 'default'));
    }

    public function test_flush_removes_all_keys_in_namespace(): void
    {
        Functions\expect('get_option')->zeroOrMoreTimes()->andReturn([]);

        $memory = new FileRouterMemory($this->storageDir);
        $memory->set('k1', 'v1', 'testns');
        $memory->set('k2', 'v2', 'testns');
        $memory->flush('testns');

        self::assertNull($memory->get('k1', 'testns'));
        self::assertNull($memory->get('k2', 'testns'));
    }
}
