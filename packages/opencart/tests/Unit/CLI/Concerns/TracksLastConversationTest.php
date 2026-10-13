<?php

declare(strict_types=1);

namespace PhpClaw\OpenCart\Tests\Unit\CLI\Concerns;

use PhpClaw\Agent\Conversation;
use PhpClaw\Contracts\ClawInterface as PhpClawInterface;
use PhpClaw\OpenCart\CLI\Concerns\TracksLastConversation;
use PhpClaw\OpenCart\Exceptions\ConversationAccessDeniedException;
use PHPUnit\Framework\TestCase;

final class TracksLastConversationTest extends TestCase
{
    private function makeHost(string $dir = ''): object
    {
        return new class($dir)
        {
            use TracksLastConversation;

            public function __construct(private readonly string $storageDirectory = '') {}

            public function callOpen(callable $notice, PhpClawInterface $engine, string $convId, bool $continue): Conversation
            {
                return $this->openConversation($notice, $engine, $convId, $continue);
            }

            public function callRemember(string $id): void
            {
                $this->rememberConversation($id);
            }

            public function callLastId(): string
            {
                return $this->lastConversationId();
            }
        };
    }

    private function makeEngine(string $returnedId = '', bool $accessDenied = false): PhpClawInterface
    {
        $engine = $this->createMock(PhpClawInterface::class);
        $engine->method('conversation')->willReturnCallback(
            function (?string $id = null) use ($returnedId, $accessDenied): Conversation {
                if ($accessDenied && $id !== null && $id !== '') {
                    throw new ConversationAccessDeniedException('denied');
                }

                $convId = $returnedId !== '' ? $returnedId : ($id ?? '01FRESHCONVID00GHJKMNPQRS0');

                return new Conversation(id: $convId, history: [], createdAt: new \DateTimeImmutable);
            },
        );

        return $engine;
    }

    public function test_remember_and_retrieve_a_conversation_id(): void
    {
        $dir = sys_get_temp_dir().'/phpclaw-test-'.uniqid();
        $host = $this->makeHost($dir);

        $host->callRemember('01ABCDEFGHJKMNPQRSTVWXYZ00');

        self::assertSame('01ABCDEFGHJKMNPQRSTVWXYZ00', $host->callLastId());
    }

    public function test_last_id_returns_empty_when_no_file_exists(): void
    {
        $dir = sys_get_temp_dir().'/phpclaw-test-missing-'.uniqid();
        $host = $this->makeHost($dir);

        self::assertSame('', $host->callLastId());
    }

    public function test_last_id_returns_empty_when_the_file_holds_invalid_content(): void
    {
        $dir = sys_get_temp_dir().'/phpclaw-test-invalid-'.uniqid();
        @mkdir($dir.'/phpclaw', 0755, true);
        file_put_contents($dir.'/phpclaw/.last-conversation', "../../etc/passwd\n");

        $host = $this->makeHost($dir);

        self::assertSame('', $host->callLastId());
    }

    public function test_remember_is_a_no_op_when_no_storage_dir(): void
    {
        $host = $this->makeHost('');

        $host->callRemember('01ABCDEFGHJKMNPQRSTVWXYZ00');

        self::assertSame('', $host->callLastId());
    }

    public function test_open_returns_a_new_conversation_when_no_id_and_not_continue(): void
    {
        $host = $this->makeHost();
        $notices = [];
        $engine = $this->makeEngine();

        $conv = $host->callOpen(function (string $m) use (&$notices): void {
            $notices[] = $m;
        }, $engine, '', false);

        self::assertInstanceOf(Conversation::class, $conv);
        self::assertSame([], $notices);
    }

    public function test_open_with_continue_and_no_saved_id_emits_the_fallback_notice(): void
    {
        $host = $this->makeHost(sys_get_temp_dir().'/phpclaw-test-empty-'.uniqid());
        $notices = [];

        $conv = $host->callOpen(function (string $m) use (&$notices): void {
            $notices[] = $m;
        }, $this->makeEngine(), '', true);

        self::assertInstanceOf(Conversation::class, $conv);
        self::assertSame(['No previous conversation to continue; starting a new one.'], $notices);
    }

    public function test_open_with_continue_uses_the_saved_id(): void
    {
        $dir = sys_get_temp_dir().'/phpclaw-test-cont-'.uniqid();
        $host = $this->makeHost($dir);
        $host->callRemember('01SAVEDIDGHJKMNPQRSTVWXYZ0');
        $captured = null;
        $engine = $this->createMock(PhpClawInterface::class);
        $engine->method('conversation')->willReturnCallback(
            function (?string $id = null) use (&$captured): Conversation {
                $captured = $id;

                return Conversation::start();
            },
        );

        $host->callOpen(static function (string $m): void {}, $engine, '', true);

        self::assertSame('01SAVEDIDGHJKMNPQRSTVWXYZ0', $captured);
    }

    public function test_open_with_unknown_id_emits_the_not_found_notice(): void
    {
        $host = $this->makeHost();
        $notices = [];
        $engine = $this->createMock(PhpClawInterface::class);
        $engine->method('conversation')->willReturnCallback(
            function (?string $id): Conversation {
                return Conversation::start();
            },
        );

        $conv = $host->callOpen(function (string $m) use (&$notices): void {
            $notices[] = $m;
        }, $engine, '01UNKNOWNIDHJKMNPQRSTVWXYZ', false);

        self::assertCount(1, $notices);
        self::assertStringContainsString('01UNKNOWNIDHJKMNPQRSTVWXYZ', $notices[0]);
        self::assertStringContainsString('started', $notices[0]);
    }

    public function test_open_with_access_denied_emits_the_not_available_notice(): void
    {
        $host = $this->makeHost();
        $notices = [];

        $conv = $host->callOpen(function (string $m) use (&$notices): void {
            $notices[] = $m;
        }, $this->makeEngine(accessDenied: true), '01DENIEDHHJKMNPQRSTVWXYZ0', false);

        self::assertInstanceOf(Conversation::class, $conv);
        self::assertCount(1, $notices);
        self::assertStringContainsString('is not available to the console', $notices[0]);
        self::assertStringNotContainsString('denied', $notices[0]);
    }
}
