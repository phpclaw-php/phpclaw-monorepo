<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Agent\Durable;

use PhpClaw\Tools\Concerns\HasCoreToolBinding;
use PhpClaw\Tools\Contracts\AuthorizableToolInterface;
use PhpClaw\Tools\Contracts\ToolInterface;

final class CountingTool implements AuthorizableToolInterface, ToolInterface
{
    use HasCoreToolBinding;

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

    protected function plan(array $input): array
    {
        return ['input' => $input, 'result' => null];
    }

    protected function perform(array $input): array
    {
        self::$runs[$this->name] = (self::$runs[$this->name] ?? 0) + 1;

        return ['ok' => $this->name];
    }

    protected function verify(array $execution, array $input): array
    {
        return ['result' => null];
    }

    protected function complete(array $execution, array $input): string
    {
        return $this->success($execution, ['mode' => $this->name]);
    }
}
