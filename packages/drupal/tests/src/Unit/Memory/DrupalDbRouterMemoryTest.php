<?php

declare(strict_types=1);

namespace PhpClaw\Drupal\Tests\Unit\Memory;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\Delete;
use Drupal\Core\Database\StatementInterface;
use PhpClaw\Drupal\Memory\DrupalDbConversationMemory;
use PhpClaw\Drupal\Memory\DrupalDbMemory;
use PhpClaw\Drupal\Memory\DrupalDbRouterMemory;
use PHPUnit\Framework\TestCase;

final class DrupalDbRouterMemoryTest extends TestCase
{
    private DrupalDbRouterMemory $router;

    private Connection $convDb;

    private Connection $kvDb;

    protected function setUp(): void
    {
        $this->convDb = $this->createMock(Connection::class);
        $this->kvDb = $this->createMock(Connection::class);

        $time = $this->createMock(TimeInterface::class);
        $time->method('getRequestTime')->willReturnCallback(static fn () => time());

        $config = $this->createMock(ImmutableConfig::class);
        $config->method('get')->with('store_messages')->willReturn(true);
        $configFactory = $this->createMock(ConfigFactoryInterface::class);
        $configFactory->method('get')->with('phpclaw.settings')->willReturn($config);

        $conv = new DrupalDbConversationMemory($this->convDb, $configFactory);
        $kv = new DrupalDbMemory($this->kvDb, $time);

        $this->router = new DrupalDbRouterMemory($conv, $kv);
    }

    private function buildQueryStmt(mixed $returnValue): StatementInterface
    {
        $stmt = $this->createMock(StatementInterface::class);
        $stmt->method('fetchField')->willReturn($returnValue);
        $stmt->method('fetchAssoc')->willReturn(false);
        $stmt->method('fetchCol')->willReturn([]);

        return $stmt;
    }

    public function test_get_routes_conversations_namespace_to_conv_db(): void
    {
        $stmt = $this->buildQueryStmt(false);
        $this->convDb->expects($this->atLeastOnce())->method('query')->willReturn($stmt);
        $this->kvDb->expects($this->never())->method('query');

        $result = $this->router->get('missing-id', 'conversations');

        $this->assertNull($result);
    }

    public function test_get_routes_default_namespace_to_kv_db(): void
    {
        $stmt = $this->buildQueryStmt(false);
        $this->kvDb->expects($this->once())->method('query')->willReturn($stmt);
        $this->convDb->expects($this->never())->method('query');

        $result = $this->router->get('some-key', 'default');

        $this->assertNull($result);
    }

    public function test_get_routes_arbitrary_namespace_to_kv_db(): void
    {
        $stmt = $this->buildQueryStmt('cached-val');
        $this->kvDb->expects($this->once())->method('query')->willReturn($stmt);
        $this->convDb->expects($this->never())->method('query');

        $result = $this->router->get('k', 'custom');

        $this->assertSame('cached-val', $result);
    }

    public function test_flush_routes_conversations_namespace_to_conv_db(): void
    {
        $idStmt = $this->createMock(StatementInterface::class);
        $idStmt->method('fetchCol')->willReturn([]);

        $this->convDb->expects($this->once())->method('query')->willReturn($idStmt);
        $this->kvDb->expects($this->never())->method('query');

        $this->router->flush('conversations');
    }

    public function test_flush_routes_default_namespace_to_kv_db(): void
    {
        $delete = $this->createMock(Delete::class);
        $delete->method('condition')->willReturnSelf();
        $delete->method('execute')->willReturn(0);

        $this->kvDb->expects($this->once())->method('delete')->willReturn($delete);
        $this->convDb->expects($this->never())->method('delete');

        $this->router->flush('default');
    }

    public function test_all_routes_conversations_namespace_to_conv_db(): void
    {
        $stmt = $this->createMock(StatementInterface::class);
        $stmt->method('fetchAssoc')->willReturn(false);

        $this->convDb->expects($this->once())->method('query')->willReturn($stmt);
        $this->kvDb->expects($this->never())->method('query');

        $result = $this->router->all('conversations');

        $this->assertSame([], $result);
    }

    public function test_all_routes_default_namespace_to_kv_db(): void
    {
        $stmt = $this->createMock(StatementInterface::class);
        $stmt->method('fetchAllKeyed')->willReturn([]);

        $this->kvDb->expects($this->once())->method('query')->willReturn($stmt);
        $this->convDb->expects($this->never())->method('query');

        $result = $this->router->all('default');

        $this->assertSame([], $result);
    }

    public function test_forget_routes_conversations_namespace_to_conv_db(): void
    {
        $delete = $this->createMock(Delete::class);
        $delete->method('condition')->willReturnSelf();
        $delete->method('execute')->willReturn(0);

        $ownerStmt = $this->createMock(StatementInterface::class);
        $ownerStmt->method('fetchAssoc')->willReturn(['user_id' => 0]);
        $this->convDb->method('query')->willReturn($ownerStmt);

        $this->convDb->expects($this->atLeastOnce())->method('delete')->willReturn($delete);
        $this->kvDb->expects($this->never())->method('delete');

        $this->router->forget('cid', 'conversations');
    }

    public function test_forget_routes_default_namespace_to_kv_db(): void
    {
        $delete = $this->createMock(Delete::class);
        $delete->method('condition')->willReturnSelf();
        $delete->method('execute')->willReturn(0);

        $this->kvDb->expects($this->once())->method('delete')->willReturn($delete);
        $this->convDb->expects($this->never())->method('delete');

        $this->router->forget('k', 'default');
    }

    public function test_has_routes_conversations_namespace_to_conv_db(): void
    {
        $stmt = $this->buildQueryStmt(false);
        $this->convDb->expects($this->atLeastOnce())->method('query')->willReturn($stmt);
        $this->kvDb->expects($this->never())->method('query');

        $result = $this->router->has('cid', 'conversations');

        $this->assertFalse($result);
    }

    public function test_has_routes_default_namespace_to_kv_db(): void
    {
        $stmt = $this->buildQueryStmt('value');
        $this->kvDb->expects($this->once())->method('query')->willReturn($stmt);
        $this->convDb->expects($this->never())->method('query');

        $result = $this->router->has('key', 'default');

        $this->assertTrue($result);
    }
}
