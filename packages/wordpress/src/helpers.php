<?php

declare(strict_types=1);

/**
 * phpClaw WordPress global helper.
 */

use PhpClaw\WordPress\Plugin;

if (! function_exists('phpclaw')) {
    /**
     * Send a prompt to the phpClaw agent or retrieve the engine instance.
     *
     * @param  string|null  $message  The prompt to send, or null to get the engine instance.
     * @return mixed The agent response when a message is provided, or the PhpClaw engine instance.
     */
    function phpclaw(?string $message = null): mixed
    {
        $instance = Plugin::getInstance()->engine();

        if ($message !== null) {
            return $instance->send($message);
        }

        return $instance;
    }
}
