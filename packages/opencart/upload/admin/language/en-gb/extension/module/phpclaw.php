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

$_['help_api_key'] = 'Your API key for the selected provider. Leave blank for Ollama (local models).';
$_['help_base_url'] = 'For the Custom provider only. The full OpenAI-compatible /chat/completions endpoint URL. Enter your API key above if the endpoint requires one.';
$_['help_store_messages'] = 'When enabled: prompt and response text is saved, so a conversation remembers previous messages. When disabled: no message content is ever saved; each prompt is processed independently.';
$_['help_max_iterations'] = 'Maximum tool-call iterations per request. Default: 20.';
$_['help_system_prompt'] = 'Optional. Customise the AI\'s persona and behaviour for your site.';
$_['help_cloud_key'] = 'phpClaw Cloud API key. Enables cloud guards and webhook features. Optional.';
$_['help_cloud_signing_secret'] = 'Shared secret used to verify signed cloud scan responses. Copy it from your phpClaw Cloud dashboard when you create the API key. Leave empty to skip signature verification.';
$_['help_cloud_disable'] = 'Comma-separated cloud feature names to turn off, or hide_inputs, hide_outputs and hide_metadata to keep that content on your server while tracing stays on. Leave empty to use every feature in your plan. Only applies when a Cloud Key is set above.';
$_['help_cloud_inactive']       = 'Cloud fields are hidden because Store Messages is off. Your saved cloud settings are kept and will reappear when you turn it back on.';
$_['help_remote_skill_urls'] = 'One HTTPS URL per line (commas also accepted). Each points to a phpClaw skill collection (JSON) or a single SKILL.md. Loaded on every engine build.';
$_['entry_fallback_provider'] = 'Fallback Provider';
$_['entry_fallback_model'] = 'Fallback Model';
$_['entry_fallback_api_key'] = 'Fallback API Key';
$_['entry_rate_limit_rpm'] = 'Rate Limit (requests/min)';
$_['entry_response_cache'] = 'Response Cache';
$_['entry_response_cache_ttl'] = 'Response Cache TTL (seconds)';
$_['entry_max_token_budget'] = 'Max Token Budget';
$_['text_response_cache'] = 'Reuse the answer for an identical request';
$_['help_response_cache'] = 'An identical request within the TTL is answered from the cache instead of calling the provider again.';
$_['help_response_cache_ttl'] = 'How long a cached answer is kept, in seconds: 60 to 86400. Default 3600.';
$_['help_fallback_provider'] = 'Optional. Used only when the main provider fails with a connection error, a 429 or a 5xx. Lists only providers with the same tool format as the main provider. Off turns fallback off.';
$_['help_fallback_model'] = 'Leave blank to use the fallback provider\'s default model.';
$_['help_fallback_api_key'] = 'API key for the fallback provider.';
$_['help_rate_limit_rpm'] = 'Most calls to the AI provider per minute, shared by every request. 0 turns it off. Maximum 600.';
$_['help_max_token_budget'] = 'Stops a run before a provider call would take its token spend over this number. 0 turns it off. Maximum 10000000.';
