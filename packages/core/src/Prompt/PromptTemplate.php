<?php

declare(strict_types=1);

namespace PhpClaw\Prompt;

use PhpClaw\Exceptions\PromptTemplateException;
use Stringable;

/**
 * Immutable `{name}` placeholder template that does no escaping; the rendered text still passes the
 * guard stack in Claw::send() and Claw::sendStructured().
 */
final class PromptTemplate
{
    private const SEGMENT_LITERAL = 'literal';

    private const SEGMENT_PLACEHOLDER = 'placeholder';

    private const PLACEHOLDER_PATTERN = '/\{\{|\}\}|\{([A-Za-z_][A-Za-z0-9_]*)\}/';

    /**
     * Hold the parsed literal and placeholder segments.
     *
     * @param  list<array{type: string, value: string}>  $segments  Parsed segments in template order.
     */
    private function __construct(
        private readonly array $segments,
    ) {}

    /**
     * Parse the given template string into an immutable PromptTemplate.
     *
     * @param  string  $template  Raw template containing `{name}` placeholders.
     * @return self
     */
    public static function from(string $template): self
    {
        return new self(self::parse($template));
    }

    /**
     * Render the template, substituting every placeholder from $vars. Extra keys in $vars that
     * do not match a placeholder are ignored. Does no escaping of the substituted values.
     *
     * @param  array<string, scalar|Stringable>  $vars  Values keyed by placeholder name.
     * @return string
     *
     * @throws PromptTemplateException When a placeholder has no matching key in $vars, or the
     *                                 matching value is not scalar and not Stringable.
     */
    public function format(array $vars): string
    {
        $result = '';

        foreach ($this->segments as $segment) {
            if ($segment['type'] === self::SEGMENT_LITERAL) {
                $result .= $segment['value'];

                continue;
            }

            $result .= self::resolvePlaceholder($segment['value'], $vars);
        }

        return $result;
    }

    /**
     * List the distinct placeholder names found in the template, in order of first appearance.
     *
     * @return list<string>
     */
    public function variables(): array
    {
        $names = [];

        foreach ($this->segments as $segment) {
            if ($segment['type'] === self::SEGMENT_PLACEHOLDER && ! in_array($segment['value'], $names, true)) {
                $names[] = $segment['value'];
            }
        }

        return $names;
    }

    /**
     * Return a new template with the placeholders named in $vars substituted; the rest stay for a
     * later format() call, unknown keys are ignored.
     *
     * @param  array<string, scalar|Stringable>  $vars  Values keyed by placeholder name.
     * @return self
     *
     * @throws PromptTemplateException When a bound value is not scalar and not Stringable.
     */
    public function partial(array $vars): self
    {
        $segments = [];

        foreach ($this->segments as $segment) {
            if ($segment['type'] !== self::SEGMENT_PLACEHOLDER || ! array_key_exists($segment['value'], $vars)) {
                $segments[] = $segment;

                continue;
            }

            $segments[] = [
                'type' => self::SEGMENT_LITERAL,
                'value' => self::stringify($segment['value'], $vars[$segment['value']]),
            ];
        }

        return new self($segments);
    }

    /**
     * Split the template into literal and placeholder segments; `{{` and `}}` become single braces
     * and any other `{` that does not open a valid `{name}` stays literal.
     *
     * @param  string  $template  Raw template string.
     * @return list<array{type: string, value: string}>
     */
    private static function parse(string $template): array
    {
        $segments = [];

        if (preg_match_all(self::PLACEHOLDER_PATTERN, $template, $matches, PREG_OFFSET_CAPTURE) === 0) {
            if ($template !== '') {
                $segments[] = ['type' => self::SEGMENT_LITERAL, 'value' => $template];
            }

            return $segments;
        }

        $cursor = 0;

        foreach ($matches[0] as $index => [$matchText, $matchOffset]) {
            if ($matchOffset > $cursor) {
                $segments[] = [
                    'type' => self::SEGMENT_LITERAL,
                    'value' => substr($template, $cursor, $matchOffset - $cursor),
                ];
            }

            $segments[] = match ($matchText) {
                '{{' => ['type' => self::SEGMENT_LITERAL, 'value' => '{'],
                '}}' => ['type' => self::SEGMENT_LITERAL, 'value' => '}'],
                default => ['type' => self::SEGMENT_PLACEHOLDER, 'value' => $matches[1][$index][0]],
            };

            $cursor = $matchOffset + strlen($matchText);
        }

        if ($cursor < strlen($template)) {
            $segments[] = ['type' => self::SEGMENT_LITERAL, 'value' => substr($template, $cursor)];
        }

        return $segments;
    }

    /**
     * Resolve one placeholder segment against the given variables.
     *
     * @param  string  $name  Placeholder name.
     * @param  array<string, scalar|Stringable>  $vars  Values keyed by placeholder name.
     * @return string
     *
     * @throws PromptTemplateException When $name has no key in $vars, or the value is not
     *                                 scalar and not Stringable.
     */
    private static function resolvePlaceholder(string $name, array $vars): string
    {
        if (! array_key_exists($name, $vars)) {
            throw new PromptTemplateException("Missing value for placeholder '{$name}'.");
        }

        return self::stringify($name, $vars[$name]);
    }

    /**
     * Convert one placeholder value to a string, or reject it.
     *
     * @param  string  $name  Placeholder name, used only for the exception message.
     * @param  mixed  $value  Candidate value.
     * @return string
     *
     * @throws PromptTemplateException When $value is not scalar and not Stringable.
     */
    private static function stringify(string $name, mixed $value): string
    {
        if (! is_scalar($value) && ! $value instanceof Stringable) {
            $type = get_debug_type($value);

            throw new PromptTemplateException("Value for placeholder '{$name}' must be scalar or Stringable, got {$type}.");
        }

        return (string) $value;
    }
}
