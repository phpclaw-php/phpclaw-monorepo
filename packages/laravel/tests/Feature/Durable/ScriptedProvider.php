<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Tests\Feature\Durable;

use PhpClaw\Providers\Contracts\ProviderInterface;

final class ScriptedProvider implements ProviderInterface
{
    public function __construct(private readonly array $plan) {}

    public function send(array $messages, array $tools = []): array
    {
        $step = count(array_filter($messages, static fn ($m): bool => $m->role === 'tool_batch'));
        $reply = $this->plan[$step] ?? ['type' => 'text', 'text' => 'done'];

        if ($reply === 'throw') {
            throw new \RuntimeException('provider down');
        }

        return $reply + ['input_tokens' => 5, 'output_tokens' => 0];
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

    public static function refundPlan(): array
    {
        return [
            ['type' => 'tool_use_batch', 'calls' => [['tool_use_id' => 'c1', 'tool_name' => 'refund', 'tool_input' => ['order' => 7]]]],
            ['type' => 'text', 'text' => 'refund handled'],
        ];
    }

    public static function twoRefundPlan(): array
    {
        return [
            ['type' => 'tool_use_batch', 'calls' => [['tool_use_id' => 'c1', 'tool_name' => 'refund', 'tool_input' => ['order' => 7]]]],
            ['type' => 'tool_use_batch', 'calls' => [['tool_use_id' => 'c2', 'tool_name' => 'refund', 'tool_input' => ['order' => 8]]]],
            ['type' => 'text', 'text' => 'both handled'],
        ];
    }

    public static function lookupPlan(int $steps): array
    {
        $plan = [];
        for ($i = 1; $i <= $steps; $i++) {
            $plan[] = ['type' => 'tool_use_batch', 'calls' => [['tool_use_id' => 's'.$i, 'tool_name' => 'lookup', 'tool_input' => []]]];
        }
        $plan[] = ['type' => 'text', 'text' => 'all steps done'];

        return $plan;
    }
}
