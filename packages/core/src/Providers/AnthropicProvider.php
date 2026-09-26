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
 * Anthropic Messages API provider: supports prompt caching, extended thinking, tool use, and server-side web search.
 */
#[Provider(name: 'anthropic', defaultModel: self::DEFAULT_MODEL, label: 'Anthropic', since: '1.0.0')]
final class AnthropicProvider implements ProviderInterface, SupportsWebSearchInterface
{
    use HasProviderTools;

    public const MODEL_HAIKU = 'claude-haiku-4-5-20251001';

    public const MODEL_SONNET = 'claude-sonnet-5';

    public const MODEL_OPUS = 'claude-opus-4-8';

    public const DEFAULT_ENDPOINT = 'https://api.anthropic.com/v1/messages';

    public const DEFAULT_MODEL = self::MODEL_HAIKU;

    public const DEFAULT_MAX_TOKENS = 4096;

    public const ANTHROPIC_VERSION = '2023-06-01';

    private const BETA_PROMPT_CACHE = 'prompt-caching-2024-07-31';

    private const BETA_THINKING = 'interleaved-thinking-2025-05-14';

    private const MIN_THINKING_BUDGET = 1024;

    private const THINKING_MAX_TOKENS_HEADROOM = 1000;

    private const CACHE_CONTROL_EPHEMERAL = ['type' => 'ephemeral'];

    private const MODEL_MAX_TOKENS = [
        self::MODEL_OPUS => 16000,
        self::MODEL_SONNET => 16000,
        self::MODEL_HAIKU => 8192,
    ];

    private readonly string $apiKey;

    private readonly RawHttpClient $http;

    private readonly StreamParser $parser;

    private readonly string $model;

    private readonly string $systemPrompt;

    private readonly int $maxTokens;

    private readonly bool $promptCache;

    private readonly int $thinkingBudget;

    private readonly string $endpoint;

    /**
     * Create a new AnthropicProvider instance.
     *
     * @param  string  $apiKey  Anthropic API key.
     * @param  RawHttpClient|null  $http  HTTP client. Defaults to a new RawHttpClient instance.
     * @param  StreamParser|null  $parser  SSE stream parser. Defaults to a new StreamParser instance.
     * @param  string  $model  Model identifier. Defaults to MODEL_HAIKU.
     * @param  string  $systemPrompt  System prompt sent with every request. Empty = none.
     * @param  int  $maxTokens  Max output tokens. 0 = auto per model; auto-adjusted upward to at least thinkingBudget + 1000 when thinkingBudget >= 1024.
     * @param  bool  $promptCache  Enable Anthropic prompt caching. No-op when system prompt is empty and no tools are registered.
     * @param  int  $thinkingBudget  Extended thinking budget tokens. 0 = disabled. Min = 1024.
     * @param  string  $endpoint  Override API endpoint URL. Empty = use default.
     * @return void
     */
    public function __construct(
        string $apiKey,
        ?RawHttpClient $http = null,
        ?StreamParser $parser = null,
        string $model = self::DEFAULT_MODEL,
        string $systemPrompt = '',
        int $maxTokens = 0,
        bool $promptCache = false,
        int $thinkingBudget = 0,
        string $endpoint = '',
    ) {
        $this->apiKey = $apiKey;
        $this->http = $http ?? new RawHttpClient;
        $this->parser = $parser ?? new StreamParser;
        $this->model = $model;
        $this->systemPrompt = $systemPrompt;
        $this->maxTokens = $maxTokens;
        $this->promptCache = $promptCache;
        $this->thinkingBudget = $thinkingBudget;
        $this->endpoint = $endpoint === '' ? self::DEFAULT_ENDPOINT : $endpoint;
    }

    /**
     * Build an AnthropicProvider from a resolved ClawConfig.
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
            promptCache: $config->promptCache,
            thinkingBudget: $config->thinkingBudget,
        );
    }

    /**
     * Send conversation history to Anthropic and return the normalised response.
     *
     * @param  Message[]  $messages  Conversation history.
     * @param  array<int, array<string, mixed>>  $tools  Tool schemas in Anthropic-native format.
     * @return array{type: string, text?: string, thinking?: string|null, calls?: array<int, array<string, mixed>>, input_tokens?: int|null, output_tokens?: int|null, cache_read_tokens?: int|null, cache_write_tokens?: int|null}
     *
     * @throws ProviderException When the API returns an error or an unrecognised shape.
     */
    public function send(array $messages, array $tools = []): array
    {
        $body = $this->buildRequestPayload($messages, $tools);

        $raw = $this->http->post(
            url: $this->endpoint,
            headers: $this->buildHeaders(),
            body: $body,
        );

        return $this->parseResponse($raw);
    }

