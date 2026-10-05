<?php

declare(strict_types=1);

namespace PhpClaw\Agent;

use PhpClaw\Exceptions\ProviderException;
use PhpClaw\Exceptions\StructuredOutputException;
use PhpClaw\Exceptions\UnsupportedSchemaException;
use PhpClaw\Hooks\HookDispatcher;
use PhpClaw\Providers\Contracts\ProviderInterface;
use PhpClaw\Providers\Contracts\SupportsStructuredOutputInterface;
use PhpClaw\Support\JsonSchemaValidator;
use PhpClaw\Tools\ToolRegistry;

/**
 * Runs one sendStructured() request: native provider mode when supported, otherwise one schema-shaped tool the
 * model may call (tool choice stays auto), with repair retries on prose, invalid JSON or validation errors.
 */
final class StructuredOutputRunner
{
    /**
     * Build a StructuredOutputRunner.
     *
     * @param  ProviderInterface  $provider  Provider (or decorated chain) the run is made through.
     * @param  JsonSchemaValidator  $validator  Validator used to enforce the caller's schema.
     * @param  int  $maxParseRetries  Repair attempts allowed after the first try.
     * @param  int  $maxRetries  Transient-failure retries per provider call, forwarded to ProviderRetryLoop.
     * @return void
     */
    public function __construct(
        private readonly ProviderInterface $provider,
        private readonly JsonSchemaValidator $validator,
        private readonly int $maxParseRetries,
        private readonly int $maxRetries,
    ) {}

    /**
     * Run the structured-output request to completion, repairing malformed replies up to maxParseRetries.
     *
     * @param  string  $message  Augmented user message.
     * @param  array<string, mixed>  $schema  JSON Schema the reply must satisfy.
     * @param  string  $runId  Active run identifier propagated to hooks.
     * @return StructuredResponse
     *
     * @throws UnsupportedSchemaException When the schema uses an unsupported keyword.
     * @throws ProviderException When the provider call fails after its own retries.
     * @throws StructuredOutputException When the reply is still invalid after every repair attempt.
     */
    public function run(string $message, array $schema, string $runId): StructuredResponse
    {
        $this->validator->assertSupported($schema);

        $sendProvider = $this->resolveSendProvider($schema);
        $native = $sendProvider !== $this->provider;
        $toolSchemas = $native ? [] : $this->singleToolSchemas($schema);

        $history = [Message::user($message)];
        $inputTokens = 0;
        $outputTokens = 0;
        $lastRawText = '';
        $errors = [];

        for ($attempt = 1; $attempt <= $this->maxParseRetries + 1; $attempt++) {
            $response = $this->send($sendProvider, $history, $toolSchemas, $attempt, $runId);
            $inputTokens += (int) ($response['input_tokens'] ?? 0);
            $outputTokens += (int) ($response['output_tokens'] ?? 0);

            [$payload, $lastRawText] = $this->extractPayload($response);
            $errors = $payload === null ? ['reply was prose'] : $this->validator->validate($schema, $payload);

            if ($payload !== null && $errors === []) {
                return new StructuredResponse(
                    data: $payload,
                    raw: $this->buildRaw($lastRawText, $attempt, $inputTokens, $outputTokens, $runId),
                );
            }

            if ($attempt > $this->maxParseRetries) {
                break;
            }

            HookDispatcher::structuredRepair($attempt, count($errors), $runId);
            $history[] = Message::assistant($lastRawText);
            $history[] = Message::user($this->repairPrompt($errors, $native));
        }

        throw new StructuredOutputException($lastRawText, $errors);
    }

    /**
     * Return a provider clone configured for native structured output, or the plain provider when unsupported.
     *
     * @param  array<string, mixed>  $schema  JSON Schema to enforce natively when supported.
     * @return ProviderInterface
     */
    private function resolveSendProvider(array $schema): ProviderInterface
    {
        if ($this->provider instanceof SupportsStructuredOutputInterface && $this->provider->supportsResponseSchema()) {
            return $this->provider->withResponseSchema($schema);
        }

        return $this->provider;
    }

