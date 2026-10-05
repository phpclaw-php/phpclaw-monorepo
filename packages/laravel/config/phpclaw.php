<?php

declare(strict_types=1);
use PhpClaw\ClawConfig;
use PhpClaw\Config\ToolConfig;

$providerKeyVariables = [
    'ANTHROPIC_API_KEY',
    'OPENAI_API_KEY',
    'GROQ_API_KEY',
    'GEMINI_API_KEY',
    'MISTRAL_API_KEY',
    'DEEPSEEK_API_KEY',
];

$apiKey = '';

foreach ($providerKeyVariables as $providerKeyVariable) {
    $candidate = trim((string) env($providerKeyVariable, ''));

    if ($candidate !== '') {
        $apiKey = $candidate;
        break;
    }
}

return [
    'api_key' => $apiKey,
    'provider' => env('PHPCLAW_PROVIDER', ''),
    'model' => env('PHPCLAW_MODEL', ''),
    'base_url' => env('PHPCLAW_BASE_URL', ''),
    'store_messages' => env('PHPCLAW_STORE_MESSAGES', true),
    'max_iterations' => (int) env('PHPCLAW_MAX_ITERATIONS', ClawConfig::DEFAULT_MAX_ITERATIONS),
    'memory_driver' => env('PHPCLAW_MEMORY_DRIVER', 'database'),
    'workspace_root' => env('PHPCLAW_WORKSPACE', storage_path('phpclaw')),

    'system_prompt' => env('PHPCLAW_SYSTEM_PROMPT', ''),

    'max_tokens' => (int) env('PHPCLAW_MAX_TOKENS', 0),

    'prompt_cache' => (bool) env('PHPCLAW_PROMPT_CACHE', false),

    'thinking_budget' => (int) env('PHPCLAW_THINKING_BUDGET', 0),

    'fallback_provider' => env('PHPCLAW_FALLBACK_PROVIDER', ''),
    'fallback_model' => env('PHPCLAW_FALLBACK_MODEL', ''),
    'fallback_api_key' => env('PHPCLAW_FALLBACK_API_KEY', ''),

    'rate_limit_rpm' => (int) env('PHPCLAW_RATE_LIMIT_RPM', 0),

    'response_cache' => (bool) env('PHPCLAW_RESPONSE_CACHE', false),
    'response_cache_ttl' => (int) env('PHPCLAW_RESPONSE_CACHE_TTL', 3600),

    'max_token_budget' => (int) env('PHPCLAW_MAX_TOKEN_BUDGET', 0),

    'tool_deny' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('PHPCLAW_TOOL_DENY', ''))
    ))),

    'shell_allowlist' => ToolConfig::DEFAULT_SHELL_ALLOWLIST,

    'tools' => [],

    'guards' => [],

    'hooks' => [],

    'skills' => [],

    'remote_skill_urls' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('PHPCLAW_REMOTE_SKILL_URLS', ''))
    ))),

    'events' => [
        'bridge' => env('PHPCLAW_EVENTS_BRIDGE', true),
    ],

    'telescope' => env('PHPCLAW_TELESCOPE', true),

    'api' => [
        'enabled' => (bool) env('PHPCLAW_API_ENABLED', true),
        'prefix' => env('PHPCLAW_API_PREFIX', 'phpclaw'),

        'middleware' => ['api', 'auth:sanctum'],

        'throttle' => env('PHPCLAW_API_THROTTLE', '60,1'),
    ],

    'admin_ids' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('PHPCLAW_ADMIN_IDS', ''))
    ))),

    'worker_commands' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('PHPCLAW_WORKER_COMMANDS', ''))
    ))),

    'cloud_key' => env('PHPCLAW_CLOUD_KEY', ''),
    'cloud_signing_secret' => env('PHPCLAW_CLOUD_SIGNING_SECRET', ''),
    'cloud_disable' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('PHPCLAW_CLOUD_DISABLE', ''))
    ))),
];
