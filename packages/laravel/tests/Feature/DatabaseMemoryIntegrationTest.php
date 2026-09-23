<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase;
use PhpClaw\Claw as PhpClaw;
use PhpClaw\Laravel\Memory\DatabaseRouterMemory;
use PhpClaw\Laravel\PhpClawServiceProvider;
use PhpClaw\Memory\Contracts\MemoryInterface;
use PhpClaw\Memory\PrivacyAwareMemory;
use PhpClaw\Support\Ulid;
use PHPUnit\Framework\Attributes\Group;

/**
 * Feature tests for DatabaseMemory, uses an in-memory SQLite database.
 *
 * No API key required; these tests exercise only the DB layer.
 */
#[Group('feature')]
final class DatabaseMemoryIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [PhpClawServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('phpclaw.memory_driver', 'database');
        $app['config']->set('phpclaw.api_key', 'test-key');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate');
    }

    #[Group('feature')]
    public function test_database_memory_set_and_get(): void
    {
        /** @var MemoryInterface $memory */
        $memory = $this->app->make(MemoryInterface::class);

        $memory->set('color', 'blue');

        $this->assertSame('blue', $memory->get('color'));
    }

    #[Group('feature')]
    public function test_database_memory_namespace_isolation(): void
    {
        /** @var MemoryInterface $memory */
        $memory = $this->app->make(MemoryInterface::class);

        $memory->set('key', 'a', 'ns_a');
        $memory->set('key', 'b', 'ns_b');

        $this->assertSame('a', $memory->get('key', 'ns_a'));
        $this->assertSame('b', $memory->get('key', 'ns_b'));
    }

    #[Group('feature')]
    public function test_database_memory_ttl_expiry(): void
    {
        /** @var MemoryInterface $memory */
        $memory = $this->app->make(MemoryInterface::class);

        DB::table('phpclaw_memory')->insert([
            'id' => Ulid::generate(),
            'namespace' => 'default',
            'lookup_key' => 'expired_key',
            'value' => json_encode('stale_value'),
            'expires_at' => now()->subSecond()->toDateTimeString(),
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);

        $this->assertNull($memory->get('expired_key'));
        $this->assertFalse($memory->has('expired_key'));
    }

    #[Group('feature')]
    public function test_database_memory_upsert_overwrites(): void
    {
        /** @var MemoryInterface $memory */
        $memory = $this->app->make(MemoryInterface::class);

        $memory->set('key', 'v1');
        $memory->set('key', 'v2');

        $this->assertSame('v2', $memory->get('key'));
        $this->assertSame(1, DB::table('phpclaw_memory')->count());
    }

    #[Group('feature')]
    public function test_database_memory_forget(): void
    {
        /** @var MemoryInterface $memory */
        $memory = $this->app->make(MemoryInterface::class);

        $memory->set('x', 'val');
        $memory->forget('x');

        $this->assertNull($memory->get('x'));
    }

    #[Group('feature')]
    public function test_database_memory_flush_namespace(): void
    {
        /** @var MemoryInterface $memory */
        $memory = $this->app->make(MemoryInterface::class);

        $memory->set('a', 'alpha', 'flush_test');
        $memory->set('b', 'beta', 'flush_test');
        $memory->set('c', 'gamma', 'flush_test');

        $memory->flush('flush_test');

        $this->assertSame([], $memory->all('flush_test'));
    }

    #[Group('feature')]
    public function test_database_memory_all_returns_non_expired(): void
    {
        /** @var MemoryInterface $memory */
        $memory = $this->app->make(MemoryInterface::class);

        $memory->set('alive', 'yes', 'default', 300);

        DB::table('phpclaw_memory')->insert([
            'id' => Ulid::generate(),
            'namespace' => 'default',
            'lookup_key' => 'dead',
            'value' => json_encode('no'),
            'expires_at' => now()->subSecond()->toDateTimeString(),
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);

        $all = $memory->all('default');

        $this->assertArrayHasKey('alive', $all);
        $this->assertArrayNotHasKey('dead', $all);
    }

    #[Group('feature')]
    public function test_phpclaw_service_uses_eloquent_router_memory_when_configured(): void
    {
        /** @var PhpClaw $phpclaw */
        $phpclaw = $this->app->make(PhpClaw::class);

        $engineMemory = $phpclaw->memory();
        $this->assertInstanceOf(PrivacyAwareMemory::class, $engineMemory);
        $this->assertInstanceOf(DatabaseRouterMemory::class, $engineMemory->inner());
    }
}
