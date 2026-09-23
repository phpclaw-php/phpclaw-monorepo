<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Tests\Helpers;

trait AssertsToolEnvelope
{
    protected function envelope(string $json): array
    {
        $decoded = json_decode($json, true);

        self::assertIsArray($decoded, 'The tool response is not decodable JSON: '.$json);

        return $decoded;
    }

    protected function data(string $json): array
    {
        $envelope = $this->envelope($json);

        self::assertTrue(
            $envelope['success'] ?? false,
            'The tool refused instead of answering: '.$json,
        );

        return (array) $envelope['data'];
    }

    protected function rows(string $json, string $key = 'rows'): array
    {
        $data = $this->data($json);

        self::assertArrayHasKey($key, $data, 'The response carries no "'.$key.'" list.');

        return (array) $data[$key];
    }

    protected function flat(string $json): array
    {
        $envelope = $this->envelope($json);

        self::assertTrue(
            $envelope['success'] ?? false,
            'The tool refused instead of answering: '.$json,
        );

        return array_merge((array) $envelope['meta'], (array) $envelope['data']);
    }

    protected function meta(string $json): array
    {
        return (array) ($this->envelope($json)['meta'] ?? []);
    }

    protected function warningCodes(string $json): array
    {
        return array_column((array) ($this->envelope($json)['warnings'] ?? []), 'code');
    }

    protected function errorCode(string $json): ?string
    {
        return $this->envelope($json)['error']['code'] ?? null;
    }
}
