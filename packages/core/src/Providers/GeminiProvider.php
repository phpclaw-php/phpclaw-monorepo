<?php

declare(strict_types=1);

namespace PhpClaw\Providers;

use PhpClaw\Agent\Message;
use PhpClaw\AutoDiscovery\Attributes\Provider;
use PhpClaw\ClawConfig;
use PhpClaw\Exceptions\ProviderException;
use PhpClaw\Http\RawHttpClient;
use PhpClaw\Http\StreamParser;
use PhpClaw\Providers\Concerns\HasProviderTools;
use PhpClaw\Providers\Contracts\ProviderInterface;
use PhpClaw\Providers\Contracts\SupportsWebSearchInterface;
use PhpClaw\Providers\Tools\WebSearch;

/**
 * Google Gemini provider - default model gemini-3.5-flash-lite, API key via query parameter.
 */
#[Provider(name: 'gemini', defaultModel: self::DEFAULT_MODEL, label: 'Gemini', since: '1.0.0')]
final class GeminiProvider implements ProviderInterface, SupportsWebSearchInterface
{
    use HasProviderTools;

    public const DEFAULT_BASE_URL = 'https://generativelanguage.googleapis.com/v1beta/models';

    public const DEFAULT_MODEL = 'gemini-3.5-flash-lite';

    public const DEFAULT_MAX_TOKENS = 2048;

    private const MODEL_MAX_TOKENS = [
        'gemini-3.5-flash-lite' => 8192,
        'gemini-3.5-flash' => 8192,
        'gemini-2.5-flash' => 8192,
        'gemini-2.5-pro' => 8192,
        'gemini-2' => 8192,
    ];

    private const SCHEMA_KEYWORDS_TO_STRIP = [
        'additionalProperties', '$schema', '$id', '$ref', 'const',
        'patternProperties', 'minProperties', 'maxProperties',
        'exclusiveMinimum', 'exclusiveMaximum', 'multipleOf',
    ];

    private const OPERATION_GENERATE = ':generateContent';

    private const OPERATION_STREAM_GENERATE = ':streamGenerateContent?alt=sse';

    private const LEGACY_MODEL_PREFIX = 'gemini-1.';

    private readonly string $apiKey;

    private readonly RawHttpClient $http;

    private readonly StreamParser $parser;

    private readonly string $model;

    private readonly string $systemPrompt;

    private readonly int $maxTokens;

    private readonly string $baseUrl;

    /**
     * Create a new GeminiProvider instance.
     *
     * @param  string  $apiKey  Google API key (passed as ?key= query parameter).
     * @param  RawHttpClient|null  $http  HTTP client. Defaults to a new RawHttpClient instance.
     * @param  StreamParser|null  $parser  SSE stream parser. Defaults to a new StreamParser instance.
     * @param  string  $model  Model identifier. Defaults to gemini-3.5-flash-lite.
     * @param  string  $systemPrompt  System prompt sent with every request.
     * @param  int  $maxTokens  Max output tokens. 0 = auto per model.
     * @param  string  $baseUrl  Override API base URL. Empty = use default.
     * @return void
     */
    public function __construct(
        string $apiKey,
        ?RawHttpClient $http = null,
        ?StreamParser $parser = null,
        string $model = self::DEFAULT_MODEL,
        string $systemPrompt = '',
        int $maxTokens = 0,
        string $baseUrl = '',
    ) {
        $this->apiKey = $apiKey;
        $this->http = $http ?? new RawHttpClient;
        $this->parser = $parser ?? new StreamParser;
        $this->model = $model;
        $this->systemPrompt = $systemPrompt;
        $this->maxTokens = $maxTokens;
        $this->baseUrl = $baseUrl === '' ? self::DEFAULT_BASE_URL : $baseUrl;
    }

    /**
     * Build a GeminiProvider from a resolved ClawConfig.
     *
     * @param  ClawConfig  $config  Resolved engine configuration.
     * @return self Configured provider instance.
     */
    public static function fromConfig(ClawConfig $config): self
    {
        return new self(
            apiKey: $config->apiKey,
            model: $config->model !== '' ? $config->model : self::DEFAULT_MODEL,
            systemPrompt: $config->systemPrompt,
            maxTokens: $config->maxTokens,
        );
    }

    /**
     * Send conversation history to the LLM and return a normalised response array.
     *
     * @param  Message[]  $messages  Conversation history.
     * @param  array<int, array<string, mixed>>  $tools  Tool schemas; converted to Gemini functionDeclarations.
     * @return array{type: string, calls?: array<int, array<string, mixed>>, text?: string, input_tokens?: int, output_tokens?: int}
     *
     * @throws ProviderException When the API returns an error.
     */
    public function send(array $messages, array $tools = []): array
    {
        $url = $this->buildUrl(self::OPERATION_GENERATE);
        $body = $this->buildBody($messages, $tools);

        $raw = $this->http->post($url, [], $body);

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
        $url = $this->buildUrl(self::OPERATION_STREAM_GENERATE);
        $body = $this->buildBody($messages);

        $assembled = '';

        $this->http->stream(
            url: $url,
            headers: [],
            body: $body,
            onChunk: function (string $chunk) use (&$assembled, $onToken): void {
                $token = $this->parser->parseLine($chunk);
                if ($token !== null && $token !== '') {
                    $onToken($token);
                    $assembled .= $token;
                }
            },
        );

        return $assembled;
    }

