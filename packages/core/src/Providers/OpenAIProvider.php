<?php

declare(strict_types=1);

namespace PhpClaw\Providers;

use PhpClaw\Agent\Message;
use PhpClaw\Exceptions\ProviderException;
use PhpClaw\Http\RawHttpClient;
use PhpClaw\Http\StreamParser;
use PhpClaw\Providers\Concerns\HasProviderTools;
use PhpClaw\Providers\Contracts\ProviderInterface;
use PhpClaw\Providers\Contracts\SupportsWebSearchInterface;
use PhpClaw\Providers\Tools\WebSearch;

/**
 * Engine for every OpenAI-compatible provider: OpenAI, Groq, DeepSeek, Mistral, Ollama, and any custom `v1/chat/completions` endpoint.
 */
final class OpenAIProvider implements ProviderInterface, SupportsWebSearchInterface
{
    use HasProviderTools;

    public const DEFAULT_ENDPOINT = 'https://api.openai.com/v1/chat/completions';

    public const DEFAULT_MODEL = 'gpt-4o-mini';

    public const DEFAULT_MAX_TOKENS = 4096;

    public const DEFAULT_NAME = 'openai';

    private const CHAT_COMPLETIONS_PATH = '/v1/chat/completions';

    private const SEARCH_PREVIEW_MODEL_SUFFIX = 'search-preview';

    private const WIRE_ROLE_SYSTEM = 'system';

    private const WIRE_ROLE_ASSISTANT = 'assistant';

    private const WIRE_ROLE_TOOL = 'tool';

    private const WIRE_TYPE_FUNCTION = 'function';

    private const JSON_DECODE_DEPTH = 512;

    private const TEXT_CALL_ID_PREFIX = 'text_call_';

    private readonly string $apiKey;

    private readonly RawHttpClient $http;

    private readonly StreamParser $parser;

    private readonly string $model;

    private readonly string $systemPrompt;

    private readonly int $maxTokens;

    private readonly string $endpoint;

    private readonly string $name;

    private readonly string $authStyle;

    /**
     * Create a new OpenAIProvider instance.
     *
     * @param  string  $apiKey  Provider API key. May be empty for keyless local endpoints.
     * @param  RawHttpClient|null  $http  HTTP client. Defaults to a new RawHttpClient instance.
     * @param  StreamParser|null  $parser  SSE stream parser. Defaults to a new StreamParser instance.
     * @param  string  $model  Model identifier. Defaults to gpt-4o-mini.
     * @param  string  $systemPrompt  Optional system prompt prepended to every conversation.
     * @param  int  $maxTokens  Max tokens to generate. 0 = use provider default (4096).
     * @param  string  $endpoint  API endpoint URL. Empty = OpenAI default. A host-only URL gains the standard chat-completions path.
     * @param  string  $name  Provider identifier returned by name(). Defaults to 'openai'.
     * @param  string  $authStyle  Auth style: OpenAIPresets::AUTH_BEARER or AUTH_NONE.
     * @return void
     */
    public function __construct(
        string $apiKey,
        ?RawHttpClient $http = null,
        ?StreamParser $parser = null,
        string $model = self::DEFAULT_MODEL,
        string $systemPrompt = '',
        int $maxTokens = 0,
        string $endpoint = '',
        string $name = self::DEFAULT_NAME,
        string $authStyle = OpenAIPresets::AUTH_BEARER,
    ) {
        $this->apiKey = $apiKey;
        $this->http = $http ?? new RawHttpClient;
        $this->parser = $parser ?? new StreamParser;
        $this->model = $model;
        $this->systemPrompt = $systemPrompt;
        $this->maxTokens = $maxTokens;
        $this->endpoint = $endpoint === '' ? self::DEFAULT_ENDPOINT : self::normaliseEndpoint($endpoint);
        $this->name = $name;
        $this->authStyle = $authStyle;
    }

    /**
     * Send conversation history to the LLM and return a normalised response array.
     *
     * @param  Message[]  $messages  Conversation history.
     * @param  array<int, array<string, mixed>>  $tools  Tool schemas in OpenAI function format.
     * @return array{type: string, calls?: array<int, array<string, mixed>>, text?: string, input_tokens?: int|null, output_tokens?: int|null}
     *
     * @throws ProviderException When the API returns an error or an unrecognised shape.
     */
    public function send(array $messages, array $tools = []): array
    {
        $body = [
            'model' => $this->model,
            'max_tokens' => $this->resolveMaxTokens(),
            'messages' => $this->formatMessages($messages, $this->systemPrompt),
        ];

        if (! empty($tools)) {
            $body['tools'] = $tools;
            $body['tool_choice'] = 'auto';
        }

        if ($this->providerTools !== [] && str_contains($this->model, self::SEARCH_PREVIEW_MODEL_SUFFIX)) {
            $options = $this->webSearchToolOptions($this->providerTools[0]);
            $body['web_search_options'] = $options === [] ? new \stdClass : $options;
        }

        $raw = $this->http->post(
            url: $this->endpoint,
            headers: $this->buildHeaders(),
            body: $body,
        );

        return $this->parseResponse($raw);
    }

