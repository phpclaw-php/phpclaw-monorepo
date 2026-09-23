<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Component\Administrator\Engine;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Event\Event;

/**
 * Thin static helpers shared by EngineBootstrapper and ToolBuilder.
 */
final class JoomlaEventDispatcher
{
    /**
     * Dispatch a mutable Joomla event and write the modified argument back into $value.
     *
     * @param  string  $event  Event name, e.g. 'onPhpClawExtraTools'.
     * @param  string  $key  Argument key inside the event.
     * @param  mixed  $value  Value passed in and written back out.
     * @return void
     */
    public static function fire(string $event, string $key, mixed &$value): void
    {
        try {
            $e = new Event($event, [$key => $value]);
            Factory::getApplication()->getDispatcher()->dispatch($event, $e);
            $value = $e->getArgument($key, $value);
        } catch (\Throwable) {
        }
    }

    /**
     * Resolve the Joomla database interface from the DI container.
     *
     * @return DatabaseInterface
     */
    public static function db(): DatabaseInterface
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);

        return $db;
    }
}
