<?php

declare(strict_types=1);

namespace PhpClaw\Mcp\Transport;

use PhpClaw\Mcp\Contracts\TransportInterface;
use PhpClaw\Mcp\Exceptions\McpException;
use PhpClaw\Mcp\McpRequest;
use PhpClaw\Mcp\McpResponse;

/**
 * Newline-delimited JSON transport over stdin/stdout.
 */
final class StdioTransport implements TransportInterface
{
    /** @var resource */
    private $input;

    /** @var resource */
    private $output;

    private bool $open = true;

    /**
     * Construct the stdio transport with optional custom streams.
     *
     * @param  resource|null  $input  Readable stream (default: STDIN).
     * @param  resource|null  $output  Writable stream (default: STDOUT).
     * @return void
     */
    public function __construct($input = null, $output = null)
    {
        $this->input = $input ?? STDIN;
        $this->output = $output ?? STDOUT;

        if (is_resource($this->input)) {
            @stream_set_timeout($this->input, 86400);
        }
    }

    /**
     * Read the next newline-delimited JSON line from stdin.
     *
     * @return McpRequest The parsed JSON-RPC request value object.
     *
     * @throws McpException On EOF (-32700) or empty line (-32700).
     */
    public function read(): McpRequest
    {
        while (true) {
            $line = fgets($this->input);

            if ($line === false) {
                $meta = stream_get_meta_data($this->input);
                if (! empty($meta['timed_out']) && ! feof($this->input)) {
                    continue;
                }

                $this->open = false;
                throw new McpException('EOF, stdin closed', McpException::PARSE_ERROR);
            }

            $line = trim($line);

            if ($line === '') {
                throw new McpException('Empty line received', McpException::PARSE_ERROR);
            }

            return McpRequest::fromJson($line);
        }
    }

    /**
     * Write a newline-terminated JSON response to stdout.
     *
     * @param  McpResponse  $response  The response to write.
     * @return void
     */
    public function write(McpResponse $response): void
    {
        fwrite($this->output, $response->toJson());
        fflush($this->output);
    }

    /**
     * Return true while stdin is open and not at EOF.
     *
     * @return bool Whether the serve loop should keep reading.
     */
    public function isOpen(): bool
    {
        return $this->open && ! feof($this->input);
    }
}
