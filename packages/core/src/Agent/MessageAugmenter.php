<?php

declare(strict_types=1);

namespace PhpClaw\Agent;

use PhpClaw\Hooks\Dispatchers\SkillEventDispatcher;
use PhpClaw\Hooks\HookDispatcher;
use PhpClaw\Memory\Contracts\MemoryInterface;
use PhpClaw\Skills\SkillRegistry;

/**
 * Augments a user message with memory context and skill context before it reaches the LLM.
 *
 * @internal
 */
final class MessageAugmenter
{
    private const MIN_KEYWORD_LENGTH = 3;

    private const MAX_MEMORY_HITS = 3;

    /**
     * Build a MessageAugmenter.
     *
     * @param  MemoryInterface|null  $memory  Memory driver used to augment messages with stored context, or null.
     * @param  int  $skillMatchLimit  Maximum matched skills injected per message.
     */
    public function __construct(
        private readonly ?MemoryInterface $memory,
        private readonly int $skillMatchLimit = SkillRegistry::DEFAULT_MATCH_LIMIT,
    ) {}

    /**
     * Return the message prefixed with whichever of (memory, skill) context sources match. Returns the original message when nothing matches.
     *
     * @param  string  $message  User message.
     * @return string The resulting value.
     */
    public function augment(string $message): string
    {
        return $this->injectSkillContext(
            $this->injectMemoryContext($message),
            $message,
        );
    }

    /**
     * Prefix the message with the top-N keyword-relevant memory entries.
     *
     * @param  string  $message  User message.
     * @return string The resulting value.
     */
    private function injectMemoryContext(string $message): string
    {
        if ($this->memory === null) {
            return $message;
        }

        $entries = $this->memory->all();

        if (empty($entries)) {
            return $message;
        }

        $messageWords = $this->keywords($message);
        $scored = [];

        foreach ($entries as $key => $value) {
            $text = is_string($value) ? $value : (string) json_encode($value);
            $score = count(array_intersect($messageWords, $this->keywords($text)));

            if ($score > 0) {
                $scored[$key] = ['score' => $score, 'value' => $value];
            }
        }

        if (empty($scored)) {
            return $message;
        }

        arsort($scored);
        $top = array_slice($scored, 0, self::MAX_MEMORY_HITS, true);

        $context = "Relevant memory context:\n";
        foreach ($top as $key => $item) {
            $val = is_string($item['value']) ? $item['value'] : (string) json_encode($item['value']);
            $context .= "- {$key}: {$val}\n";
        }

        return "[Context from memory]\n{$context}\n[User message]\n{$message}";
    }

    /**
     * Prepend matched skill content to the (already memory-augmented) message.
     *
     * @param  string  $augmented  Message already processed by injectMemoryContext().
     * @param  string  $original  Original unmodified user message for skill matching.
     * @return string
     */
    private function injectSkillContext(string $augmented, string $original): string
    {
        $matched = SkillRegistry::match($original, $this->skillMatchLimit);
        $excerpt = mb_substr($original, 0, 200);
        $runId = HookDispatcher::currentRunId();

        if (empty($matched)) {
            SkillEventDispatcher::notMatched($excerpt, SkillRegistry::names(), runId: $runId);

            return $augmented;
        }

        SkillEventDispatcher::matched(array_map(static fn ($s) => $s->name(), $matched), $excerpt, runId: $runId);

        $context = '';
        foreach ($matched as $skill) {
            $context .= $skill->content()."\n\n";
        }

        return "[Skill context]\n{$context}[Message]\n{$augmented}";
    }

    /**
     * Lowercase keyword list from a string, filtered to words of MIN_KEYWORD_LENGTH+.
     *
     * @param  string  $text  Assistant response text.
     * @return list<string>
     */
    private function keywords(string $text): array
    {
        return array_values(array_unique(array_filter(
            str_word_count(strtolower($text), 1),
            static fn (string $w): bool => strlen($w) >= self::MIN_KEYWORD_LENGTH,
        )));
    }
}
