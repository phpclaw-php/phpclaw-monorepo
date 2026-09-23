<?php

declare(strict_types=1);

namespace PhpClaw\Providers\Concerns;

/**
 * Wire-format constants shared by every OpenAI-compatible provider (OpenAI, Groq, Mistral, Ollama, DeepSeek).
 */
interface OpenAIWireConstants
{
    public const WIRE_ROLE_SYSTEM = 'system';

    public const WIRE_ROLE_ASSISTANT = 'assistant';

    public const WIRE_ROLE_TOOL = 'tool';

    public const WIRE_TYPE_FUNCTION = 'function';

    public const JSON_DECODE_DEPTH = 512;
}
