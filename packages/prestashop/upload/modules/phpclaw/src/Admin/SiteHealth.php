<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Admin;

use PhpClaw\ClawConfig;
use PhpClaw\PrestaShop\Contracts\PsDbInterface;
use PhpClaw\PrestaShop\Plugin;

/**
 * Diagnostic reporter returning a structured phpClaw status report.
 */
final class SiteHealth
{
    /**
     * Build the full diagnostic report array.
     *
     * @param  Plugin  $plugin  Plugin singleton
     * @param  PsDbInterface|null  $db  Native DB handle (for table checks)
     * @param  string  $prefix  Table prefix (e.g. 'ps_')
     * @return array<string, mixed>
     */
    public static function report(Plugin $plugin, ?PsDbInterface $db = null, string $prefix = 'ps_'): array
    {
        $config = $plugin->config();
        $saved = $plugin->saved();

        $provider = (string) (($saved['provider'] ?? '') ?: ($config['provider'] ?? 'not set'));
        $model = (string) (($saved['model'] ?? '') ?: ($config['model'] ?? 'not set'));
        $memoryDriver = 'ps_router';
        $storeMessages = (bool) ($saved['store_messages'] ?? true);
        $maxIter = (int) (($saved['max_iterations'] ?? 0) ?: ($config['max_iterations'] ?? ClawConfig::DEFAULT_MAX_ITERATIONS));

        $toolCount = 0;
        try {
            $engine = $plugin->engine();
            if (method_exists($engine, 'tools')) {
                $toolCount = count($engine->tools());
            }
        } catch (\Throwable) {
        }

        $tables = self::checkTables($db, $prefix);

        $cloudKey = (string) ($config['cloud_key'] ?? '');
        $cloudEnabled = $cloudKey !== '';

        $apiTokenCount = self::countApiTokens($db, $prefix);
        $systemPromptSet = trim((string) ($saved['system_prompt'] ?? '')) !== '';

        return [
            'phpClaw_version' => class_exists(\Phpclaw::class) ? \Phpclaw::PHPCLAW_VERSION : 'unknown',
            'php_version' => PHP_VERSION,
            'prestashop_version' => _PS_VERSION_,
            'provider' => $provider,
            'model' => $model,
            'memory_driver' => $memoryDriver,
            'max_iterations' => $maxIter,
            'tool_count' => $toolCount,
            'store_messages' => $storeMessages,
            'system_prompt_set' => $systemPromptSet,
            'api_token_count' => $apiTokenCount,
            'cloud_enabled' => $cloudEnabled,
            'db_tables' => $tables,
        ];
    }

    /**
     * Build a human-readable status string for use in admin notices or logs.
     *
     * @param  Plugin  $plugin
     * @param  ?PsDbInterface  $db
     * @param  string  $prefix
     * @return string
     */
    public static function summary(Plugin $plugin, ?PsDbInterface $db = null, string $prefix = 'ps_'): string
    {
        $r = self::report($plugin, $db, $prefix);
        $ok = array_filter($r['db_tables'], fn (mixed $v): bool => $v === true);
        $miss = array_filter($r['db_tables'], fn (mixed $v): bool => $v === false);

        $lines = [
            "phpClaw {$r['phpClaw_version']} on PS {$r['prestashop_version']} / PHP {$r['php_version']}",
            "Provider: {$r['provider']} | Model: {$r['model']}",
            "Memory: {$r['memory_driver']} | Max iterations: {$r['max_iterations']}",
            "Tools: {$r['tool_count']} | Store messages: ".($r['store_messages'] ? 'yes' : 'no'),
            'DB tables OK: '.implode(', ', array_keys($ok)),
        ];

        if ($miss !== []) {
            $lines[] = '⚠ Missing tables: '.implode(', ', array_keys($miss));
        }

        if ($r['api_token_count'] === 0) {
            $lines[] = '⚠ No REST API token issued: open Settings to issue one for an employee.';
        }

        return implode("\n", $lines);
    }

    /**
     * Count the REST API tokens issued on this shop.
     *
     * @param  PsDbInterface|null  $db  Native DB handle, or null to report zero.
     * @param  string  $prefix  Table prefix (e.g. 'ps_').
     * @return int Issued token count, 0 when the table is absent or unreadable.
     */
    private static function countApiTokens(?PsDbInterface $db, string $prefix): int
    {
        if ($db === null) {
            return 0;
        }

        try {
            $rows = $db->query("SELECT COUNT(*) AS c FROM `{$prefix}phpclaw_api_token`")->rows;

            return (int) ($rows[0]['c'] ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Probe each required table with a cheap read, mapping name to existence.
     *
     * @param  PsDbInterface|null  $db  Native DB handle, or null to mark all tables missing.
     * @param  string  $prefix  Table prefix (e.g. 'ps_').
     * @return array<string, bool> Map of table name → exists.
     */
    private static function checkTables(?PsDbInterface $db, string $prefix): array
    {
        $required = [
            "{$prefix}phpclaw_memory",
            "{$prefix}phpclaw_conversations",
            "{$prefix}phpclaw_messages",
            "{$prefix}phpclaw_api_token",
        ];

        $status = [];

        foreach ($required as $table) {
            if ($db === null) {
                $status[$table] = false;

                continue;
            }
            try {
                $db->query("SELECT 1 FROM `{$table}` LIMIT 1");
                $status[$table] = true;
            } catch (\Throwable) {
                $status[$table] = false;
            }
        }

        return $status;
    }
}
