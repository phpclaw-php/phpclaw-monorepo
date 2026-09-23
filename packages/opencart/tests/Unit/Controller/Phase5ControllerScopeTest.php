<?php

declare(strict_types=1);

namespace PhpClaw\OpenCart\Tests\Unit\Controller;

use PhpClaw\OpenCart\Plugin;
use PhpClaw\OpenCart\Tests\Helpers\OcDbTestCase;
use PhpClaw\OpenCart\Tests\Helpers\StubUser;

require_once __DIR__.'/../../stubs/oc3-native.php';
require_once __DIR__.'/../../stubs/oc4-native.php';

final class Phase5ControllerScopeTest extends OcDbTestCase
{
    private const OC3_CONTROLLER = __DIR__.'/../../../upload/admin/controller/extension/module/phpclaw.php';

    private const OC4_CONTROLLER = __DIR__.'/../../../upload-oc4/admin/controller/module/phpclaw.php';

    private const OC3_CLASS = 'ControllerExtensionModulePhpclaw';

    private const OC4_CLASS = 'Opencart\\Admin\\Controller\\Extension\\Phpclaw\\Module\\Phpclaw';

    private const ACTING_USER_ID = 42;

    private const FOREIGN_OWNER_ID = 999;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (! defined('DIR_SYSTEM')) {
            define('DIR_SYSTEM', sys_get_temp_dir().'/phpclaw-test-nonexistent/');
        }
        if (! defined('DIR_EXTENSION')) {
            define('DIR_EXTENSION', sys_get_temp_dir().'/phpclaw-test-nonexistent/');
        }

