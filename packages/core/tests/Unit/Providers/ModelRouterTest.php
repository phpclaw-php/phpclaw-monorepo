<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Providers;

use PhpClaw\Exceptions\StructuredOutputException;
use PhpClaw\Providers\Contracts\ProviderInterface;
use PhpClaw\Providers\ModelRouter;
use PHPUnit\Framework\TestCase;

final class ModelRouterTest extends TestCase
{
    private function classifier(string $reply): ProviderInterface
    {
        return new class($reply) implements ProviderInterface
        {
            public array $questions = [];

            public function __construct(private readonly string $reply) {}

            public function send(array $messages, array $tools = []): array
            {
                $this->questions[] = $messages;

                return ['type' => 'text', 'text' => $this->reply];
            }

            public function stream(array $messages, callable $onToken): string
            {
                return '';
            }

            public function name(): string
            {
                return 'stub';
            }

            public function model(): string
            {
                return 'stub-classifier';
            }
        };
    }

    public function test_it_resolves_the_provider_name_mapped_to_the_classified_difficulty(): void
    {
        $router = new ModelRouter($this->classifier('{"value": "hard"}'), ['easy' => 'groq', 'hard' => 'anthropic']);

        $this->assertSame('anthropic', $router->resolve('Plan a three-step data migration.'));
    }

    public function test_the_question_carries_the_message_and_the_allowed_difficulties(): void
    {
        $classifier = $this->classifier('{"value": "easy"}');

        (new ModelRouter($classifier, ['easy' => 'groq', 'hard' => 'anthropic']))->resolve('What time is it?');

        $question = $classifier->questions[0][0]->content;
        $this->assertStringContainsString('What time is it?', $question);
        $this->assertStringContainsString('"easy", "hard"', $question);
    }

    public function test_it_throws_when_the_classified_difficulty_has_no_mapped_provider(): void
    {
        $router = new ModelRouter($this->classifier('{"value": "medium"}'), ['easy' => 'groq', 'hard' => 'anthropic']);

        $this->expectException(StructuredOutputException::class);

        $router->resolve('Summarise this order.');
    }

    public function test_it_throws_when_the_answer_is_not_json(): void
    {
        $router = new ModelRouter($this->classifier('hard'), ['easy' => 'groq', 'hard' => 'anthropic']);

        try {
            $router->resolve('Summarise this order.');
            $this->fail('A reply that is not JSON must not pick a provider.');
        } catch (StructuredOutputException $e) {
            $this->assertSame('hard', $e->lastRawText);
        }
    }
}
