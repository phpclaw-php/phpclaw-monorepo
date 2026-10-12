<?php

declare(strict_types=1);

namespace PhpClaw\Drupal\Tests\Unit\Fixtures;

use PhpClaw\Agent\Message;
use PhpClaw\Providers\Contracts\ProviderInterface;

final class ScriptedProvider implements ProviderInterface
{
    public array $received = [];

    public function __construct(private readonly ?\Closure $beforeReply = null) {}

    public function send(array $messages, array $tools = []): array
    {
        return ['type' => 'text', 'text' => $this->reply($messages), 'input_tokens' => 11, 'output_tokens' => 3];
    }

    public function stream(array $messages, callable $onToken): string
    {
        $text = $this->reply($messages);
        $onToken($text);

        return $text;
    }

    public function name(): string
    {
        return 'scripted';
    }

    public function model(): string
    {
        return 'scripted-model';
    }

    private function reply(array $messages): string
    {
        $last = '';
        foreach ($messages as $message) {
            if ($message instanceof Message && $message->role === 'user') {
                $last = (string) $message->content;
            }
        }
        $this->received[] = $last;

        if ($this->beforeReply !== null) {
            ($this->beforeReply)($last);
        }

        return 'reply to '.$last;
    }
}
