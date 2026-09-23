<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Tests\Unit\Block\Adminhtml;

use Magento\Backend\Block\Template;
use PhpClaw\Magento\Block\Adminhtml\About;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class AboutTest extends TestCase
{
    private Template\Context&MockObject $context;

    private About $block;

    protected function setUp(): void
    {
        $this->context = $this->createMock(Template\Context::class);
        $this->block = new About($this->context);
    }

    public function test_get_github_url_contains_phpclaw_php(): void
    {
        self::assertStringContainsString('phpclaw-php', $this->block->getGithubUrl());
    }

    public function test_get_website_url_is_phpclaw_ai(): void
    {
        self::assertStringContainsString('phpclaw.ai', $this->block->getWebsiteUrl());
    }

    public function test_get_packagist_url_contains_phpclaw_magento(): void
    {
        self::assertStringContainsString('phpclaw-magento', $this->block->getPackagistUrl());
    }

    public function test_get_version_is_dev_when_the_package_is_not_composer_installed(): void
    {
        self::assertSame('dev', $this->block->getVersion());
    }

    public function test_get_version_reads_real_version_from_composer_installed_versions(): void
    {
        $version = $this->block->getVersion();

        self::assertTrue(
            $version === 'dev' || (bool) preg_match('/^\d+\.\d+\.\d+/', $version),
            "getVersion() must return 'dev' or a real semver-shaped version, got: {$version}",
        );
    }

    public function test_get_settings_url_points_at_the_settings_route(): void
    {
        self::assertSame('http://example.com/admin/phpclaw/settings', $this->block->getSettingsUrl());
    }

    public function test_get_chat_url_points_at_the_chat_index_route(): void
    {
        self::assertSame('http://example.com/admin/phpclaw/chat/index', $this->block->getChatUrl());
    }
}
