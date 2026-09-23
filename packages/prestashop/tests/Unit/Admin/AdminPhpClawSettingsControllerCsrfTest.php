<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Tests\Unit\Admin;

use PhpClaw\PrestaShop\Plugin;
use PhpClaw\PrestaShop\Tests\Helpers\FakeTokenDb;
use PHPUnit\Framework\TestCase;

final class AdminPhpClawSettingsControllerCsrfTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        \Configuration::reset();
        \Context::reset();
        $_POST = [];

        require_once __DIR__.'/../../../upload/modules/phpclaw/controllers/admin/AdminPhpClawSettingsController.php';
    }

    protected function tearDown(): void
    {
        \Configuration::reset();
        \Context::reset();
        $_POST = [];
        parent::tearDown();
    }

    private function save(bool $tokenValid, bool $canEdit): \AdminPhpClawSettingsController
    {
        $ref = new \ReflectionClass(\AdminPhpClawSettingsController::class);
        $controller = $ref->newInstanceWithoutConstructor();
        $controller->tokenValid = $tokenValid;
        $controller->accessLevels = ['edit' => $canEdit ? 1 : 0, 'view' => 1];
        $controller->errors = [];

        $_POST = [
            'provider' => 'ollama',
            'model' => 'qwen2.5:7b',
            'max_iterations' => '20',
            'store_messages' => '1',
            'submitPhpClawSettings' => '1',
        ];

        $m = $ref->getMethod('handleSettingsSave');
        $m->setAccessible(true);
        $m->invoke($controller, Plugin::getInstance(new FakeTokenDb, 'ps_'));

        return $controller;
    }

    public function test_a_forged_request_writes_no_setting(): void
    {
        $this->save(tokenValid: false, canEdit: true);

        self::assertFalse(
            \Configuration::get('PHPCLAW_PROVIDER'),
            'A request that fails the CSRF token check must not persist a single setting.',
        );
    }

    public function test_a_forged_request_reports_an_error(): void
    {
        $controller = $this->save(tokenValid: false, canEdit: true);

        self::assertNotSame([], $controller->errors);
    }

    public function test_a_valid_token_without_edit_permission_writes_no_setting(): void
    {
        $this->save(tokenValid: true, canEdit: false);

        self::assertFalse(\Configuration::get('PHPCLAW_PROVIDER'));
    }

    public function test_a_valid_token_without_edit_permission_reports_an_error(): void
    {
        $controller = $this->save(tokenValid: true, canEdit: false);

        self::assertNotSame([], $controller->errors);
    }

    public function test_the_csrf_check_runs_before_the_permission_check(): void
    {
        $controller = $this->save(tokenValid: false, canEdit: false);

        self::assertStringContainsString(
            'security token',
            (string) ($controller->errors[0] ?? ''),
            'With both checks failing the CSRF message must win, proving it is evaluated first.',
        );
    }
}
