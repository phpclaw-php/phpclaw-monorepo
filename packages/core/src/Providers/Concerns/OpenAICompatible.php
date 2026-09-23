<?php

declare(strict_types=1);

namespace PhpClaw\Providers\Concerns;

use PhpClaw\Agent\Message;
use PhpClaw\Exceptions\ProviderException;

/**
 * Shared message formatting and response parsing for OpenAI-compatible providers (OpenAI, Groq, Mistral, Ollama, DeepSeek).
 */
trait OpenAICompatible
{
    /**
     * Convert Message[] to the OpenAI messages array format.
     *
     * @param  Message[]  $messages  Conversation history.
     * @param  string  $systemPrompt  Optional system prompt prepended as the first message.
     * @return array<int, array<string, mixed>>
     */
    private function formatMessages(array $messages, string $systemPrompt = ''): array
    {
        $formatted = [];

        if ($systemPrompt !== '') {
            $formatted[] = ['role' => OpenAIWireConstants::WIRE_ROLE_SYSTEM, 'content' => $systemPrompt];
        }

        foreach ($messages as $message) {
            if ($message->isBatchToolUse()) {
                $toolCalls = [];
                foreach ($message->batchCalls ?? [] as $call) {
                    $toolCalls[] = $this->buildToolCallFunctionEntry(
                        (string) $call['tool_use_id'],
                        (string) $call['tool_name'],
                        (array) ($call['tool_input'] ?? []),
                    );
                }
                $formatted[] = ['role' => OpenAIWireConstants::WIRE_ROLE_ASSISTANT, 'content' => null, 'tool_calls' => $toolCalls];

                foreach ($message->batchResults as $toolUseId => $result) {
                    $formatted[] = $this->buildToolResultEntry((string) $toolUseId, (string) $result);
                }

                continue;
            }

            if ($message->isToolUse()) {
                $formatted[] = [
                    'role' => OpenAIWireConstants::WIRE_ROLE_ASSISTANT,
                    'content' => null,
                    'tool_calls' => [
                        $this->buildToolCallFunctionEntry(
                            (string) $message->toolUseId,
                            (string) $message->toolName,
                            (array) ($message->toolInput ?? []),
                        ),
                    ],
                ];

                continue;
            }

            if ($message->isToolResult()) {
                $formatted[] = $this->buildToolResultEntry((string) $message->toolUseId, $message->content);

                continue;
            }

            $formatted[] = [
                'role' => $message->role,
                'content' => $message->content,
            ];
        }

        return $formatted;
    }

    /**
     * Build a single tool_call entry in OpenAI wire format.
     *
     * @param  string  $id  Tool use identifier.
     * @param  string  $name  Tool name being invoked.
     * @param  array<string, mixed>  $input  Tool arguments to JSON-encode.
     * @return array<string, mixed>
     */
    private function buildToolCallFunctionEntry(string $id, string $name, array $input): array
    {
        return [
            'id' => $id,
            'type' => OpenAIWireConstants::WIRE_TYPE_FUNCTION,
            'function' => [
                'name' => $name,
                'arguments' => json_encode(
                    empty($input) ? new \stdClass : $input,
                    JSON_THROW_ON_ERROR,
                ),
            ],
        ];
    }

    /**
     * Build a tool result message entry in OpenAI wire format.
     *
     * @param  string  $toolUseId  Tool use identifier the result belongs to.
     * @param  string  $content  Tool output text.
     * @return array<string, mixed>
     */
    private function buildToolResultEntry(string $toolUseId, string $content): array
    {
        return [
            'role' => OpenAIWireConstants::WIRE_ROLE_TOOL,
            'tool_call_id' => $toolUseId,
            'content' => $content,
        ];
    }

