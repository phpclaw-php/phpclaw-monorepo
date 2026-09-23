<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Http;

use PhpClaw\Http\StreamParser;
use PHPUnit\Framework\TestCase;

final class StreamParserTest extends TestCase
{
    private StreamParser $parser;

    protected function setUp(): void
    {
        $this->parser = new StreamParser;
    }

    public function test_parses_anthropic_text_delta(): void
    {
        $line = 'data: {"type":"content_block_delta","delta":{"type":"text_delta","text":"Hello"}}';
        $this->assertSame('Hello', $this->parser->parseLine($line));
    }

    public function test_ignores_anthropic_non_text_delta(): void
    {
        $line = 'data: {"type":"message_start","message":{"id":"msg_01"}}';
        $this->assertNull($this->parser->parseLine($line));
    }

    public function test_ignores_anthropic_ping(): void
    {
        $line = 'event: ping';
        $this->assertNull($this->parser->parseLine($line));
    }

    public function test_ignores_anthropic_content_block_start(): void
    {
        $line = 'data: {"type":"content_block_start","index":0,"content_block":{"type":"text","text":""}}';
        $this->assertNull($this->parser->parseLine($line));
    }

    public function test_parses_openai_content_delta(): void
    {
        $line = 'data: {"choices":[{"delta":{"content":"World"},"index":0}]}';
        $this->assertSame('World', $this->parser->parseLine($line));
    }

    public function test_returns_null_for_openai_empty_content(): void
    {
        $line = 'data: {"choices":[{"delta":{"content":""},"index":0}]}';
        $this->assertNull($this->parser->parseLine($line));
    }

    public function test_ignores_openai_role_only_delta(): void
    {
        $line = 'data: {"choices":[{"delta":{"role":"assistant"},"index":0}]}';
        $this->assertNull($this->parser->parseLine($line));
    }

    public function test_parses_gemini_text_part(): void
    {
        $line = 'data: {"candidates":[{"content":{"parts":[{"text":"Gemini here"}],"role":"model"}}]}';
        $this->assertSame('Gemini here', $this->parser->parseLine($line));
    }

    public function test_returns_null_for_done_sentinel(): void
    {
        $this->assertNull($this->parser->parseLine('data: [DONE]'));
    }

    public function test_returns_null_for_empty_line(): void
    {
        $this->assertNull($this->parser->parseLine(''));
    }

    public function test_returns_null_for_non_data_line(): void
    {
        $this->assertNull($this->parser->parseLine('id: 123'));
    }

    public function test_returns_null_for_invalid_json(): void
    {
        $this->assertNull($this->parser->parseLine('data: not-json'));
    }

    public function test_parse_all_returns_all_tokens_from_anthropic_stream(): void
    {
        $rawStream = implode("\n", [
            'event: message_start',
            'data: {"type":"message_start","message":{}}',
            '',
            'event: content_block_delta',
            'data: {"type":"content_block_delta","delta":{"type":"text_delta","text":"Hello"}}',
            '',
            'event: content_block_delta',
            'data: {"type":"content_block_delta","delta":{"type":"text_delta","text":", world"}}',
            '',
            'event: message_stop',
            'data: {"type":"message_stop"}',
        ]);

        $tokens = $this->parser->parseAll($rawStream);
        $this->assertSame(['Hello', ', world'], $tokens);
    }

    public function test_assemble_text_joins_tokens(): void
    {
        $rawStream = implode("\n", [
            'data: {"type":"content_block_delta","delta":{"type":"text_delta","text":"Foo"}}',
            'data: {"type":"content_block_delta","delta":{"type":"text_delta","text":"Bar"}}',
        ]);

        $this->assertSame('FooBar', $this->parser->assembleText($rawStream));
    }

    public function test_parse_all_returns_empty_array_for_done_only_stream(): void
    {
        $rawStream = "data: [DONE]\n";
        $this->assertSame([], $this->parser->parseAll($rawStream));
    }

    public function test_parse_all_collects_openai_tokens(): void
    {
        $rawStream = implode("\n", [
            'data: {"choices":[{"delta":{"content":"Hi"},"index":0}]}',
            'data: {"choices":[{"delta":{"content":" there"},"index":0}]}',
            'data: [DONE]',
        ]);

        $this->assertSame(['Hi', ' there'], $this->parser->parseAll($rawStream));
    }

    public function test_assemble_text_returns_empty_string_for_empty_stream(): void
    {
        $this->assertSame('', $this->parser->assembleText(''));
    }

    public function test_assemble_text_returns_single_token(): void
    {
        $line = 'data: {"type":"content_block_delta","delta":{"type":"text_delta","text":"Solo"}}';
        $this->assertSame('Solo', $this->parser->assembleText($line));
    }

    public function test_assemble_text_preserves_whitespace_tokens(): void
    {
        $rawStream = implode("\n", [
            'data: {"type":"content_block_delta","delta":{"type":"text_delta","text":"A"}}',
            'data: {"type":"content_block_delta","delta":{"type":"text_delta","text":" "}}',
            'data: {"type":"content_block_delta","delta":{"type":"text_delta","text":"B"}}',
        ]);

        $this->assertSame('A B', $this->parser->assembleText($rawStream));
    }
}
