<?php

declare(strict_types=1);
use PhpClaw\ClawConfig;

/**
 * phpClaw PrestaShop module developer-only keys (no user-facing settings here).
 */
return [
    'workspace_root' => defined('PHPCLAW_WORKSPACE_ROOT')
        ? PHPCLAW_WORKSPACE_ROOT
        : (defined('_PS_ROOT_DIR_') ? _PS_ROOT_DIR_.'/storage/phpclaw' : 'storage/phpclaw'),

    'shell_allowlist' => defined('PHPCLAW_SHELL_ALLOWLIST')
        ? explode(',', PHPCLAW_SHELL_ALLOWLIST)
        : ClawConfig::DEFAULT_SHELL_ALLOWLIST,

    'tool_deny' => [],

    'guards' => [],

    'hooks' => [],

    'skills' => [],

    'events_bridge' => defined('PHPCLAW_EVENTS_BRIDGE')
        ? (bool) PHPCLAW_EVENTS_BRIDGE
        : true,

    'update_server' => defined('PHPCLAW_UPDATE_SERVER')
        ? PHPCLAW_UPDATE_SERVER
        : 'https://phpclaw.ai/api/ps-update',
];
