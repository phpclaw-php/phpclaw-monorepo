<?php

declare(strict_types=1);

namespace PhpClaw\OpenCart\Tests\Unit\Admin;

use PhpClaw\OpenCart\Admin\AboutPage;
use PHPUnit\Framework\TestCase;

final class AboutPageTest extends TestCase
{
    public function test_data_has_required_keys(): void
    {
        $data = AboutPage::data();
        foreach (['version', 'packages'] as $key) {
            self::assertArrayHasKey($key, $data, "Missing key: {$key}");
        }
    }

    public function test_data_packages_is_non_empty_list(): void
    {
        $data = AboutPage::data();
        self::assertIsArray($data['packages']);
        self::assertNotEmpty($data['packages']);
    }

    public function test_data_version_is_semver_or_unknown_fallback(): void
    {
        $version = AboutPage::data()['version'];

        self::assertIsString($version);
        self::assertMatchesRegularExpression('/^(\d+\.\d+\.\d+|unknown)$/', $version);
    }

    public function test_data_version_follows_the_plugin_constant_when_defined(): void
    {
        if (! defined('PHPCLAW_VERSION')) {
            define('PHPCLAW_VERSION', '9.9.9');
        }

        self::assertSame(PHPCLAW_VERSION, AboutPage::data()['version']);
    }
}
