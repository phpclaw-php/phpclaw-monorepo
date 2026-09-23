<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Component\Administrator\Engine;

use Joomla\Database\DatabaseInterface;
use PhpClaw\Joomla\Component\Administrator\Tools\DatabaseTool;
use PhpClaw\Joomla\Component\Administrator\Tools\JoomlaArticleTool;
use PhpClaw\Joomla\Component\Administrator\Tools\JoomlaCategoryTool;
use PhpClaw\Joomla\Component\Administrator\Tools\JoomlaExtensionTool;
use PhpClaw\Joomla\Component\Administrator\Tools\JoomlaUserTool;
use PhpClaw\Joomla\Component\Administrator\Tools\JoomlaZipBuilderTool;
use PhpClaw\Tools\ToolCatalogue;
use PhpClaw\Tools\ToolProfileResolver;
use PhpClaw\Tools\ZipPackagerTool;

/**
 * Assembles the tool list for a given config.
 */
final class ToolBuilder
{
    public const TOOL_GROUPS = [
        'group:content' => ['joomla_articles', 'joomla_categories'],
        'group:admin' => ['joomla_users', 'joomla_extensions'],
        'group:system' => [
            'joomla_database_query', 'joomla_zip_extension', 'zip_package',
            'file_read', 'file_write', 'file_edit', 'code_search', 'project_info', 'shell_exec', 'http_request',
        ],
    ];

    /**
     * Build the tool list for the given config.
     *
     * @param  PhpClawConfig  $config
     * @param  bool  $applyProfile  Apply the provider/model profile slice (false = full list, for listings).
     * @param  bool  $allowPhpWrite  True to permit file_write to write .php/.phtml/.phar files.
     * @return array<int, object>
     */
    public function build(PhpClawConfig $config, bool $applyProfile = true, bool $allowPhpWrite = false): array
    {
        $extra = [];
        JoomlaEventDispatcher::fire('onPhpClawExtraTools', 'tools', $extra);

        $builtin = $this->builtinTools(JoomlaEventDispatcher::db(), $config, $allowPhpWrite);

        $all = array_merge($extra, $builtin);

        if (! $applyProfile) {
            return $all;
        }

        return ToolProfileResolver::filter($all, $config->toolDeny, self::TOOL_GROUPS);
    }

    /**
     * Built-in tools registered for every chat: the five Joomla-native tools, then the core
     * catalogue defaults, then the ZIP packagers when that core tool is available.
     *
     * @param  DatabaseInterface  $db
     * @param  PhpClawConfig  $config
     * @param  bool  $allowPhpWrite  True to permit file_write to write .php/.phtml/.phar files.
     * @return array<int, object>
     */
    private function builtinTools(DatabaseInterface $db, PhpClawConfig $config, bool $allowPhpWrite = false): array
    {
        $joomlaNative = [
            new JoomlaArticleTool($db),
            new JoomlaUserTool($db),
            new JoomlaCategoryTool($db),
            new JoomlaExtensionTool($db),
            new DatabaseTool($db),
        ];

        $coreUtility = class_exists(ToolCatalogue::class)
            ? ToolCatalogue::instantiateDefaults([
                'workspaceRoot' => $this->workspaceRoot(),
                'projectRoot' => $this->projectRoot(),
                'allowlist' => $config->shellAllowlist,
                'allowPhpWrite' => $allowPhpWrite,
            ])
            : [];

        if (class_exists(ZipPackagerTool::class)) {
            $coreUtility[] = new ZipPackagerTool($this->workspaceRoot());
            $coreUtility[] = new JoomlaZipBuilderTool($this->workspaceRoot());
        }

        return array_merge($joomlaNative, $coreUtility);
    }

    /**
     * Workspace directory for the file and shell tools.
     *
     * @return string
     */
    private function workspaceRoot(): string
    {
        return JPATH_ADMINISTRATOR.'/components/com_phpclaw/storage/workspace';
    }

    /**
     * Site root the project inspector reports on. Without it the tool falls back to the current
     * working directory, which is the launch directory of whatever process booted the engine.
     *
     * @return string
     */
    private function projectRoot(): string
    {
        return JPATH_ROOT;
    }
}
