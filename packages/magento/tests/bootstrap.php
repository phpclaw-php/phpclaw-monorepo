<?php

declare(strict_types=1);
use Magento\Framework\Phrase;

/**
 * PHPUnit bootstrap for the Magento adapter.
 *
 * Loads Composer autoloader first, then the Magento stub classes needed for
 * mocking. No real Magento installation is required for unit tests.
 */
$autoloader = require dirname(__DIR__).'/vendor/autoload.php';

require __DIR__.'/stubs.php';

(static function (): void {
    $composerDir = dirname(__DIR__).'/vendor/composer';
    $cachePath = $composerDir.'/phpclaw-discovery.php';

    if (is_file($cachePath)) {
        $installed = $composerDir.'/installed.json';
        if (! is_file($installed) || @filemtime($cachePath) >= @filemtime($installed)) {
            return;
        }
    }

    $guards = [
        'PhpClaw\\Guards\\MessageLengthGuard' => ['priority' => 0,  'name' => 'message_length', 'label' => 'Message Length',        'enabledByDefault' => true,  'since' => '1.0.0'],
        'PhpClaw\\Guards\\InjectionGuard' => ['priority' => 1,  'name' => 'injection',      'label' => 'Prompt Injection',       'enabledByDefault' => true,  'since' => '1.0.0'],
        'PhpClaw\\Guards\\UnicodeGuard' => ['priority' => 2,  'name' => 'unicode',         'label' => 'Unicode Injection',      'enabledByDefault' => true,  'since' => '1.0.0'],
        'PhpClaw\\Guards\\HomoglyphGuard' => ['priority' => 3,  'name' => 'homoglyph',       'label' => 'Homoglyph Substitution', 'enabledByDefault' => true,  'since' => '1.0.0'],
        'PhpClaw\\Guards\\RoleSwitchGuard' => ['priority' => 4,  'name' => 'role_switch',     'label' => 'Role Switch',            'enabledByDefault' => true,  'since' => '1.0.0'],
        'PhpClaw\\Guards\\CodeInjectionGuard' => ['priority' => 5,  'name' => 'code_injection',  'label' => 'Code Injection',         'enabledByDefault' => true,  'since' => '1.0.0'],
        'PhpClaw\\Guards\\PiiDetectionGuard' => ['priority' => 10, 'name' => 'pii_detection',   'label' => 'PII Detection',          'enabledByDefault' => false, 'since' => '1.0.0'],
        'PhpClaw\\Guards\\RateLimitGuard' => ['priority' => 20, 'name' => 'rate_limit',      'label' => 'Rate Limit',             'enabledByDefault' => false, 'since' => '1.0.0'],
        'PhpClaw\\Guards\\ToolOutputGuard' => ['priority' => 30, 'name' => 'tool_output',     'label' => 'Tool Output',            'enabledByDefault' => false, 'since' => '1.0.0'],
    ];

    $data = [
        'tools' => [],
        'providers' => [],
        'memory' => [],
        'skills' => [],
        'hooks' => [],
        'guards' => $guards,
    ];

    if (is_dir($composerDir) && is_writable($composerDir)) {
        $payload = "<?php\n\nreturn ".var_export($data, true).";\n";
        @file_put_contents($cachePath, $payload, LOCK_EX);
        @touch($cachePath, time() + 86400);
    }
})();

if (! function_exists('__')) {
    function __(string $text, mixed ...$args): Phrase
    {
        return new Phrase($text, $args);
    }
}
