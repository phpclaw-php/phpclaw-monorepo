<?php

declare(strict_types=1);

namespace PhpClaw\Tools;

use PhpClaw\AutoDiscovery\Attributes\Tool;
use PhpClaw\Exceptions\ToolException;
use PhpClaw\Support\SsrfValidator;
use PhpClaw\Tools\Concerns\HasCoreToolBinding;
use PhpClaw\Tools\Contracts\AuthorizableToolInterface;
use PhpClaw\Tools\Contracts\ToolInterface;
use PhpClaw\Tools\Contracts\ToolRoutingInterface;

/**
 * Makes outbound HTTP GET or POST requests to public URLs, SSRF-protected, response truncated.
 */
#[Tool(
    name: 'http',
    description: 'Make outbound HTTP GET/POST requests with SSRF protection and response truncation.',
    since: '1.0.0',
    default: true,
)]
final class HttpTool implements AuthorizableToolInterface, ToolInterface, ToolRoutingInterface
{
    use HasCoreToolBinding;

    public const DEFAULT_MAX_RESPONSE_BYTES = 8192;

    public const DEFAULT_TIMEOUT_SECONDS = 10;

    private const ALLOWED_METHODS = ['GET', 'POST'];

    private const USER_AGENT = 'phpClaw/1.0';

    /**
     * Create a new HttpTool instance.
     *
     * @param  int  $maxResponseBytes  Max response bytes returned to the LLM. Response truncated beyond this.
     * @param  int  $timeoutSeconds  HTTP request timeout in seconds.
     * @return void
     */
    public function __construct(
        private readonly int $maxResponseBytes = self::DEFAULT_MAX_RESPONSE_BYTES,
        private readonly int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
    ) {}

    /**
     * Tool name advertised to the LLM.
     *
     * @return string
     */
    public function name(): string
    {
        return 'http_request';
    }

    /**
     * Tool description advertised to the LLM.
     *
     * @return string
     */
    public function description(): string
    {
        return 'FETCH a real HTTP response from a public URL (GET/POST). Use whenever the user asks to retrieve or check a URL. Invoke: never describe what the URL might return.';
    }

    /**
     * JSON Schema describing the tool's url / method / body / headers inputs.
     *
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'url' => [
                    'type' => 'string',
                    'description' => 'The URL to request (must be http:// or https://).',
                ],
                'method' => [
                    'type' => 'string',
                    'enum' => self::ALLOWED_METHODS,
                    'description' => 'HTTP method. Defaults to GET.',
                ],
                'body' => [
                    'type' => 'string',
                    'description' => 'Request body for POST requests.',
                ],
                'headers' => [
                    'type' => 'object',
                    'additionalProperties' => ['type' => 'string'],
                    'description' => 'Optional HTTP headers as key-value pairs.',
                ],
            ],
            'required' => ['url'],
        ];
    }

    /**
     * Authorize the caller, validate the URL and method, and resolve the target address.
     *
     * @param  array<string, mixed>  $input  Must contain 'url'; optionally 'method', 'body', 'headers'.
     * @return array{input: array<string, mixed>, result: string|null}
     *
     * @throws ToolException On invalid URL or blocked host.
     */
    protected function plan(array $input): array
    {
        $forbidden = $this->guardCapability('make an outbound HTTP request');

        if ($forbidden !== null) {
            return ['input' => $input, 'result' => $forbidden];
        }

        $url = trim((string) ($input['url'] ?? ''));
        $method = strtoupper(trim((string) ($input['method'] ?? 'GET')));

        $this->validateUrl($url);
        $this->validateMethod($method);

        return [
            'input' => [
                'url' => $url,
                'method' => $method,
                'body' => (string) ($input['body'] ?? ''),
                'headers' => is_array($input['headers'] ?? null) ? $input['headers'] : [],
                'resolved' => SsrfValidator::resolveValidated($url),
            ],
            'result' => null,
        ];
    }

    /**
     * Send the validated request.
     *
     * @param  array<string, mixed>  $input  Validated runtime input.
     * @return array<string, mixed> Internal execution result.
     *
     * @throws ToolException When cURL execution fails.
     */
    protected function perform(array $input): array
    {
        /** @var array{host: string, port: int, ips: list<string>}|null $resolved */
        $resolved = $input['resolved'];

        return $this->request(
            (string) $input['url'],
            (string) $input['method'],
            (string) $input['body'],
            (array) $input['headers'],
            $resolved,
        );
    }

    /**
     * Accept the response unchanged.
     *
     * @param  array<string, mixed>  $execution  Internal execution result.
     * @param  array<string, mixed>  $input  Validated input.
     * @return array{result: string|null}
     */
    protected function verify(array $execution, array $input): array
    {
        return ['result' => null];
    }

    /**
     * Convert the response into the public response envelope.
     *
     * @param  array<string, mixed>  $execution  Verified execution result.
     * @param  array<string, mixed>  $input  Validated input.
     * @return string JSON-encoded response envelope.
     */
    protected function complete(array $execution, array $input): string
    {
        return $this->success($execution, [
            'mode' => 'http',
            'method' => (string) $input['method'],
            'url' => (string) $input['url'],
        ]);
    }

    /**
     * Max response bytes the tool will return to the LLM.
     *
     * @return int
     */
    public function maxResponseBytes(): int
    {
        return $this->maxResponseBytes;
    }

    /**
     * Request timeout in seconds.
     *
     * @return int
     */
    public function timeoutSeconds(): int
    {
        return $this->timeoutSeconds;
    }

