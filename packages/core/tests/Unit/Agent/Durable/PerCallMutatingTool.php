<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Agent\Durable;

use PhpClaw\Tools\Concerns\HasCoreToolBinding;
use PhpClaw\Tools\Contracts\AuthorizableToolInterface;
use PhpClaw\Tools\Contracts\PerInvocationMutabilityInterface;
use PhpClaw\Tools\Contracts\ToolInterface;

final class PerCallMutatingTool implements AuthorizableToolInterface, PerInvocationMutabilityInterface, ToolInterface
{
    use HasCoreToolBinding;

    public const NAME = 'sql';

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return 'sql';
    }

    public function inputSchema(): array
    {
        return [];
    }

    public function isMutating(array $input): bool
    {
        return ($input['write'] ?? false) === true;
    }

    protected function plan(array $input): array
    {
        return ['input' => $input, 'result' => null];
    }

    protected function perform(array $input): array
    {
        return [];
    }

    protected function verify(array $execution, array $input): array
    {
        return ['result' => null];
    }

    protected function complete(array $execution, array $input): string
    {
        return $this->success($execution, ['mode' => self::NAME]);
    }
}
