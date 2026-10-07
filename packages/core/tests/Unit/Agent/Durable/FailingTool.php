<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Agent\Durable;

use PhpClaw\Tools\Concerns\HasCoreToolBinding;
use PhpClaw\Tools\Contracts\AuthorizableToolInterface;
use PhpClaw\Tools\Contracts\ToolInterface;

final class FailingTool implements AuthorizableToolInterface, ToolInterface
{
    use HasCoreToolBinding;

    public const NAME = 'flaky';

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return 'always fails';
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
        CountingTool::$runs[self::NAME] = (CountingTool::$runs[self::NAME] ?? 0) + 1;

        return [];
    }

    protected function verify(array $execution, array $input): array
    {
        return ['result' => $this->error('SERVICE_UNAVAILABLE', 'service unavailable')];
    }

    protected function complete(array $execution, array $input): string
    {
        return $this->success($execution, ['mode' => self::NAME]);
    }
}
