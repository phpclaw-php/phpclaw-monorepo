<?php

declare(strict_types=1);

namespace PhpClaw\Laravel;

use Illuminate\Contracts\Foundation\Application;

/**
 * Reports whether phpClaw is running under an interactive console entrypoint.
 */
final class LaravelConsole
{
    public const WORKER_COMMANDS = [
        'queue:work',
        'queue:listen',
        'horizon',
        'horizon:work',
        'horizon:supervisor',
    ];

    /**
     * Whether this process is an interactive console entrypoint rather than a queue worker.
     *
     * @param  Application  $app  The application whose entrypoint is being classified.
     * @return bool
     */
    public static function isInteractive(Application $app): bool
    {
        if (! $app->runningInConsole()) {
            return false;
        }

        return ! self::isQueueWorker($app);
    }

    /**
     * Whether this process is a queue worker.
     *
     * @param  Application  $app  The application whose entrypoint is being classified.
     * @return bool
     */
    public static function isQueueWorker(Application $app): bool
    {
        try {
            return (bool) $app->runningConsoleCommand(...self::workerCommands());
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Return the worker command names to exclude, the built-in set plus any the host app names.
     *
     * @return string[]
     */
    private static function workerCommands(): array
    {
        $extra = array_values(array_filter(
            (array) config('phpclaw.worker_commands', []),
            static fn (mixed $name): bool => is_string($name) && $name !== '',
        ));

        return array_values(array_unique([...self::WORKER_COMMANDS, ...$extra]));
    }
}
