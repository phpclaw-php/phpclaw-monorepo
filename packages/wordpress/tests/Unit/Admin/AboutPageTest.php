<?php

declare(strict_types=1);

namespace PhpClaw\WordPress\Tests\Unit\Admin;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PhpClaw\WordPress\Admin\AboutPage;
use PhpClaw\WordPress\Engine\EngineFactory;
use PhpClaw\WordPress\Plugin;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AboutPage::class)]
final class AboutPageTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\stubs([
            'esc_html__' => static fn (string $s, string $d = ''): string => $s,
            'esc_attr__' => static fn (string $s, string $d = ''): string => $s,
            'esc_html' => static fn (string $s): string => $s,
            'esc_attr' => static fn (string $s): string => $s,
        ]);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_register_method_signature_is_static_public(): void
    {
        $ref = new \ReflectionClass(AboutPage::class);
        $m = $ref->getMethod('register');

        self::assertTrue($m->isPublic());
        self::assertTrue($m->isStatic());
    }

    public function test_render_dies_for_user_without_permission(): void
    {
        Functions\expect('current_user_can')->once()->andReturnFalse();
        Functions\expect('wp_die')->once()->andThrow(new \RuntimeException('died'));

        $this->expectException(\RuntimeException::class);

        AboutPage::render();
    }

    public function test_render_outputs_hero_features_packages_and_enterprise(): void
    {
        Functions\expect('current_user_can')->once()->andReturnTrue();

        ob_start();
        AboutPage::render();
        $html = ob_get_clean();

        self::assertStringContainsString('phpClaw', $html);
        self::assertStringContainsString('Universal AI agent engine for WordPress', $html);
        self::assertStringContainsString('What is phpClaw?', $html);
        self::assertStringContainsString('Installable packages', $html);
        self::assertStringContainsString('Need more? phpClaw Enterprise', $html);
        self::assertStringContainsString('phpclaw.ai/enterprise', $html);
        self::assertStringContainsString('Built for the PHP community', $html);
    }

    public function test_the_tools_card_counts_only_the_registered_tools(): void
    {
        Functions\expect('current_user_can')->once()->andReturnTrue();
        Functions\when('get_option')->justReturn([]);
        $config = require __DIR__.'/../../../config/phpclaw.php';
        $registered = EngineFactory::registeredTools($config, []);

        $html = $this->renderWithPlugin($config);

        self::assertMatchesRegularExpression('/\b'.count($registered).' Built-in Tools\b/', $html);
        self::assertStringContainsString('Posts', $html);
        self::assertStringContainsString('Code Search', $html);
        self::assertStringNotContainsString('File Write', $html);
        self::assertStringNotContainsString('Shell', $html);
    }

    public function test_without_woocommerce_the_card_names_how_many_commerce_tools_unlock(): void
    {
        Functions\expect('current_user_can')->once()->andReturnTrue();
        Functions\when('get_option')->justReturn([]);

        $html = $this->renderWithPlugin(require __DIR__.'/../../../config/phpclaw.php');

        self::assertStringContainsString('Install WooCommerce to unlock 10 more commerce tools.', $html);
    }

    public function test_the_tools_card_splits_wordpress_and_woocommerce_tools(): void
    {
        $card = $this->toolsFeature(['wp_query', 'wc_get_orders', 'wc_tax'], true);

        self::assertSame('3 Built-in Tools', $card['title']);
        self::assertSame('WordPress: Posts. WooCommerce: Orders, Tax.', $card['text']);
    }

    public function test_a_tool_without_a_label_is_listed_by_its_name(): void
    {
        $card = $this->toolsFeature(['wp_query', 'custom_report'], false);

        self::assertSame('2 Built-in Tools', $card['title']);
        self::assertStringStartsWith('Posts, custom_report.', $card['text']);
    }

    public function test_the_tools_card_shows_no_count_when_the_live_list_is_unavailable(): void
    {
        $card = $this->toolsFeature(null, true);

        self::assertSame('Built-in Tools', $card['title']);
        self::assertDoesNotMatchRegularExpression('/\d/', $card['title']);
    }

    private function renderWithPlugin(array $config): string
    {
        $plugin = (new \ReflectionClass(Plugin::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(Plugin::class, 'config'))->setValue($plugin, $config);
        (new \ReflectionProperty(Plugin::class, 'instance'))->setValue(null, $plugin);

        try {
            ob_start();
            AboutPage::render();

            return (string) ob_get_clean();
        } finally {
            (new \ReflectionProperty(Plugin::class, 'instance'))->setValue(null, null);
        }
    }

    private function toolsFeature(?array $names, bool $wooCommerceActive): array
    {
        return (new \ReflectionMethod(AboutPage::class, 'toolsFeature'))->invoke(null, $names, $wooCommerceActive);
    }

    public function test_render_shows_all_open_source_packages(): void
    {
        Functions\expect('current_user_can')->once()->andReturnTrue();

        ob_start();
        AboutPage::render();
        $html = ob_get_clean();

        foreach (['phpclaw/phpclaw', 'phpclaw/phpclaw-wordpress', 'phpclaw/phpclaw-cloud', 'phpclaw/phpclaw-mcp'] as $pkg) {
            self::assertStringContainsString($pkg, $html);
        }
    }
}
