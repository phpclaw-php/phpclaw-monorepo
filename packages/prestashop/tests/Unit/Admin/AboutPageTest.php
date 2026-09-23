<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Tests\Unit\Admin;

use PhpClaw\PrestaShop\Admin\AboutPage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AboutPage::class)]
final class AboutPageTest extends TestCase
{
    public function test_data_exposes_only_the_keys_the_template_renders(): void
    {
        self::assertSame(['version', 'packages'], array_keys(AboutPage::data()));
    }

    public function test_data_version_is_semver_or_unknown_fallback(): void
    {
        $version = AboutPage::data()['version'];

        self::assertIsString($version);
        self::assertMatchesRegularExpression('/^(\d+\.\d+\.\d+|unknown)$/', $version);
    }

    public function test_data_lists_exactly_the_four_phpclaw_packages_in_order(): void
    {
        self::assertSame(
            [
                'phpclaw/phpclaw',
                'phpclaw/phpclaw-prestashop',
                'phpclaw/phpclaw-cloud',
                'phpclaw/phpclaw-mcp',
            ],
            array_column(AboutPage::data()['packages'], 'package'),
        );
    }

    public function test_data_each_package_has_name_status_and_description(): void
    {
        $packages = AboutPage::data()['packages'];

        self::assertCount(4, $packages);

        foreach ($packages as $package) {
            self::assertSame(['package', 'installed', 'description'], array_keys($package));
            self::assertIsBool($package['installed']);
            self::assertNotSame('', $package['description']);
        }
    }

    public function test_data_reports_this_module_as_installed(): void
    {
        $packages = array_column(AboutPage::data()['packages'], 'installed', 'package');

        self::assertTrue($packages['phpclaw/phpclaw-prestashop']);
    }

    public function test_data_contains_no_internal_class_names(): void
    {
        $json = (string) json_encode(AboutPage::data());

        foreach (['ToolRegistry', 'ReAct', 'AgentResponse', 'ClawInterface', 'GuardRegistry'] as $leak) {
            self::assertStringNotContainsString($leak, $json);
        }
    }

    public function test_data_is_deterministic(): void
    {
        self::assertSame(AboutPage::data(), AboutPage::data());
    }
}