    /**
     * Parse a raw OpenAI-compatible response.
     *
     * @param  array<string, mixed>  $raw  Decoded API response body.
     * @return array{type: string, calls?: array<int, array<string, mixed>>, text?: string, input_tokens?: int|null, output_tokens?: int|null}
     *
     * @throws ProviderException When the response shape is not recognised.
     */
    private function parseResponse(array $raw): array
    {
        $usage = [
            'input_tokens' => $raw['usage']['prompt_tokens'] ?? null,
            'output_tokens' => $raw['usage']['completion_tokens'] ?? null,
        ];

        $choice = $raw['choices'][0] ?? [];
        $message = $choice['message'] ?? [];

        $rawToolCalls = $message['tool_calls'] ?? null;
        $toolCalls = is_array($rawToolCalls) ? $rawToolCalls : [];
        if ($toolCalls !== []) {
            return [
                'type' => 'tool_use_batch',
                'calls' => $this->parseToolCallsArray($toolCalls),
                'input_tokens' => $usage['input_tokens'],
                'output_tokens' => $usage['output_tokens'],
            ];
        }

        $content = $message['content'] ?? null;
        if ($content !== null) {
            $textContent = (string) $content;
            $textToolCalls = $this->extractTextToolCalls($textContent);

            if (! empty($textToolCalls)) {
                return [
                    'type' => 'tool_use_batch',
                    'calls' => $textToolCalls,
                    'input_tokens' => $usage['input_tokens'],
                    'output_tokens' => $usage['output_tokens'],
                ];
            }

            return [
                'type' => 'text',
                'text' => $textContent,
                'input_tokens' => $usage['input_tokens'],
                'output_tokens' => $usage['output_tokens'],
            ];
        }

        throw new ProviderException(
            'Unexpected OpenAI-compatible response structure: '.json_encode($raw)
        );
    }

    /**
     * Convert raw OpenAI tool_calls array into PhpClaw's canonical batch-call shape.
     *
     * @param  array<int, array<string, mixed>>  $toolCalls  Raw tool_calls list from the response.
     * @return array<int, array<string, mixed>>
     */
    private function parseToolCallsArray(array $toolCalls): array
    {
        $calls = [];

        foreach ($toolCalls as $call) {
            $args = $call['function']['arguments'] ?? '{}';
            $toolInput = is_string($args)
                ? (array) json_decode($args, true, OpenAIWireConstants::JSON_DECODE_DEPTH, JSON_THROW_ON_ERROR)
                : [];

            $calls[] = [
                'tool_use_id' => (string) ($call['id'] ?? ''),
                'tool_name' => (string) ($call['function']['name'] ?? ''),
                'tool_input' => $toolInput,
            ];
        }

        return $calls;
    }

    /**
     * Detect tool calls embedded in plain text by smaller models that don't emit structured tool_calls.
     *
     * @param  string  $text  Assistant response text to scan for embedded calls.
     * @return array<int, array<string, mixed>>
     */
    private function extractTextToolCalls(string $text): array
    {
        $calls = [];
        $id = 0;

        if (preg_match_all('/\(\(\((\w+)\s*(\{.*?\})\)\)\)/s', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $toolName = $match[1];
                $argsJson = $match[2];
                try {
                    $toolInput = (array) json_decode($argsJson, true, OpenAIWireConstants::JSON_DECODE_DEPTH, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    $toolInput = [];
                }
                $calls[] = [
                    'tool_use_id' => 'text_call_'.(++$id),
                    'tool_name' => $toolName,
                    'tool_input' => $toolInput,
                ];
            }

            return $calls;
        }

        if (preg_match_all('/<tool_call>(.*?)<\/tool_call>/s', $text, $matches)) {
            foreach ($matches[1] as $json) {
                try {
                    $decoded = (array) json_decode(trim($json), true, OpenAIWireConstants::JSON_DECODE_DEPTH, JSON_THROW_ON_ERROR);
                    $toolName = (string) ($decoded['name'] ?? '');
                    $toolInput = (array) ($decoded['arguments'] ?? $decoded['parameters'] ?? []);
                    if ($toolName === '') {
                        continue;
                    }
                    $calls[] = [
                        'tool_use_id' => 'text_call_'.(++$id),
                        'tool_name' => $toolName,
                        'tool_input' => $toolInput,
                    ];
                } catch (\JsonException) {
                    continue;
                }
            }

            return $calls;
        }

        if (str_contains($text, '</tool_call>')) {
            foreach (explode('</tool_call>', $text) as $segment) {
                $end = strrpos($segment, '}');

                if ($end === false) {
                    continue;
                }

                $offset = 0;
                while (($start = strpos($segment, '{', $offset)) !== false && $start < $end) {
                    try {
                        $decoded = (array) json_decode(
                            substr($segment, $start, $end - $start + 1),
                            true,
                            OpenAIWireConstants::JSON_DECODE_DEPTH,
                            JSON_THROW_ON_ERROR,
                        );
                        $toolName = (string) ($decoded['name'] ?? '');
                        $toolInput = (array) ($decoded['arguments'] ?? $decoded['parameters'] ?? []);

                        if ($toolName !== '') {
                            $calls[] = [
                                'tool_use_id' => 'text_call_'.(++$id),
                                'tool_name' => $toolName,
                                'tool_input' => $toolInput,
                            ];
                        }
                        break;
                    } catch (\JsonException) {
                        $offset = $start + 1;
                    }
                }
            }

            return $calls;
        }

        return [];
    }
}
