<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Tests\Support\Stubs;

use RuntimeException;

final class DriverExceptionWithSqlState extends RuntimeException
{
    public function __construct(
        private readonly string $sqlState,
        int $vendorCode,
        string $message,
    ) {
        parent::__construct($message, $vendorCode);
    }

    public function getSqlState(): string
    {
        return $this->sqlState;
    }
}
