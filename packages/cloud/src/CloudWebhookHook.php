<?php

declare(strict_types=1);

namespace PhpClaw\Cloud;

use PhpClaw\Hooks\Contracts\HookInterface;
use PhpClaw\Hooks\LifecycleEvent;

/**
 * Cloud lifecycle hook: transport-layer façade.
 */
final class CloudWebhookHook implements HookInterface
{
    public const ANY_EVENT_MARKER = '__any__';

    private const RUNS_PATH = '/'.CloudHttp::API_VERSION.'/runs';

    private ?CloudPayloadBuilder $builder = null;

    /**
     * Configure the cloud lifecycle hook for one event (or the wildcard marker).
     *
     * @param  string  $key  phpClaw Cloud API key.
     * @param  string  $event  Lifecycle event name this instance is registered for, or `__any__`.
     * @param  list<string>  $disable  The site's cloud_disable names; hide_ names blank payload fields.
     * @return void
     */
    public function __construct(
        private readonly string $key,
        private readonly string $event,
        private readonly array $disable = [],
    ) {}

    /**
     * Dispatch event data to cloud.
     *
     * @param  array<string, mixed>  $context  Event data from the hook dispatcher.
     * @return void
     */
    public function handle(array $context): void
    {
        $event = $this->event === self::ANY_EVENT_MARKER
            ? (string) ($context['event'] ?? '')
            : $this->event;

        if ($event === LifecycleEvent::ProviderToken->value) {
            return;
        }

        $payload = $this->builder()->build($event, $context);

        CloudHttp::fire(
            CloudHttp::baseUrl().self::RUNS_PATH,
            $this->key,
            $payload,
        );
    }

    /**
     * Event name this hook instance is registered for (or `__any__`).
     *
     * @return string Event name, or `__any__` for the wildcard listener.
     */
    public function event(): string
    {
        return $this->event;
    }

    /**
     * Lazily instantiate the payload builder.
     *
     * @return CloudPayloadBuilder The lazily-instantiated payload builder.
     */
    private function builder(): CloudPayloadBuilder
    {
        return $this->builder ??= new CloudPayloadBuilder($this->disable);
    }
}