    /**
     * Stream tokens from Anthropic, invoking $onToken for each text chunk.
     *
     * @param  Message[]  $messages  Conversation history.
     * @param  callable(string): void  $onToken  Called with each text token as it arrives.
     * @return string Full assembled text emitted by the stream.
     *
     * @throws ProviderException When the upstream stream fails.
     */
    public function stream(array $messages, callable $onToken): string
    {
        $body = $this->buildRequestPayload($messages, tools: []);
        $body['stream'] = true;

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
     * Provider identifier: always `anthropic`.
     *
     * @return string
     */
    public function name(): string
    {
        return 'anthropic';
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
     * Return the active API endpoint URL.
     *
     * @return string
     */
    public function endpoint(): string
    {
        return $this->endpoint;
    }

    /**
     * Translate a WebSearch config into Anthropic's native web_search tool entry.
     *
     * @param  WebSearch  $tool  Provider-agnostic web search config.
     * @return array<string, mixed> Complete web_search_20250305 tool entry for the tools array.
     */
    public function webSearchToolOptions(WebSearch $tool): array
    {
        $entry = [
            'type' => 'web_search_20250305',
            'name' => 'web_search',
        ];

        if ($tool->maxUses() > 0) {
            $entry['max_uses'] = $tool->maxUses();
        }
        if ($tool->allowedDomains() !== []) {
            $entry['allowed_domains'] = $tool->allowedDomains();
        }
        if ($tool->userLocation() !== []) {
            $entry['user_location'] = ['type' => 'approximate'] + $tool->userLocation();
        }

        return $entry;
    }

    /**
     * Build the request payload sent to the Messages API.
     *
     * @param  Message[]  $messages  Conversation history.
     * @param  array<int, array<string, mixed>>  $tools  Tool schemas to forward.
     * @return array<string, mixed>
     */
    private function buildRequestPayload(array $messages, array $tools): array
    {
        $body = [
            'model' => $this->model,
            'max_tokens' => $this->resolveMaxTokens(),
            'messages' => $this->formatMessages($messages),
        ];

        if ($this->systemPrompt !== '') {
            $body['system'] = $this->buildSystemField();
        }

        if ($this->isThinkingEnabled()) {
            $body['thinking'] = [
                'type' => 'enabled',
                'budget_tokens' => $this->thinkingBudget,
            ];
        }

        $toolList = $this->promptCache ? $this->applyToolCacheMarker($tools) : $tools;
        foreach ($this->providerTools as $providerTool) {
            $toolList[] = $this->webSearchToolOptions($providerTool);
        }

        if ($toolList !== []) {
            $body['tools'] = $toolList;
            $body['tool_choice'] = ['type' => 'auto'];
        }

        return $body;
    }

    /**
     * Resolve max_tokens, auto-adjusting upward when thinking is enabled.
     *
     * @return int
     */
    private function resolveMaxTokens(): int
    {
        $base = $this->maxTokens > 0
            ? $this->maxTokens
            : (self::MODEL_MAX_TOKENS[$this->model] ?? self::DEFAULT_MAX_TOKENS);

        if ($this->isThinkingEnabled()) {
            $minimum = $this->thinkingBudget + self::THINKING_MAX_TOKENS_HEADROOM;

            return max($base, $minimum);
        }

        return $base;
    }

    /**
     * True when extended thinking is configured to a valid budget.
     *
     * @return bool
     */
    private function isThinkingEnabled(): bool
    {
        return $this->thinkingBudget >= self::MIN_THINKING_BUDGET;
    }

    /**
     * Build the `system` field: plain string or cached content block array.
     *
     * @return string|array<int, array<string, mixed>>
     */
    private function buildSystemField(): string|array
    {
        if (! $this->promptCache) {
            return $this->systemPrompt;
        }

        return [
            [
                'type' => 'text',
                'text' => $this->systemPrompt,
                'cache_control' => self::CACHE_CONTROL_EPHEMERAL,
            ],
        ];
    }

    /**
     * Add a cache_control marker to the last tool in the list.
     *
     * @param  array<int, array<string, mixed>>  $tools  Tool schemas, copied with the marker appended.
     * @return array<int, array<string, mixed>>
     */
    private function applyToolCacheMarker(array $tools): array
    {
        if (empty($tools)) {
            return $tools;
        }

        $last = count($tools) - 1;
        $tools[$last]['cache_control'] = self::CACHE_CONTROL_EPHEMERAL;

        return $tools;
    }

    /**
     * Build request headers, including beta feature identifiers when active.
     *
     * @return array<string, string>
     */
    private function buildHeaders(): array
    {
        $headers = [
            'x-api-key' => $this->apiKey,
            'anthropic-version' => self::ANTHROPIC_VERSION,
        ];

        $betas = [];

        if ($this->promptCache) {
            $betas[] = self::BETA_PROMPT_CACHE;
        }

        if ($this->isThinkingEnabled()) {
            $betas[] = self::BETA_THINKING;
        }

        if (! empty($betas)) {
            $headers['anthropic-beta'] = implode(',', $betas);
        }

        return $headers;
    }

    /**
     * Convert Message[] to Anthropic's messages array format.
     *
     * @param  Message[]  $messages  Conversation history.
     * @return array<int, array<string, mixed>>
     */
    private function formatMessages(array $messages): array
    {
        $formatted = [];

        foreach ($messages as $message) {
            if ($message->isBatchToolUse()) {
                array_push($formatted, ...$this->formatBatchToolUseMessages($message));

                continue;
            }

            if ($message->isToolUse()) {
                $formatted[] = [
                    'role' => 'assistant',
                    'content' => [
                        [
                            'type' => 'tool_use',
                            'id' => $message->toolUseId,
                            'name' => $message->toolName,
                            'input' => ($message->toolInput === null || $message->toolInput === []) ? new \stdClass : $message->toolInput,
                        ],
                    ],
                ];

                continue;
            }

            if ($message->isToolResult()) {
                $formatted[] = [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'tool_result',
                            'tool_use_id' => $message->toolUseId,
                            'content' => $message->content,
                        ],
                    ],
                ];

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
     * Convert a batch tool-use Message into the assistant + user content block pair for the Anthropic wire format.
     *
     * @param  Message  $message  A batch tool-use message containing batchCalls and batchResults.
     * @return array<int, array<string, mixed>> Two entries: assistant tool_use blocks followed by user tool_result blocks.
     */
    private function formatBatchToolUseMessages(Message $message): array
    {
        $contentBlocks = [];
        foreach ($message->batchCalls ?? [] as $call) {
            $contentBlocks[] = [
                'type' => 'tool_use',
                'id' => $call['tool_use_id'],
                'name' => $call['tool_name'],
                'input' => empty($call['tool_input']) ? new \stdClass : $call['tool_input'],
            ];
        }

        $resultBlocks = [];
        foreach ($message->batchResults as $toolUseId => $result) {
            $resultBlocks[] = [
                'type' => 'tool_result',
                'tool_use_id' => $toolUseId,
                'content' => $result,
            ];
        }

        return [
            ['role' => 'assistant', 'content' => $contentBlocks],
            ['role' => 'user', 'content' => $resultBlocks],
        ];
    }

    /**
     * Parse a raw Anthropic API response into the normalised array shape.
     *
     * @param  array<string, mixed>  $raw  Decoded API response body.
     * @return array{type: string, text?: string, thinking?: string|null, calls?: array<int, array<string, mixed>>, input_tokens?: int|null, output_tokens?: int|null, cache_read_tokens?: int|null, cache_write_tokens?: int|null}
     *
     * @throws ProviderException When the response shape is not recognised.
     */
    private function parseResponse(array $raw): array
    {
        $usage = [
            'input_tokens' => $raw['usage']['input_tokens'] ?? null,
            'output_tokens' => $raw['usage']['output_tokens'] ?? null,
            'cache_read_tokens' => $raw['usage']['cache_read_input_tokens'] ?? null,
            'cache_write_tokens' => $raw['usage']['cache_creation_input_tokens'] ?? null,
        ];

        $content = $raw['content'] ?? [];
        $thinkingText = $this->extractThinking($content);
        $toolCalls = $this->extractToolCalls($content);

        if (! empty($toolCalls)) {
            return array_merge($usage, [
                'type' => 'tool_use_batch',
                'calls' => $toolCalls,
                'thinking' => $thinkingText !== '' ? $thinkingText : null,
            ]);
        }

        $text = $this->extractText($content);

        if ($text !== null) {
            return array_merge($usage, [
                'type' => 'text',
                'text' => $text,
                'thinking' => $thinkingText !== '' ? $thinkingText : null,
            ]);
        }

        if (in_array($raw['stop_reason'] ?? '', ['end_turn', 'pause_turn'], true)) {
            return array_merge($usage, ['type' => 'text', 'text' => '', 'thinking' => null]);
        }

        throw new ProviderException(
            'Unexpected Anthropic response structure (keys: '.implode(', ', array_keys($raw)).').'
        );
    }

    /**
     * Concatenate text from every `thinking` content block.
     *
     * @param  array<int, array<string, mixed>>  $content  Response content blocks.
     * @return string
     */
    private function extractThinking(array $content): string
    {
        $text = '';
        foreach ($content as $block) {
            if (($block['type'] ?? '') === 'thinking') {
                $text .= (string) ($block['thinking'] ?? '');
            }
        }

        return $text;
    }

    /**
     * Concatenate text from every `text` content block, or return null when none are present.
     *
     * @param  array<int, array<string, mixed>>  $content  Response content blocks.
     * @return string|null Concatenated text, or null when no text blocks exist.
     */
    private function extractText(array $content): ?string
    {
        $text = '';
        $hasText = false;
        foreach ($content as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= (string) ($block['text'] ?? '');
                $hasText = true;
            }
        }

        return $hasText ? $text : null;
    }

    /**
     * Extract every `tool_use` content block into the canonical PhpClaw batch shape.
     *
     * @param  array<int, array<string, mixed>>  $content  Response content blocks.
     * @return array<int, array<string, mixed>>
     */
    private function extractToolCalls(array $content): array
    {
        $calls = [];
        foreach ($content as $block) {
            if (($block['type'] ?? '') !== 'tool_use') {
                continue;
            }
            $calls[] = [
                'tool_use_id' => (string) ($block['id'] ?? ''),
                'tool_name' => (string) ($block['name'] ?? ''),
                'tool_input' => (array) ($block['input'] ?? []),
            ];
        }

        return $calls;
    }
}
