<?php

declare(strict_types=1);

/**
 * phpClaw AI Agent Engine for OpenCart 3 and 4, requiring PHP 8.1. Extension metadata
 * is declared in install.json and install.xml, which are what OpenCart actually reads.
 */
if (! defined('VERSION')) {
    exit('OpenCart context required.');
}

define('PHPCLAW_OC_FILE', __FILE__);

define('PHPCLAW_OC_DIR', __DIR__);

define('PHPCLAW_VERSION', '1.0.0');

if (file_exists(__DIR__.'/vendor/autoload.php')) {
    require_once __DIR__.'/vendor/autoload.php';
}
