<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Guards;

use PhpClaw\AutoDiscovery\AttributeScanner;
use PhpClaw\Exceptions\GuardException;
use PhpClaw\Guards\Contracts\RawInputGuardInterface;
use PhpClaw\Guards\DestructiveSqlGuard;
use PhpClaw\Guards\GuardRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DestructiveSqlGuardTest extends TestCase
{
    private DestructiveSqlGuard $guard;

    protected function setUp(): void
    {
        $this->guard = new DestructiveSqlGuard;
    }

    public static function destructiveInstructions(): array
    {
        return [
            'drop table' => ['drop table users'],
            'drop database' => ['drop database shop'],
            'drop schema' => ['drop schema public'],
            'drop index' => ['drop index idx_users_email'],
            'truncate table' => ['truncate table orders'],
            'delete from' => ['delete from users where id = 1'],
            'alter table' => ['alter table users add column x int'],
        ];
    }

    #[DataProvider('destructiveInstructions')]
    public function test_blocks_each_destructive_instruction(string $message): void
    {
        $this->expectException(GuardException::class);
        $this->guard->scan($message);
    }

    public function test_blocks_uppercase(): void
    {
        $this->expectException(GuardException::class);
        $this->guard->scan('DROP TABLE users');
    }

    public function test_blocks_mixed_case(): void
    {
        $this->expectException(GuardException::class);
        $this->guard->scan('Drop Table Users');
    }

    public function test_blocks_with_polite_prefix(): void
    {
        $this->expectException(GuardException::class);
        $this->guard->scan('please drop table users');
    }

    public function test_blocks_when_message_leads_with_the_phrase_and_ends_in_a_question_mark(): void
    {
        $this->expectException(GuardException::class);
        $this->guard->scan('drop table users?');
    }

    public function test_blocks_fullwidth_homoglyph_form(): void
    {
        $this->expectException(GuardException::class);
        $this->guard->scan('ｄｒｏｐ ｔａｂｌｅ users');
    }

    public static function legitimateQuestions(): array
    {
        return [
            'how' => ['how do I drop a table in MySQL?'],
            'what' => ['what does drop table do?'],
            'why' => ['why is drop table dangerous'],
            'can' => ['can you explain delete from semantics'],
            'is' => ['is alter table safe on a live database'],
            'explain' => ['explain why DROP TABLE is dangerous'],
            'show' => ['show me the drop table syntax'],
        ];
    }

    #[DataProvider('legitimateQuestions')]
    public function test_allows_questions_about_sql(string $message): void
    {
        $this->expectNotToPerformAssertions();
        $this->guard->scan($message);
    }

    public static function ordinaryMessages(): array
    {
        return [
            'greeting' => ['hello'],
            'listing' => ['list my users'],
            'count' => ['how many orders today?'],
            'select' => ['SELECT * FROM users'],
            'empty' => [''],
            'truncate alone' => ['truncate the description text'],
            'drop alone' => ['drop me a note when the build finishes'],
        ];
    }

    #[DataProvider('ordinaryMessages')]
    public function test_allows_ordinary_messages(string $message): void
    {
        $this->expectNotToPerformAssertions();
        $this->guard->scan($message);
    }

    public function test_exception_names_the_matched_pattern_and_attributes_the_guard(): void
    {
        try {
            $this->guard->scan('drop table users');
            self::fail('Expected GuardException.');
        } catch (GuardException $e) {
            self::assertStringContainsString('drop table', $e->getMessage());
            self::assertSame(DestructiveSqlGuard::class, $e->guardClass());
        }
    }

    public function test_is_a_raw_input_guard_so_conversation_context_cannot_trip_it(): void
    {
        self::assertInstanceOf(RawInputGuardInterface::class, $this->guard);
    }

    public function test_registry_scan_passes_only_the_raw_user_message_to_this_guard(): void
    {
        GuardRegistry::reset();
        GuardRegistry::register($this->guard, 6);

        $this->expectNotToPerformAssertions();
        GuardRegistry::scan('earlier turn mentioned drop table users', 'what is the weather?');
    }

    public function test_registry_scan_blocks_a_destructive_raw_message(): void
    {
        GuardRegistry::reset();
        GuardRegistry::register($this->guard, 6);

        $this->expectException(GuardException::class);
        GuardRegistry::scan('augmented context', 'drop table users');
    }

    public function test_guard_also_reaches_tool_call_arguments(): void
    {
        GuardRegistry::reset();
        GuardRegistry::register($this->guard, 6);

        $this->expectException(GuardException::class);
        GuardRegistry::scanToolArguments('drop table users');
    }

    public function test_attribute_discovery_marks_this_guard_enabled_by_default(): void
    {
        $entry = AttributeScanner::scan()['guards'][DestructiveSqlGuard::class] ?? null;

        self::assertIsArray($entry);
        self::assertTrue($entry['enabledByDefault']);
        self::assertSame(6, $entry['priority']);
        self::assertSame('destructive_sql', $entry['name']);
    }

    public function test_runs_before_the_cloud_scan_guard_default_priority(): void
    {
        $entry = AttributeScanner::scan()['guards'][DestructiveSqlGuard::class] ?? null;

        self::assertIsArray($entry);
        self::assertLessThan(50, $entry['priority']);
    }
}
