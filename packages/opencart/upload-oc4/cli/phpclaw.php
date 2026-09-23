<?php

declare(strict_types=1);

/**
 * phpClaw CLI entry script for OpenCart 3/4.
 */

use Opencart\System\Engine\Autoloader;
use PhpClaw\Mcp\PhpClawMcpServer;
use PhpClaw\Mcp\Transport\StdioTransport;
use PhpClaw\OpenCart\CLI\PhpClawCommand;
use PhpClaw\OpenCart\Db\OcDbAdapter;
use PhpClaw\OpenCart\Plugin;
use PhpClaw\Tools\ToolRegistry;

if (PHP_SAPI !== 'cli') {
    header('HTTP/1.1 403 Forbidden');
    exit('This script may only be run from the command line.');
}

define('PHPCLAW_OC_CONSOLE', true);

$ocRoot = dirname(__DIR__);

if (! file_exists($ocRoot.DIRECTORY_SEPARATOR.'config.php')) {
    $candidate = $ocRoot;
    for ($i = 0; $i < 6; $i++) {
        $candidate = dirname($candidate);
        if (file_exists($candidate.DIRECTORY_SEPARATOR.'config.php')
            && is_dir($candidate.DIRECTORY_SEPARATOR.'admin')
            && is_dir($candidate.DIRECTORY_SEPARATOR.'system')) {
            $ocRoot = $candidate;
            break;
        }
    }
}

$ocConfig = $ocRoot.DIRECTORY_SEPARATOR.'config.php';

if (! file_exists($ocConfig)) {
    fwrite(STDERR, "phpClaw: OpenCart config.php not found at {$ocConfig}\n");
    fwrite(STDERR, "        Run phpclaw.php from its cli/ folder inside your OpenCart installation.\n");
    exit(1);
}

set_error_handler(static function (int $errno): bool {
    return ($errno & (E_DEPRECATED | E_USER_DEPRECATED)) !== 0;
});

require_once $ocConfig;

if (! defined('VERSION')) {
    define('VERSION', '3.0.0.0');
}

require_once DIR_SYSTEM.'startup.php';
restore_error_handler();

if (class_exists('\\Opencart\\System\\Engine\\Autoloader')) {
    $oc4Loader = new Autoloader;
    $oc4Loader->register('Opencart\\System', DIR_SYSTEM);
    if (defined('DIR_EXTENSION')) {
        $oc4Loader->register('Opencart\\Extension', DIR_EXTENSION);
    }
    if (defined('APPLICATION') && defined('DIR_APPLICATION')) {
        $oc4Loader->register('Opencart\\'.APPLICATION, DIR_APPLICATION);
    }
    unset($oc4Loader);
}

require_once DIR_SYSTEM.'library/db.php';

$phpClawDir = $ocRoot.DIRECTORY_SEPARATOR.'extension'.DIRECTORY_SEPARATOR.'phpclaw';
if (! is_dir($phpClawDir)) {
    $phpClawDir = $ocRoot.DIRECTORY_SEPARATOR.'system'.DIRECTORY_SEPARATOR.'library'.DIRECTORY_SEPARATOR.'phpclaw';
}
if (! is_dir($phpClawDir)) {
    $phpClawDir = $ocRoot.DIRECTORY_SEPARATOR.'phpclaw';
}

$autoloader = $phpClawDir.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'autoload.php';

if (! file_exists($autoloader)) {
    fwrite(STDERR, "phpClaw: autoloader not found at {$autoloader}\n");
    fwrite(STDERR, "        Re-install the phpClaw extension via the OpenCart Extension Installer.\n");
    exit(1);
}

require_once $autoloader;

$bootstrap = $phpClawDir.DIRECTORY_SEPARATOR.'phpclaw.php';

if (! file_exists($bootstrap)) {
    fwrite(STDERR, "phpClaw: bootstrap file not found at {$bootstrap}\n");
    exit(1);
}

require_once $bootstrap;

$dbClass = class_exists('\\Opencart\\System\\Library\\DB') ? '\\Opencart\\System\\Library\\DB' : 'DB';

try {
    $db = new $dbClass(
        DB_DRIVER,
        DB_HOSTNAME,
        DB_USERNAME,
        DB_PASSWORD,
        DB_DATABASE,
        defined('DB_PORT') ? DB_PORT : '3306',
    );
} catch (Exception $e) {
    fwrite(STDERR, "phpClaw: database connection failed. {$e->getMessage()}\n");
    exit(1);
}

$tablePrefix = defined('DB_PREFIX') ? DB_PREFIX : 'oc_';

$dbAdapter = new OcDbAdapter($db);
$plugin = Plugin::getInstance($tablePrefix, null, $dbAdapter);

$argv = $_SERVER['argv'] ?? [];
$sub = $argv[1] ?? '';
$rest = array_slice($argv, 2);

/**
 * Split remaining argv into positional args and --key[=value] flags.
 *
 * @param  string[]  $argv
 * @return array{args: string[], flags: array<string,string>}
 */
function parseArgs(array $argv): array
{
    $args = [];
    $flags = [];

    foreach ($argv as $item) {
        if (str_starts_with($item, '--')) {
            $part = substr($item, 2);
            $eq = strpos($part, '=');
            $key = $eq !== false ? substr($part, 0, $eq) : $part;
            $value = $eq !== false ? substr($part, $eq + 1) : 'true';
            $flags[$key] = $value;
        } else {
            $args[] = $item;
        }
    }

    return ['args' => $args, 'flags' => $flags];
}

match ($sub) {
    'send' => (static function () use ($rest, $plugin): void {
        ['args' => $args, 'flags' => $flags] = parseArgs($rest);
        (new PhpClawCommand($plugin))->run($args, $flags);
    })(),
    'mcp-server' => (static function () use ($plugin): void {
        $registry = new ToolRegistry;
        $registry->register($plugin->guideTools(callerMayUseModule: true));
        (new PhpClawMcpServer($registry))->serve(new StdioTransport);
    })(),
    default => (static function (): void {
        echo "phpClaw CLI: AI agent runner for OpenCart\n\n";
        echo "Usage:\n";
        echo "  php phpclaw.php send \"<prompt>\" [--stream] [--provider=<p>] [--model=<m>]\n";
        echo "  php phpclaw.php mcp-server\n";
        echo "\n";
        echo "Examples:\n";
        echo "  php phpclaw.php send \"what are my top 5 products by revenue?\"\n";
        echo "  php phpclaw.php send \"summarise today's orders\" --stream\n";
        exit(0);
    })(),
};
