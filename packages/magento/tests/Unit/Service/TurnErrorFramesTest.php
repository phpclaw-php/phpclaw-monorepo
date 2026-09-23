<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Tests\Unit\Service;

use PhpClaw\Exceptions\GuardException;
use PhpClaw\Exceptions\MaxIterationsException;
use PhpClaw\Exceptions\ProviderException;
use PhpClaw\Magento\Exception\ConversationAccessDeniedException;
use PhpClaw\Magento\Service\TurnErrorFrames;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class TurnErrorFramesTest extends TestCase
{
    private object $subject;

    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->logged = [];

        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('error')->willReturnCallback(
            function (string $message, array $context = []): void {
                $this->logged[] = ['message' => $message, 'context' => $context];
            },
        );

        $this->subject = new class($logger)
        {
            use TurnErrorFrames;

            public function __construct(private LoggerInterface $logger) {}

            protected function getErrorLogger(): LoggerInterface
            {
                return $this->logger;
            }

            public function frameFor(\Throwable $e, string $context = 'turn failed'): array
            {
                return $this->errorFrame($e, $context);
            }
        };
    }

    public function test_access_denied_maps_to_a_403_permission_frame(): void
    {
        self::assertSame(
            ['error' => 'You do not have permission to access this conversation.', 'code' => 403],
            $this->subject->frameFor(new ConversationAccessDeniedException),
        );
    }

    public function test_guard_exception_maps_to_a_422_blocked_frame(): void
    {
        self::assertSame(
            ['error' => 'Blocked request.', 'code' => 422],
            $this->subject->frameFor(new GuardException('injection detected')),
        );
    }

    public function test_provider_exception_maps_to_a_502_frame(): void
    {
        self::assertSame(
            ['error' => 'AI provider error. Check your API key and try again.', 'code' => 502],
            $this->subject->frameFor(new ProviderException('HTTP 401 sk-secret-key')),
        );
    }

    public function test_max_iterations_maps_to_a_504_frame(): void
    {
        self::assertSame(
            ['error' => 'Could not complete. Try a simpler question.', 'code' => 504],
            $this->subject->frameFor(new MaxIterationsException('20 iterations')),
        );
    }

    public function test_mapped_exceptions_are_never_logged(): void
    {
        $this->subject->frameFor(new GuardException('injection detected'));
        $this->subject->frameFor(new ProviderException('HTTP 401'));
        $this->subject->frameFor(new MaxIterationsException('20'));
        $this->subject->frameFor(new ConversationAccessDeniedException);

        self::assertSame([], $this->logged, 'expected failures are user-facing, not operator alerts');
    }

    public function test_unmapped_exception_returns_a_generic_500_and_is_logged(): void
    {
        $e = new \RuntimeException('Table phpclaw_conversations does not exist');

        $frame = $this->subject->frameFor($e, 'run turn failed');

        self::assertSame(
            ['error' => 'An internal error occurred. Please try again.', 'code' => 500],
            $frame,
        );
        self::assertSame('run turn failed', $this->logged[0]['message']);
        self::assertSame($e, $this->logged[0]['context']['exception']);
    }

    public function test_the_generic_frame_never_leaks_the_underlying_message(): void
    {
        $frame = $this->subject->frameFor(
            new \RuntimeException('mysql://root:hunter2@db-primary/magento'),
            'run turn failed',
        );

        self::assertStringNotContainsString('hunter2', $frame['error']);
        self::assertStringNotContainsString('db-primary', $frame['error']);
    }
}
