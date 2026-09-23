<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Block\Adminhtml;

use Composer\InstalledVersions;
use Magento\Backend\Block\Template;

/**
 * Block for the phpClaw About page.
 */
// non-final: Magento interceptor required
class About extends Template
{
    private const GITHUB_URL = 'https://github.com/phpclaw-php/phpclaw';

    private const WEBSITE_URL = 'https://phpclaw.ai';

    private const PACKAGIST_URL = 'https://packagist.org/packages/phpclaw/phpclaw-magento';

    /**
     * Installed package version, resolved from Composer's own runtime metadata.
     *
     * @return string
     */
    public function getVersion(): string
    {
        if (! InstalledVersions::isInstalled('phpclaw/phpclaw-magento')) {
            return 'dev';
        }

        return self::normalizeVersion(InstalledVersions::getPrettyVersion('phpclaw/phpclaw-magento'));
    }

    /**
     * URL to the phpClaw GitHub repository.
     *
     * @return string
     */
    public function getGithubUrl(): string
    {
        return self::GITHUB_URL;
    }

    /**
     * URL to the phpClaw marketing website.
     *
     * @return string
     */
    public function getWebsiteUrl(): string
    {
        return self::WEBSITE_URL;
    }

    /**
     * URL to the Packagist page for the Magento adapter.
     *
     * @return string
     */
    public function getPackagistUrl(): string
    {
        return self::PACKAGIST_URL;
    }

    /**
     * Settings page URL (PhpClaw → Settings).
     *
     * @return string
     */
    public function getSettingsUrl(): string
    {
        return $this->getUrl('phpclaw/settings');
    }

    /**
     * Chat page URL.
     *
     * @return string
     */
    public function getChatUrl(): string
    {
        return $this->getUrl('phpclaw/chat/index');
    }

    /**
     * Passes through a real release tag; normalizes anything else (dev alias, no-version-set, null) to 'dev'.
     *
     * @param  ?string  $version
     * @return string
     */
    private static function normalizeVersion(?string $version): string
    {
        if ($version === null || ! preg_match('/^\d+\.\d+\.\d+/', $version)) {
            return 'dev';
        }

        return $version;
    }
}
