<?php

declare(strict_types=1);

$_['heading_title'] = 'phpClaw AI Agent';

$_['entry_provider'] = 'Provider';
$_['entry_model'] = 'Model';
$_['entry_api_key'] = 'API Key';
$_['entry_max_iterations'] = 'Max Iterations';
$_['entry_store_messages'] = 'Store Messages';
$_['entry_base_url'] = 'Base URL';
$_['entry_cloud_key'] = 'Cloud Key';
$_['entry_cloud_signing_secret'] = 'Cloud Signing Secret';
$_['entry_cloud_disable'] = 'Disable Cloud Features';
$_['entry_remote_skill_urls'] = 'Remote Skill URLs';
$_['entry_system_prompt'] = 'System Prompt';

$_['help_api_key'] = 'Your API key for the selected provider. Leave blank for Ollama (local models). Not required for Custom if the endpoint is keyless.';
$_['help_base_url'] = 'Custom provider only. Full OpenAI-compatible /chat/completions endpoint.';
$_['help_store_messages'] = 'When enabled: prompt and response text is persisted via your configured memory driver, required for multi-turn chat to remember previous messages. When disabled: no message content is ever saved; each prompt is processed independently with no memory of previous turns.';
$_['help_max_iterations'] = 'Maximum tool-call iterations per request. Default: 20.';
$_['help_system_prompt'] = 'Optional. Customise the AI\'s persona and behaviour for your site.';
$_['help_cloud_key'] = 'phpClaw Cloud API key. Enables cloud guards and webhook features. Requires Store Messages to be enabled; cloud features stay off while message storage is disabled.';
$_['help_cloud_signing_secret'] = 'Shared secret used to verify signed cloud scan responses. Copy it from your phpClaw Cloud dashboard when you create the API key. Leave empty to skip signature verification.';
$_['help_cloud_disable'] = 'Comma-separated cloud feature names to disable. Leave empty to enable all features from your plan. Only applies when a Cloud Key is set above.';
$_['help_cloud_inactive']       = 'Cloud fields are hidden because Store Messages is off. Your saved cloud settings are kept and will reappear when you turn it back on.';
$_['help_remote_skill_urls'] = 'One HTTPS URL per line (commas also accepted). Each points to a phpClaw skill collection (JSON) or a single SKILL.md. Loaded on every engine build.';
