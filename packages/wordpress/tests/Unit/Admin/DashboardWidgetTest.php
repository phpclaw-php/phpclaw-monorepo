<?php

declare(strict_types=1);

namespace PhpClaw\WordPress\Tests\Unit\Admin;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PhpClaw\WordPress\Admin\DashboardWidget;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DashboardWidget::class)]
final class DashboardWidgetTest extends TestCase
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

    public function test_register_hooks_wp_dashboard_setup(): void
    {
        Functions\expect('add_action')
            ->once()
            ->with('wp_dashboard_setup', [DashboardWidget::class, 'addWidget']);

        DashboardWidget::register();
    }

    public function test_add_widget_calls_wp_add_dashboard_widget(): void
    {
        Functions\expect('__')->andReturnArg(0);
        Functions\expect('current_user_can')->with('phpclaw_use_admin_chat')->andReturn(true);

        Functions\expect('wp_add_dashboard_widget')
            ->once()
            ->with(
                'phpclaw_dashboard_widget',
                \Mockery::type('string'),
                [DashboardWidget::class, 'render'],
            );

        DashboardWidget::addWidget();
    }

    public function test_add_widget_skipped_for_non_admin(): void
    {
        Functions\expect('current_user_can')->with('phpclaw_use_admin_chat')->andReturn(false);

        Functions\expect('wp_add_dashboard_widget')->never();

        DashboardWidget::addWidget();
    }

    public function test_render_shows_connected_status_when_api_key_set(): void
    {
        $this->mockWpdb(3, 12);
        $this->mockCommonFunctions(['provider' => 'anthropic', 'api_key' => 'sk-test-123', 'model' => 'claude-haiku-4-5-20251001']);

        ob_start();
        DashboardWidget::render();
        $html = ob_get_clean();

        $this->assertStringContainsString('Connected', $html);
        $this->assertStringContainsString('Anthropic', $html);
        $this->assertStringContainsString('3', $html);
        $this->assertStringContainsString('12', $html);
    }

    public function test_render_shows_not_configured_when_no_api_key(): void
    {
        $this->mockWpdb(0, 0);
        $this->mockCommonFunctions([]);

        ob_start();
        DashboardWidget::render();
        $html = ob_get_clean();

        $this->assertStringContainsString('Not configured', $html);
        $this->assertStringNotContainsString('Open AI Chat', $html);
    }

    public function test_render_shows_connected_for_ollama_without_api_key(): void
    {
        $this->mockWpdb(1, 5);
        $this->mockCommonFunctions(['provider' => 'ollama', 'api_key' => '', 'model' => '']);

        ob_start();
        DashboardWidget::render();
        $html = ob_get_clean();

        $this->assertStringContainsString('Connected', $html);
    }

    public function test_render_shows_open_ai_chat_button_when_configured(): void
    {
        $this->mockWpdb(0, 0);
        $this->mockCommonFunctions(['provider' => 'openai', 'api_key' => 'sk-test', 'model' => 'gpt-4o-mini']);

        ob_start();
        DashboardWidget::render();
        $html = ob_get_clean();

        $this->assertStringContainsString('Open AI Chat', $html);
        $this->assertStringContainsString('Settings', $html);
    }

    private function mockWpdb(int $conversations, int $messages): void
    {
        global $wpdb;

        $mock = \Mockery::mock('wpdb');
        $mock->prefix = 'wp_';
        $mock->shouldReceive('suppress_errors')->andReturn(false);
        $mock->shouldReceive('prepare')->andReturnUsing(function (string $sql, mixed ...$args) {
            return $sql;
        });
        $mock->shouldReceive('get_var')->andReturnValues([$conversations, $messages]);

        $wpdb = $mock;
    }

    private function mockCommonFunctions(array $settings): void
    {
        Functions\expect('wp_cache_get')->andReturn(false);
        Functions\expect('wp_cache_set')->andReturn(true);

        Functions\expect('get_option')
            ->with('phpclaw_settings', [])
            ->andReturn($settings);

        Functions\expect('admin_url')
            ->andReturnUsing(fn (string $p) => 'http://example.com/wp-admin/'.$p);

        Functions\expect('__')->andReturnArg(0);
        Functions\expect('esc_url')->andReturnArg(0);
        Functions\expect('esc_html')->andReturnArg(0);
        Functions\expect('esc_html__')->andReturnArg(0);
    }
}
