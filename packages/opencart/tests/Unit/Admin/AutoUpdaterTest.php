<?php

declare(strict_types=1);

namespace PhpClaw\OpenCart\Tests\Unit\Admin;

use PhpClaw\OpenCart\Admin\AutoUpdater;
use PHPUnit\Framework\TestCase;

final class AutoUpdaterTest extends TestCase
{
    public function test_empty_server_url_returns_null(): void
    {
        $updater = new AutoUpdater('1.0.0', '');
        self::assertNull($updater->checkForUpdate());
    }

    public function test_is_up_to_date_when_server_url_empty(): void
    {
        $updater = new AutoUpdater('1.0.0', '');
        self::assertTrue($updater->isUpToDate());
    }

    public function test_returns_null_on_network_error(): void
    {
        $updater = new AutoUpdater('1.0.0', 'http://localhost:19999/nonexistent-phpclaw-test');
        self::assertNull($updater->checkForUpdate());
    }

    public function test_returns_null_on_invalid_server_response(): void
    {
        $updater = new AutoUpdater('1.0.0', 'noop://invalid-scheme-for-test');
        self::assertNull($updater->checkForUpdate());
    }

    public function test_returns_null_when_server_responds_with_no_version_key(): void
    {
        $url = 'data:application/json,'.rawurlencode('{"status":"ok"}');
        $updater = new AutoUpdater('1.0.0', $url);
        self::assertNull($updater->checkForUpdate());
    }

    public function test_returns_null_when_server_version_not_newer(): void
    {
        $url = 'data:application/json,'.rawurlencode('{"version":"1.0.0","download_url":"https://phpclaw.ai/opencart.zip","changelog_url":"https://phpclaw.ai/changelog","released_at":"2026-01-01"}');
        $updater = new AutoUpdater('1.0.0', $url);
        self::assertNull($updater->checkForUpdate());
        self::assertTrue($updater->isUpToDate());
    }

    public function test_returns_null_when_server_version_older_than_current(): void
    {
        $url = 'data:application/json,'.rawurlencode('{"version":"0.5.0","download_url":"https://phpclaw.ai/opencart.zip","changelog_url":"https://phpclaw.ai/changelog","released_at":"2025-01-01"}');
        $updater = new AutoUpdater('2.0.0', $url);
        self::assertNull($updater->checkForUpdate());
    }

    public function test_returns_update_info_when_server_responds(): void
    {
        $json = json_encode([
            'version' => '9.9.9',
            'download_url' => 'https://phpclaw.ai/opencart.zip',
            'changelog_url' => 'https://phpclaw.ai/changelog',
            'released_at' => '2026-06-01',
        ]);

        $updater = new AutoUpdater(
            '1.0.0',
            'https://example.com/update',
            fn (string $url, mixed $context): string|false => $json,
        );

        $result = $updater->checkForUpdate();
        self::assertIsArray($result);
        self::assertSame('9.9.9', $result['version']);
        self::assertSame('https://phpclaw.ai/opencart.zip', $result['download_url']);
        self::assertFalse($updater->isUpToDate());
    }

    public function test_returns_null_when_server_returns_non_array_json(): void
    {
        $url = 'data:application/json,'.rawurlencode('"just a string"');
        $updater = new AutoUpdater('1.0.0', $url);
        self::assertNull($updater->checkForUpdate());
    }
}
