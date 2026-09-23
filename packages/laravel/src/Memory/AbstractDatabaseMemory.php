<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Memory;

use PhpClaw\Exceptions\MemoryException;
use PhpClaw\Laravel\Exceptions\ConversationAccessDeniedException;
use PhpClaw\Memory\Contracts\MemoryInterface;

/**
 * Base for the query-builder backed memory drivers.
 */
abstract class AbstractDatabaseMemory implements MemoryInterface
{
    /**
     * Run a memory operation, translating any unexpected failure into a MemoryException.
     *
     * @param  string  $operation  Label used in the wrapped message.
     * @param  \Closure  $fn  The operation to run.
     * @return mixed
     *
     * @throws MemoryException
     */
    protected function guard(string $operation, \Closure $fn): mixed
    {
        try {
            return $fn();
        } catch (ConversationAccessDeniedException|MemoryException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new MemoryException("{$operation} failed.", previous: $e);
        }
    }
}
