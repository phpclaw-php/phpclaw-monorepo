<?php

declare(strict_types=1);

namespace PhpClaw\OpenCart\Tests\Unit\Controller;

use PhpClaw\OpenCart\Plugin;
use PhpClaw\OpenCart\Tests\Helpers\OcDbTestCase;
use PhpClaw\OpenCart\Tests\Helpers\StubUser;
use PHPUnit\Framework\Attributes\DataProvider;

require_once __DIR__.'/../../stubs/oc3-native.php';
require_once __DIR__.'/../../stubs/oc4-native.php';

final class ControllerParityTest extends OcDbTestCase
{
    private const OC3_CONTROLLER = __DIR__.'/../../../upload/admin/controller/extension/module/phpclaw.php';

    private const OC4_CONTROLLER = __DIR__.'/../../../upload-oc4/admin/controller/module/phpclaw.php';

    private const OC3_CLASS = 'ControllerExtensionModulePhpclaw';

    private const OC4_CLASS = 'Opencart\\Admin\\Controller\\Extension\\Phpclaw\\Module\\Phpclaw';

    private const ACTING_USER_ID = 77;

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

    private function settingDdl(): string
    {
        return "CREATE TABLE IF NOT EXISTS `{$this->prefix}setting` (
            setting_id  INT UNSIGNED NOT NULL AUTO_INCREMENT,
            store_id    INT UNSIGNED NOT NULL DEFAULT 0,
            code        VARCHAR(128) NOT NULL,
            `key`       VARCHAR(128) NOT NULL,
            value       TEXT         NOT NULL,
            serialized  TINYINT(1)   NOT NULL DEFAULT 0,
            PRIMARY KEY (setting_id)
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
        $this->resetTables([$this->settingDdl()]);
    }

    protected function tearDown(): void
    {
        $this->resetPlugin();
        parent::tearDown();
    }

    private function seedSetting(string $field, string $value): void
    {
        $table = $this->prefix.'setting';
        $key = 'module_phpclaw_'.$field;
        $this->seed(
            "INSERT INTO `{$table}` (store_id, code, `key`, value, serialized)
             VALUES (0, 'module_phpclaw', '{$key}', '{$value}', 0)",
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

    private function manageAllUser(): StubUser
    {
        return new StubUser(self::ACTING_USER_ID, [
            'access' => [
                'extension/module/phpclaw/manage_all',
                'extension/phpclaw/module/phpclaw/manage_all',
            ],
        ]);
    }

    public static function forks(): array
    {
        return [
            'OC3' => [self::OC3_CLASS, false],
            'OC4' => [self::OC4_CLASS, true],
        ];
    }

    private function bootController(string $class, bool $isOc4): array
    {
        [$registry, $response] = $isOc4
            ? $this->buildOc4Registry($this->manageAllUser(), [])
            : $this->buildRegistry($this->manageAllUser(), []);

        return [new $class($registry), $response];
    }

    public function test_both_controllers_exist(): void
    {
        self::assertFileExists(self::OC3_CONTROLLER, 'OC3 controller missing.');
        self::assertFileExists(self::OC4_CONTROLLER, 'OC4 controller missing.');
    }

    #[DataProvider('forks')]
    public function test_test_connection_guards_empty_provider(string $class, bool $isOc4): void
    {
        [$ctrl, $response] = $this->bootController($class, $isOc4);

        $ctrl->test_connection();

        $body = json_decode((string) $response->getOutput(), true);
        self::assertSame(
            'No provider selected. Choose a provider in Settings and save.',
            $body['error'] ?? null,
        );
    }

    #[DataProvider('forks')]
    public function test_test_connection_guards_missing_api_key(string $class, bool $isOc4): void
    {
        $this->seedSetting('provider', 'anthropic');
        $this->seedSetting('model', 'claude-haiku-4-5-20251001');

        [$ctrl, $response] = $this->bootController($class, $isOc4);

        $ctrl->test_connection();

        $body = json_decode((string) $response->getOutput(), true);
        self::assertSame(
            'No API key configured. Add your API key in Settings and save.',
            $body['error'] ?? null,
        );
    }

    #[DataProvider('forks')]
    public function test_test_connection_returns_generic_error_without_leaking_the_exception(string $class, bool $isOc4): void
    {
        $this->seedSetting('provider', 'totally-bogus-provider-xyz');
        $this->seedSetting('api_key', 'sk-whatever');

        [$ctrl, $response] = $this->bootController($class, $isOc4);

        $ctrl->test_connection();

        $raw = (string) $response->getOutput();
        $body = json_decode($raw, true);

        self::assertSame('An internal error occurred. Please try again.', $body['error'] ?? null);
        self::assertStringNotContainsString('totally-bogus-provider-xyz', $raw);
        self::assertStringNotContainsString('Unknown provider', $raw);
    }

    public static function requiredAdminEndpoints(): array
    {
        return [
            'about' => ['about'],
            'analytics' => ['analytics'],
            'check_update' => ['check_update'],
            'debug' => ['debug'],
            'guide' => ['guide'],
            'index' => ['index'],
            'install' => ['install'],
            'load_conversation' => ['load_conversation'],
            'send' => ['send'],
            'stream' => ['stream'],
            'test_connection' => ['test_connection'],
            'uninstall' => ['uninstall'],
        ];
    }

    public function test_both_forks_expose_exactly_the_declared_endpoints(): void
    {
        $expected = array_keys(self::requiredAdminEndpoints());
        sort($expected);

        foreach (self::forks() as $fork => [$class, $isOc4]) {
            $declared = array_values(array_filter(
                array_map(
                    static fn (\ReflectionMethod $m): string => $m->getName(),
                    (new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC),
                ),
                static fn (string $name): bool => ! str_starts_with($name, '__'),
            ));
            sort($declared);

            self::assertSame($expected, $declared, "{$fork} controller endpoint set drifted.");
        }
    }
}
