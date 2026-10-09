<?php

declare(strict_types=1);

namespace PhpClaw\Pipeline\Steps;

use PhpClaw\Exceptions\PipelineException;
use PhpClaw\Exceptions\PromptTemplateException;
use PhpClaw\Pipeline\Contracts\PipelineStepInterface;
use PhpClaw\Prompt\PromptTemplate;

/**
 * Pipeline step that fills a `{placeholder}` PromptTemplate from an array input.
 */
final class PromptStep implements PipelineStepInterface
{
    private readonly PromptTemplate $template;

    /**
     * Parse the template once, so every run only fills it.
     *
     * @param  string  $template  Template text with `{name}` placeholders; `{{` and `}}` are literal braces.
     */
    public function __construct(string $template)
    {
        $this->template = PromptTemplate::from($template);
    }

    /**
     * Return the template with every placeholder replaced by the matching input value.
     *
     * @param  mixed  $input  Placeholder values keyed by placeholder name.
     * @return string
     *
     * @throws PipelineException When the input is not an array.
     * @throws PromptTemplateException When a placeholder has no value, or its value cannot become text.
     */
    public function run(mixed $input): string
    {
        if (! is_array($input)) {
            throw PipelineException::invalidInput(self::class, 'an array of placeholder values', $input);
        }

        return $this->template->format($input);
    }
}
