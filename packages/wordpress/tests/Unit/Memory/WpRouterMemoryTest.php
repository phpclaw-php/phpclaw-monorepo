<?php

declare(strict_types=1);

namespace PhpClaw\WordPress\Tests\Unit\Memory;

use Brain\Monkey;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PhpClaw\Memory\Contracts\MemoryInterface;
use PhpClaw\WordPress\Memory\WpRouterMemory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WpRouterMemory::class)]
final class WpRouterMemoryTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function makeRouter(): array
    {
        $conv = Mockery::mock(MemoryInterface::class);
        $generic = Mockery::mock(MemoryInterface::class);
        $router = new WpRouterMemory($conv, $generic);

        return [$router, $conv, $generic];
    }

    public function test_routes_conversations_namespace_to_conversation_driver(): void
    {
        [$router, $conv, $generic] = $this->makeRouter();

        $conv->shouldReceive('get')->once()->with('key', 'conversations')->andReturn(['id' => 'key']);
        $generic->shouldNotReceive('get');

        $result = $router->get('key', 'conversations');

        self::assertSame(['id' => 'key'], $result);
    }

    public function test_routes_default_namespace_to_generic_driver(): void
    {
        [$router, $conv, $generic] = $this->makeRouter();

        $generic->shouldReceive('get')->once()->with('key', 'default')->andReturn('value');
        $conv->shouldNotReceive('get');

        self::assertSame('value', $router->get('key', 'default'));
    }

    public function test_set_routes_to_conversation_driver_for_conversations_namespace(): void
    {
        [$router, $conv, $generic] = $this->makeRouter();

        $conv->shouldReceive('set')->once()->with('key', ['data'], 'conversations', null);
        $generic->shouldNotReceive('set');

        $router->set('key', ['data'], 'conversations');
    }

    public function test_forget_routes_to_generic_for_non_conversation_namespace(): void
    {
        [$router, $conv, $generic] = $this->makeRouter();

        $generic->shouldReceive('forget')->once()->with('key', 'phpclaw_jobs');
        $conv->shouldNotReceive('forget');

        $router->forget('key', 'phpclaw_jobs');
    }

    public function test_has_routes_correctly(): void
    {
        [$router, $conv, $generic] = $this->makeRouter();

        $conv->shouldReceive('has')->once()->with('id', 'conversations')->andReturnTrue();

        self::assertTrue($router->has('id', 'conversations'));
    }

    public function test_all_routes_conversations_to_conv_driver(): void
    {
        [$router, $conv, $generic] = $this->makeRouter();

        $conv->shouldReceive('all')->once()->with('conversations')->andReturn(['a' => 1]);

        self::assertSame(['a' => 1], $router->all('conversations'));
    }

    public function test_flush_routes_default_to_generic(): void
    {
        [$router, $conv, $generic] = $this->makeRouter();

        $generic->shouldReceive('flush')->once()->with('default');

        $router->flush('default');
    }
}
