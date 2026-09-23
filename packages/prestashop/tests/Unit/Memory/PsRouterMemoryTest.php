<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Tests\Unit\Memory;

use PhpClaw\PrestaShop\Memory\PsDbConversationMemory;
use PhpClaw\PrestaShop\Memory\PsDbMemory;
use PhpClaw\PrestaShop\Memory\PsRouterMemory;
use PhpClaw\PrestaShop\Tests\Helpers\PsDbTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(PsRouterMemory::class)]
final class PsRouterMemoryTest extends PsDbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->resetTables([
            "CREATE TABLE IF NOT EXISTS `{$this->prefix}phpclaw_memory` (
                id          VARCHAR(26)  NOT NULL,
                namespace   VARCHAR(100) NOT NULL DEFAULT 'default',
                lookup_key  VARCHAR(255) NOT NULL,
                value       LONGTEXT     NOT NULL,
                expires_at  DATETIME     NULL,
                created_at  DATETIME     NOT NULL,
                updated_at  DATETIME     NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_ns_key (namespace, lookup_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `{$this->prefix}phpclaw_conversations` (
                id          VARCHAR(64)  NOT NULL,
                namespace   VARCHAR(100) NOT NULL DEFAULT 'default',
                id_employee INT UNSIGNED DEFAULT NULL,
                title       VARCHAR(255) DEFAULT NULL,
                metadata    LONGTEXT     DEFAULT NULL,
                created_at  DATETIME     NOT NULL,
                updated_at  DATETIME     NOT NULL,
                PRIMARY KEY (id),
                KEY idx_namespace_created (namespace, created_at),
                KEY idx_employee_ns_updated (id_employee, namespace, updated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `{$this->prefix}phpclaw_messages` (
                id              VARCHAR(64)  NOT NULL,
                conversation_id VARCHAR(64)  NOT NULL DEFAULT '',
                role            VARCHAR(20)  NOT NULL DEFAULT '',
                content         LONGTEXT     DEFAULT NULL,
                tool_name       VARCHAR(255) DEFAULT NULL,
                tool_input      TEXT         DEFAULT NULL,
                created_at      DATETIME     NOT NULL,
                PRIMARY KEY (id),
                KEY idx_conv_created (conversation_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ]);
    }

    private function makeRouter(): PsRouterMemory
    {
        return new PsRouterMemory(
            new PsDbConversationMemory($this->db, $this->prefix, true),
            new PsDbMemory($this->db, $this->prefix),
        );
    }

    private function makeManageAllRouter(): PsRouterMemory
    {
        return new PsRouterMemory(
            new PsDbConversationMemory($this->db, $this->prefix, true, 0, true),
            new PsDbMemory($this->db, $this->prefix),
        );
    }

    public function test_conversations_namespace_routes_to_conversation_memory(): void
    {
        $router = $this->makeRouter();

        $data = ['title' => 'Route Test', 'history' => []];
        $router->set('cv1', $data, 'conversations');

        $result = $router->get('cv1', 'conversations');

        self::assertIsArray($result);
        self::assertSame('Route Test', $result['title']);
    }

    public function test_default_namespace_routes_to_kv_memory(): void
    {
        $router = $this->makeRouter();

        $router->set('kv_key', 'kv_value');
        $result = $router->get('kv_key');

        self::assertSame('kv_value', $result);
    }

    public function test_forget_routes_to_the_driver_that_owns_the_namespace(): void
    {
        $router = $this->makeRouter();

        $router->set('bye', 'val');
        $router->set('cv-bye', ['title' => 'Gone', 'history' => []], 'conversations');

        $router->forget('bye');
        $router->forget('cv-bye', 'conversations');

        self::assertNull($router->get('bye'));
        self::assertNull($router->get('cv-bye', 'conversations'));
    }

    public function test_flush_routes_to_the_driver_that_owns_the_namespace(): void
    {
        $router = $this->makeManageAllRouter();

        $router->set('f1', 'v1', 'flush_ns');
        $router->set('cv-f1', ['title' => 'Flushed', 'history' => []], 'conversations');

        $router->flush('flush_ns');
        $router->flush('conversations');

        self::assertNull($router->get('f1', 'flush_ns'));
        self::assertNull($router->get('cv-f1', 'conversations'));
    }

    public function test_has_returns_true_for_existing_in_default_ns(): void
    {
        $router = $this->makeRouter();

        $router->set('present', 'yes');

        self::assertTrue($router->has('present'));
    }

    public function test_has_returns_false_for_missing_key(): void
    {
        $router = $this->makeRouter();

        self::assertFalse($router->has('missing'));
    }

    public function test_all_returns_keys_in_namespace(): void
    {
        $router = $this->makeRouter();

        $router->set('a', 'av', 'allns');
        $router->set('b', 'bv', 'allns');

        self::assertSame(['a' => 'av', 'b' => 'bv'], $router->all('allns'));
    }
}
