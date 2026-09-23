<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Tests\Unit\Memory;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase;
use PhpClaw\Laravel\Memory\DatabaseConversationMemory;
use PhpClaw\Laravel\Memory\DatabaseMemory;
use PhpClaw\Laravel\Memory\DatabaseRouterMemory;
use PhpClaw\Laravel\PhpClawServiceProvider;
use PhpClaw\Memory\Contracts\MemoryInterface;

final class DatabaseRouterMemoryTest extends TestCase
{
    use RefreshDatabase;

    private DatabaseRouterMemory $router;

    protected function getPackageProviders($app): array
    {
        return [PhpClawServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('phpclaw.api_key', 'test-key');
        $app['config']->set('phpclaw.memory_driver', 'array');
        $app['config']->set('phpclaw.store_messages', false);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->router = new DatabaseRouterMemory(
            conversations: new DatabaseConversationMemory,
            generic: new DatabaseMemory,
        );
    }

    public function test_implements_memory_interface(): void
    {
        $this->assertInstanceOf(MemoryInterface::class, $this->router);
    }

    public function test_set_conversations_namespace_writes_to_conversations_table(): void
    {
        $this->router->set('conv_1', ['history' => []], 'conversations');

        $count = DB::table('phpclaw_conversations')->where('id', 'conv_1')->count();
        $this->assertSame(1, $count, 'Should write to phpclaw_conversations for conversations namespace');
    }

    public function test_set_conversations_namespace_does_not_write_to_memory_table(): void
    {
        $this->router->set('conv_2', ['history' => []], 'conversations');

        $count = DB::table('phpclaw_memory')->count();
        $this->assertSame(0, $count, 'Should NOT write to phpclaw_memory for conversations namespace');
    }

    public function test_get_conversations_namespace_reads_from_conversations_table(): void
    {
        $this->router->set('conv_get', ['history' => [], 'title' => 'Test'], 'conversations');

        $result = $this->router->get('conv_get', 'conversations');

        $this->assertNotNull($result);
        $this->assertSame('conv_get', $result['id']);
    }

    public function test_has_conversations_namespace_checks_conversations_table(): void
    {
        $this->router->set('conv_has', ['history' => []], 'conversations');

        $this->assertTrue($this->router->has('conv_has', 'conversations'));
        $this->assertFalse($this->router->has('not_there', 'conversations'));
    }

    public function test_forget_conversations_namespace_removes_from_conversations_table(): void
    {
        $this->router->set('conv_del', ['history' => []], 'conversations');
        $this->router->forget('conv_del', 'conversations');

        $count = DB::table('phpclaw_conversations')->where('id', 'conv_del')->count();
        $this->assertSame(0, $count);
    }

    public function test_flush_conversations_namespace_clears_conversations_table(): void
    {
        $this->router->set('c1', ['history' => []], 'conversations');
        $this->router->set('c2', ['history' => []], 'conversations');

        $this->router->flush('conversations');

        $this->assertSame([], $this->router->all('conversations'));
        $this->assertSame(0, DB::table('phpclaw_conversations')->count());
    }

    public function test_all_conversations_namespace_returns_all_conversations(): void
    {
        $this->router->set('cA', ['history' => []], 'conversations');
        $this->router->set('cB', ['history' => []], 'conversations');

        $all = $this->router->all('conversations');

        $this->assertCount(2, $all);
    }

    public function test_set_default_namespace_writes_to_memory_table(): void
    {
        $this->router->set('my_key', 'my_value');

        $count = DB::table('phpclaw_memory')->where('lookup_key', 'my_key')->count();
        $this->assertSame(1, $count, 'Default namespace should write to phpclaw_memory');
    }

    public function test_set_default_namespace_does_not_write_to_conversations_table(): void
    {
        $this->router->set('my_key', 'my_value');

        $count = DB::table('phpclaw_conversations')->count();
        $this->assertSame(0, $count, 'Default namespace should NOT write to phpclaw_conversations');
    }

    public function test_get_default_namespace_reads_from_memory_table(): void
    {
        $this->router->set('kv_key', 'kv_value');

        $result = $this->router->get('kv_key');

        $this->assertSame('kv_value', $result);
    }

    public function test_has_default_namespace_checks_memory_table(): void
    {
        $this->router->set('present', 'yes');

        $this->assertTrue($this->router->has('present'));
        $this->assertFalse($this->router->has('absent'));
    }

    public function test_forget_default_namespace_removes_from_memory_table(): void
    {
        $this->router->set('removable', 'v');
        $this->router->forget('removable');

        $this->assertNull($this->router->get('removable'));
    }

    public function test_flush_default_namespace_clears_memory_table(): void
    {
        $this->router->set('a', 1);
        $this->router->set('b', 2);

        $this->router->flush();

        $this->assertSame([], $this->router->all());
    }

    public function test_all_default_namespace_returns_all_kv_entries(): void
    {
        $this->router->set('x', 10);
        $this->router->set('y', 20);

        $all = $this->router->all();

        $this->assertCount(2, $all);
        $this->assertSame(10, $all['x']);
        $this->assertSame(20, $all['y']);
    }

    public function test_custom_namespace_also_routes_to_memory_table(): void
    {
        $this->router->set('job_1', 'result_1', 'phpclaw_jobs');

        $count = DB::table('phpclaw_memory')->where('namespace', 'phpclaw_jobs')->count();
        $this->assertSame(1, $count, 'phpclaw_jobs namespace should route to phpclaw_memory');
    }

    public function test_conversations_and_default_namespaces_do_not_bleed(): void
    {
        $this->router->set('shared', ['history' => []], 'conversations');
        $this->router->set('shared', 'kv_value');

        $conv = $this->router->get('shared', 'conversations');
        $kv = $this->router->get('shared');

        $this->assertIsArray($conv, 'conversations result should be an array');
        $this->assertSame('kv_value', $kv);
    }
}
