<?php

declare(strict_types=1);

namespace PhpClaw\Pipeline;

use JsonException;
use PhpClaw\Exceptions\PipelineException;
use PhpClaw\Exceptions\PromptTemplateException;
use PhpClaw\Exceptions\StructuredOutputException;
use PhpClaw\Exceptions\ToolException;
use PhpClaw\Tools\Contracts\ToolInterface;
use Stringable;

/**
 * Agent tool that runs a Pipeline on the tool input and returns the output as text.
 */
final class PipelineTool implements ToolInterface
{
    /**
     * Describe the pipeline to the model as one tool.
     *
     * @param  Pipeline  $pipeline  Pipeline run with the tool input array.
     * @param  string  $name  Tool name the model calls, snake_case.
     * @param  string  $description  Tells the model when to call the tool.
     * @param  array<string, mixed>  $inputSchema  JSON Schema of the tool input.
     */
    public function __construct(
        private readonly Pipeline $pipeline,
        private readonly string $name,
        private readonly string $description,
        private readonly array $inputSchema,
    ) {}

    /**
     * Tool name the model calls.
     *
     * @return string
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * Tells the model when to call the tool.
     *
     * @return string
     */
    public function description(): string
    {
        return $this->description;
    }

    /**
     * JSON Schema of the tool input.
     *
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return $this->inputSchema;
    }

    /**
     * Run the pipeline on the tool input; an input or output-shape problem becomes a tool error the model can fix.
     *
     * @param  array<string, mixed>  $input  Tool input sent by the model.
     * @return string
     *
     * @throws ToolException When a step rejects the input, a prompt value is missing, a structured reply stays
     *                       invalid, or the output cannot be encoded as JSON.
     */
    public function execute(array $input): string
    {
        try {
            $output = $this->pipeline->invoke($input);
        } catch (PipelineException|PromptTemplateException|StructuredOutputException $error) {
            throw new ToolException($error->getMessage(), previous: $error);
        }

        return $this->toText($output);
    }

    /**
     * Return the output as text: strings and Stringable as they are, anything else as JSON.
     *
     * @param  mixed  $output  Last step's output.
     * @return string
     *
     * @throws ToolException When the output cannot be encoded as JSON.
     */
    private function toText(mixed $output): string
    {
        if (is_string($output) || $output instanceof Stringable) {
            return (string) $output;
        }

        try {
            return json_encode($output, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new ToolException(sprintf("Pipeline tool '%s' returned a value that cannot be encoded as JSON.", $this->name), previous: $error);
        }
    }
}
