<?php

declare(strict_types=1);

namespace PhpClaw\Drupal\Tests\Unit\Tools;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\Select;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Logger\RfcLogLevel;
use PhpClaw\Drupal\Tests\Unit\Fixtures\BootsDrupalContainer;
use PhpClaw\Drupal\Tools\LogTool;
use PhpClaw\Exceptions\ToolException;
use PhpClaw\Tools\Contracts\MutatingToolInterface;
use PHPUnit\Framework\TestCase;

final class LogToolTest extends TestCase
{
    use BootsDrupalContainer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootDrupalContainerWithUser();
    }

    private function buildDb(array $rows, ?int $total = null, array $typeCounts = [], array $severityCounts = []): Connection
    {
        $pageStmt = $this->createMock(StatementInterface::class);
        $pageStmt->method('fetchAssoc')->willReturnOnConsecutiveCalls(...array_merge($rows, [false]));

        $page = $this->createMock(Select::class);
        $page->method('fields')->willReturnSelf();
        $page->method('orderBy')->willReturnSelf();
        $page->method('range')->willReturnSelf();
        $page->method('condition')->willReturnSelf();
        $page->method('execute')->willReturn($pageStmt);

        $countStmt = $this->createMock(StatementInterface::class);
        $countStmt->method('fetchField')->willReturn($total ?? count($rows));

        $count = $this->createMock(Select::class);
        $count->method('condition')->willReturnSelf();
        $count->method('countQuery')->willReturnSelf();
        $count->method('execute')->willReturn($countStmt);

        $group = function (array $counts, string $column): Select {
            $groupRows = [];

            foreach ($counts as $value => $n) {
                $groupRows[] = [$column => $value, 'cnt' => $n];
            }

            $stmt = $this->createMock(StatementInterface::class);
            $stmt->method('fetchAssoc')->willReturnOnConsecutiveCalls(...array_merge($groupRows, [false]));

            $select = $this->createMock(Select::class);
            $select->method('fields')->willReturnSelf();
            $select->method('groupBy')->willReturnSelf();
            $select->method('addExpression')->willReturnSelf();
            $select->method('condition')->willReturnSelf();
            $select->method('execute')->willReturn($stmt);

            return $select;
        };

        $groupCalls = 0;
        $db = $this->createMock(Connection::class);
        $db->method('select')->willReturnCallback(
            static function (string $table, string $alias) use (&$groupCalls, $page, $count, $group, $typeCounts, $severityCounts): Select {
                if ($alias === 'w') {
                    return $page;
                }

                if ($alias === 'wc') {
                    return $count;
                }

                $groupCalls++;

                return $groupCalls === 1 ? $group($typeCounts, 'type') : $group($severityCounts, 'severity');
            }
        );

        return $db;
    }

    private function ask(LogTool $tool, array $args): array
    {
        return json_decode($tool->execute($args), true, 512, JSON_THROW_ON_ERROR);
    }

    private function moduleHandler(bool $installed): ModuleHandlerInterface
    {
        $handler = $this->createMock(ModuleHandlerInterface::class);
        $handler->method('moduleExists')->willReturn($installed);

        return $handler;
    }

    private function tool(array $rows, ?int $total = null): LogTool
    {
        return new LogTool($this->buildDb($rows, $total), $this->moduleHandler(true));
    }

    private function row(string $message = 'Something failed', string $variables = ''): array
    {
        return [[
            'wid' => 7,
            'type' => 'php',
            'message' => $message,
            'variables' => $variables,
            'severity' => RfcLogLevel::ERROR,
            'timestamp' => 1700000000,
        ]];
    }

    public function test_name_is_read_log(): void
    {
        self::assertSame('read_log', $this->tool([])->name());
    }

    public function test_description_says_bodies_are_omitted_by_default(): void
    {
        self::assertStringContainsString('omitted unless', $this->tool([])->description());
    }

    public function test_input_schema_rejects_unknown_properties(): void
    {
        $schema = $this->tool([])->inputSchema();

        self::assertSame(
            ['entries', 'level', 'include_messages', 'offset', 'schema'],
            array_keys($schema['properties']),
        );
        self::assertFalse($schema['additionalProperties']);
    }

    public function test_forbidden_when_the_caller_lacks_the_permission(): void
    {
        $this->bootDrupalContainerWithUser(2, allPermissions: false);

        $tool = new LogTool($this->buildDb([]), $this->moduleHandler(true));
        $decoded = $this->ask($tool, []);

        self::assertFalse($decoded['success']);
        self::assertSame('FORBIDDEN', $decoded['error']['code']);
        self::assertStringContainsString('use phpclaw chat', $decoded['error']['message']);
    }

    public function test_refuses_cleanly_when_dblog_is_absent(): void
    {
        $tool = new LogTool($this->buildDb([]), $this->moduleHandler(false));
        $decoded = $this->ask($tool, []);

        self::assertSame('MODULE_NOT_INSTALLED', $decoded['error']['code']);
        self::assertSame('dblog', $decoded['error']['module']);
    }

    public function test_the_default_answer_carries_no_message_body(): void
    {
        $decoded = $this->ask($this->tool($this->row('SECRET-IN-BODY')), []);

        self::assertTrue($decoded['success']);
        self::assertArrayNotHasKey('message', $decoded['data']['entries'][0]);
        self::assertFalse($decoded['meta']['messages_included']);
        self::assertNotContains('message', $decoded['meta']['columns_returned']);
        self::assertStringNotContainsString('SECRET-IN-BODY', json_encode($decoded, JSON_THROW_ON_ERROR));
    }

    public function test_the_default_answer_still_says_what_failed_and_how_often(): void
    {
        $db = $this->buildDb($this->row(), 3, ['php' => 2, 'cron' => 1], [RfcLogLevel::ERROR => 3]);
        $decoded = $this->ask(new LogTool($db, $this->moduleHandler(true)), []);

        self::assertSame(['cron' => 1, 'php' => 2], $decoded['data']['counts_by_type']);
        self::assertSame(3, $decoded['meta']['total']);
    }

    public function test_opting_in_returns_the_body_and_both_warnings(): void
    {
        $decoded = $this->ask($this->tool($this->row('Something failed')), ['include_messages' => true]);

        self::assertSame('Something failed', $decoded['data']['entries'][0]['message']);
        self::assertTrue($decoded['meta']['messages_included']);

        $codes = array_column($decoded['warnings'], 'code');
        self::assertContains('UNTRUSTED_CONTENT', $codes);
        self::assertContains('MESSAGE_SCRUBBING_IS_PARTIAL', $codes);
    }

    public function test_the_untrusted_warning_names_the_real_provenance(): void
    {
        $decoded = $this->ask($this->tool($this->row()), ['include_messages' => true]);
        $warning = $decoded['warnings'][array_search('UNTRUSTED_CONTENT', array_column($decoded['warnings'], 'code'), true)];

        self::assertStringContainsString('template it controls', $warning['message']);
        self::assertStringContainsString('anonymous visitor', $warning['message']);
    }

    public function test_the_scrubbing_warning_does_not_claim_a_guarantee(): void
    {
        $decoded = $this->ask($this->tool($this->row()), ['include_messages' => true]);
        $warning = $decoded['warnings'][array_search('MESSAGE_SCRUBBING_IS_PARTIAL', array_column($decoded['warnings'], 'code'), true)];

        self::assertStringContainsString('Nothing else was inspected', $warning['message']);
        self::assertStringContainsString('do not treat these bodies as scrubbed', $warning['message']);
    }

    public function test_url_credentials_are_removed_from_a_body(): void
    {
        $rows = $this->row('Connection to mysql://shopuser:hunter2@db.internal:3306/shop failed');
        $decoded = $this->ask($this->tool($rows), ['include_messages' => true]);

        self::assertStringContainsString('mysql://***withheld***@db.internal', $decoded['data']['entries'][0]['message']);
        self::assertStringNotContainsString('hunter2', $decoded['data']['entries'][0]['message']);
    }

    public function test_absolute_paths_are_removed_from_a_body(): void
    {
        $rows = $this->row('TypeError in /var/www/html/web/core/lib/Drupal.php on line 12');
        $decoded = $this->ask($this->tool($rows), ['include_messages' => true]);

        self::assertStringNotContainsString('/var/www/html', $decoded['data']['entries'][0]['message']);
        self::assertStringContainsString('Drupal.php', $decoded['data']['entries'][0]['message']);
    }

    public function test_a_credential_in_an_unknown_shape_is_not_caught(): void
    {
        $rows = $this->row('Upstream rejected. Sent Authorization: Bearer sk-live-abcdef123456');
        $decoded = $this->ask($this->tool($rows), ['include_messages' => true]);

        self::assertStringContainsString('sk-live-abcdef123456', $decoded['data']['entries'][0]['message']);
        self::assertContains('MESSAGE_SCRUBBING_IS_PARTIAL', array_column($decoded['warnings'], 'code'));
    }

    public function test_variables_are_interpolated_into_the_template(): void
    {
        $rows = $this->row('@uri', serialize(['@uri' => '/hostile-path']));
        $decoded = $this->ask($this->tool($rows), ['include_messages' => true]);

        self::assertSame('/hostile-path', $decoded['data']['entries'][0]['message']);
    }

    public function test_sensitive_columns_are_never_returned_and_are_named(): void
    {
        $decoded = $this->ask($this->tool($this->row()), ['include_messages' => true]);

        self::assertSame(
            ['uid', 'hostname', 'location', 'link', 'referer'],
            $decoded['meta']['sensitive_columns_withheld'],
        );

        foreach (['uid', 'hostname', 'location'] as $column) {
            self::assertArrayNotHasKey($column, $decoded['data']['entries'][0]);
        }
    }

    public function test_a_page_that_exceeds_the_output_cap_drops_rows_and_stays_valid_json(): void
    {
        $rows = [];

        for ($i = 0; $i < 40; $i++) {
            $rows[] = [
                'wid' => $i,
                'type' => 'php',
                'message' => str_repeat('x', 400),
                'variables' => '',
                'severity' => RfcLogLevel::ERROR,
                'timestamp' => 1700000000,
            ];
        }

        $raw = $this->tool($rows, 40)->execute(['include_messages' => true, 'entries' => 40]);
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        self::assertTrue($decoded['success']);
        self::assertGreaterThan(0, $decoded['meta']['rows_dropped']);
        self::assertContains('OUTPUT_TRUNCATED', array_column($decoded['warnings'], 'code'));
        self::assertStringNotContainsString('truncated at', $raw);
    }

    public function test_a_full_last_page_does_not_claim_more(): void
    {
        $decoded = $this->ask($this->tool($this->row(), 1), ['entries' => 1]);

        self::assertSame(1, $decoded['meta']['total']);
        self::assertFalse($decoded['meta']['has_more']);
        self::assertNull($decoded['meta']['next_offset']);
    }

    public function test_total_comes_from_a_count_not_from_the_page(): void
    {
        $decoded = $this->ask($this->tool($this->row(), 91), ['entries' => 1]);

        self::assertSame(91, $decoded['meta']['total']);
        self::assertSame(1, $decoded['meta']['count']);
        self::assertTrue($decoded['meta']['has_more']);
        self::assertSame(1, $decoded['meta']['next_offset']);
    }

    public function test_schema_mode_answers_without_touching_the_database(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->never())->method('select');

        $decoded = $this->ask(new LogTool($db, $this->moduleHandler(true)), ['schema' => true]);

        self::assertSame('schema', $decoded['meta']['mode']);
        self::assertSame(['message'], $decoded['data']['untrusted_columns']);
        self::assertSame('use phpclaw chat', $decoded['data']['drupal_permission']);
        self::assertSame('not inspected', $decoded['data']['message_scrubbing']['anything_else']);
    }

    public function test_unknown_argument_is_refused(): void
    {
        self::assertSame('UNKNOWN_ARGUMENT', $this->ask($this->tool([]), ['nope' => 1])['error']['code']);
    }

    public function test_invalid_level_is_refused(): void
    {
        $decoded = $this->ask($this->tool([]), ['level' => 'chatty']);

        self::assertSame('INVALID_ARGUMENT', $decoded['error']['code']);
        self::assertStringContainsString('emergency', $decoded['error']['message']);
    }

    public function test_entries_over_the_maximum_is_refused(): void
    {
        self::assertSame('INVALID_LIMIT', $this->ask($this->tool([]), ['entries' => 9999])['error']['code']);
    }

    public function test_include_messages_must_be_a_boolean(): void
    {
        $decoded = $this->ask($this->tool([]), ['include_messages' => 'yes']);

        self::assertSame('INVALID_ARGUMENT', $decoded['error']['code']);
    }

    public function test_db_exception_throws_tool_exception(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('select')->willThrowException(new \RuntimeException('db down'));

        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('failed to query watchdog');

        (new LogTool($db, $this->moduleHandler(true)))->execute([]);
    }

    public function test_the_tool_is_read_only(): void
    {
        self::assertArrayNotHasKey(MutatingToolInterface::class, (array) class_implements(LogTool::class));
        self::assertFalse(method_exists(LogTool::class, 'requiresApproval'));

        $db = $this->buildDb($this->row());
        $db->expects($this->never())->method('delete');
        $db->expects($this->never())->method('update');
        $db->expects($this->never())->method('insert');
        $db->expects($this->never())->method('merge');
        $db->expects($this->never())->method('truncate');

        $decoded = json_decode(
            (new LogTool($db, $this->moduleHandler(true)))->execute([]),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertTrue($decoded['success'], 'the read-only path must still answer');
    }
}