    /**
     * Send one attempt through ProviderRetryLoop, so transient failures reuse the same retry policy as send().
     *
     * @param  ProviderInterface  $provider  Provider used for this attempt.
     * @param  Message[]  $history  Conversation history sent this attempt.
     * @param  array<int, array<string, mixed>>  $toolSchemas  Tool schemas for this attempt, empty in native mode.
     * @param  int  $attempt  Attempt number, used as the retry-loop iteration for hook payloads.
     * @param  string  $runId  Active run identifier propagated to hooks.
     * @return array<string, mixed>
     */
    private function send(ProviderInterface $provider, array $history, array $toolSchemas, int $attempt, string $runId): array
    {
        return (new ProviderRetryLoop($provider, $this->maxRetries))
            ->send($history, $toolSchemas, iteration: $attempt, runId: $runId);
    }

    /**
     * Register the one-off `respond_with_schema` tool and format it for the active provider.
     *
     * @param  array<string, mixed>  $schema  JSON Schema used as the tool's input schema.
     * @return array<int, array<string, mixed>>
     */
    private function singleToolSchemas(array $schema): array
    {
        $registry = new ToolRegistry;
        $registry->register([new RespondWithSchemaTool($schema)]);

        return $registry->schemas($this->provider->name());
    }

    /**
     * Extract the candidate payload and the raw text to show on repair, from either a tool call or plain text.
     *
     * @param  array<string, mixed>  $response  Raw provider response.
     * @return array{0: array<string, mixed>|null, 1: string} Decoded payload (or null when unusable) and the raw text.
     */
    private function extractPayload(array $response): array
    {
        if (($response['type'] ?? '') === 'tool_use_batch') {
            $calls = $response['calls'] ?? [];
            $first = is_array($calls) ? ($calls[0] ?? null) : null;

            if (is_array($first) && ($first['tool_name'] ?? '') === RespondWithSchemaTool::NAME) {
                $input = (array) ($first['tool_input'] ?? []);

                return [$input, (string) json_encode($input)];
            }

            return [null, (string) json_encode($calls)];
        }

        $text = (string) ($response['text'] ?? '');
        $decoded = json_decode($text, true);

        return [is_array($decoded) ? $decoded : null, $text];
    }

    /**
     * Build the terminal AgentResponse carried on the StructuredResponse.
     *
     * @param  string  $text  Raw text of the final, valid attempt.
     * @param  int  $attempts  Total attempts made, including repairs.
     * @param  int  $inputTokens  Input tokens summed across every attempt.
     * @param  int  $outputTokens  Output tokens summed across every attempt.
     * @param  string  $runId  Active run identifier.
     * @return AgentResponse
     */
    private function buildRaw(string $text, int $attempts, int $inputTokens, int $outputTokens, string $runId): AgentResponse
    {
        return new AgentResponse(
            text: $text,
            provider: $this->provider->name(),
            model: $this->provider->model(),
            iterations: $attempts,
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            runId: $runId,
        );
    }

    /**
     * Build the user-facing repair message listing every validation error, worded for the mode that was
     * actually sent: native mode asks for corrected JSON text, single-tool mode asks for another tool call.
     *
     * @param  list<string>  $errors  Errors from the failed attempt.
     * @param  bool  $native  Whether the failed attempt was sent in native mode (no tool was offered).
     * @return string
     */
    private function repairPrompt(array $errors, bool $native): string
    {
        $instruction = $native
            ? 'Reply again with corrected JSON matching the required schema exactly, with no other text.'
            : 'Call '.RespondWithSchemaTool::NAME.' again with corrected data.';

        return 'The previous reply did not match the required schema. '.$instruction.' Errors: '.implode('; ', $errors);
    }
}
