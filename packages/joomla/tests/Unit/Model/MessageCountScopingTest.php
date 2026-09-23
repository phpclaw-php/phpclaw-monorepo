<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Tests\Unit\Model;

use PHPUnit\Framework\TestCase;

final class MessageCountScopingTest extends TestCase
{
    private const MODELS = ['AnalyticsModel', 'ChatModel'];

    public function test_every_count_over_the_message_table_joins_the_conversation_table(): void
    {
        foreach (self::MODELS as $model) {
            $source = $this->source($model);

            preg_match_all('/COUNT\(\*\)[^;]{0,400}?;/s', $source, $matches);

            foreach ($matches[0] as $statement) {
                if (! $this->touchesMessageTable($statement)) {
                    continue;
                }

                self::assertMatchesRegularExpression(
                    '/INNER JOIN|innerJoin/',
                    $statement,
                    $model.' counts the message table without joining the conversation table.',
                );
            }
        }
    }

    public function test_the_conversation_count_is_never_joined_through_messages(): void
    {
        foreach (self::MODELS as $model) {
            $source = $this->source($model);

            preg_match_all('/COUNT\(\*\)[^;]{0,400}?;/s', $source, $matches);

            foreach ($matches[0] as $statement) {
                if ($this->touchesMessageTable($statement)) {
                    continue;
                }

                self::assertDoesNotMatchRegularExpression(
                    '/INNER JOIN|innerJoin/',
                    $statement,
                    $model.' counts conversations through a join, which double-counts.',
                );
            }
        }
    }

    public function test_both_models_still_scope_by_the_ownership_column(): void
    {
        foreach (self::MODELS as $model) {
            self::assertStringContainsString(
                "quoteName('user_id')",
                $this->source($model),
                $model.' must still scope by the ownership column.',
            );
        }
    }

    private function source(string $model): string
    {
        return (string) file_get_contents(
            dirname(__DIR__, 3).'/component/src/Model/'.$model.'.php',
        );
    }

    private function touchesMessageTable(string $statement): bool
    {
        return stripos($statement, 'msgTable') !== false || str_contains($statement, 'MESSAGES_TABLE');
    }
}