    /**
     * Verify the URL is non-empty and uses http or https.
     *
     * @param  string  $url  URL to validate.
     * @return void
     *
     * @throws ToolException When the URL is empty or uses a non-http(s) scheme.
     */
    private function validateUrl(string $url): void
    {
        if ($url === '') {
            throw new ToolException('No URL provided.');
        }

        $scheme = strtolower(parse_url($url, PHP_URL_SCHEME) ?? '');

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new ToolException(
                "URL scheme '{$scheme}' is not allowed. Only http:// and https:// are permitted."
            );
        }
    }

    /**
     * Verify the HTTP method is GET or POST.
     *
     * @param  string  $method  Upper-cased HTTP method.
     * @return void
     *
     * @throws ToolException When the method is not in the allowed list.
     */
    private function validateMethod(string $method): void
    {
        if (! in_array($method, self::ALLOWED_METHODS, true)) {
            throw new ToolException(
                "HTTP method '{$method}' is not allowed. Use GET or POST."
            );
        }
    }

    /**
     * Execute the cURL request and return the status, headers and truncated body.
     *
     * @param  string  $url  Validated URL.
     * @param  string  $method  HTTP method (GET or POST).
     * @param  string  $body  Request body (POST only).
     * @param  array<string, string>  $headers  Header map (CRLF-stripped before sending).
     * @param  array{host: string, port: int, ips: list<string>}|null  $resolved  Validated address set from resolveValidated(); null skips IP pinning.
     * @return array<string, mixed>
     *
     * @throws ToolException When cURL execution fails.
     */
    private function request(string $url, string $method, string $body, array $headers, ?array $resolved = null): array
    {
        $ch = curl_init();

        $responseHeaders = [];
        $opts = $this->buildCurlOptions($url, $headers, $resolved);
        $opts[CURLOPT_HEADERFUNCTION] = function ($ch, string $headerLine) use (&$responseHeaders): int {
            $this->captureResponseHeader($headerLine, $responseHeaders);

            return strlen($headerLine);
        };

        curl_setopt_array($ch, $opts);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $raw = curl_exec($ch);

        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new ToolException("HTTP request failed: {$error}");
        }

        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $this->buildResponsePayload($status, $responseHeaders, is_string($raw) ? $raw : '');
    }

    /**
     * Parse one raw header line from CURLOPT_HEADERFUNCTION into the accumulating header map.
     *
     * @param  string  $headerLine  Raw header line, including the trailing CRLF.
     * @param  array<string, string>  $responseHeaders  Accumulator, passed by reference.
     * @return void
     */
    private function captureResponseHeader(string $headerLine, array &$responseHeaders): void
    {
        $parts = explode(':', $headerLine, 2);
        if (count($parts) !== 2) {
            return;
        }

        $name = trim($parts[0]);
        if ($name === '') {
            return;
        }

        $responseHeaders[$name] = trim($parts[1]);
    }

    /**
     * Build the payload returned to the caller: status, response headers, and truncated body.
     *
     * @param  int  $status  HTTP response status code.
     * @param  array<string, string>  $headers  Parsed response headers.
     * @param  string  $body  Raw response body.
     * @return array<string, mixed>
     */
    private function buildResponsePayload(int $status, array $headers, string $body): array
    {
        return [
            'status' => $status,
            'headers' => $headers,
            'body' => $this->truncateResponse($body),
        ];
    }

    /**
     * Build the cURL option array for a request, optionally injecting CURLOPT_RESOLVE for IP pinning. Extracted as a named method so tests can inspect options (including CURLOPT_RESOLVE and CURLOPT_URL) without executing a real network request.
     *
     * @param  string  $url  Request URL: kept as the hostname URL to preserve Host header and SNI.
     * @param  array<string, string>  $headers  Caller-supplied headers.
     * @param  array{host: string, port: int, ips: list<string>}|null  $resolved  Validated address set; null omits the pin.
     * @return array<int, mixed>
     */
    private function buildCurlOptions(string $url, array $headers, ?array $resolved = null): array
    {
        $opts = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_HTTPHEADER => $this->buildCurlHeaders($headers),
            CURLOPT_USERAGENT => self::USER_AGENT,
        ];

        if ($resolved !== null) {
            $pin = SsrfValidator::pinEntry($resolved);
            if ($pin !== null) {
                $opts[CURLOPT_RESOLVE] = [$pin];
            }
        }

        return $opts;
    }

    /**
     * Format associative headers into `Name: Value` strings, stripping CRLF to prevent injection.
     *
     * @param  array<string, string>  $headers  Caller-supplied header map.
     * @return array<int, string>
     */
    private function buildCurlHeaders(array $headers): array
    {
        $curlHeaders = [];
        foreach ($headers as $name => $value) {
            $safeName = str_replace(["\r", "\n"], '', (string) $name);
            $safeValue = str_replace(["\r", "\n"], '', (string) $value);
            $curlHeaders[] = "{$safeName}: {$safeValue}";
        }

        return $curlHeaders;
    }

    /**
     * Truncate response to maxResponseBytes with a marker suffix.
     *
     * @param  string  $response  Raw response body.
     * @return string
     */
    private function truncateResponse(string $response): string
    {
        if (strlen($response) <= $this->maxResponseBytes) {
            return $response;
        }

        return substr($response, 0, $this->maxResponseBytes)
            ."\n[Response truncated at ".$this->maxResponseBytes.' bytes]';
    }

    /**
     * Return the routing signals the router ranks this tool by.
     *
     * @return ToolRoutingMetadata Domains, tags, intents and examples for this tool.
     */
    public function routingMetadata(): ToolRoutingMetadata
    {
        return new ToolRoutingMetadata(
            domains: ['network'],
            tags: ['http', 'request', 'fetch', 'url', 'api', 'endpoint', 'curl'],
            intents: ['fetch url', 'call api', 'http request'],
            examples: ['fetch https://example.com/status'],
        );
    }
}
