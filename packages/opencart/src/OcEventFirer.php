<?php

declare(strict_types=1);

namespace PhpClaw\OpenCart;

/**
 * Fires OpenCart registry events with a mutable bucket.
 */
final class OcEventFirer
{
    /**
     * Bind the OpenCart Registry this firer dispatches events through, or null off-request.
     *
     * @param  object|null  $registry  OpenCart Registry instance, or null (CLI / tests).
     */
    public function __construct(
        private readonly ?object $registry,
    ) {}

    /**
     * Trigger a third-party-extension event through OC's Registry with a mutable bucket.
     *
     * @param  string  $eventName  OC event name, e.g. `phpclaw/extra/tools`.
     * @param  array<array-key, mixed>  $bucket  Initial bucket; listeners append to it by reference.
     * @return array<array-key, mixed> Bucket after all listeners have run.
     */
    public function fire(string $eventName, array $bucket): array
    {
        if ($this->registry === null || ! $this->registry->has('event')) {
            return $bucket;
        }

        try {
            $this->registry->get('event')->trigger($eventName, [&$bucket]);
        } catch (\Throwable) {
        }

        return $bucket;
    }
}
