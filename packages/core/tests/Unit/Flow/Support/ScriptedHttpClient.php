<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Flow\Support;

use PhpClaw\Exceptions\ProviderException;
use PhpClaw\Http\RawHttpClient;

final class ScriptedHttpClient extends RawHttpClient
{
    private array $postQueue = [];

    private array $streamQueue = [];

    public array $postBodies = [];

    public array $streamBodies = [];

    public int $postCallCount = 0;

    public int $streamCallCount = 0;

    public function queuePostResponse(array $response): void
    {
        $this->postQueue[] = $response;
    }

    public function queuePostException(ProviderException $exception): void
    {
        $this->postQueue[] = $exception;
    }

    public function queueStreamLines(array $lines): void
    {
        $this->streamQueue[] = ['lines' => $lines, 'exception' => null];
    }

    public function queueStreamException(ProviderException $exception): void
    {
        $this->streamQueue[] = ['lines' => [], 'exception' => $exception];
    }

    public function queueStreamLinesThenException(array $lines, ProviderException $exception): void
    {
        $this->streamQueue[] = ['lines' => $lines, 'exception' => $exception];
    }

    public function post(string $url, array $headers, array $body): array
    {
        $this->postCallCount++;
        $this->postBodies[] = $body;

        $next = array_shift($this->postQueue);

        if ($next === null) {
            throw new ProviderException('ScriptedHttpClient: no queued post response.');
        }

        if ($next instanceof ProviderException) {
            throw $next;
        }

        return $next;
    }

    public function stream(string $url, array $headers, array $body, callable $onChunk): void
    {
        $this->streamCallCount++;
        $this->streamBodies[] = $body;

        $next = array_shift($this->streamQueue) ?? ['lines' => [], 'exception' => null];

        foreach ($next['lines'] as $line) {
            $onChunk($line);
        }

        if ($next['exception'] !== null) {
            throw $next['exception'];
        }
    }
}
