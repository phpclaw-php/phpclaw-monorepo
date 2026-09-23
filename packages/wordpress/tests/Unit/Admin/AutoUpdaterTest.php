<?php

declare(strict_types=1);

namespace PhpClaw\WordPress\Tests\Unit\Admin;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PhpClaw\WordPress\Admin\AutoUpdater;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AutoUpdater::class)]
final class AutoUpdaterTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        if (! defined('HOUR_IN_SECONDS')) {
            define('HOUR_IN_SECONDS', 3600);
        }
        if (! defined('WP_PLUGIN_DIR')) {
            define('WP_PLUGIN_DIR', '/tmp/wp-content/plugins');
        }

        Functions\stubs([
            'plugin_basename' => static fn (string $file): string => 'phpclaw/'.basename($file),
            'add_filter' => static fn (...$args): bool => true,
            'is_wp_error' => static fn (mixed $thing): bool => is_object($thing) && method_exists($thing, 'get_error_message'),
            'wp_remote_retrieve_response_code' => static function (mixed $r): int {
                return is_array($r) ? ($r['response']['code'] ?? 0) : 0;
            },
            'wp_remote_retrieve_body' => static function (mixed $r): string {
                return is_array($r) ? ($r['body'] ?? '') : '';
            },
        ]);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_init_skips_when_update_url_empty(): void
    {
        $hooks = [];
        Functions\when('add_filter')->alias(static function (string $h) use (&$hooks): bool {
            $hooks[] = $h;

            return true;
        });

        AutoUpdater::init('/tmp/phpclaw/phpclaw.php', '1.0.0', '');

        self::assertEmpty($hooks);
    }

    public function test_init_registers_three_filters_when_url_set(): void
    {
        $hooks = [];
        Functions\when('add_filter')->alias(static function (string $h) use (&$hooks): bool {
            $hooks[] = $h;

            return true;
        });

        AutoUpdater::init('/tmp/phpclaw/phpclaw.php', '1.0.0', 'https://example.com/update');

        self::assertContains('pre_set_site_transient_update_plugins', $hooks);
        self::assertContains('plugins_api', $hooks);
        self::assertContains('upgrader_post_install', $hooks);
    }

    private function makeUpdater(string $version = '1.0.0'): AutoUpdater
    {
        $ref = new \ReflectionClass(AutoUpdater::class);
        $instance = $ref->newInstanceWithoutConstructor();

        foreach ([
            'pluginFile' => '/tmp/phpclaw/phpclaw.php',
            'currentVersion' => $version,
            'updateUrl' => 'https://example.com/update',
        ] as $prop => $value) {
            $p = $ref->getProperty($prop);
            $p->setAccessible(true);
            $p->setValue($instance, $value);
        }

        $slugProp = $ref->getProperty('pluginSlug');
        $slugProp->setAccessible(true);
        $slugProp->setValue($instance, 'phpclaw/phpclaw.php');

        return $instance;
    }

    public function test_check_for_update_returns_transient_unchanged_when_empty(): void
    {
        $transient = (object) ['checked' => []];

        $updater = $this->makeUpdater();
        $r = $updater->checkForUpdate($transient);

        self::assertSame($transient, $r);
    }

    public function test_check_for_update_returns_transient_when_remote_fetch_fails(): void
    {
        Functions\expect('get_transient')->once()->andReturn(false);
        Functions\expect('wp_remote_get')->once()->andReturn(['response' => ['code' => 500], 'body' => '']);
        Functions\expect('set_transient')->once()->with('phpclaw_update_check', 'none', 3600);

        $transient = (object) ['checked' => ['phpclaw/phpclaw.php' => '1.0.0']];

        $updater = $this->makeUpdater();
        $r = $updater->checkForUpdate($transient);

        self::assertSame($transient, $r);
    }

    public function test_check_for_update_inserts_response_when_new_version_available(): void
    {
        $body = [
            'version' => '1.1.0',
            'download_url' => 'https://github.com/phpclaw/phpclaw/releases/download/v1.1.0/phpclaw-1.1.0.zip',
            'requires_php' => '8.1',
            'requires' => '6.0',
            'tested' => '6.7',
        ];

        Functions\expect('get_transient')->once()->andReturn(false);
        Functions\expect('wp_remote_get')->once()->andReturn([
            'response' => ['code' => 200],
            'body' => json_encode($body),
        ]);
        Functions\expect('set_transient')->once();

        $transient = (object) ['checked' => ['phpclaw/phpclaw.php' => '1.0.0'], 'response' => []];

        $updater = $this->makeUpdater();
        $r = $updater->checkForUpdate($transient);

        self::assertArrayHasKey('phpclaw/phpclaw.php', $r->response);
        self::assertSame('1.1.0', $r->response['phpclaw/phpclaw.php']->new_version);
        self::assertSame('https://github.com/phpclaw/phpclaw/releases/download/v1.1.0/phpclaw-1.1.0.zip', $r->response['phpclaw/phpclaw.php']->package);
    }

    public function test_check_for_update_refuses_untrusted_download_host(): void
    {
        $body = [
            'version' => '1.1.0',
            'download_url' => 'https://evil.example/phpclaw-1.1.0.zip',
        ];

        Functions\expect('get_transient')->once()->andReturn(false);
        Functions\expect('wp_remote_get')->once()->andReturn([
            'response' => ['code' => 200],
            'body' => json_encode($body),
        ]);
        Functions\expect('set_transient')->once();

        $transient = (object) ['checked' => ['phpclaw/phpclaw.php' => '1.0.0'], 'response' => []];

        $updater = $this->makeUpdater();
        $r = $updater->checkForUpdate($transient);

        self::assertArrayNotHasKey('phpclaw/phpclaw.php', $r->response);
    }

    public function test_check_for_update_uses_cached_transient(): void
    {
        $body = [
            'version' => '1.1.0',
            'download_url' => 'https://phpclaw.ai/download/x.zip',
        ];

        Functions\expect('get_transient')->once()->andReturn($body);
        Functions\expect('wp_remote_get')->never();

        $transient = (object) ['checked' => ['phpclaw/phpclaw.php' => '1.0.0'], 'response' => []];

        $updater = $this->makeUpdater();
        $r = $updater->checkForUpdate($transient);

        self::assertArrayHasKey('phpclaw/phpclaw.php', $r->response);
    }

    public function test_plugin_info_returns_result_for_other_actions(): void
    {
        $updater = $this->makeUpdater();
        $result = $updater->pluginInfo(false, 'plugin_install', (object) ['slug' => 'phpclaw']);

        self::assertFalse($result);
    }

    public function test_plugin_info_returns_result_for_other_plugin_slug(): void
    {
        $updater = $this->makeUpdater();
        $result = $updater->pluginInfo(false, 'plugin_information', (object) ['slug' => 'akismet']);

        self::assertFalse($result);
    }

    public function test_plugin_info_returns_object_when_args_match(): void
    {
        $body = [
            'version' => '1.2.0',
            'download_url' => 'https://phpclaw.ai/download/phpclaw-1.2.0.zip',
        ];

        Functions\expect('get_transient')->once()->andReturn($body);

        $updater = $this->makeUpdater();
        $info = $updater->pluginInfo(false, 'plugin_information', (object) ['slug' => 'phpclaw']);

        self::assertIsObject($info);
        self::assertSame('1.2.0', $info->version);
        self::assertSame('https://phpclaw.ai/download/phpclaw-1.2.0.zip', $info->download_link);
    }

    public function test_after_install_skips_move_when_no_fs(): void
    {
        global $wp_filesystem;
        $wp_filesystem = null;

        Functions\expect('is_plugin_active')->once()->andReturnFalse();

        $updater = $this->makeUpdater();
        $r = $updater->afterInstall(true, [], ['destination' => '/foo/bar']);

        self::assertSame('/foo/bar', $r['destination']);
    }

    public function test_after_install_reactivates_plugin_when_previously_active(): void
    {
        global $wp_filesystem;
        $wp_filesystem = null;

        $activated = false;
        Functions\expect('is_plugin_active')->once()->andReturnTrue();
        Functions\when('activate_plugin')->alias(static function ($file) use (&$activated): bool {
            $activated = true;

            return true;
        });

        $updater = $this->makeUpdater();
        $updater->afterInstall(true, [], ['destination' => '/foo/bar']);

        self::assertTrue($activated);
    }

    public function test_fetch_remote_info_skips_invalid_response_body(): void
    {
        Functions\expect('get_transient')->once()->andReturn(false);
        Functions\expect('wp_remote_get')->once()->andReturn([
            'response' => ['code' => 200],
            'body' => 'not-json',
        ]);
        Functions\expect('set_transient')->once();

        $transient = (object) ['checked' => ['phpclaw/phpclaw.php' => '1.0.0'], 'response' => []];

        $updater = $this->makeUpdater();
        $r = $updater->checkForUpdate($transient);

        self::assertSame([], $r->response);
    }
}
