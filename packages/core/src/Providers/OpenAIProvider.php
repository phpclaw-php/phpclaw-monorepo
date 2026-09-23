<?php

declare(strict_types=1);

namespace PhpClaw\Providers;

use PhpClaw\Agent\Message;
use PhpClaw\Exceptions\ProviderException;
use PhpClaw\Http\RawHttpClient;
use PhpClaw\Http\StreamParser;
use PhpClaw\Providers\Concerns\HasProviderTools;
use PhpClaw\Providers\Concerns\OpenAICompatible;
use PhpClaw\Providers\Concerns\OpenAIWireConstants;
use PhpClaw\Providers\Contracts\ProviderInterface;
use PhpClaw\Providers\Contracts\SupportsWebSearchInterface;
use PhpClaw\Providers\Tools\WebSearch;

/**
 * Engine for every OpenAI-compatible provider: OpenAI, Groq, DeepSeek, Mistral, Ollama, and any custom `v1/chat/completions` endpoint.
 */
final class OpenAIProvider implements OpenAIWireConstants, ProviderInterface, SupportsWebSearchInterface
{
    use HasProviderTools;
    use OpenAICompatible;

    public const DEFAULT_ENDPOINT = 'https://api.openai.com/v1/chat/completions';

    private const CHAT_COMPLETIONS_PATH = '/v1/chat/completions';

    public const DEFAULT_MODEL = 'gpt-4o-mini';

    public const DEFAULT_MAX_TOKENS = 4096;

    private const SEARCH_PREVIEW_MODEL_SUFFIX = 'search-preview';

    private readonly RawHttpClient $http;

    private readonly StreamParser $parser;

    private readonly string $endpoint;

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
        private readonly string $apiKey,
        ?RawHttpClient $http = null,
        ?StreamParser $parser = null,
        private readonly string $model = self::DEFAULT_MODEL,
        private readonly string $systemPrompt = '',
        private readonly int $maxTokens = 0,
        string $endpoint = '',
        private readonly string $name = 'openai',
        private readonly string $authStyle = OpenAIPresets::AUTH_BEARER,
    ) {
        $this->http = $http ?? new RawHttpClient;
        $this->parser = $parser ?? new StreamParser;
        $this->endpoint = $endpoint !== '' ? self::normaliseEndpoint($endpoint) : self::DEFAULT_ENDPOINT;
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
            headers: $this->headers(),
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
            headers: $this->headers(),
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
    private function headers(): array
    {
        if ($this->authStyle === OpenAIPresets::AUTH_BEARER) {
            return ['Authorization' => "Bearer {$this->apiKey}"];
        }

        return $this->apiKey !== ''
            ? ['Authorization' => "Bearer {$this->apiKey}"]
            : [];
    }
}
