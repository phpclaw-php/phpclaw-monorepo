<?php

declare(strict_types=1);

/**
 * phpClaw OpenCart global helper.
 */

use PhpClaw\Contracts\ClawInterface;
use PhpClaw\OpenCart\Plugin;

if (! function_exists('phpclaw')) {
    /**
     * Send a prompt to the phpClaw agent, or return the engine when no message is given.
     *
     * @param  string|null  $message  Prompt to send; null returns the engine instance.
     * @return string|ClawInterface
     */
    function phpclaw(?string $message = null): string|ClawInterface
    {
        $engine = Plugin::getInstance()->engine(
            defined('PHPCLAW_OC_CONSOLE') && constant('PHPCLAW_OC_CONSOLE') === true,
        );

        if ($message !== null) {
            return $engine->send($message)->text;
        }

        return $engine;
    }
}
