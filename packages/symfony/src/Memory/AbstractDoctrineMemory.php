<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Memory;

use PhpClaw\Exceptions\MemoryException;
use PhpClaw\Memory\Contracts\MemoryInterface;
use PhpClaw\Symfony\Exceptions\ConversationAccessDeniedException;

/**
 * Base for Doctrine DBAL-backed memory drivers.
 */
abstract class AbstractDoctrineMemory implements MemoryInterface
{
    /**
     * Run a memory operation, re-throwing MemoryException as-is and wrapping any other failure.
     *
     * @param  string  $operation  Label used in the exception message.
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

    /**
     * Returns the current UTC datetime as a formatted string for DB writes.
     *
     * @return string
     */
    protected function utcNow(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }

    /**
     * Returns the UTC expiry datetime string, or null when no TTL is given.
     *
     * @param  int|null  $ttl  Seconds until expiry; null means no expiry.
     * @return ?string
     */
    protected function expiryAt(?int $ttl): ?string
    {
        if ($ttl === null) {
            return null;
        }

        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify("+{$ttl} seconds")
            ->format('Y-m-d H:i:s');
    }

    /**
     * Returns true when the stored UTC timestamp string is in the past.
     *
     * @param  string  $expiresAt  UTC datetime string from the database.
     * @return bool
     */
    protected function isExpired(string $expiresAt): bool
    {
        try {
            $expiry = new \DateTimeImmutable($expiresAt, new \DateTimeZone('UTC'));
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

            return $expiry < $now;
        } catch (\Throwable) {
            return false;
        }
    }
}
