<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PhpClaw\Laravel\LaravelIdentityResolver;
use Symfony\Component\HttpFoundation\Response;

/**
 * Requires an authenticated user on every phpClaw REST endpoint, so each conversation has an owner.
 */
final class AuthenticatePhpClawApi
{
    /**
     * Handle an incoming request.
     *
     * @param  Request  $request
     * @param  Closure(Request): Response  $next
     * @return Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (LaravelIdentityResolver::actingUserId() === '') {
            return new JsonResponse(['error' => 'Unauthenticated.'], 401);
        }

        return $next($request);
    }
}
