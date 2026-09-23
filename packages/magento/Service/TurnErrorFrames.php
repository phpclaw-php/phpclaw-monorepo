<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Service;

use PhpClaw\Exceptions\GuardException;
use PhpClaw\Exceptions\MaxIterationsException;
use PhpClaw\Exceptions\ProviderException;
use PhpClaw\Magento\Exception\ConversationAccessDeniedException;
use Psr\Log\LoggerInterface;

/**
 * Shared exception-to-SSE-frame mapping for streaming chat turns.
 */
trait TurnErrorFrames
{
    /**
     * Return the PSR-3 logger used to record unexpected failures.
     *
     * @return LoggerInterface
     */
    abstract protected function getErrorLogger(): LoggerInterface;

    /**
     * Exception-to-SSE-frame map.
     *
     * @return array<class-string<\Throwable>, array{error: string, code: int}>
     */
    private static function turnErrorMap(): array
    {
        return [
            ConversationAccessDeniedException::class => ['error' => 'You do not have permission to access this conversation.', 'code' => 403],
            GuardException::class => ['error' => 'Blocked request.', 'code' => 422],
            ProviderException::class => ['error' => 'AI provider error. Check your API key and try again.', 'code' => 502],
            MaxIterationsException::class => ['error' => 'Could not complete. Try a simpler question.', 'code' => 504],
        ];
    }

    /**
     * Map a turn exception to its SSE error frame, logging only unexpected failures.
     *
     * @param  \Throwable  $e  Exception thrown while running the agent turn.
     * @param  string  $logContext  Log message used when the failure is unexpected.
     * @return array{error: string, code: int} Error frame with a user-safe message and HTTP-style code.
     */
    private function errorFrame(\Throwable $e, string $logContext): array
    {
        foreach (self::turnErrorMap() as $class => $frame) {
            if ($e instanceof $class) {
                return $frame;
            }
        }

        $this->getErrorLogger()->error($logContext, ['exception' => $e]);

        return ['error' => 'An internal error occurred. Please try again.', 'code' => 500];
    }
}
