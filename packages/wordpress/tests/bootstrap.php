<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap for phpClaw WordPress adapter unit tests.
 *
 * Uses Brain\Monkey to stub WordPress global functions (get_option, update_option,
 * add_action, register_rest_route, etc.) without requiring a full WP install.
 */

require_once __DIR__.'/../vendor/autoload.php';

require_once __DIR__.'/Stubs/WP_Post.php';
require_once __DIR__.'/Stubs/WP_User.php';
require_once __DIR__.'/Stubs/wpdb.php';

require_once __DIR__.'/Stubs/WC_Order.php';
require_once __DIR__.'/Stubs/WC_Product.php';
require_once __DIR__.'/Stubs/WC_Customer.php';
require_once __DIR__.'/Stubs/WC_Coupon.php';
require_once __DIR__.'/Stubs/WC_Shipping_Zone.php';
require_once __DIR__.'/Stubs/WC_Tax.php';
require_once __DIR__.'/Stubs/ConfigurableToolStubs.php';
