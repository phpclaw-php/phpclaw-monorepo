<?php

declare(strict_types=1);

namespace PhpClaw\WordPress\Tests\Unit\Admin;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PhpClaw\WordPress\Admin\AdminFooter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AdminFooter::class)]
final class AdminFooterTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\stubs([
            'esc_html__' => static fn (string $s, string $domain = ''): string => $s,
        ]);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_community_card_renders_expected_text_and_links(): void
    {
        ob_start();
        AdminFooter::renderCommunityCard();
        $html = ob_get_clean();

        self::assertStringContainsString('Built for the PHP community', $html);
        self::assertStringContainsString('open source under the MIT license', $html);
        self::assertStringContainsString('github.com/phpclaw-php/phpclaw', $html);
        self::assertStringContainsString('packagist.org/packages/phpclaw/phpclaw', $html);
        self::assertStringContainsString('phpclaw.ai/enterprise', $html);
        self::assertStringContainsString('Star on GitHub', $html);
        self::assertStringContainsString('View on Packagist', $html);
    }

    public function test_community_card_uses_translatable_strings(): void
    {
        $domains = [];
        Functions\when('esc_html__')->alias(static function (string $s, string $domain = '') use (&$domains): string {
            $domains[] = $domain;

            return $s;
        });

        ob_start();
        AdminFooter::renderCommunityCard();
        ob_end_clean();

        self::assertNotEmpty($domains);
        foreach ($domains as $d) {
            self::assertSame('phpclaw', $d);
        }
    }
}
