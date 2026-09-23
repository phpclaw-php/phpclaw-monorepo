<?php

declare(strict_types=1);

use PhpClaw\Config\ToolConfig;

/**
 * phpClaw WordPress developer-only defaults - internal keys with no Settings-page field.
 */
return [

    'workspace_root' => defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR.'/uploads/phpclaw' : 'storage/phpclaw',

    'shell_allowlist' => ToolConfig::DEFAULT_SHELL_ALLOWLIST,

    'tool_deny' => [],

    'guards' => [],

    'hooks' => [],

    'skills' => [],

    'events_bridge' => true,

    'update_server' => 'https://phpclaw.ai/api/wp-update',
];
