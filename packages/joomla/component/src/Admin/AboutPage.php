<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Component\Administrator\Admin;

use Joomla\CMS\Extension\ExtensionHelper;

/**
 * About page data for the Joomla phpClaw adapter admin panel.
 */
final class AboutPage
{
    /**
     * Read the component version from the Joomla manifest cache, empty when it cannot be read.
     *
     * @return string
     */
    public static function version(): string
    {
        try {
            $row = ExtensionHelper::getExtensionRecord('com_phpclaw', 'component');
            if (is_object($row) && isset($row->manifest_cache)) {
                $manifest = json_decode((string) $row->manifest_cache, associative: true);
                if (is_array($manifest) && isset($manifest['version']) && is_string($manifest['version'])) {
                    return $manifest['version'];
                }
            }
        } catch (\Throwable) {
        }

        return '';
    }
}
