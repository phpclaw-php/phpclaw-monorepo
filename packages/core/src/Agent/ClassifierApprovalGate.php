<?php

declare(strict_types=1);

namespace PhpClaw\Agent;

use PhpClaw\Agent\Contracts\ApprovalGateInterface;
use PhpClaw\Exceptions\ApprovalPendingException;
use PhpClaw\Exceptions\HumanDeniedException;
use PhpClaw\Exceptions\PhpClawException;
use PhpClaw\Providers\Contracts\ProviderInterface;
use PhpClaw\Tools\Contracts\MutatingToolInterface;
use PhpClaw\Tools\Contracts\ToolInterface;
use PhpClaw\Tools\ToolMutability;

/**
 * Approval gate that asks a classifier model about calls the static check lets through, and makes the inner gate ask a human about one rated dangerous.
 */
final class ClassifierApprovalGate implements ApprovalGateInterface
{
    private const VERDICT_DANGEROUS = 'dangerous';

    private const VERDICT_SAFE = 'safe';

    private const QUESTION = <<<'TEXT'
        A tool is about to run inside an AI agent. Decide whether this call is dangerous: it changes data,
        sends data to an outside system, or could harm the application or its users.
        Reply with JSON only, no other text: {"value": "%s" or "%s", "confidence": a number from 0 to 1}
        Tool: %s
        Input: %s
        TEXT;

    /**
     * Wrap the gate that makes the final decision.
     *
     * @param  ApprovalGateInterface  $inner  Gate that asks a human or pauses the run.
     * @param  ProviderInterface  $classifier  Model asked whether a call is dangerous.
     * @param  float  $threshold  Lowest confidence at which a "dangerous" answer sends the call to a human.
     */
    public function __construct(
        private readonly ApprovalGateInterface $inner,
        private readonly ProviderInterface $classifier,
        private readonly float $threshold,
    ) {}

    /**
     * Pass the call to the inner gate, marked as mutating when the classifier rates a call the static check lets through as dangerous.
     *
     * @param  string  $toolName  Tool about to execute.
     * @param  array<string, mixed>  $toolInput  Input the model supplied.
     * @param  ToolInterface|null  $tool  The resolved tool instance, or null if not found.
     * @return void Returning normally means the call may run.
     *
     * @throws HumanDeniedException When the inner gate denies the call.
     * @throws ApprovalPendingException When the inner gate pauses the run for a human decision.
     */
    public function check(string $toolName, array $toolInput, ?ToolInterface $tool = null): void
    {
        if ($tool === null || ToolMutability::isApprovalRequired($tool, $toolInput) || ! $this->isRatedDangerous($toolName, $toolInput)) {
            $this->inner->check($toolName, $toolInput, $tool);

            return;
        }

        $this->inner->check($toolName, $toolInput, self::markedMutating($tool));
    }

    /**
     * Whether the classifier rates the call dangerous at or above the threshold; an error or an unreadable reply counts as unsure.
     *
     * @param  string  $toolName  Tool about to execute.
     * @param  array<string, mixed>  $toolInput  Input the model supplied.
     * @return bool
     */
    private function isRatedDangerous(string $toolName, array $toolInput): bool
    {
        $question = sprintf(self::QUESTION, self::VERDICT_DANGEROUS, self::VERDICT_SAFE, $toolName, (string) json_encode($toolInput));

        try {
            $reply = $this->classifier->send([Message::user($question)]);
        } catch (PhpClawException) {
            return false;
        }

        $answer = json_decode((string) ($reply['text'] ?? ''), true);

        if (! is_array($answer) || ($answer['value'] ?? null) !== self::VERDICT_DANGEROUS) {
            return false;
        }

        $confidence = $answer['confidence'] ?? null;

        return (is_int($confidence) || is_float($confidence)) && $confidence >= $this->threshold;
    }

    /**
     * Return the same tool marked as mutating for this one call, so the inner gate asks a human or pauses the run.
     *
     * @param  ToolInterface  $tool  Tool the static check lets through.
     * @return ToolInterface
     */
    private static function markedMutating(ToolInterface $tool): ToolInterface
    {
        return new class($tool) implements MutatingToolInterface, ToolInterface
        {
            /**
             * Hold the tool the call targets.
             *
             * @param  ToolInterface  $tool  Tool the static check lets through.
             */
            public function __construct(private readonly ToolInterface $tool) {}

            /**
             * Name of the tool the call targets.
             *
             * @return string
             */
            public function name(): string
            {
                return $this->tool->name();
            }

            /**
             * Description of the tool the call targets.
             *
             * @return string
             */
            public function description(): string
            {
                return $this->tool->description();
            }

            /**
             * Input schema of the tool the call targets.
             *
             * @return array<string, mixed>
             */
            public function inputSchema(): array
            {
                return $this->tool->inputSchema();
            }

            /**
             * Run the tool the call targets.
             *
             * @param  array<string, mixed>  $input  Input the model supplied.
             * @return string
             */
            public function execute(array $input): string
            {
                return $this->tool->execute($input);
            }
        };
    }
}
