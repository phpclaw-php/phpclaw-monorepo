<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Tests\Unit\Admin;

use PhpClaw\Joomla\Component\Administrator\Admin\AutoUpdater;
use PHPUnit\Framework\TestCase;

final class AutoUpdaterTest extends TestCase
{
    public function test_array_result_has_required_keys(): void
    {
        $body = json_encode(['version' => '9.9.9', 'url' => 'https://x', 'notes' => 'n'], JSON_THROW_ON_ERROR);
        $updater = new AutoUpdater('1.0.0', fn () => $body);
        $result = $updater->checkForUpdate();

        self::assertNotNull($result);
        self::assertArrayHasKey('version', $result);
        self::assertArrayHasKey('url', $result);
        self::assertArrayHasKey('notes', $result);
        self::assertIsString($result['version']);
        self::assertIsString($result['url']);
        self::assertIsString($result['notes']);
    }

    public function test_returns_null_when_fetcher_returns_false(): void
    {
        $updater = new AutoUpdater('1.0.0', fn () => false);

        self::assertNull($updater->checkForUpdate());
    }

    public function test_returns_null_when_fetcher_returns_empty_string(): void
    {
        $updater = new AutoUpdater('1.0.0', fn () => '');

        self::assertNull($updater->checkForUpdate());
    }

    public function test_returns_null_when_version_not_newer(): void
    {
        $body = json_encode(['version' => '1.0.0', 'url' => 'https://x', 'notes' => ''], JSON_THROW_ON_ERROR);
        $updater = new AutoUpdater('1.0.0', fn () => $body);

        self::assertNull($updater->checkForUpdate());
    }

    public function test_returns_update_array_when_newer_version_available(): void
    {
        $body = json_encode(['version' => '2.0.0', 'url' => 'https://phpclaw.ai/dl', 'notes' => 'Bugfixes'], JSON_THROW_ON_ERROR);
        $updater = new AutoUpdater('1.0.0', fn () => $body);
        $result = $updater->checkForUpdate();

        self::assertNotNull($result);
        self::assertSame('2.0.0', $result['version']);
        self::assertSame('https://phpclaw.ai/dl', $result['url']);
        self::assertSame('Bugfixes', $result['notes']);
    }

    public function test_returns_null_when_fetcher_throws(): void
    {
        $updater = new AutoUpdater('1.0.0', function (): never {
            throw new \RuntimeException('connection refused');
        });

        self::assertNull($updater->checkForUpdate());
    }

    public function test_static_check_delegates_to_instance_with_injected_fetcher(): void
    {
        $body = json_encode(['version' => '9.9.9', 'url' => 'https://x', 'notes' => 'n'], JSON_THROW_ON_ERROR);
        $result = AutoUpdater::check('1.0.0', fn () => $body);

        self::assertNotNull($result);
        self::assertSame('9.9.9', $result['version']);
        self::assertSame('https://x', $result['url']);
        self::assertSame('n', $result['notes']);
    }

    public function test_static_check_returns_null_when_fetcher_fails(): void
    {
        self::assertNull(AutoUpdater::check('1.0.0', fn () => false));
    }

    public function test_static_check_returns_null_when_version_not_newer(): void
    {
        $body = json_encode(['version' => '1.0.0', 'url' => 'https://x', 'notes' => ''], JSON_THROW_ON_ERROR);

        self::assertNull(AutoUpdater::check('1.0.0', fn () => $body));
    }

    public function test_returns_null_when_body_is_non_array_json(): void
    {
        $updater = new AutoUpdater('1.0.0', fn () => '"just-a-string"');

        self::assertNull($updater->checkForUpdate());
    }

    public function test_returns_null_when_body_is_array_without_version(): void
    {
        $body = json_encode(['notes' => 'no version key'], JSON_THROW_ON_ERROR);
        $updater = new AutoUpdater('1.0.0', fn () => $body);

        self::assertNull($updater->checkForUpdate());
    }

    public function test_injected_current_version_is_sent_to_the_update_endpoint(): void
    {
        $seen = '';
        $updater = new AutoUpdater('0.1.0', function (string $url) use (&$seen): string {
            $seen = $url;

            return '';
        });
        $updater->checkForUpdate();

        self::assertStringContainsString('current=0.1.0', $seen);
    }
}
