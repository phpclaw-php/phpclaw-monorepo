<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Pipeline;

use PhpClaw\Claw;
use PhpClaw\Exceptions\ProviderException;
use PhpClaw\Exceptions\ToolException;
use PhpClaw\Guards\GuardRegistry;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Pipeline\Pipeline;
use PhpClaw\Pipeline\PipelineTool;
use PhpClaw\Pipeline\Steps\ClawStep;
use PhpClaw\Pipeline\Steps\PromptStep;
use PhpClaw\Providers\OpenAIProvider;
use PhpClaw\Skills\SkillRegistry;
use PhpClaw\Tests\Unit\Flow\Support\ScriptedHttpClient;
use PHPUnit\Framework\TestCase;
use Stringable;

final class PipelineToolTest extends TestCase
{
    private const SCHEMA = [
        'type' => 'object',
        'properties' => ['ticket' => ['type' => 'string']],
        'required' => ['ticket'],
    ];

    protected function setUp(): void
    {
        HookRegistry::reset();
        GuardRegistry::reset();
        SkillRegistry::reset();
    }

    protected function tearDown(): void
    {
        HookRegistry::reset();
        GuardRegistry::reset();
        SkillRegistry::reset();
    }

    private function tool(Pipeline $pipeline): PipelineTool
    {
        return new PipelineTool($pipeline, 'summarise_ticket', 'Summarise one support ticket.', self::SCHEMA);
    }

    private function toolCallResponse(string $name, array $arguments): array
    {
        return [
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => [[
                        'id' => 'call_1',
                        'type' => 'function',
                        'function' => ['name' => $name, 'arguments' => (string) json_encode($arguments)],
                    ]],
                ],
            ]],
            'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 5],
        ];
    }

    private function textResponse(string $text): array
    {
        return [
            'choices' => [['message' => ['role' => 'assistant', 'content' => $text]]],
            'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 5],
        ];
    }

    public function test_name_description_and_schema_are_the_ones_given(): void
    {
        $tool = $this->tool(Pipeline::make());

        $this->assertSame('summarise_ticket', $tool->name());
        $this->assertSame('Summarise one support ticket.', $tool->description());
        $this->assertSame(self::SCHEMA, $tool->inputSchema());
    }

    public function test_execute_runs_the_pipeline_on_the_tool_input_and_returns_text(): void
    {
        $tool = $this->tool(Pipeline::make()->pipe(static fn (array $input): string => 'Summary of '.$input['ticket']));

        $this->assertSame('Summary of T-1', $tool->execute(['ticket' => 'T-1']));
    }

    public function test_execute_returns_a_stringable_output_as_its_text(): void
    {
        $output = new class implements Stringable
        {
            public function __toString(): string
            {
                return 'stringable summary';
            }
        };
        $tool = $this->tool(Pipeline::make()->pipe(static fn (): Stringable => $output));

        $this->assertSame('stringable summary', $tool->execute(['ticket' => 'T-1']));
    }

    public function test_execute_encodes_a_non_text_output_as_json(): void
    {
        $tool = $this->tool(Pipeline::make()->pipe(static fn (array $input): array => ['ticket' => $input['ticket'], 'priority' => 2]));

        $this->assertSame('{"ticket":"T-1","priority":2}', $tool->execute(['ticket' => 'T-1']));
    }

    public function test_execute_turns_an_output_json_cannot_encode_into_a_tool_error(): void
    {
        $tool = $this->tool(Pipeline::make()->pipe(static fn (): float => NAN));

        $this->expectException(ToolException::class);
        $this->expectExceptionMessage("Pipeline tool 'summarise_ticket' returned a value that cannot be encoded as JSON.");

        $tool->execute(['ticket' => 'T-1']);
    }

    public function test_execute_turns_a_fixable_input_problem_into_a_tool_error(): void
    {
        $tool = $this->tool(Pipeline::make()->pipe(new PromptStep('Summarise {ticket} for {team}.')));

        $this->expectException(ToolException::class);
        $this->expectExceptionMessage("Missing value for placeholder 'team'.");

        $tool->execute(['ticket' => 'T-1']);
    }

    public function test_execute_lets_any_other_error_stop_the_run(): void
    {
        $tool = $this->tool(Pipeline::make()->pipe(static function (): never {
            throw new ProviderException('provider down');
        }));

        $this->expectException(ProviderException::class);

        $tool->execute(['ticket' => 'T-1']);
    }

    public function test_an_agent_calls_the_pipeline_tool_and_answers_with_its_result(): void
    {
        $http = new ScriptedHttpClient;
        $http->queuePostResponse($this->toolCallResponse('summarise_ticket', ['ticket' => 'T-77']));
        $http->queuePostResponse($this->textResponse('Ticket T-77 is about a late refund.'));
        $provider = new OpenAIProvider(apiKey: 'test-key', http: $http, model: 'llama-3.1-8b-instant', name: 'groq');
        $agent = Claw::builder()->provider('groq')->model('llama-3.1-8b-instant')->providerOverride($provider)
            ->addTool($this->tool(Pipeline::make()->pipe(static fn (array $input): string => 'T-77: customer waits for a refund since May.')))
            ->build();

        $reply = $agent->send('What is ticket T-77 about?');

        $this->assertSame('Ticket T-77 is about a late refund.', $reply->text);
        $toolMessage = array_values(array_filter($http->postBodies[1]['messages'], static fn (array $message): bool => $message['role'] === 'tool'));
        $this->assertSame('T-77: customer waits for a refund since May.', $toolMessage[0]['content']);
    }

    public function test_a_pipeline_tool_can_wrap_a_chain_that_calls_another_agent(): void
    {
        $http = new ScriptedHttpClient;
        $http->queuePostResponse($this->textResponse('Late refund.'));
        $provider = new OpenAIProvider(apiKey: 'test-key', http: $http, model: 'llama-3.1-8b-instant', name: 'groq');
        $summariser = Claw::builder()->provider('groq')->model('llama-3.1-8b-instant')->providerOverride($provider)->build();
        $tool = $this->tool(Pipeline::make()->pipe(new PromptStep('Summarise ticket {ticket}.'))->pipe(new ClawStep($summariser)));

        $this->assertSame('Late refund.', $tool->execute(['ticket' => 'T-77']));
    }
}
