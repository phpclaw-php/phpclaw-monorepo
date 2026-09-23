<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Api\Data;

/**
 * Data contract for the POST /V1/phpclaw/send response. The interface is declared because Magento's
 * DataObjectProcessor reflects on it to emit a JSON object rather than a bare array.
 */
interface SendResponseInterface
{
    /**
     * Return the final assistant text from the agent run.
     *
     * @return string
     */
    public function getText(): string;

    /**
     * Return the provider slug used for this run (e.g. "anthropic", "openai").
     *
     * @return string
     */
    public function getProvider(): string;

    /**
     * Return the model identifier used for this run (e.g. "claude-haiku-4-5-20251001").
     *
     * @return string
     */
    public function getModel(): string;

    /**
     * Return the total number of tokens consumed during the run.
     *
     * @return int
     */
    public function getTokens(): int;

    /**
     * Return the number of agentic iterations completed during the run.
     *
     * @return int
     */
    public function getIterations(): int;
}
