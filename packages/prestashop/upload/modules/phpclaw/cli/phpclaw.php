<?php

declare(strict_types=1);

use PhpClaw\Mcp\PhpClawMcpServer;
use PhpClaw\Mcp\StdoutPurity;
use PhpClaw\Mcp\Transport\StdioTransport;
use PhpClaw\PrestaShop\CLI\PhpClawCommand;
use PhpClaw\PrestaShop\Db\PsDbAdapter;
use PhpClaw\PrestaShop\Plugin;
use PhpClaw\Tools\ToolRegistry;

/**
 * phpClaw CLI entry script for PrestaShop 8 and 9.
 */
require_once dirname(__DIR__).'/vendor/phpclaw/phpclaw-mcp/src/StdoutPurity.php';

StdoutPurity::beginCapture();

if (PHP_SAPI !== 'cli') {
    header('HTTP/1.1 403 Forbidden');
    exit('This script may only be run from the command line.');
}

define('PHPCLAW_PS_CONSOLE', true);

$psRoot = dirname(__DIR__, 3);
$moduleDir = dirname(__DIR__);

$psConfig = $psRoot.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'config.inc.php';

if (! file_exists($psConfig)) {
    fwrite(STDERR, "phpClaw: PrestaShop config not found at {$psConfig}\n");
    fwrite(STDERR, "        Make sure cli/phpclaw.php is inside your PrestaShop root.\n");
    exit(1);
}

require_once $psConfig;

if (! defined('_PS_VERSION_')) {
    define('_PS_VERSION_', '8.0.0');
}

$autoloader = $moduleDir.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'autoload.php';

if (! file_exists($autoloader)) {
    fwrite(STDERR, "phpClaw: autoloader not found at {$autoloader}\n");
    fwrite(STDERR, "        Re-install the phpClaw module via the Back Office Module Manager.\n");
    exit(1);
}

require_once $autoloader;

StdoutPurity::endCaptureAndRoute();

$tablePrefix = defined('_DB_PREFIX_') ? _DB_PREFIX_ : 'ps_';

$db = new PsDbAdapter(Db::getInstance());
$plugin = Plugin::getInstance($db, $tablePrefix, 0, false, true);

$cliArgv = $_SERVER['argv'] ?? [];
$sub = $cliArgv[1] ?? '';
$rest = array_slice($cliArgv, 2);

/**
 * Split argv into positional args and --key[=value] flags.
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
    'send' => (static function () use ($plugin, $rest): void {
        ['args' => $args, 'flags' => $flags] = parseArgs($rest);
        (new PhpClawCommand($plugin))->run($args, $flags);
    })(),
    'mcp-server' => (static function () use ($plugin): void {
        $registry = new ToolRegistry;
        $registry->register($plugin->guideTools());

        if (StdoutPurity::reassertBeforeFrame()) {
            fwrite(STDERR, "phpClaw: display_errors was reset downstream and has been routed back to stderr.\n");
        }

        (new PhpClawMcpServer($registry))->serve(new StdioTransport);
    })(),
    default => (static function (): never {
        echo "phpClaw CLI: AI agent runner for PrestaShop\n\n";
        echo "Usage:\n";
        echo "  php modules/phpclaw/cli/phpclaw.php send \"<prompt>\" [--stream] [--provider=<p>] [--model=<m>]\n";
        echo "  php modules/phpclaw/cli/phpclaw.php mcp-server\n\n";
        echo "Examples:\n";
        echo "  php modules/phpclaw/cli/phpclaw.php send \"what are my top 5 products by revenue?\"\n";
        echo "  php modules/phpclaw/cli/phpclaw.php send \"check stock levels\" --stream\n";
        echo "  php modules/phpclaw/cli/phpclaw.php send \"summarise orders\" --provider=openai --model=gpt-4o\n";
        exit(0);
    })(),
};
