<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PhpClaw\Laravel\Exceptions\ConversationAccessDeniedException;
use PhpClaw\Laravel\Memory\DatabaseConversationMemory;
use PhpClaw\Laravel\Memory\DatabaseRouterMemory;
use PhpClaw\Memory\Contracts\MemoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses every request when the bound memory cannot keep conversations per user, and a request naming a conversation the acting user does not own, before any response is committed.
 */
final class EnforceConversationOwnership
{
    private const FORBIDDEN = 'You do not have permission to access this conversation.';

    private const UNSCOPED_MEMORY = 'The phpClaw REST API is off: the configured memory driver cannot keep each user\'s conversations private. Set PHPCLAW_MEMORY_DRIVER to database or eloquent, or set PHPCLAW_API_ENABLED=false.';

    /**
     * Bind the memory the agent stores conversations in and the logger that records a refusal.
     *
     * @param  MemoryInterface  $memory  Memory bound for the agent.
     * @param  LoggerInterface  $logger  Application logger.
     * @return void
     */
    public function __construct(
        private readonly MemoryInterface $memory,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  Request  $request
     * @param  Closure(Request): Response  $next
     * @return Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->memory instanceof DatabaseRouterMemory && ! $this->memory instanceof DatabaseConversationMemory) {
            $this->logger->warning('phpClaw REST API refused a request: memory '.$this->memory::class.' cannot enforce conversation ownership.');

            return new JsonResponse(['error' => self::UNSCOPED_MEMORY], 503);
        }

        $conversationId = trim((string) $request->input('conversation_id', ''));

        if ($conversationId === '') {
            return $next($request);
        }

        try {
            DatabaseConversationMemory::assertAccess($conversationId);
        } catch (ConversationAccessDeniedException) {
            return new JsonResponse(['error' => self::FORBIDDEN], 403);
        }

        return $next($request);
    }
}
