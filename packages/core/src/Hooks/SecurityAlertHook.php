<?php

declare(strict_types=1);

namespace PhpClaw\Hooks;

use PhpClaw\AutoDiscovery\Attributes\Hook;
use PhpClaw\Hooks\Contracts\HookInterface;
use PhpClaw\Support\Log;
use PhpClaw\Support\SsrfValidator;

/**
 * Sends a webhook alert when a guard blocks an attack; non-final so tests can override the protected sendWebhook()/buildWebhookContext() seams to substitute an HTTP spy without real network calls.
 */
#[Hook(
    event: 'guard.blocked',
    priority: 50,
    name: 'security_alert',
    label: 'Security Alert (notify on guard block)',
    enabledByDefault: true,
    since: '1.0.0',
)]
class SecurityAlertHook implements HookInterface
{
    public const DEFAULT_TIMEOUT = 3;

    private const DEFAULT_EVENTS = ['guard.blocked'];

    private readonly string $webhookUrl;

    private readonly int $timeout;

    private readonly array $events;

    private readonly ?\Closure $payloadFormatter;

    /**
     * Build a SecurityAlertHook configured with a webhook URL, timeout, event filter, and optional payload formatter.
     *
     * @param  string  $webhookUrl  HTTPS webhook URL. Falls back to PHPCLAW_SECURITY_WEBHOOK env var.
     * @param  int  $timeout  HTTP request timeout in seconds. Default: 3.
     * @param  list<string>  $events  Event names to react to. Default: ['guard.blocked'].
     * @param  \Closure|null  $payloadFormatter  Optional callable to build the alert payload. Receives (event, payload, iso8601Date) and returns an array. Null = use the default shape (alert, guard, reason, at).
     * @return void
     */
    public function __construct(
        string $webhookUrl = '',
        int $timeout = self::DEFAULT_TIMEOUT,
        array $events = self::DEFAULT_EVENTS,
        ?\Closure $payloadFormatter = null,
    ) {
        $this->webhookUrl = $webhookUrl;
        $this->timeout = $timeout;
        $this->events = array_values($events);
        $this->payloadFormatter = $payloadFormatter;
    }

    /**
     * Handle a hook event and dispatch a security alert for configured events.
     *
     * @param  array<string, mixed>  $context  Event context: for guard.blocked, carries flat 'guard' and 'reason' keys (see GuardEventDispatcher::blocked()).
     * @return void
     */
    public function handle(array $context): void
    {
        $event = (string) ($context['event'] ?? '');

        if (! in_array($event, $this->events, true)) {
            return;
        }

        $payload = [
            'guard' => (string) ($context['guard'] ?? 'unknown'),
            'reason' => (string) ($context['reason'] ?? 'unknown'),
        ];

        $webhook = $this->webhookUrl !== ''
            ? $this->webhookUrl
            : $this->env('PHPCLAW_SECURITY_WEBHOOK');

        if ($webhook === '') {
            return;
        }

        if (! str_starts_with($webhook, 'https://')) {
            Log::warning('phpClaw SecurityAlertHook: webhook must use HTTPS: alert not sent.');

            return;
        }

        if (! SsrfValidator::isPublicHost($webhook)) {
            Log::warning('phpClaw SecurityAlertHook: webhook targets a private address or could not be resolved (private/internal host or DNS failure), alert not sent.');

            return;
        }

        $isoTimestamp = (new \DateTimeImmutable)->format(\DateTimeInterface::ATOM);
        $payload = $this->buildPayload($event, $payload, $isoTimestamp);

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($body === false) {
            return;
        }

        $this->sendWebhook($webhook, $body);
    }

    /**
     * Return the configured webhook URL for this hook instance.
     *
     * @return string The webhook URL passed to the constructor (may be '' when the env-var fallback is used at runtime).
     */
    public function webhookUrl(): string
    {
        return $this->webhookUrl;
    }

    /**
     * Return the configured HTTP timeout in seconds.
     *
     * @return int Timeout used for sendWebhook() POST requests.
     */
    public function timeout(): int
    {
        return $this->timeout;
    }

    /**
     * Return the list of event names this hook reacts to.
     *
     * @return list<string> Subscribed event names; events outside this list are ignored by handle().
     */
    public function events(): array
    {
        return $this->events;
    }

    /**
     * Fire-and-forget POST of the JSON-encoded alert body to the webhook URL.
     *
     * @param  string  $url  Webhook destination, already HTTPS- and SSRF-checked.
     * @param  string  $body  JSON-encoded alert payload.
     * @return void
     */
    protected function sendWebhook(string $url, string $body): void
    {
        $streamContext = stream_context_create($this->buildWebhookContext($body));

        @file_get_contents($url, false, $streamContext);
    }

    /**
     * Build the stream context options for the webhook POST. Redirect following is explicitly disabled: a redirect to an internal address would not be re-validated by the SSRF check that already ran against the original URL.
     *
     * @param  string  $body  JSON-encoded alert payload.
     * @return array<string, array<string, mixed>>
     */
    protected function buildWebhookContext(string $body): array
    {
        return [
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => $body,
                'timeout' => $this->timeout,
                'ignore_errors' => true,
                'follow_location' => 0,
                'max_redirects' => 0,
            ],
        ];
    }

    /**
     * Build the alert payload: uses payloadFormatter if provided, otherwise the default shape.
     *
     * @param  string  $event  Lifecycle event name that triggered the alert.
     * @param  array<string, mixed>  $payload  Hook context payload carrying the guard and reason keys.
     * @param  string  $isoTimestamp  ISO-8601 / ATOM timestamp for the alert.
     * @return array<string, mixed> Formatted alert payload ready for JSON encoding.
     */
    private function buildPayload(string $event, array $payload, string $isoTimestamp): array
    {
        if ($this->payloadFormatter !== null) {
            return ($this->payloadFormatter)($event, $payload, $isoTimestamp);
        }

        return [
            'alert' => 'phpClaw: Attack blocked',
            'guard' => $payload['guard'],
            'reason' => $payload['reason'],
            'at' => $isoTimestamp,
        ];
    }

    /**
     * Read an environment variable: checks $_ENV first, then getenv().
     *
     * @param  string  $name  Environment variable name to read.
     * @param  string  $default  Value returned when the variable is unset or empty.
     * @return string The resolved value, or $default when not present.
     */
    private function env(string $name, string $default = ''): string
    {
        return (string) ($_ENV[$name] ?? getenv($name) ?: $default);
    }
}
