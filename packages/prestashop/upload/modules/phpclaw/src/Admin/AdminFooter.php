<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Admin;

/**
 * Shared admin footer renderer for all phpClaw Back Office pages.
 */
final class AdminFooter
{
    /**
     * Render the HTML footer string.
     *
     * @param  string  $settingsUrl  URL to the Settings admin page
     * @param  string  $docsUrl  URL to documentation
     * @param  string  $changelogUrl  URL to changelog
     * @return string
     */
    public static function render(
        string $settingsUrl = '',
        string $docsUrl = 'https://phpclaw.ai/docs',
        string $changelogUrl = 'https://phpclaw.ai/docs',
    ): string {
        $version = htmlspecialchars(self::version(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $sUrl = htmlspecialchars($settingsUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $dUrl = htmlspecialchars($docsUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $cUrl = htmlspecialchars($changelogUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $settingsLink = $sUrl !== ''
            ? '<a href="'.$sUrl.'" class="phpclaw-footer-link">Settings</a>'
            : '';

        return <<<HTML
            <div class="phpclaw-footer">
              <span>phpClaw v{$version}</span>
              {$settingsLink}
              <a href="{$dUrl}" target="_blank" rel="noopener" class="phpclaw-footer-link">Docs</a>
              <a href="{$cUrl}" target="_blank" rel="noopener" class="phpclaw-footer-link">Changelog</a>
              <a href="https://phpclaw.ai" target="_blank" rel="noopener" class="phpclaw-footer-link">phpclaw.ai</a>
              <span class="phpclaw-footer-end">
                Made with &#10084; for the PHP community,
                <a href="https://github.com/phpclaw-php/phpclaw" target="_blank" rel="noopener" class="phpclaw-footer-link">GitHub</a>
              </span>
            </div>
            HTML;
    }

    /**
     * Return the current adapter version string.
     *
     * @return string
     */
    public static function version(): string
    {
        return class_exists(\Phpclaw::class) ? \Phpclaw::PHPCLAW_VERSION : 'unknown';
    }
}
