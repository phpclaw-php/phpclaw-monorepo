<?php

declare(strict_types=1);

use Drupal\Core\Access\CsrfTokenGenerator;
use PhpClaw\Agent\AgentResponse;
use PhpClaw\Agent\Conversation;
use PhpClaw\Agent\ConversationTurn;
use PhpClaw\Contracts\ClawInterface;
use PhpClaw\Drupal\Controller\Admin\PhpClawChatStreamController;
use PhpClaw\Exceptions\GuardException;
use PhpClaw\Exceptions\ProviderException;
use PhpClaw\Memory\Contracts\MemoryInterface;
use Symfony\Component\HttpFoundation\Request;

require dirname(__DIR__, 4).'/vendor/autoload.php';

$secret = (string) ($argv[1] ?? 'SECRET');
$kind = (string) ($argv[2] ?? 'unexpected');

$exception = match ($kind) {
    'guard' => new GuardException($secret),
    'provider' => new ProviderException($secret),
    default => new RuntimeException($secret),
};

$csrf = new class extends CsrfTokenGenerator
{
    public function __construct() {}

    public function validate($token, $value = ''): bool
    {
        return true;
    }
};

$agent = new class($exception) implements ClawInterface
{
    public function __construct(private readonly Throwable $exception) {}

    public function send(string $message): AgentResponse
    {
        throw $this->exception;
    }

    public function stream(string $message, callable $onToken): AgentResponse
    {
        throw $this->exception;
    }

    public function conversation(string $id = '', array $metadata = []): Conversation
    {
        return new Conversation('probe-conversation', [], new DateTimeImmutable);
    }

    public function sendInConversation(Conversation $conversation, string $message): ConversationTurn
    {
        throw $this->exception;
    }

    public function streamInConversation(
        Conversation $conversation,
        string $message,
        callable $onToken,
        ?callable $onPayload = null,
    ): ConversationTurn {
        throw $this->exception;
    }

    public function memory(): ?MemoryInterface
    {
        return null;
    }

    public function storeMessages(): bool
    {
        return false;
    }
};

$request = Request::create('/stream', 'POST', [], [], [], [], json_encode(['message' => 'probe']));
$response = (new PhpClawChatStreamController($agent, null, $csrf))->stream($request);
($response->getCallback())();
