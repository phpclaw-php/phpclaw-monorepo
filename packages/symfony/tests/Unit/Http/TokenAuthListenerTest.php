<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Tests\Unit\Http;

use PhpClaw\Symfony\Contracts\AssertsConversationAccess;
use PhpClaw\Symfony\Exceptions\ConversationAccessDeniedException;
use PhpClaw\Symfony\Http\TokenAuthListener;
use PhpClaw\Symfony\Tests\Support\BuildsIdentity;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;

final class TokenAuthListenerTest extends TestCase
{
    use BuildsIdentity;

    private function makeEvent(Request $request, string $route = 'phpclaw_send', bool $main = true): RequestEvent
    {
        $kernel = $this->createMock(KernelInterface::class);
        $request->attributes->set('_route', $route);

        return new RequestEvent(
            $kernel,
            $request,
            $main ? HttpKernelInterface::MAIN_REQUEST : HttpKernelInterface::SUB_REQUEST,
        );
    }

    private function listener(string $userId, bool $enabled = true): TokenAuthListener
    {
        return new TokenAuthListener($enabled, $this->buildIdentity($userId));
    }

    public function test_a_non_core_route_is_not_gated(): void
    {
        $request = Request::create('/phpclaw/usecase/orders-summary');
        $event = $this->makeEvent($request, 'app_usecase_orders');

        $this->listener('secret')->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    public function test_sub_request_is_skipped(): void
    {
        $request = Request::create('/phpclaw/send');
        $event = $this->makeEvent($request, main: false);

        $this->listener('')->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $request = Request::create('/phpclaw/send');
        $event = $this->makeEvent($request);

        $this->listener('')->onKernelRequest($event);

        self::assertInstanceOf(JsonResponse::class, $event->getResponse());
        self::assertSame(401, $event->getResponse()->getStatusCode());
    }

    public function test_listener_without_any_identity_service_returns_401(): void
    {
        $request = Request::create('/phpclaw/send');
        $event = $this->makeEvent($request);

        (new TokenAuthListener(true))->onKernelRequest($event);

        self::assertSame(401, $event->getResponse()?->getStatusCode());
    }

    public function test_api_disabled_returns_403(): void
    {
        $request = Request::create('/phpclaw/send');
        $event = $this->makeEvent($request);

        $this->listener('alice@example.com', false)->onKernelRequest($event);

        self::assertSame(403, $event->getResponse()->getStatusCode());
    }

    public function test_authenticated_user_passes_through(): void
    {
        $request = Request::create('/phpclaw/send');
        $event = $this->makeEvent($request);

        $this->listener('alice@example.com')->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    public function test_stream_route_authenticated_user_passes_through(): void
    {
        $request = Request::create('/phpclaw/chat/stream');
        $event = $this->makeEvent($request, 'phpclaw_chat_stream');

        $this->listener('alice@example.com')->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    public function test_stream_route_unauthenticated_request_returns_401(): void
    {
        $request = Request::create('/phpclaw/chat/stream');
        $event = $this->makeEvent($request, 'phpclaw_chat_stream');

        $this->listener('')->onKernelRequest($event);

        self::assertSame(401, $event->getResponse()?->getStatusCode());
    }

    public function test_a_custom_route_path_under_a_core_route_name_is_still_gated(): void
    {
        $request = Request::create('/any-path-here');
        $event = $this->makeEvent($request, 'phpclaw_send');

        $this->listener('')->onKernelRequest($event);

        self::assertSame(401, $event->getResponse()?->getStatusCode(), 'Gating is by route name, independent of the request path.');
    }

    public function test_foreign_conversation_id_returns_403_before_the_controller_runs(): void
    {
        $memory = $this->createMock(AssertsConversationAccess::class);
        $memory->expects(self::once())
            ->method('assertAccess')
            ->with('01HZY8ABCDEFGHJKMNPQRSTVWX')
            ->willThrowException(new ConversationAccessDeniedException);

        $request = Request::create(
            '/phpclaw/send',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode(['message' => 'hi', 'conversation_id' => '01HZY8ABCDEFGHJKMNPQRSTVWX']),
        );
        $event = $this->makeEvent($request);

        $listener = new TokenAuthListener(
            true,
            $this->buildIdentity('alice@example.com'),
            null,
            60,
            60,
            $memory,
        );
        $listener->onKernelRequest($event);

        self::assertNotNull($event->getResponse());
        self::assertSame(403, $event->getResponse()->getStatusCode());
    }

    public function test_own_conversation_id_passes_through(): void
    {
        $memory = $this->createMock(AssertsConversationAccess::class);
        $memory->expects(self::once())->method('assertAccess');

        $request = Request::create(
            '/phpclaw/send',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode(['message' => 'hi', 'conversation_id' => '01HZY8ABCDEFGHJKMNPQRSTVWX']),
        );
        $event = $this->makeEvent($request);

        $listener = new TokenAuthListener(
            true,
            $this->buildIdentity('alice@example.com'),
            null,
            60,
            60,
            $memory,
        );
        $listener->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    public function test_a_bearer_token_alone_does_not_grant_access(): void
    {
        $request = Request::create('/phpclaw/send');
        $request->headers->set('Authorization', 'Bearer any-token-at-all');
        $event = $this->makeEvent($request);

        $this->listener('')->onKernelRequest($event);

        self::assertSame(401, $event->getResponse()?->getStatusCode());
    }

    public function test_rate_limit_returns_429_after_window_budget_exceeded(): void
    {
        $listener = new TokenAuthListener(true, $this->buildIdentity('alice@example.com'), new ArrayAdapter, 2, 60);

        $last = null;
        for ($i = 0; $i < 3; $i++) {
            $request = Request::create('/phpclaw/send', 'POST', [], [], [], ['REMOTE_ADDR' => '203.0.113.9']);
            $request->headers->set('Authorization', 'Bearer mytoken');
            $event = $this->makeEvent($request);
            $listener->onKernelRequest($event);
            $last = $event->getResponse();
        }

        self::assertInstanceOf(JsonResponse::class, $last);
        self::assertSame(429, $last->getStatusCode());
    }

    public function test_rate_limit_disabled_without_cache(): void
    {
        $listener = $this->listener('mytoken');

        for ($i = 0; $i < 5; $i++) {
            $request = Request::create('/phpclaw/send', 'POST', [], [], [], ['REMOTE_ADDR' => '203.0.113.10']);
            $request->headers->set('Authorization', 'Bearer mytoken');
            $event = $this->makeEvent($request);
            $listener->onKernelRequest($event);
            self::assertNull($event->getResponse());
        }
    }

    public function test_401_response_is_json(): void
    {
        $event = $this->makeEvent(Request::create('/phpclaw/send'));

        $this->listener('')->onKernelRequest($event);

        $response = $event->getResponse();

        self::assertNotNull($response);
        self::assertSame(401, $response->getStatusCode());
        self::assertSame('application/json', $response->headers->get('Content-Type'));
        self::assertArrayHasKey('error', json_decode((string) $response->getContent(), true));
    }

    public function test_403_response_is_json(): void
    {
        $event = $this->makeEvent(Request::create('/phpclaw/send'));

        $this->listener('alice@example.com', false)->onKernelRequest($event);

        $response = $event->getResponse();

        self::assertNotNull($response);
        self::assertSame(403, $response->getStatusCode());
        self::assertSame('application/json', $response->headers->get('Content-Type'));
        self::assertArrayHasKey('error', json_decode((string) $response->getContent(), true));
    }
}
