<?php

declare(strict_types=1);

namespace PhpClaw\WordPress\Tests\Unit\Admin;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PhpClaw\WordPress\Admin\AboutPage;
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

    public function test_render_shows_20_tools_when_woocommerce_inactive(): void
    {
        Functions\expect('current_user_can')->once()->andReturnTrue();

        ob_start();
        AboutPage::render();
        $html = ob_get_clean();

        self::assertStringContainsString('20 Built-in Tools', $html);
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
