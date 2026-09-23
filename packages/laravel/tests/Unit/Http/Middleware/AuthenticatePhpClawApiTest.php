<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Tests\Unit\Http\Middleware;

use Illuminate\Auth\GenericUser;
use Illuminate\Http\Request;
use Orchestra\Testbench\TestCase;
use PhpClaw\Laravel\Http\Middleware\AuthenticatePhpClawApi;
use PhpClaw\Laravel\PhpClawServiceProvider;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticatePhpClawApiTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PhpClawServiceProvider::class];
    }

    public function test_it_rejects_an_unauthenticated_request_with_401_json(): void
    {
        $reached = false;

        $response = (new AuthenticatePhpClawApi)->handle(
            Request::create('/phpclaw/send', 'POST'),
            static function (Request $r) use (&$reached): Response {
                $reached = true;

                return new Response;
            },
        );

        self::assertFalse($reached, 'the pipeline must stop before the controller');
        self::assertSame(401, $response->getStatusCode());
        self::assertSame(['error' => 'Unauthenticated.'], json_decode((string) $response->getContent(), true));
    }

    public function test_it_passes_an_authenticated_request_down_the_pipeline(): void
    {
        $this->actingAs(new GenericUser(['id' => 7]));

        $downstream = new Response('ok', 200);

        $result = (new AuthenticatePhpClawApi)->handle(
            Request::create('/phpclaw/send', 'POST'),
            static fn (Request $r): Response => $downstream,
        );

        self::assertSame($downstream, $result);
    }

    public function test_a_user_with_a_zero_id_is_no_longer_mistaken_for_a_guest(): void
    {
        $this->actingAs(new GenericUser(['id' => 0]));

        $downstream = new Response('ok', 200);

        $result = (new AuthenticatePhpClawApi)->handle(
            Request::create('/phpclaw/send', 'POST'),
            static fn (Request $r): Response => $downstream,
        );

        self::assertSame($downstream, $result);
    }

    public function test_it_passes_a_uuid_keyed_user_down_the_pipeline(): void
    {
        $this->actingAs(new GenericUser(['id' => '9f8c1e2a-4b6d-4f10-9c3e-7a51b2d8e4f7']));

        $downstream = new Response('ok', 200);

        $result = (new AuthenticatePhpClawApi)->handle(
            Request::create('/phpclaw/send', 'POST'),
            static fn (Request $r): Response => $downstream,
        );

        self::assertSame($downstream, $result);
    }
}
