<?php

declare(strict_types=1);

namespace PhpClaw\OpenCart\Tests\Unit\Controller;

use PhpClaw\OpenCart\Plugin;
use PhpClaw\OpenCart\Tests\Helpers\OcDbTestCase;
use PhpClaw\OpenCart\Tests\Helpers\StubUser;
use PHPUnit\Framework\Attributes\DataProvider;

require_once __DIR__.'/../../stubs/oc3-native.php';
require_once __DIR__.'/../../stubs/oc4-native.php';

final class AgentLimitsControllerTest extends OcDbTestCase
{
    private const OC3_CONTROLLER = __DIR__.'/../../../upload/admin/controller/extension/module/phpclaw.php';

    private const OC4_CONTROLLER = __DIR__.'/../../../upload-oc4/admin/controller/module/phpclaw.php';

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

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetPlugin();
        $this->resetTables([
            "CREATE TABLE IF NOT EXISTS `{$this->prefix}setting` (
                setting_id INT UNSIGNED NOT NULL AUTO_INCREMENT, store_id INT UNSIGNED NOT NULL DEFAULT 0,
                code VARCHAR(128) NOT NULL, `key` VARCHAR(128) NOT NULL, value TEXT NOT NULL,
                serialized TINYINT(1) NOT NULL DEFAULT 0, PRIMARY KEY (setting_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `{$this->prefix}phpclaw_conversations` (
                id VARCHAR(26) NOT NULL, namespace VARCHAR(100) NOT NULL DEFAULT 'default', owner_id INT UNSIGNED DEFAULT NULL,
                title VARCHAR(255) DEFAULT NULL, metadata LONGTEXT DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT '2024-01-01 00:00:00', updated_at DATETIME NOT NULL DEFAULT '2024-01-01 00:00:00',
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `{$this->prefix}phpclaw_messages` (
                id VARCHAR(26) NOT NULL, conversation_id VARCHAR(26) NOT NULL, role VARCHAR(20) NOT NULL,
                content LONGTEXT DEFAULT NULL, tool_name VARCHAR(255) DEFAULT NULL, tool_input LONGTEXT DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT '2024-01-01 00:00:00', PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ]);
    }

    protected function tearDown(): void
    {
        $this->resetPlugin();
        parent::tearDown();
    }

    public static function forks(): array
    {
        return [
            'OC3' => ['ControllerExtensionModulePhpclaw', false],
            'OC4' => ['Opencart\\Admin\\Controller\\Extension\\Phpclaw\\Module\\Phpclaw', true],
        ];
    }

    #[DataProvider('forks')]
    public function test_send_answers_422_with_the_budget_message_when_the_token_budget_is_spent(string $class, bool $isOc4): void
    {
        foreach (['provider' => 'ollama', 'model' => 'qwen2.5:7b', 'max_token_budget' => '1'] as $field => $value) {
            $this->seed("INSERT INTO `{$this->prefix}setting` (store_id, code, `key`, value, serialized) VALUES (0, 'module_phpclaw', 'module_phpclaw_{$field}', '{$value}', 0)");
        }

        $route = $isOc4 ? 'extension/phpclaw/module/phpclaw' : 'extension/module/phpclaw';
        $user = new StubUser(42, ['access' => [$route], 'modify' => [$route]]);
        $post = ['message' => 'Write a long story about a dragon.'];
        [$registry, $response] = $isOc4 ? $this->oc4Registry($user, $post) : $this->oc3Registry($user, $post);

        (new $class($registry))->send();

        self::assertContains('HTTP/1.1 422 Unprocessable Content', $response->getHeaders());
        self::assertSame(['error' => 'Token budget reached for this run.'], json_decode((string) $response->getOutput(), true));
    }

    private function oc3Registry(StubUser $user, array $post): array
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

    private function oc4Registry(StubUser $user, array $post): array
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

    private function resetPlugin(): void
    {
        (new \ReflectionProperty(Plugin::class, 'instance'))->setValue(null, null);
    }
}
