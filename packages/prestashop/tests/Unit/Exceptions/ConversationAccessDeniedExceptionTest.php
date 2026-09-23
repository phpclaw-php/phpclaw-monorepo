<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Tests\Unit\Exceptions;

use PhpClaw\Exceptions\PhpClawException;
use PhpClaw\PrestaShop\Exceptions\ConversationAccessDeniedException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConversationAccessDeniedException::class)]
final class ConversationAccessDeniedExceptionTest extends TestCase
{
    public function test_it_is_a_phpclaw_exception(): void
    {
        self::assertInstanceOf(PhpClawException::class, new ConversationAccessDeniedException);
    }

    public function test_message_never_reveals_whether_the_conversation_exists(): void
    {
        self::assertSame('This conversation is unavailable.', (new ConversationAccessDeniedException)->getMessage());
    }
}