    /**
     * Provider identifier: always `gemini`.
     *
     * @return string
     */
    public function name(): string
    {
        return 'gemini';
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
     * Return the active API base URL (without model or operation suffix).
     *
     * @return string
     */
    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * Translate a WebSearch config into Gemini's grounding tool element.
     *
     * @param  WebSearch  $tool  Provider-agnostic web search config; max/allow/location are not supported by grounding and are ignored.
     * @return array<string, mixed> Separate tools element; google_search must JSON-encode as an object.
     */
    public function webSearchToolOptions(WebSearch $tool): array
    {
        return ['google_search' => new \stdClass];
    }

    /**
     * Compose the full API URL for the given operation suffix.
     *
     * @param  string  $operation  Gemini operation suffix (e.g. ':generateContent').
     * @return string
     */
    private function buildUrl(string $operation): string
    {
        return $this->baseUrl.'/'.$this->model.$operation.'?key='.urlencode($this->apiKey);
    }

    /**
     * Resolve maxOutputTokens, selecting a safe per-model default when 0.
     *
     * @return int
     */
    private function resolveMaxTokens(): int
    {
        if ($this->maxTokens > 0) {
            return $this->maxTokens;
        }

        foreach (self::MODEL_MAX_TOKENS as $prefix => $tokens) {
            if (str_starts_with($this->model, $prefix)) {
                return $tokens;
            }
        }

        return self::DEFAULT_MAX_TOKENS;
    }

    /**
     * Build the Gemini request body.
     *
     * @param  Message[]  $messages  Conversation history.
     * @param  array<int, array<string, mixed>>  $tools  Tool schemas, optional.
     * @return array<string, mixed>
     */
    private function buildBody(array $messages, array $tools = []): array
    {
        $body = [
            'contents' => $this->formatMessages($messages),
            'generationConfig' => [
                'maxOutputTokens' => $this->resolveMaxTokens(),
            ],
        ];

        if ($this->systemPrompt !== '') {
            $body['systemInstruction'] = [
                'parts' => [['text' => $this->systemPrompt]],
            ];
        }

        $toolList = ! empty($tools) ? $this->formatTools($tools) : [];

        if ($this->providerTools !== [] && $toolList === [] && ! str_starts_with($this->model, self::LEGACY_MODEL_PREFIX)) {
            foreach ($this->providerTools as $providerTool) {
                $toolList[] = $this->webSearchToolOptions($providerTool);
            }
        }

        if ($toolList !== []) {
            $body['tools'] = $toolList;
        }

        return $body;
    }

    /**
     * Convert Message[] to Gemini's contents format.
     *
     * @param  Message[]  $messages  Conversation history.
     * @return array<int, array<string, mixed>>
     */
    private function formatMessages(array $messages): array
    {
        $contents = [];

        foreach ($messages as $message) {
            if ($message->isBatchToolUse()) {
                array_push($contents, ...$this->formatBatchToolUseContents($message));

                continue;
            }

            if ($message->isToolUse()) {
                $contents[] = [
                    'role' => 'model',
                    'parts' => [[
                        'functionCall' => [
                            'name' => $message->toolName,
                            'args' => $message->toolInput === null || $message->toolInput === [] ? new \stdClass : $message->toolInput,
                        ],
                    ]],
                ];

                continue;
            }

            if ($message->isToolResult()) {
                $contents[] = [
                    'role' => 'user',
                    'parts' => [[
                        'functionResponse' => [
                            'name' => $message->toolName,
                            'response' => ['content' => $message->content],
                        ],
                    ]],
                ];

                continue;
            }

            $role = $message->role === Message::ROLE_ASSISTANT ? 'model' : 'user';

            $contents[] = [
                'role' => $role,
                'parts' => [['text' => $message->content]],
            ];
        }

        return $contents;
    }

    /**
     * Convert a batch tool-use Message into the model + user content pair for the Gemini wire format.
     *
     * @param  Message  $message  A batch tool-use message containing batchCalls and batchResults.
     * @return array<int, array<string, mixed>> Two entries: model functionCall parts followed by user functionResponse parts.
     */
    private function formatBatchToolUseContents(Message $message): array
    {
        $parts = [];
        foreach ($message->batchCalls ?? [] as $call) {
            $parts[] = [
                'functionCall' => [
                    'name' => $call['tool_name'],
                    'args' => ($call['tool_input'] ?? null) === null || $call['tool_input'] === [] ? new \stdClass : $call['tool_input'],
                ],
            ];
        }

        $callsByToolUseId = array_column($message->batchCalls ?? [], null, 'tool_use_id');
        $resultParts = [];
        foreach ($message->batchResults ?? [] as $toolUseId => $result) {
            $callName = $callsByToolUseId[$toolUseId]['tool_name'] ?? $toolUseId;
            $resultParts[] = [
                'functionResponse' => [
                    'name' => $callName,
                    'response' => ['content' => $result],
                ],
            ];
        }

        return [
            ['role' => 'model', 'parts' => $parts],
            ['role' => 'user', 'parts' => $resultParts],
        ];
    }

    /**
     * Convert OpenAI-format tool schemas (from ToolRegistry) to Gemini's functionDeclarations.
     *
     * @param  array<int, array<string, mixed>>  $tools  Tool schemas in OpenAI function format.
     * @return array<int, array<string, mixed>>
     */
    private function formatTools(array $tools): array
    {
        $declarations = array_map(fn (array $t) => [
            'name' => $t['function']['name'],
            'description' => $t['function']['description'],
            'parameters' => $this->sanitizeSchemaForGemini($t['function']['parameters']),
        ], $tools);

        return [['functionDeclarations' => $declarations]];
    }

    /**
     * Strip JSON Schema keywords that Gemini's OpenAPI subset rejects.
     *
     * @param  mixed  $schema  Schema fragment to clean (recursively).
     * @return mixed
     */
    private function sanitizeSchemaForGemini(mixed $schema): mixed
    {
        if ($schema instanceof \stdClass) {
            return $schema;
        }
        if (! is_array($schema)) {
            return $schema;
        }

        foreach (self::SCHEMA_KEYWORDS_TO_STRIP as $keyword) {
            unset($schema[$keyword]);
        }

        if (isset($schema['type']) && is_array($schema['type'])) {
            $types = array_values(array_filter($schema['type'], static fn (string $type): bool => $type !== 'null'));
            if (count($types) !== count($schema['type'])) {
                $schema['nullable'] = true;
            }
            $schema['type'] = $types[0] ?? 'string';
        }

        if (isset($schema['enum']) && is_array($schema['enum'])) {
            $schema['type'] = 'string';
            $schema['enum'] = array_map(static fn (mixed $value): string => (string) $value, $schema['enum']);
        }

        foreach ($schema as $key => $value) {
            if ($key === 'properties' && is_array($value)) {
                foreach ($value as $name => $child) {
                    $value[$name] = $this->sanitizeSchemaForGemini($child);
                }
                $schema[$key] = $value;

                continue;
            }
            if (is_array($value)) {
                $schema[$key] = $this->sanitizeSchemaForGemini($value);
            }
        }

        return $schema;
    }

    /**
     * Normalise Gemini response to the internal format.
     *
     * @param  array<string, mixed>  $raw  Decoded API response body.
     * @return array{type: string, calls?: array<int, array<string, mixed>>, text?: string, input_tokens?: int, output_tokens?: int}
     */
    private function parseResponse(array $raw): array
    {
        $usage = [
            'input_tokens' => (int) ($raw['usageMetadata']['promptTokenCount'] ?? 0),
            'output_tokens' => (int) ($raw['usageMetadata']['candidatesTokenCount'] ?? 0),
        ];

        $parts = $raw['candidates'][0]['content']['parts'] ?? [];
        $toolCalls = $this->extractFunctionCalls($parts);

        if (! empty($toolCalls)) {
            return array_merge($usage, [
                'type' => 'tool_use_batch',
                'calls' => $toolCalls,
            ]);
        }

        $text = $this->extractTextFromParts($parts);

        return array_merge($usage, [
            'type' => 'text',
            'text' => $text,
        ]);
    }

    /**
     * Extract every functionCall part into PhpClaw's canonical batch shape.
     *
     * @param  array<int, array<string, mixed>>  $parts  Response content parts.
     * @return array<int, array<string, mixed>>
     */
    private function extractFunctionCalls(array $parts): array
    {
        $calls = [];
        foreach ($parts as $part) {
            if (! isset($part['functionCall'])) {
                continue;
            }
            $functionCall = $part['functionCall'];
            $calls[] = [
                'tool_use_id' => 'gemini-'.uniqid(),
                'tool_name' => (string) ($functionCall['name'] ?? ''),
                'tool_input' => (array) ($functionCall['args'] ?? []),
            ];
        }

        return $calls;
    }

    /**
     * Return concatenated text from the first text part (or '' when none).
     *
     * @param  array<int, array<string, mixed>>  $parts  Response content parts.
     * @return string
     */
    private function extractTextFromParts(array $parts): string
    {
        $text = '';
        foreach ($parts as $part) {
            if (isset($part['text'])) {
                $text .= (string) $part['text'];
            }
        }

        return $text;
    }
}
