<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Tools\Support;

use PhpClaw\Tools\Contracts\ToolAuthorizerInterface;

final class FakeToolAuthorizer implements ToolAuthorizerInterface
{
    public function __construct(
        private readonly bool $allows,
        private readonly bool $console = false,
    ) {}

    public function allows(): bool
    {
        return $this->allows;
    }

    public function runningInConsole(): bool
    {
        return $this->console;
    }
}
