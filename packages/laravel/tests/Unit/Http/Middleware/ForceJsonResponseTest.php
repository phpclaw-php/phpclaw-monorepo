<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Tests\Unit\Http\Middleware;

use Illuminate\Http\Request;
use PhpClaw\Laravel\Http\Middleware\ForceJsonResponse;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

final class ForceJsonResponseTest extends TestCase
{
    public function test_it_overwrites_a_non_json_accept_header(): void
    {
        $request = Request::create('/phpclaw/send', 'POST');
        $request->headers->set('Accept', 'text/html');

        (new ForceJsonResponse)->handle($request, static fn (Request $r): Response => new Response);

        self::assertSame('application/json', $request->headers->get('Accept'));
    }

    public function test_it_sets_the_accept_header_when_the_client_sends_none(): void
    {
        $request = Request::create('/phpclaw/send', 'POST');
        $request->headers->remove('Accept');

        (new ForceJsonResponse)->handle($request, static fn (Request $r): Response => new Response);

        self::assertSame('application/json', $request->headers->get('Accept'));
    }

    public function test_it_returns_the_downstream_response_untouched(): void
    {
        $downstream = new Response('body', 201);

        $result = (new ForceJsonResponse)->handle(
            Request::create('/phpclaw/send', 'POST'),
            static fn (Request $r): Response => $downstream,
        );

        self::assertSame($downstream, $result);
    }

    public function test_the_header_is_rewritten_before_the_pipeline_continues(): void
    {
        $seen = null;

        (new ForceJsonResponse)->handle(
            Request::create('/phpclaw/send', 'POST', server: ['HTTP_ACCEPT' => 'text/html']),
            static function (Request $r) use (&$seen): Response {
                $seen = $r->headers->get('Accept');

                return new Response;
            },
        );

        self::assertSame('application/json', $seen);
    }
}
