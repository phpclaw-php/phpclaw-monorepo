<?php

declare(strict_types=1);

/**
 * Plugin Name:       phpClaw
 * Plugin URI:        https://phpclaw.ai
 * Description:       Universal AI agent engine for WordPress & WooCommerce. WP-CLI, REST API, and admin settings.
 * Version:           1.0.0
 * Requires at least: 6.2
 * Requires PHP:      8.1
 * Author:            Akash Patel
 * Author URI:        https://phpclaw.ai
 * License:           MIT
 * Text Domain:       phpclaw
 * Domain Path:       /languages
 * WC requires at least: 7.0
 * WC tested up to:      9.0
 * Tested up to:         7.0
 */
if (! defined('ABSPATH')) {
    exit;
}

add_action('before_woocommerce_init', static function (): void {
    if (class_exists(FeaturesUtil::class)) {
        FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            __FILE__,
            true,
        );
    }
});

define('PHPCLAW_PLUGIN_FILE', __FILE__);

$_phpclaw_header = function_exists('get_file_data')
    ? get_file_data(__FILE__, ['Version' => 'Version'])
    : ['Version' => ''];
define('PHPCLAW_VERSION', $_phpclaw_header['Version'] !== '' ? $_phpclaw_header['Version'] : '0.0.0');
unset($_phpclaw_header);

if (file_exists(__DIR__.'/vendor/autoload.php')) {
    require_once __DIR__.'/vendor/autoload.php';
}

use Automattic\WooCommerce\Utilities\FeaturesUtil;
use PhpClaw\WordPress\Plugin;

register_activation_hook(__FILE__, static function (): void {
    if (file_exists(__DIR__.'/vendor/autoload.php')) {
        require_once __DIR__.'/vendor/autoload.php';
    }

    Plugin::activate();
});

/**
 * Bootstrap the plugin on plugins_loaded, once all WP functions are available and other plugins can hook in.
 */
add_action('plugins_loaded', static function (): void {
    load_plugin_textdomain(
        'phpclaw',
        false,
        dirname(plugin_basename(__FILE__)).'/languages',
    );

    Plugin::getInstance();
}, 1);
