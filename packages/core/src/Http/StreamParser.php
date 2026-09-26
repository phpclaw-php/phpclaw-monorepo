<?php

declare(strict_types=1);

namespace PhpClaw\Http;

/**
 * Server-Sent Events (SSE) parser for Anthropic and OpenAI-compatible streaming responses.
 */
final class StreamParser
{
    private const SSE_DATA_PREFIX = 'data: ';

    private const SSE_DONE_MARKER = '[DONE]';

    private const SSE_LINE_TERMINATOR = "\n";

    private const SSE_LINE_TRIM_CHARS = "\r";

    private const ANTHROPIC_BLOCK_DELTA = 'content_block_delta';

    private const ANTHROPIC_TEXT_DELTA = 'text_delta';

    /**
     * Parse a single raw SSE line and extract the text token (if any).
     *
     * @param  string  $line  Raw SSE line including the `data: ` prefix.
     * @return string|null Extracted text token, or null when no text is present.
     */
    public function parseLine(string $line): ?string
    {
        $payload = self::extractDataPayload($line);

        if ($payload === null || $payload === self::SSE_DONE_MARKER) {
            return null;
        }

        $data = json_decode($payload, associative: true);

        if (! is_array($data)) {
            return null;
        }

        return $this->extractAnthropicDelta($data)
            ?? $this->extractOpenAiDelta($data)
            ?? $this->extractGeminiDelta($data);
    }

    /**
     * Parse a complete SSE stream body and return every extracted text token in order.
     *
     * @param  string  $rawStream  Full SSE response body.
     * @return string[]
     */
    public function parseAll(string $rawStream): array
    {
        $tokens = [];

        foreach (explode(self::SSE_LINE_TERMINATOR, $rawStream) as $line) {
            $token = $this->parseLine(rtrim($line, self::SSE_LINE_TRIM_CHARS));

            if ($token !== null) {
                $tokens[] = $token;
            }
        }

        return $tokens;
    }

    /**
     * Parse a full stream body and return the assembled text.
     *
     * @param  string  $rawStream  Full SSE response body.
     * @return string
     */
    public function assembleText(string $rawStream): string
    {
        return implode('', $this->parseAll($rawStream));
    }

    /**
     * Strip the `data: ` prefix and return the payload, or null when the line is not a data event.
     *
     * @param  string  $line  Raw SSE line.
     * @return ?string
     */
    private static function extractDataPayload(string $line): ?string
    {
        if (! str_starts_with($line, self::SSE_DATA_PREFIX)) {
            return null;
        }

        return substr($line, strlen(self::SSE_DATA_PREFIX));
    }

    /**
     * Extract a text token from an Anthropic content_block_delta event.
     *
     * @param  array<string, mixed>  $data  Decoded SSE event payload.
     * @return string|null Extracted text token, or null when not a text delta.
     */
    private function extractAnthropicDelta(array $data): ?string
    {
        if (($data['type'] ?? null) !== self::ANTHROPIC_BLOCK_DELTA) {
            return null;
        }

        $delta = $data['delta'] ?? [];

        if (($delta['type'] ?? '') !== self::ANTHROPIC_TEXT_DELTA || ! isset($delta['text'])) {
            return null;
        }

        return (string) $delta['text'];
    }

    /**
     * Extract a text token from an OpenAI-compatible choices[0].delta.content event.
     *
     * @param  array<string, mixed>  $data  Decoded SSE event payload.
     * @return string|null Extracted text token, or null when no content present.
     */
    private function extractOpenAiDelta(array $data): ?string
    {
        $content = $data['choices'][0]['delta']['content'] ?? null;

        return ($content === null || $content === '') ? null : (string) $content;
    }

    /**
     * Extract a text token from a Gemini candidates[0].content.parts[0].text event.
     *
     * @param  array<string, mixed>  $data  Decoded SSE event payload.
     * @return string|null Extracted text token, or null when no text present.
     */
    private function extractGeminiDelta(array $data): ?string
    {
        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;

        return ($text === null || $text === '') ? null : (string) $text;
    }
}
