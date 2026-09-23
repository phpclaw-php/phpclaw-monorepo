<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Model\Api\Data;

use PhpClaw\Magento\Api\Data\SendResponseInterface;

/**
 * Immutable value object for the POST /V1/phpclaw/send response.
 */
final class SendResponse implements SendResponseInterface
{
    /**
     * Bind the run result values this response exposes.
     *
     * @param  string  $text  The final assistant text.
     * @param  string  $provider  AI provider slug used for this run.
     * @param  string  $model  Model name used for this run.
     * @param  int  $tokens  Total tokens consumed (input + output).
     * @param  int  $iterations  Number of agent iterations taken.
     * @return void
     */
    public function __construct(
        private readonly string $text,
        private readonly string $provider,
        private readonly string $model,
        private readonly int $tokens,
        private readonly int $iterations,
    ) {}

    /**
     * Return the final assistant response text.
     *
     * @return string
     */
    public function getText(): string
    {
        return $this->text;
    }

    /**
     * Return the AI provider slug used for this run.
     *
     * @return string
     */
    public function getProvider(): string
    {
        return $this->provider;
    }

    /**
     * Return the model name used for this run.
     *
     * @return string
     */
    public function getModel(): string
    {
        return $this->model;
    }

    /**
     * Return the total tokens consumed (input + output).
     *
     * @return int
     */
    public function getTokens(): int
    {
        return $this->tokens;
    }

    /**
     * Return the number of agent iterations taken.
     *
     * @return int
     */
    public function getIterations(): int
    {
        return $this->iterations;
    }
}
