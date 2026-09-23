<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PhpClaw\Laravel\Exceptions\ConversationAccessDeniedException;
use PhpClaw\Laravel\Memory\DatabaseConversationMemory;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses a request naming a conversation the acting user does not own, before any response is committed.
 */
final class EnforceConversationOwnership
{
    private const FORBIDDEN = 'You do not have permission to access this conversation.';

    /**
     * Handle an incoming request.
     *
     * @param  Request  $request
     * @param  Closure(Request): Response  $next
     * @return Response
     */
    public function handle(Request $request, Closure $next): Response
    {
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
