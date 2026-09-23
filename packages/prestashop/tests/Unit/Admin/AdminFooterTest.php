<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Tests\Unit\Admin;

use PhpClaw\PrestaShop\Admin\AdminFooter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AdminFooter::class)]
final class AdminFooterTest extends TestCase
{
    public function test_render_returns_html_string(): void
    {
        $html = AdminFooter::render();

        self::assertIsString($html);
        self::assertStringContainsString('phpClaw', $html);
        self::assertStringContainsString('phpclaw.ai', $html);
    }

    public function test_render_includes_version(): void
    {
        $html = AdminFooter::render();

        self::assertMatchesRegularExpression('/v(\d+\.\d+\.\d+|unknown)/', $html);
    }

    public function test_render_includes_docs_link(): void
    {
        $html = AdminFooter::render(docsUrl: 'https://phpclaw.ai/docs');

        self::assertStringContainsString('https://phpclaw.ai/docs', $html);
        self::assertStringContainsString('Docs', $html);
    }

    public function test_render_includes_changelog_link(): void
    {
        $html = AdminFooter::render(changelogUrl: 'https://phpclaw.ai/docs');

        self::assertStringContainsString('https://phpclaw.ai/docs', $html);
        self::assertStringContainsString('Changelog', $html);
    }

    public function test_render_includes_settings_link_when_url_provided(): void
    {
        $html = AdminFooter::render(settingsUrl: 'https://example.com/admin/settings');

        self::assertStringContainsString('Settings', $html);
        self::assertStringContainsString('https://example.com/admin/settings', $html);
    }

    public function test_render_omits_settings_link_when_url_empty(): void
    {
        $html = AdminFooter::render(settingsUrl: '');

        self::assertStringNotContainsString('Settings', $html);
    }

    public function test_render_escapes_xss_in_settings_url(): void
    {
        $html = AdminFooter::render(settingsUrl: '"><script>alert(1)</script>');

        self::assertStringNotContainsString('<script>', $html);
    }

    public function test_version_falls_back_to_unknown_outside_prestashop(): void
    {
        self::assertSame(
            class_exists(\Phpclaw::class) ? \Phpclaw::PHPCLAW_VERSION : 'unknown',
            AdminFooter::version(),
        );
    }

    public function test_version_matches_render_output(): void
    {
        $version = AdminFooter::version();
        $html = AdminFooter::render();

        self::assertStringContainsString($version, $html);
    }

    public function test_render_includes_github_link(): void
    {
        $html = AdminFooter::render();

        self::assertStringContainsString('GitHub', $html);
        self::assertStringContainsString('github.com/phpclaw-php', $html);
    }

    public function test_render_has_rel_noopener(): void
    {
        $html = AdminFooter::render();

        self::assertStringContainsString('rel="noopener"', $html);
    }

    public function test_render_with_all_empty_urls_omits_every_link_href(): void
    {
        $html = AdminFooter::render(settingsUrl: '', docsUrl: '', changelogUrl: '');

        self::assertStringNotContainsString('>Settings</a>', $html);
        self::assertStringContainsString('<a href="" target="_blank" rel="noopener" class="phpclaw-footer-link">Docs</a>', $html);
        self::assertStringContainsString('<a href="" target="_blank" rel="noopener" class="phpclaw-footer-link">Changelog</a>', $html);
    }

    public function test_render_escapes_xss_in_docs_url(): void
    {
        $html = AdminFooter::render(docsUrl: '"><script>alert(2)</script>');

        self::assertStringNotContainsString('<script>', $html);
    }

    public function test_render_escapes_xss_in_changelog_url(): void
    {
        $html = AdminFooter::render(changelogUrl: '"><script>alert(3)</script>');

        self::assertStringNotContainsString('<script>', $html);
    }

    public function test_render_omits_settings_href_when_url_empty(): void
    {
        $html = AdminFooter::render(settingsUrl: '');

        self::assertStringNotContainsString('href=""', $html);
    }

    public function test_render_has_valid_html_structure(): void
    {
        $html = AdminFooter::render();

        self::assertStringContainsString('<div', $html);
        self::assertStringContainsString('</div>', $html);
    }

    public function test_version_is_semver_or_unknown_fallback(): void
    {
        self::assertMatchesRegularExpression('/^(\d+\.\d+\.\d+|unknown)$/', AdminFooter::version());
    }

    public function test_render_includes_phpclaw_ai_link(): void
    {
        $html = AdminFooter::render();

        self::assertStringContainsString('phpclaw.ai', $html);
    }
}
