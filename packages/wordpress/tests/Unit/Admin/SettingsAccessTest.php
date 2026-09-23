<?php

declare(strict_types=1);

namespace PhpClaw\WordPress\Tests\Unit\Admin;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PhpClaw\WordPress\Admin\SettingsPage;
use PhpClaw\WordPress\Plugin;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(SettingsPage::class)]
final class SettingsAccessTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private const ADMIN = ['manage_options' => true, 'edit_posts' => true, 'read' => true];

    private const EDITOR = ['edit_posts' => true, 'read' => true];

    private const SUBSCRIBER = ['read' => true];

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

    public function test_an_administrator_reaches_the_settings_page(): void
    {
        $this->actAs(self::ADMIN);
        Functions\when('get_option')->justReturn(['provider' => 'ollama']);

        self::assertNotSame('', $this->renderSettings());
    }

    public function test_an_editor_is_refused_the_settings_page(): void
    {
        $this->actAs(self::EDITOR);

        self::assertSame('', $this->renderSettings());
    }

    public function test_a_subscriber_is_refused_the_settings_page(): void
    {
        $this->actAs(self::SUBSCRIBER);

        self::assertSame('', $this->renderSettings());
    }

    public function test_the_chat_tier_alone_does_not_open_settings(): void
    {
        $editor = $this->resolve(self::EDITOR, ['phpclaw_use_chat', 'phpclaw_use_admin_chat']);

        self::assertTrue(
            $editor['phpclaw_use_chat'] ?? false,
            'an editor must hold the chat tier, or this test proves nothing about the gap',
        );

        $this->actAs(self::EDITOR);

        self::assertSame(
            '',
            $this->renderSettings(),
            'holding the chat tier must not be enough to open Settings',
        );
    }

    /**
     * Resolve capabilities through the plugin's real user_has_cap filter.
     *
     * @param  array<string, bool>  $caps  WordPress capabilities the account already holds.
     * @param  array<int, string>  $required  Capabilities being asked about.
     * @return array<string, bool> The resolved capability set.
     */
    private function resolve(array $caps, array $required): array
    {
        $filter = null;

        Functions\when('add_filter')->alias(
            static function (string $hook, callable $callback) use (&$filter): void {
                if ($hook === 'user_has_cap') {
                    $filter = $callback;
                }
            },
        );

        $plugin = (new ReflectionClass(Plugin::class))->newInstanceWithoutConstructor();
        $method = (new ReflectionClass(Plugin::class))->getMethod('registerConversationCapability');
        $method->setAccessible(true);
        $method->invoke($plugin);

        self::assertIsCallable($filter, 'the user_has_cap filter was never registered');

        return $filter($caps, $required);
    }

    /**
     * Answer current_user_can through the real derivation, for an account holding these capabilities.
     *
     * @param  array<string, bool>  $caps  WordPress capabilities the account already holds.
     * @return void
     */
    private function actAs(array $caps): void
    {
        Functions\when('current_user_can')->alias(
            fn (string $capability): bool => (bool) ($this->resolve($caps, [$capability])[$capability] ?? false),
        );
    }

    /**
     * Render the settings page and capture what it emitted.
     *
     * @return string The rendered markup, empty when the caller was refused.
     */
    private function renderSettings(): string
    {
        Functions\when('__')->returnArg();
        Functions\when('esc_html__')->returnArg();
        Functions\when('esc_attr')->returnArg();
        Functions\when('esc_html')->returnArg();
        Functions\when('esc_url')->returnArg();
        Functions\when('admin_url')->justReturn('http://example.test/wp-admin/');
        Functions\when('settings_fields')->justReturn(null);
        Functions\when('do_settings_sections')->justReturn(null);
        Functions\when('submit_button')->justReturn(null);
        Functions\when('wp_create_nonce')->justReturn('nonce');
        Functions\when('selected')->justReturn('');
        Functions\when('checked')->justReturn('');

        ob_start();
        SettingsPage::render();

        return (string) ob_get_clean();
    }
}
