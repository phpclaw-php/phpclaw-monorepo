<?php

declare(strict_types=1);

namespace PhpClaw\Tools;

/**
 * Routing signals a tool advertises so the router can rank it by intent and domain rather than by
 * the words that happen to appear in its description.
 */
final class ToolRoutingMetadata
{
    /**
     * Create a new ToolRoutingMetadata instance.
     *
     * @param  string[]  $domains  Broad subject areas the tool belongs to, for example 'filesystem' or 'catalog'.
     * @param  string[]  $tags  Narrow keywords a user is likely to type when they want this tool.
     * @param  string[]  $intents  Verb phrases describing what the tool does, for example 'read file'.
     * @param  string[]  $examples  Short example requests the tool answers well.
     * @return void
     */
    public function __construct(
        public readonly array $domains = [],
        public readonly array $tags = [],
        public readonly array $intents = [],
        public readonly array $examples = [],
    ) {}

    /**
     * Return an instance carrying no signals, used for any tool that declares no routing metadata.
     *
     * @return self
     */
    public static function empty(): self
    {
        return new self;
    }

    /**
     * Whether this metadata carries no routing signal at all.
     *
     * @return bool
     */
    public function isEmpty(): bool
    {
        return $this->domains === []
            && $this->tags === []
            && $this->intents === []
            && $this->examples === [];
    }
}
