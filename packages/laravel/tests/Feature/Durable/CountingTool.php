<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Tests\Feature\Durable;

use PhpClaw\Tools\Contracts\MutatingToolInterface;
use PhpClaw\Tools\Contracts\ToolInterface;

final class CountingTool implements MutatingToolInterface, ToolInterface
{
    public static array $runs = [];

    public function __construct(private readonly string $name) {}

    public function name(): string
    {
        return $this->name;
    }

    public function description(): string
    {
        return 'counts its runs';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => []];
    }

    public function execute(array $input): string
    {
        self::$runs[$this->name] = (self::$runs[$this->name] ?? 0) + 1;

        return 'ok';
    }
}
