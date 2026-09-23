<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Tests\Support\Stubs;

use RuntimeException;

final class DriverExceptionWithMagicSqlState extends RuntimeException
{
    public function __construct(
        private readonly string $sqlState,
        int $vendorCode,
        string $message,
    ) {
        parent::__construct($message, $vendorCode);
    }

    public function __call(string $method, array $arguments): string
    {
        if ($method === 'getSqlState') {
            return $this->sqlState;
        }

        throw new \BadMethodCallException($method);
    }
}
