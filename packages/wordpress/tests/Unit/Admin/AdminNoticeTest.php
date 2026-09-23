<?php

declare(strict_types=1);

namespace PhpClaw\WordPress\Tests\Unit\Admin;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PhpClaw\WordPress\Admin\AdminNotice;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AdminNotice::class)]
final class AdminNoticeTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        if (! defined('PHPCLAW_PLUGIN_FILE')) {
            define('PHPCLAW_PLUGIN_FILE', '/tmp/phpclaw/phpclaw.php');
        }

        Functions\stubs([
            'esc_html__' => static fn (string $s, string $d = ''): string => $s,
            'esc_attr__' => static fn (string $s, string $d = ''): string => $s,
            'esc_html' => static fn (string $s): string => $s,
            'esc_attr' => static fn (string $s): string => $s,
            'esc_url' => static fn (string $s): string => $s,
            'plugins_url' => static fn (string $path, string $file): string => 'http://example.com/plugin/'.$path,
            'admin_url' => static fn (string $path): string => 'http://example.com/wp-admin/'.ltrim($path, '/'),
            'wp_enqueue_style' => static function (...$args): void {},
            'wp_register_script' => static function (...$args): void {},
            'wp_enqueue_script' => static function (...$args): void {},
            'wp_add_inline_script' => static function (...$args): void {},
            'wp_json_encode' => static fn (mixed $data): string => (string) json_encode($data),
            'wp_create_nonce' => static fn (string $action): string => 'NONCE_'.$action,
        ]);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_register_hooks_admin_notices_and_ajax_dismiss(): void
    {
        $hooks = [];
        Functions\when('add_action')->alias(static function (string $hook, $cb) use (&$hooks): bool {
            $hooks[] = $hook;

            return true;
        });

        AdminNotice::register();

        self::assertContains('admin_notices', $hooks);
        self::assertContains('wp_ajax_phpclaw_dismiss_notice', $hooks);
    }

    public function test_render_does_nothing_for_user_without_permission(): void
    {
        Functions\expect('current_user_can')->once()->with('phpclaw_use_admin_chat')->andReturnFalse();

        ob_start();
        AdminNotice::render();
        $html = ob_get_clean();

        self::assertSame('', $html);
    }

    public function test_render_skips_when_provider_already_configured(): void
    {
        Functions\expect('current_user_can')->once()->andReturnTrue();
        Functions\expect('get_option')->once()->with('phpclaw_settings', [])->andReturn(['provider' => 'openai']);

        ob_start();
        AdminNotice::render();
        $html = ob_get_clean();

        self::assertSame('', $html);
    }

    public function test_render_skips_when_notice_already_dismissed(): void
    {
        Functions\expect('current_user_can')->once()->andReturnTrue();
        Functions\expect('get_option')
            ->twice()
            ->andReturnUsing(static function (string $key, mixed $default = null) {
                if ($key === 'phpclaw_settings') {
                    return [];
                }
                if ($key === 'phpclaw_notice_dismissed') {
                    return '1';
                }

                return $default;
            });

        ob_start();
        AdminNotice::render();
        $html = ob_get_clean();

        self::assertSame('', $html);
    }

    public function test_render_outputs_onboarding_card_when_configured_but_not_dismissed(): void
    {
        Functions\expect('current_user_can')->once()->andReturnTrue();
        Functions\expect('get_option')
            ->twice()
            ->andReturnUsing(static function (string $key) {
                if ($key === 'phpclaw_settings') {
                    return [];
                }

                return false;
            });

        ob_start();
        AdminNotice::render();
        $html = ob_get_clean();

        self::assertStringContainsString('phpClaw is installed', $html);
        self::assertStringContainsString('Choose a provider', $html);
        self::assertStringContainsString('Save & Test Connection', $html);
        self::assertStringContainsString('phpclaw-setup-notice', $html);
        self::assertStringContainsString('phpClawNoticeData', $html);
        self::assertStringContainsString('phpclaw.ai/docs', $html);
    }

    public function test_handle_dismiss_stores_the_flag_for_an_authorized_user(): void
    {
        self::markTestSkipped(
            'handleDismiss() calls update_option() with the named argument autoload:, '
            .'and Brain Monkey generates that stub with a two-parameter signature, '
            .'so the authorized branch cannot be exercised here.',
        );
    }

    public function test_handle_dismiss_skips_update_for_unauthorized_user(): void
    {
        Functions\expect('check_ajax_referer')->once();
        Functions\expect('current_user_can')->once()->andReturnFalse();
        Functions\expect('update_option')->never();
        Functions\expect('wp_die')->once();

        AdminNotice::handleDismiss();
    }
}
