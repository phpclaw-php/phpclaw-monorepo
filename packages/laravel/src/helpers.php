<?php

declare(strict_types=1);

/**
 * phpClaw Laravel global helper: resolves the agent from the container (cached) or runs a message.
 */

use PhpClaw\Contracts\ClawInterface as PhpClawInterface;
use PhpClaw\Exceptions\AdapterException;

if (! function_exists('phpclaw')) {
    /**
     * Resolve the phpClaw agent from the container, or send a message when one is given.
     *
     * @param  string|null  $message  Message to send immediately, or null to return the instance.
     * @param  bool  $fresh  Force a fresh container resolution.
     * @return mixed The AgentResponse when $message is given, otherwise the agent instance.
     *
     * @throws AdapterException When phpClaw is not registered in the container.
     */
    function phpclaw(?string $message = null, bool $fresh = false): mixed
    {
        $app = function_exists('app') ? app() : null;

        if ($app === null || ! $app->bound(PhpClawInterface::class)) {
            throw new AdapterException(
                'phpClaw is not registered in the container. Load PhpClawServiceProvider before '
                .'calling phpclaw(); building an engine outside the container would silently skip '
                .'your config, guards, tools and memory driver.'
            );
        }

        if ($fresh) {
            $app->forgetInstance(PhpClawInterface::class);
        }

        $instance = $app->make(PhpClawInterface::class);

        if ($message !== null) {
            return $instance->send($message);
        }

        return $instance;
    }
}
