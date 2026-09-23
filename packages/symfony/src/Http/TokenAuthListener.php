<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Http;

use PhpClaw\Symfony\Contracts\AssertsConversationAccess;
use PhpClaw\Symfony\Exceptions\ConversationAccessDeniedException;
use PhpClaw\Symfony\SymfonyIdentityResolver;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Requires an authenticated user on every phpClaw REST endpoint, so each conversation has an owner.
 */
final class TokenAuthListener implements EventSubscriberInterface
{
    private const CORE_ROUTES = [
        'phpclaw_send',
        'phpclaw_chat_stream',
    ];

    /**
     * Bind the API enabled flag and the services this listener authenticates with.
     *
     * @param  bool  $apiEnabled  Whether the REST API is enabled.
     * @param  SymfonyIdentityResolver|null  $identity  Resolves the acting user; null when the bundle is built without it.
     * @param  CacheItemPoolInterface|null  $cache  Cache pool backing the fixed-window rate limiter; null disables it.
     * @param  int  $rateLimit  Maximum requests per client IP per window.
     * @param  int  $rateWindow  Rate-limit window length in seconds.
     * @param  AssertsConversationAccess|null  $conversations  Resolves conversation ownership; null skips the ownership check.
     */
    public function __construct(
        private readonly bool $apiEnabled,
        private readonly ?SymfonyIdentityResolver $identity = null,
        private readonly ?CacheItemPoolInterface $cache = null,
        private readonly int $rateLimit = 60,
        private readonly int $rateWindow = 60,
        private readonly ?AssertsConversationAccess $conversations = null,
    ) {}

    /**
     * Return the subscribed kernel events.
     *
     * @return array<string, string|array{string, int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 8],
        ];
    }

    /**
     * Reject requests to phpClaw REST routes when there is no authenticated user.
     *
     * @param  RequestEvent  $event  The kernel request event.
     * @return void
     */
    public function onKernelRequest(RequestEvent $event): void
    {
        if (! $event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if (! in_array((string) $request->attributes->get('_route', ''), self::CORE_ROUTES, true)) {
            return;
        }

        if (! $this->apiEnabled) {
            $event->setResponse(new JsonResponse(
                ['error' => 'phpClaw API is disabled. Set PHPCLAW_API_ENABLED=true to enable.'],
                403,
            ));

            return;
        }

        if ($this->isRateLimited((string) $request->getClientIp())) {
            $event->setResponse(new JsonResponse(['error' => 'Too many requests.'], 429));

            return;
        }

        if (($this->identity?->actingUserId() ?? '') === '') {
            $event->setResponse(new JsonResponse(['error' => 'Unauthenticated.'], 401));

            return;
        }

        $conversationId = $this->conversationIdFrom($request);

        if ($conversationId === '' || $this->conversations === null) {
            return;
        }

        try {
            $this->conversations->assertAccess($conversationId);
        } catch (ConversationAccessDeniedException) {
            $event->setResponse(new JsonResponse(
                ['error' => 'You do not have permission to access this conversation.'],
                403,
            ));
        }
    }

    /**
     * Read the requested conversation id from the JSON body or the request parameters.
     *
     * @param  Request  $request  The incoming request.
     * @return string The conversation id, or an empty string when none was supplied.
     */
    private function conversationIdFrom(Request $request): string
    {
        $fromParams = trim((string) $request->request->get('conversation_id', ''));

        if ($fromParams !== '') {
            return $fromParams;
        }

        $body = json_decode((string) $request->getContent(), true);

        return is_array($body) ? trim((string) ($body['conversation_id'] ?? '')) : '';
    }

    /**
     * Fixed-window per-IP rate check; fails open when no cache is configured or on any cache error.
     *
     * @param  string  $clientIp  The requesting client IP.
     * @return bool True when the caller has exceeded the window budget and must be rejected.
     */
    private function isRateLimited(string $clientIp): bool
    {
        if ($this->cache === null || $this->rateLimit <= 0) {
            return false;
        }

        try {
            $window = intdiv(time(), max(1, $this->rateWindow));
            $key = 'phpclaw_rl_'.sha1($clientIp).'_'.$window;
            $item = $this->cache->getItem($key);
            $count = (int) $item->get();

            if ($count >= $this->rateLimit) {
                return true;
            }

            $item->set($count + 1);
            $item->expiresAfter($this->rateWindow);
            $this->cache->save($item);

            return false;
        } catch (\Throwable) {
            return false;
        }
    }
}
