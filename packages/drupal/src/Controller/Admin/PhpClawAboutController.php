<?php

declare(strict_types=1);

namespace PhpClaw\Drupal\Controller\Admin;

use Composer\InstalledVersions;
use Drupal\Core\Controller\ControllerBase;
use PhpClaw\Claw;
use PhpClaw\Cloud\CloudManager;
use PhpClaw\Mcp\PhpClawMcpServer;

/**
 * About page: the phpClaw brand/marketing page inside Drupal admin.
 */
final class PhpClawAboutController extends ControllerBase
{
    private const PACKAGE_NAME = 'phpclaw/phpclaw-drupal';

    private const FALLBACK_VERSION = 'dev';

    /**
     * Render the About page.
     *
     * @return array<string, mixed>
     */
    public function index(): array
    {
        return [
            '#theme' => 'phpclaw_admin_about',
            '#version' => $this->resolveVersion(),
            '#has_core' => class_exists(Claw::class),
            '#has_cloud' => class_exists(CloudManager::class),
            '#has_mcp' => class_exists(PhpClawMcpServer::class),
            '#attached' => ['library' => ['phpclaw/admin.about']],
            '#cache' => ['max-age' => 0],
        ];
    }

    /**
     * Installed package version, resolved from Composer's own runtime metadata.
     *
     * @return string
     */
    private function resolveVersion(): string
    {
        if (! InstalledVersions::isInstalled(self::PACKAGE_NAME)) {
            return self::FALLBACK_VERSION;
        }

        return self::normalizeVersion(InstalledVersions::getPrettyVersion(self::PACKAGE_NAME));
    }

    /**
     * Passes through a real release tag; normalizes a dev alias or absent version to the fallback.
     *
     * @param  ?string  $version  The pretty version Composer reports for the package.
     * @return string
     */
    private static function normalizeVersion(?string $version): string
    {
        if ($version === null || ! preg_match('/^\d+\.\d+\.\d+/', $version)) {
            return self::FALLBACK_VERSION;
        }

        return $version;
    }
}
