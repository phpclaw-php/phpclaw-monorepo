<?php

declare(strict_types=1);

namespace PhpClaw\OpenCart\Tests\Unit\Memory;

use PhpClaw\OpenCart\Memory\OcDbConversationMemory;
use PhpClaw\OpenCart\Memory\OcDbMemory;
use PhpClaw\OpenCart\Memory\OcRouterMemory;
use PhpClaw\OpenCart\Tests\Helpers\OcDbTestCase;

final class OcRouterMemoryTest extends OcDbTestCase
{
    private OcRouterMemory $router;

    private OcDbMemory $kv;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resetTables([
            "CREATE TABLE IF NOT EXISTS `{$this->prefix}phpclaw_memory` (
                id          VARCHAR(64)  NOT NULL,
                namespace   VARCHAR(100) NOT NULL DEFAULT 'default',
                lookup_key  VARCHAR(255) NOT NULL DEFAULT '',
                value       LONGTEXT     NOT NULL,
                expires_at  DATETIME     DEFAULT NULL,
                created_at  DATETIME     NOT NULL DEFAULT '2024-01-01 00:00:00',
                updated_at  DATETIME     NOT NULL DEFAULT '2024-01-01 00:00:00',
                PRIMARY KEY (id),
                UNIQUE KEY uq_ns_key (namespace, lookup_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `{$this->prefix}phpclaw_conversations` (
                id          VARCHAR(64)      NOT NULL,
                namespace   VARCHAR(100)     NOT NULL DEFAULT 'default',
                owner_id    INT UNSIGNED     DEFAULT NULL,
                title       VARCHAR(255)     DEFAULT NULL,
                metadata    LONGTEXT         DEFAULT NULL,
                created_at  DATETIME         NOT NULL DEFAULT '2024-01-01 00:00:00',
                updated_at  DATETIME         NOT NULL DEFAULT '2024-01-01 00:00:00',
                PRIMARY KEY (id),
                KEY idx_namespace_created (namespace, created_at),
                KEY idx_owner (owner_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `{$this->prefix}phpclaw_messages` (
                id              VARCHAR(64)  NOT NULL,
                conversation_id VARCHAR(64)  NOT NULL DEFAULT '',
                role            VARCHAR(20)  NOT NULL DEFAULT '',
                content         LONGTEXT     DEFAULT NULL,
                tool_name       VARCHAR(255) DEFAULT NULL,
                tool_input      TEXT         DEFAULT NULL,
                created_at      DATETIME     NOT NULL DEFAULT '2024-01-01 00:00:00',
                PRIMARY KEY (id),
                KEY idx_conv_created (conversation_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ]);

        $conv = new OcDbConversationMemory($this->db, $this->prefix, true, 1, true);
        $this->kv = new OcDbMemory($this->db, $this->prefix);
        $this->router = new OcRouterMemory($conv, $this->kv);
    }

    public function test_default_namespace_routes_to_kv_driver(): void
    {
        $this->kv->set('foo', 'bar', 'default');
        $result = $this->router->get('foo', 'default');
        self::assertSame('bar', $result);
    }

    public function test_kv_set_via_router(): void
    {
        $this->router->set('hello', 'world', 'default');
        self::assertSame('world', $this->kv->get('hello', 'default'));
    }

    public function test_conversations_namespace_returns_null_for_missing_key(): void
    {
        $result = $this->router->get('nonexistent', 'conversations');
        self::assertNull($result);
    }

    public function test_all_in_default_namespace(): void
    {
        $this->router->set('k1', 'v1', 'default');
        $this->router->set('k2', 'v2', 'default');
        $all = $this->router->all('default');
        self::assertCount(2, $all);
    }

    public function test_forget_via_router(): void
    {
        $this->router->set('del', 'me', 'default');
        $this->router->forget('del', 'default');
        self::assertNull($this->router->get('del', 'default'));
    }

    public function test_flush_via_router_clears_namespace(): void
    {
        $this->router->set('x', 'one', 'flush-ns');
        $this->router->set('y', 'two', 'flush-ns');
        $this->router->flush('flush-ns');
        self::assertNull($this->router->get('x', 'flush-ns'));
        self::assertNull($this->router->get('y', 'flush-ns'));
    }

    public function test_has_via_router_returns_true_when_key_exists(): void
    {
        $this->router->set('present', 'value', 'default');
        self::assertTrue($this->router->has('present', 'default'));
    }

    public function test_has_via_router_returns_false_when_key_missing(): void
    {
        self::assertFalse($this->router->has('absent', 'default'));
    }

    public function test_flush_conversations_namespace_via_router(): void
    {
        $id = 'conv-router-flush-aabb-ccdd-0001';
        $this->router->set($id, ['title' => 'F', 'history' => []], 'conversations');
        $this->router->flush('conversations');
        self::assertNull($this->router->get($id, 'conversations'));
    }

    public function test_has_conversations_namespace_via_router(): void
    {
        $id = 'conv-router-has-aabb-ccdd-0001';
        self::assertFalse($this->router->has($id, 'conversations'));
        $this->router->set($id, ['title' => 'H', 'history' => []], 'conversations');
        self::assertTrue($this->router->has($id, 'conversations'));
    }
}