    /**
     * Stream tokens from the LLM, calling $onToken for each chunk received.
     *
     * @param  Message[]  $messages  Conversation history.
     * @param  callable(string): void  $onToken  Receives each text token as it arrives.
     * @return string Full assembled text.
     *
     * @throws ProviderException When the upstream stream fails.
     */
    public function stream(array $messages, callable $onToken): string
    {
        $body = [
            'model' => $this->model,
            'max_tokens' => $this->resolveMaxTokens(),
            'messages' => $this->formatMessages($messages, $this->systemPrompt),
            'stream' => true,
        ];

        $fullText = '';

        $this->http->stream(
            url: $this->endpoint,
            headers: $this->buildHeaders(),
            body: $body,
            onChunk: function (string $line) use ($onToken, &$fullText): void {
                $token = $this->parser->parseLine($line);
                if ($token !== null) {
                    $fullText .= $token;
                    $onToken($token);
                }
            },
        );

        return $fullText;
    }

    /**
     * Provider identifier: the injected preset slug (defaults to `openai`).
     *
     * @return string
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * Currently configured model identifier.
     *
     * @return string
     */
    public function model(): string
    {
        return $this->model;
    }

    /**
     * Append the standard chat-completions path to a host-only endpoint, so a base URL copied from a provider's docs still reaches the API.
     *
     * @param  string  $endpoint  Caller-supplied endpoint URL.
     * @return string The endpoint with a path, unchanged when one was already present.
     */
    private static function normaliseEndpoint(string $endpoint): string
    {
        $trimmed = rtrim(trim($endpoint), '/');
        $path = (string) parse_url($trimmed, PHP_URL_PATH);

        return $path === '' ? $trimmed.self::CHAT_COMPLETIONS_PATH : $trimmed;
    }

    /**
     * Return the active API endpoint URL.
     *
     * @return string
     */
    public function endpoint(): string
    {
        return $this->endpoint;
    }

    /**
     * Translate a WebSearch config into OpenAI's web_search_options body value.
     *
     * @param  WebSearch  $tool  Provider-agnostic web search config; max/allow are not supported on chat completions and are ignored.
     * @return array<string, mixed> Options for the top-level web_search_options param; empty = provider defaults.
     */
    public function webSearchToolOptions(WebSearch $tool): array
    {
        if ($tool->userLocation() === []) {
            return [];
        }

        return [
            'user_location' => [
                'type' => 'approximate',
                'approximate' => $tool->userLocation(),
            ],
        ];
    }

    /**
     * Resolve max_tokens, falling back to the provider default when unset.
     *
     * @return int
     */
    private function resolveMaxTokens(): int
    {
        return $this->maxTokens > 0 ? $this->maxTokens : self::DEFAULT_MAX_TOKENS;
    }

    /**
     * Build request headers, honouring the configured auth style.
     *
     * @return array<string, string>
     */
    private function buildHeaders(): array
    {
        if ($this->authStyle !== OpenAIPresets::AUTH_BEARER && $this->apiKey === '') {
            return [];
        }

        return ['Authorization' => "Bearer {$this->apiKey}"];
    }

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
            $formatted[] = ['role' => self::WIRE_ROLE_SYSTEM, 'content' => $systemPrompt];
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
                $formatted[] = ['role' => self::WIRE_ROLE_ASSISTANT, 'content' => null, 'tool_calls' => $toolCalls];

                foreach ($message->batchResults as $toolUseId => $result) {
                    $formatted[] = $this->buildToolResultEntry((string) $toolUseId, (string) $result);
                }

