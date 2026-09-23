<?php

declare(strict_types=1);

namespace PhpClaw\Mcp\Tests\Stubs;

use PhpClaw\Tools\Contracts\ToolInterface;

final class EchoTool implements ToolInterface
{
    public function name(): string
    {
        return 'echo';
    }

    public function description(): string
    {
        return 'Returns input unchanged';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'text' => ['type' => 'string', 'description' => 'Text to echo back'],
            ],
            'required' => ['text'],
        ];
    }

    public function execute(array $input): string
    {
        return $input['text'] ?? '';
    }
}
