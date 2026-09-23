<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use PhpClaw\Agent\AgentResponse;
use PhpClaw\Agent\Conversation;
use PhpClaw\Agent\ConversationTurn;
use PhpClaw\Claw;
use PhpClaw\ClawConfig;
use PhpClaw\Contracts\ClawInterface;
use PhpClaw\Laravel\Testing\ClawFake;
use PhpClaw\Memory\Contracts\MemoryInterface;

/**
 * Laravel Facade for the phpClaw AI agent engine.
 *
 * @method static AgentResponse send(string $message)
 * @method static AgentResponse stream(string $message, callable $onToken)
 * @method static Conversation conversation(string $id = '', array $metadata = [])
 * @method static ConversationTurn sendInConversation(Conversation $conv, string $message)
 * @method static ConversationTurn streamInConversation(Conversation $conversation, string $message, callable $onToken, ?callable $beforePersist = null)
 * @method static ?MemoryInterface memory()
 * @method static bool storeMessages()
 * @method static ClawConfig config()
 *
 * @see Claw
 */
final class PhpClaw extends Facade
{
    /**
     * Swap the agent engine for a recording fake, so tests never reach a provider.
     *
     * @param  array<int, string>  $responses  Response texts returned in order.
     * @return ClawFake
     */
    public static function fake(array $responses = []): ClawFake
    {
        $fake = new ClawFake($responses);

        self::swap($fake);

        $app = self::getFacadeApplication();

        if ($app !== null) {
            $app->instance(ClawInterface::class, $fake);
            $app->instance(Claw::class, $fake);
        }

        return $fake;
    }

    /**
     * Resolve the container binding the facade proxies to.
     *
     * @return string
     */
    protected static function getFacadeAccessor(): string
    {
        return Claw::class;
    }
}