                continue;
            }

            if ($message->isToolUse()) {
                $formatted[] = [
                    'role' => self::WIRE_ROLE_ASSISTANT,
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
            'type' => self::WIRE_TYPE_FUNCTION,
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
            'role' => self::WIRE_ROLE_TOOL,
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
            'Unexpected OpenAI-compatible response structure (keys: '.implode(', ', array_keys($raw)).').'
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
            $toolName = (string) ($call['function']['name'] ?? '');
            $args = $call['function']['arguments'] ?? '{}';

            $calls[] = [
                'tool_use_id' => (string) ($call['id'] ?? ''),
                'tool_name' => $toolName,
                'tool_input' => is_string($args) ? $this->decodeToolArguments($args, $toolName) : [],
            ];
        }

        return $calls;
    }

    /**
     * Decode one structured tool call's JSON arguments string.
     *
     * @param  string  $arguments  Raw JSON arguments emitted by the model.
     * @param  string  $toolName  Tool the arguments belong to, named in the error.
     * @return array<string, mixed>
     *
     * @throws ProviderException When the model emitted arguments that are not valid JSON.
     */
    private function decodeToolArguments(string $arguments, string $toolName): array
    {
        try {
            return (array) json_decode($arguments, true, self::JSON_DECODE_DEPTH, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ProviderException("Malformed tool-call arguments for '{$toolName}'.", previous: $e);
        }
    }

    /**
     * Detect tool calls embedded in plain text by smaller models that don't emit structured tool_calls.
     *
     * @param  string  $text  Assistant response text to scan for embedded calls.
     * @return array<int, array<string, mixed>>
     */
    private function extractTextToolCalls(string $text): array
    {
        $calls = $this->parseTripleParenToolCalls($text);
        if ($calls !== []) {
            return $calls;
        }

        if (preg_match('/<tool_call>.*?<\/tool_call>/s', $text) === 1) {
            return $this->parseXmlTagToolCalls($text);
        }

        return $this->parseFragmentedXmlToolCalls($text);
    }

    /**
     * Parse tool calls embedded in `(((toolName {json})))` triple-paren format.
     *
     * @param  string  $text  Assistant response text.
     * @return array<int, array<string, mixed>>
     */
    private function parseTripleParenToolCalls(string $text): array
    {
        $calls = [];
        $id = 0;

        if (! preg_match_all('/\(\(\((\w+)\s*(\{.*?\})\)\)\)/s', $text, $matches, PREG_SET_ORDER)) {
            return $calls;
        }

        foreach ($matches as $match) {
            $toolName = $match[1];
            $argsJson = $match[2];
            try {
                $toolInput = (array) json_decode($argsJson, true, self::JSON_DECODE_DEPTH, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $toolInput = [];
            }
            $calls[] = [
                'tool_use_id' => self::TEXT_CALL_ID_PREFIX.(++$id),
                'tool_name' => $toolName,
                'tool_input' => $toolInput,
            ];
        }

        return $calls;
    }

    /**
     * Parse tool calls in `<tool_call>{json}</tool_call>` XML-tag format.
     *
     * @param  string  $text  Assistant response text.
     * @return array<int, array<string, mixed>>
     */
    private function parseXmlTagToolCalls(string $text): array
    {
        $calls = [];
        $id = 0;

        if (! preg_match_all('/<tool_call>(.*?)<\/tool_call>/s', $text, $matches)) {
            return $calls;
        }

        foreach ($matches[1] as $json) {
            try {
                $decoded = (array) json_decode(trim($json), true, self::JSON_DECODE_DEPTH, JSON_THROW_ON_ERROR);
                $toolName = (string) ($decoded['name'] ?? '');
                $toolInput = (array) ($decoded['arguments'] ?? $decoded['parameters'] ?? []);
                if ($toolName === '') {
                    continue;
                }
                $calls[] = [
                    'tool_use_id' => self::TEXT_CALL_ID_PREFIX.(++$id),
                    'tool_name' => $toolName,
                    'tool_input' => $toolInput,
                ];
            } catch (\JsonException) {
                continue;
            }
        }

        return $calls;
    }

    /**
     * Parse tool calls from fragmented `</tool_call>` segments when only closing tags are present.
     *
     * @param  string  $text  Assistant response text.
     * @return array<int, array<string, mixed>>
     */
    private function parseFragmentedXmlToolCalls(string $text): array
    {
        $calls = [];
        $id = 0;

        if (! str_contains($text, '</tool_call>')) {
            return $calls;
        }

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
                        self::JSON_DECODE_DEPTH,
                        JSON_THROW_ON_ERROR,
                    );
                    $toolName = (string) ($decoded['name'] ?? '');
                    $toolInput = (array) ($decoded['arguments'] ?? $decoded['parameters'] ?? []);

                    if ($toolName !== '') {
                        $calls[] = [
                            'tool_use_id' => self::TEXT_CALL_ID_PREFIX.(++$id),
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
}
