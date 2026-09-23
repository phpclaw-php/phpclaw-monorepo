<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Forces JSON responses on the phpClaw REST routes regardless of the client's Accept header.
 */
final class ForceJsonResponse
{
    /**
     * Force the Accept header to JSON, then pass the request down the pipeline.
     *
     * @param  Request  $request  The incoming HTTP request.
     * @param  Closure  $next  The next middleware in the pipeline.
     * @return Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
