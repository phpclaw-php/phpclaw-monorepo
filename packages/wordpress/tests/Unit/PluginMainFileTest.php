<?php

declare(strict_types=1);

namespace PhpClaw\WordPress\Tests\Unit;

use Automattic\WooCommerce\Utilities\FeaturesUtil;
use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class PluginMainFileTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_main_file_declares_woocommerce_hpos_compatibility(): void
    {
        require_once __DIR__.'/../Stubs/FeaturesUtil.php';

        Monkey\setUp();
        Functions\when('get_file_data')->justReturn(['Version' => '1.0.0']);
        Functions\when('register_activation_hook')->justReturn(null);

        $callback = null;
        Actions\expectAdded('before_woocommerce_init')
            ->once()
            ->whenHappen(static function (callable $registered) use (&$callback): void {
                $callback = $registered;
            });

        if (! defined('ABSPATH')) {
            define('ABSPATH', sys_get_temp_dir().'/');
        }

        require __DIR__.'/../../phpclaw.php';

        self::assertIsCallable($callback);
        $callback();

        $pluginFile = realpath(__DIR__.'/../../phpclaw.php');
        self::assertSame([['custom_order_tables', $pluginFile, true]], FeaturesUtil::$declared);

        Monkey\tearDown();
    }
}
