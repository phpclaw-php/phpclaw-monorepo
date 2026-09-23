<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Tests\Unit\Admin;

use PhpClaw\PrestaShop\Admin\AutoUpdater;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AutoUpdater::class)]
final class AutoUpdaterTest extends TestCase
{
    private const SERVER_URL = 'https://updates.example.com/prestashop';

    private function respondWith(string $body): \Closure
    {
        return static fn (string $url, mixed $context): string|false => $body;
    }

    private function respondWithJson(array $payload): \Closure
    {
        return $this->respondWith((string) json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function test_check_for_update_returns_null_when_url_is_empty(): void
    {
        self::assertNull((new AutoUpdater('1.0.0', ''))->checkForUpdate());
    }

    public function test_is_up_to_date_returns_true_when_url_is_empty(): void
    {
        self::assertTrue((new AutoUpdater('1.0.0', ''))->isUpToDate());
    }

    public function test_check_for_update_returns_null_when_server_unreachable(): void
    {
        self::assertNull((new AutoUpdater('1.0.0', 'http://192.0.2.1/update'))->checkForUpdate());
    }

    public function test_is_up_to_date_true_when_server_unreachable(): void
    {
        self::assertTrue((new AutoUpdater('1.0.0', 'http://192.0.2.1/update'))->isUpToDate());
    }

    public function test_check_for_update_returns_null_on_invalid_url_scheme(): void
    {
        $updater = new AutoUpdater(
            '1.0.0',
            'ftp://localhost/noop',
            $this->respondWithJson(['version' => '9.9.9', 'download_url' => 'https://example.com/dl']),
        );

        self::assertNull($updater->checkForUpdate());
    }

    public function test_check_for_update_returns_null_on_plain_http_non_loopback_url(): void
    {
        $updater = new AutoUpdater(
            '1.0.0',
            'http://updates.example.com/prestashop',
            $this->respondWithJson(['version' => '9.9.9', 'download_url' => 'https://example.com/dl']),
        );

        self::assertNull($updater->checkForUpdate());
    }

    public function test_check_for_update_accepts_plain_http_loopback_url(): void
    {
        $updater = new AutoUpdater(
            '1.0.0',
            'http://localhost:8080/update',
            $this->respondWithJson(['version' => '2.0.0', 'download_url' => 'https://example.com/dl']),
        );

        $result = $updater->checkForUpdate();

        self::assertNotNull($result);
        self::assertSame('2.0.0', $result['version']);
    }

    public function test_check_for_update_returns_null_when_response_is_not_json(): void
    {
        $updater = new AutoUpdater('1.0.0', self::SERVER_URL, $this->respondWith('not json'));

        self::assertNull($updater->checkForUpdate());
    }

    public function test_check_for_update_returns_null_when_fetcher_fails(): void
    {
        $updater = new AutoUpdater(
            '1.0.0',
            self::SERVER_URL,
            static fn (string $url, mixed $context): string|false => false,
        );

        self::assertNull($updater->checkForUpdate());
    }

    public function test_check_for_update_returns_null_when_response_is_empty(): void
    {
        $updater = new AutoUpdater('1.0.0', self::SERVER_URL, $this->respondWith(''));

        self::assertNull($updater->checkForUpdate());
    }

    public function test_check_for_update_returns_null_when_fetcher_throws(): void
    {
        $updater = new AutoUpdater(
            '1.0.0',
            self::SERVER_URL,
            static function (string $url, mixed $context): string {
                throw new \RuntimeException('connection refused');
            },
        );

        self::assertNull($updater->checkForUpdate());
    }

    public function test_check_for_update_returns_null_when_version_not_newer(): void
    {
        $updater = new AutoUpdater(
            '1.0.0',
            self::SERVER_URL,
            $this->respondWithJson(['version' => '1.0.0', 'download_url' => 'https://example.com/dl']),
        );

        self::assertNull($updater->checkForUpdate());
    }

    public function test_check_for_update_returns_null_when_version_field_missing(): void
    {
        $updater = new AutoUpdater('1.0.0', self::SERVER_URL, $this->respondWithJson(['other' => 'field']));

        self::assertNull($updater->checkForUpdate());
    }

    public function test_check_for_update_returns_array_when_newer_version_available(): void
    {
        $updater = new AutoUpdater('1.0.0', self::SERVER_URL, $this->respondWithJson([
            'version' => '2.0.0',
            'download_url' => 'https://example.com/dl',
            'changelog_url' => 'https://example.com/cl',
            'released_at' => '2026-01-01',
        ]));

        $result = $updater->checkForUpdate();

        self::assertNotNull($result);
        self::assertIsArray($result);
        self::assertSame('2.0.0', $result['version']);
        self::assertSame('https://example.com/dl', $result['download_url']);
        self::assertSame('https://example.com/cl', $result['changelog_url']);
        self::assertSame('2026-01-01', $result['released_at']);
    }

    public function test_check_for_update_defaults_changelog_url_when_absent(): void
    {
        $updater = new AutoUpdater('1.0.0', self::SERVER_URL, $this->respondWithJson([
            'version' => '2.0.0',
            'download_url' => 'https://example.com/dl',
        ]));

        $result = $updater->checkForUpdate();

        self::assertNotNull($result);
        self::assertSame('https://phpclaw.ai/docs', $result['changelog_url']);
        self::assertSame('', $result['released_at']);
    }

    public function test_is_up_to_date_returns_false_when_newer_version_available(): void
    {
        $updater = new AutoUpdater(
            '1.0.0',
            self::SERVER_URL,
            $this->respondWithJson(['version' => '9.9.9', 'download_url' => 'https://example.com/dl']),
        );

        self::assertFalse($updater->isUpToDate());
    }
}