        require_once self::OC3_CONTROLLER;
        require_once self::OC4_CONTROLLER;
    }

    private function convDdl(): string
    {
        return "CREATE TABLE IF NOT EXISTS `{$this->prefix}phpclaw_conversations` (
            id          VARCHAR(26)  NOT NULL,
            namespace   VARCHAR(100) NOT NULL DEFAULT 'default',
            owner_id    INT UNSIGNED DEFAULT NULL,
            title       VARCHAR(255) DEFAULT NULL,
            metadata    LONGTEXT     DEFAULT NULL,
            created_at  DATETIME     NOT NULL DEFAULT '2024-01-01 00:00:00',
            updated_at  DATETIME     NOT NULL DEFAULT '2024-01-01 00:00:00',
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    }

    private function msgDdl(): string
    {
        return "CREATE TABLE IF NOT EXISTS `{$this->prefix}phpclaw_messages` (
            id              VARCHAR(26)  NOT NULL,
            conversation_id VARCHAR(26)  NOT NULL,
            role            VARCHAR(20)  NOT NULL,
            content         LONGTEXT     DEFAULT NULL,
            tool_name       VARCHAR(255) DEFAULT NULL,
            tool_input      LONGTEXT     DEFAULT NULL,
            created_at      DATETIME     NOT NULL DEFAULT '2024-01-01 00:00:00',
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    }

    private function resetPlugin(): void
    {
        $ref = new \ReflectionClass(Plugin::class);
        $prop = $ref->getProperty('instance');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetPlugin();
        $this->resetTables([$this->convDdl(), $this->msgDdl()]);
    }

    protected function tearDown(): void
    {
        $this->resetPlugin();
        parent::tearDown();
    }

    private function seedConversation(string $id, ?int $ownerId, string $title): void
    {
        $table = $this->prefix.'phpclaw_conversations';
        $ownerSql = $ownerId === null ? 'NULL' : (string) $ownerId;
        $this->seed(
            "INSERT INTO `{$table}` (id, namespace, owner_id, title, created_at, updated_at)
             VALUES ('{$id}', 'conversations', {$ownerSql}, '{$title}', NOW(), NOW())",
        );
    }

    private function seedMessage(string $id, string $conversationId, string $role, string $content): void
    {
        $table = $this->prefix.'phpclaw_messages';
        $this->seed(
            "INSERT INTO `{$table}` (id, conversation_id, role, content, created_at)
             VALUES ('{$id}', '{$conversationId}', '{$role}', '{$content}', NOW())",
        );
    }

    private function buildRegistry(StubUser $user, array $post): array
    {
        $registry = new \Registry;
        $request = new \Request;
        $request->post = $post;
        $response = new \Response;

        $registry->set('user', $user);
        $registry->set('request', $request);
        $registry->set('response', $response);
        $registry->set('db', $this->db);

        return [$registry, $response];
    }

    private function buildOc4Registry(StubUser $user, array $post): array
    {
        $registry = new \Opencart\System\Engine\Registry;
        $request = new \Opencart\System\Library\Request;
        $request->post = $post;
        $response = new \Opencart\System\Library\Response;

        $registry->set('user', $user);
        $registry->set('request', $request);
        $registry->set('response', $response);
        $registry->set('db', $this->db);

        return [$registry, $response];
    }

    private function actingUser(bool $manageAll): StubUser
    {
        $permissions = ['access' => ['extension/module/phpclaw', 'extension/phpclaw/module/phpclaw']];
        if ($manageAll) {
            $permissions['access'][] = 'extension/module/phpclaw/manage_all';
            $permissions['access'][] = 'extension/phpclaw/module/phpclaw/manage_all';
        }

        return new StubUser(self::ACTING_USER_ID, $permissions);
    }

    public function test_load_conversation_rejects_foreign_id_on_oc3(): void
    {
        $this->seedConversation('01FOREIGNCONVOC3000000001', self::FOREIGN_OWNER_ID, 'Foreign conversation');
        $this->seedMessage('01MSGOC3FOREIGN0000000001', '01FOREIGNCONVOC3000000001', 'user', 'hello');

        [$registry, $response] = $this->buildRegistry($this->actingUser(manageAll: false), [
            'conversation_id' => '01FOREIGNCONVOC3000000001',
        ]);

        $ctrl = new (self::OC3_CLASS)($registry);
        $ctrl->load_conversation();

        self::assertContains('HTTP/1.1 403 Forbidden', $response->getHeaders());
        $body = json_decode((string) $response->getOutput(), true);
        self::assertSame('You do not have permission to access this conversation.', $body['error'] ?? null);
    }

    public function test_load_conversation_rejects_foreign_id_on_oc4(): void
    {
        $this->resetPlugin();
        $this->seedConversation('01FOREIGNCONVOC4000000001', self::FOREIGN_OWNER_ID, 'Foreign conversation');
        $this->seedMessage('01MSGOC4FOREIGN0000000001', '01FOREIGNCONVOC4000000001', 'user', 'hello');

        [$registry, $response] = $this->buildOc4Registry($this->actingUser(manageAll: false), [
            'conversation_id' => '01FOREIGNCONVOC4000000001',
        ]);

        $ctrl = new (self::OC4_CLASS)($registry);
        $ctrl->load_conversation();

        self::assertContains('HTTP/1.1 403 Forbidden', $response->getHeaders());
        $body = json_decode((string) $response->getOutput(), true);
        self::assertSame('You do not have permission to access this conversation.', $body['error'] ?? null);
    }

    public function test_load_conversation_allows_own_id_on_oc3(): void
    {
        $this->seedConversation('01OWNCONVOC30000000000001', self::ACTING_USER_ID, 'My conversation');
        $this->seedMessage('01MSGOC3OWN00000000000001', '01OWNCONVOC30000000000001', 'user', 'hello there');

        [$registry, $response] = $this->buildRegistry($this->actingUser(manageAll: false), [
            'conversation_id' => '01OWNCONVOC30000000000001',
        ]);

        $ctrl = new (self::OC3_CLASS)($registry);
        $ctrl->load_conversation();

        self::assertNotContains('HTTP/1.1 403 Forbidden', $response->getHeaders());
        $body = json_decode((string) $response->getOutput(), true);
        self::assertTrue($body['success'] ?? false);
        self::assertSame('hello there', $body['messages'][0]['content'] ?? null);
    }

    public function test_load_conversation_allows_own_id_on_oc4(): void
    {
        $this->resetPlugin();
        $this->seedConversation('01OWNCONVOC40000000000001', self::ACTING_USER_ID, 'My conversation');
        $this->seedMessage('01MSGOC4OWN00000000000001', '01OWNCONVOC40000000000001', 'user', 'hello there');

        [$registry, $response] = $this->buildOc4Registry($this->actingUser(manageAll: false), [
            'conversation_id' => '01OWNCONVOC40000000000001',
        ]);

        $ctrl = new (self::OC4_CLASS)($registry);
        $ctrl->load_conversation();

        self::assertNotContains('HTTP/1.1 403 Forbidden', $response->getHeaders());
        $body = json_decode((string) $response->getOutput(), true);
        self::assertTrue($body['success'] ?? false);
        self::assertSame('hello there', $body['messages'][0]['content'] ?? null);
    }

    public function test_manage_all_bypasses_ownership_on_load_oc3(): void
    {
        $this->seedConversation('01MANAGEALLOC30000000001', self::FOREIGN_OWNER_ID, 'Someone elses conversation');
        $this->seedMessage('01MSGOC3MANAGEALL00000001', '01MANAGEALLOC30000000001', 'user', 'not mine but I can manage all');

        [$registry, $response] = $this->buildRegistry($this->actingUser(manageAll: true), [
            'conversation_id' => '01MANAGEALLOC30000000001',
        ]);

        $ctrl = new (self::OC3_CLASS)($registry);
        $ctrl->load_conversation();

        self::assertNotContains('HTTP/1.1 403 Forbidden', $response->getHeaders());
        $body = json_decode((string) $response->getOutput(), true);
        self::assertTrue($body['success'] ?? false);
    }

    public function test_manage_all_bypasses_ownership_on_load_oc4(): void
    {
        $this->resetPlugin();
        $this->seedConversation('01MANAGEALLOC40000000001', self::FOREIGN_OWNER_ID, 'Someone elses conversation');
        $this->seedMessage('01MSGOC4MANAGEALL00000001', '01MANAGEALLOC40000000001', 'user', 'not mine but I can manage all');

        [$registry, $response] = $this->buildOc4Registry($this->actingUser(manageAll: true), [
            'conversation_id' => '01MANAGEALLOC40000000001',
        ]);

        $ctrl = new (self::OC4_CLASS)($registry);
        $ctrl->load_conversation();

        self::assertNotContains('HTTP/1.1 403 Forbidden', $response->getHeaders());
        $body = json_decode((string) $response->getOutput(), true);
        self::assertTrue($body['success'] ?? false);
    }

    public function test_test_connection_denies_without_manage_all_on_oc3(): void
    {
        [$registry, $response] = $this->buildRegistry($this->actingUser(manageAll: false), []);

        $ctrl = new (self::OC3_CLASS)($registry);
        $ctrl->test_connection();

        self::assertContains('HTTP/1.1 403 Forbidden', $response->getHeaders());
        $body = json_decode((string) $response->getOutput(), true);
        self::assertSame('Permission denied.', $body['error'] ?? null);
    }

    public function test_test_connection_denies_without_manage_all_on_oc4(): void
    {
        $this->resetPlugin();
        [$registry, $response] = $this->buildOc4Registry($this->actingUser(manageAll: false), []);

        $ctrl = new (self::OC4_CLASS)($registry);
        $ctrl->test_connection();

        self::assertContains('HTTP/1.1 403 Forbidden', $response->getHeaders());
        $body = json_decode((string) $response->getOutput(), true);
        self::assertSame('Permission denied.', $body['error'] ?? null);
    }
}
