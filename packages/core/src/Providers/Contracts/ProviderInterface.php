<?php

declare(strict_types=1);

namespace PhpClaw\Providers\Contracts;

use PhpClaw\Agent\Message;

/** Contract every LLM provider must implement. */
interface ProviderInterface
{
    /**
     * Send conversation history to the LLM and return a normalised response array.
     *
     * @param  Message[]  $messages  Full conversation history so far.
     * @param  array<int, array<string, mixed>>  $tools  Tool schemas in provider-native format (list of tool definitions from ToolRegistry).
     * @return array{type: string, calls?: array<int,array<string,mixed>>, text?: string, input_tokens?: int|null, output_tokens?: int|null, cache_read_tokens?: int|null, cache_write_tokens?: int|null, thinking?: string|null}
     */
    public function send(array $messages, array $tools = []): array;

    /**
     * Stream tokens from the LLM, calling $onToken for each chunk received.
     *
     * @param  Message[]  $messages  Messages.
     * @param  callable(string):void  $onToken  Called with each text token as it arrives.
     * @return string Full assembled text.
     */
    public function stream(array $messages, callable $onToken): string;

    /**
     * Provider identifier, e.g. 'anthropic', 'openai', 'groq', 'gemini'.
     *
     * @return string
     */
    public function name(): string;

    /**
     * The default model this provider uses when none is overridden.
     *
     * @return string
     */
    public function model(): string;
}
