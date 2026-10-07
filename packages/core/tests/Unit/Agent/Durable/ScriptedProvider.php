<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Agent\Durable;

use PhpClaw\Providers\Contracts\ProviderInterface;

final class ScriptedProvider implements ProviderInterface
{
    public int $sends = 0;

    public function __construct(
        private readonly array $plan,
        private readonly int $tokensPerCall = 10,
        private readonly int $delayMicroseconds = 0,
        private readonly int $failOnSend = 0,
    ) {}

    public function send(array $messages, array $tools = []): array
    {
        $this->sends++;
        usleep($this->delayMicroseconds);
        if ($this->sends === $this->failOnSend) {
            throw new \RuntimeException('provider down');
        }
        $step = count(array_filter($messages, static fn ($m): bool => $m->role === 'tool_batch'));
        $reply = $this->plan[$step] ?? ['type' => 'text', 'text' => 'done'];

        return $reply + ['input_tokens' => $this->tokensPerCall, 'output_tokens' => 0];
    }

    public function stream(array $messages, callable $onToken): string
    {
        return '';
    }

    public function name(): string
    {
        return 'openai';
    }

    public function model(): string
    {
        return 'scripted';
    }

    public static function call(string $id, string $tool, array $input = []): array
    {
        return ['tool_use_id' => $id, 'tool_name' => $tool, 'tool_input' => $input];
    }

    public static function batch(array ...$calls): array
    {
        return ['type' => 'tool_use_batch', 'calls' => $calls];
    }
}
