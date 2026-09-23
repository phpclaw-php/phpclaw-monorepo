<?php

declare(strict_types=1);

use PhpClaw\PrestaShop\Plugin;

if (! function_exists('phpclaw_agent')) {
    /**
     * Global phpClaw helper: runs the agent with a message, or returns the Plugin singleton.
     *
     * @param  ?string  $message  Prompt to send; null returns the singleton without invoking the agent.
     * @return mixed Agent response text (string) when $message is given, Plugin otherwise.
     */
    function phpclaw_agent(?string $message = null): mixed
    {
        static $instance = null;

        if ($instance === null) {
            $instance = Plugin::getInstance();
        }

        if ($message === null) {
            return $instance;
        }

        return $instance->engine()->send($message)->text;
    }
}
