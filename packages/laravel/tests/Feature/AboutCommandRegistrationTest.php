<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Orchestra\Testbench\TestCase;
use PhpClaw\Laravel\PhpClawServiceProvider;

final class AboutCommandRegistrationTest extends TestCase
{
    private const SECRET = 'sk-super-secret-key-value';

    protected function getPackageProviders($app): array
    {
        return [PhpClawServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('phpclaw.api_key', self::SECRET);
        $app['config']->set('phpclaw.cloud_key', 'cloud-secret-value');
        $app['config']->set('phpclaw.provider', 'anthropic');
        $app['config']->set('phpclaw.memory_driver', 'array');
    }

    private function aboutOutput(): string
    {
        Artisan::call('about', ['--only' => 'phpclaw']);

        return Artisan::output();
    }

    public function test_the_phpclaw_section_is_registered(): void
    {
        $output = $this->aboutOutput();

        self::assertStringContainsString('phpClaw', $output);
        self::assertStringContainsString('Provider', $output);
        self::assertStringContainsString('anthropic', $output);
    }

    public function test_the_api_key_value_is_never_printed(): void
    {
        $output = $this->aboutOutput();

        self::assertStringNotContainsString(self::SECRET, $output);
        self::assertStringContainsString('configured', $output);
    }

    public function test_the_cloud_key_value_is_never_printed(): void
    {
        $output = $this->aboutOutput();

        self::assertStringNotContainsString('cloud-secret-value', $output);
    }

    public function test_an_absent_api_key_reports_not_configured(): void
    {
        config(['phpclaw.api_key' => '']);

        self::assertStringContainsString('not configured', $this->aboutOutput());
    }
}
