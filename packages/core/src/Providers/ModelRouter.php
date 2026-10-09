<?php

declare(strict_types=1);

namespace PhpClaw\Providers;

use PhpClaw\Agent\Message;
use PhpClaw\Exceptions\ProviderException;
use PhpClaw\Exceptions\StructuredOutputException;
use PhpClaw\Providers\Contracts\ProviderInterface;

/**
 * Picks the provider name for a message by asking a classifier model how difficult the message is.
 */
final class ModelRouter
{
    private const QUESTION = <<<'TEXT'
        Classify how difficult this request is for an AI assistant.
        Reply with JSON only, no other text: {"value": one of %s}
        Request: %s
        TEXT;

    /**
     * Hold the classifier and the provider name for each difficulty.
     *
     * @param  ProviderInterface  $classifier  Model asked how difficult a message is.
     * @param  array<string, string>  $providers  Provider name per difficulty, such as ['easy' => 'groq', 'hard' => 'anthropic'].
     */
    public function __construct(
        private readonly ProviderInterface $classifier,
        private readonly array $providers,
    ) {}

    /**
     * Return the provider name mapped to the difficulty the classifier gives the message, for ClawBuilder::provider().
     *
     * @param  string  $message  The user message to route.
     * @return string
     *
     * @throws ProviderException When the classifier call fails.
     * @throws StructuredOutputException When the reply is not JSON whose "value" names a mapped difficulty.
     */
    public function resolve(string $message): string
    {
        $choices = implode(', ', array_map(static fn (int|string $difficulty): string => '"'.$difficulty.'"', array_keys($this->providers)));
        $reply = (string) ($this->classifier->send([Message::user(sprintf(self::QUESTION, $choices, $message))])['text'] ?? '');
        $answer = json_decode($reply, true);
        $difficulty = is_array($answer) ? ($answer['value'] ?? null) : null;

        if (! is_string($difficulty) || ! array_key_exists($difficulty, $this->providers)) {
            throw new StructuredOutputException($reply, ['The classifier reply is not JSON whose "value" names a mapped difficulty.']);
        }

        return $this->providers[$difficulty];
    }
}
