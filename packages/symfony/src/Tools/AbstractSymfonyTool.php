<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Tools;

use PhpClaw\Symfony\Console\ConsoleContext;
use PhpClaw\Symfony\SymfonyIdentityResolver;
use PhpClaw\Symfony\Tools\Concerns\HasToolExecutionContract;
use PhpClaw\Tools\Contracts\ToolInterface;
use PhpClaw\Tools\Contracts\ToolRoutingInterface;
use PhpClaw\Tools\ToolRoutingMetadata;

/**
 * Base class for Symfony-specific tools with a shared output-size cap and the execution contract.
 */
abstract class AbstractSymfonyTool implements ToolInterface, ToolRoutingInterface
{
    use HasToolExecutionContract;

    protected const MAX_OUTPUT_BYTES = 8192;

    /**
     * Bind the console context, identity resolver and chat-role flag every tool guards on.
     *
     * @param  ConsoleContext|null  $console  Marks an interactive console run; null is not console.
     * @param  SymfonyIdentityResolver|null  $identity  Names the acting user; null denies every caller.
     * @param  bool  $requireChatRole  True when the host app requires ROLE_PHPCLAW_CHAT.
     * @return void
     */
    public function __construct(
        protected readonly ?ConsoleContext $console = null,
        protected readonly ?SymfonyIdentityResolver $identity = null,
        protected readonly bool $requireChatRole = false,
    ) {}

    /**
     * Whether this tool may be offered to the model. Symfony evaluates the security context when the tool runs, so every tool stays eligible for routing.
     *
     * @return bool Always true; execution-time checks remain the authority.
     */
    public function isEligibleForRouting(): bool
    {
        return true;
    }

    /**
     * Return the routing signals the router ranks this tool by; subclasses override with their own domains, tags and intents.
     *
     * @return ToolRoutingMetadata Empty by default.
     */
    public function routingMetadata(): ToolRoutingMetadata
    {
        return ToolRoutingMetadata::empty();
    }

    /**
     * Truncate a string to the output cap with a marker when it overflows.
     *
     * @param  string  $text  The text to cap.
     * @return string
     */
    protected function truncate(string $text): string
    {
        if (strlen($text) > static::MAX_OUTPUT_BYTES) {
            return substr($text, 0, static::MAX_OUTPUT_BYTES)
                ."\n[... output truncated at ".(int) (static::MAX_OUTPUT_BYTES / 1024).' KB ...]';
        }

        return $text;
    }

    /**
     * Return the console context this tool guards on.
     *
     * @return ConsoleContext|null
     */
    protected function consoleContext(): ?ConsoleContext
    {
        return $this->console;
    }

    /**
     * Return the identity resolver this tool guards on.
     *
     * @return SymfonyIdentityResolver|null
     */
    protected function identity(): ?SymfonyIdentityResolver
    {
        return $this->identity;
    }

    /**
     * Whether the host app requires ROLE_PHPCLAW_CHAT rather than any authenticated user.
     *
     * @return bool
     */
    protected function requiresChatRole(): bool
    {
        return $this->requireChatRole;
    }
}
