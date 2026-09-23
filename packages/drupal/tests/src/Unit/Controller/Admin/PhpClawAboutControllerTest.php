<?php

declare(strict_types=1);

namespace PhpClaw\Drupal\Tests\Unit\Controller\Admin;

use Composer\InstalledVersions;
use Drupal\Core\Controller\ControllerBase;
use PhpClaw\Drupal\Controller\Admin\PhpClawAboutController;
use PHPUnit\Framework\TestCase;

final class PhpClawAboutControllerTest extends TestCase
{
    public function test_index_returns_render_array_with_theme_key(): void
    {
        if (! class_exists(ControllerBase::class)) {
            $this->markTestSkipped('Drupal ControllerBase not available.');
        }

        $controller = new PhpClawAboutController;
        $result = $controller->index();

        $this->assertIsArray($result);
        $this->assertSame('phpclaw_admin_about', $result['#theme']);
        $this->assertArrayHasKey('#attached', $result);
        $this->assertArrayHasKey('#cache', $result);
        $this->assertSame(0, $result['#cache']['max-age']);
    }

    public function test_resolve_version_reports_the_version_composer_installed_the_package_at(): void
    {
        $controller = new PhpClawAboutController;
        $version = (new \ReflectionMethod($controller, 'resolveVersion'))->invoke($controller);

        $pretty = InstalledVersions::isInstalled('phpclaw/phpclaw-drupal')
            ? InstalledVersions::getPrettyVersion('phpclaw/phpclaw-drupal')
            : null;

        $expected = $pretty !== null && preg_match('/^\d+\.\d+\.\d+/', $pretty) === 1 ? $pretty : 'dev';

        $this->assertSame($expected, $version);
    }

    public function test_resolve_version_never_reports_a_release_number_the_package_is_not_installed_at(): void
    {
        $controller = new PhpClawAboutController;
        $version = (new \ReflectionMethod($controller, 'resolveVersion'))->invoke($controller);

        if ($version === 'dev') {
            $this->assertTrue(
                ! InstalledVersions::isInstalled('phpclaw/phpclaw-drupal')
                || preg_match('/^\d+\.\d+\.\d+/', (string) InstalledVersions::getPrettyVersion('phpclaw/phpclaw-drupal')) !== 1,
                'the About page reported dev while Composer holds a real release tag',
            );

            return;
        }

        $this->assertSame(InstalledVersions::getPrettyVersion('phpclaw/phpclaw-drupal'), $version);
    }

    public function test_normalize_version_rejects_every_shape_that_is_not_a_release_tag(): void
    {
        $method = new \ReflectionMethod(PhpClawAboutController::class, 'normalizeVersion');

        foreach ([null, 'dev-master', 'dev-main', '', '9999999-dev', 'v1', '1.0'] as $notARelease) {
            $this->assertSame('dev', $method->invoke(null, $notARelease), $notARelease.' is not a release tag');
        }

        $this->assertSame('1.0.8', $method->invoke(null, '1.0.8'));
        $this->assertSame('2.3.4-beta1', $method->invoke(null, '2.3.4-beta1'));
    }
}
