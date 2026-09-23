<?php

declare(strict_types=1);

namespace PhpClaw\WordPress\Tests\Stubs;

use PhpClaw\Tools\Contracts\ConfigurableToolInterface;

final class ConfiguredToolStub implements ConfigurableToolInterface
{
    private bool $configured = false;

    public function name(): string
    {
        return 'stub_configured_tool';
    }

    public function description(): string
    {
        return 'Stub tool that is configured';
    }

    public function inputSchema(): array
    {
        return [];
    }

    public function execute(array $input): string
    {
        return 'ok';
    }

    public static function settingsFields(): array
    {
        return [];
    }

    public function configure(array $config): void
    {
        $this->configured = true;
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }
}

final class UnConfiguredToolStub implements ConfigurableToolInterface
{
    public function name(): string
    {
        return 'stub_unconfigured_tool';
    }

    public function description(): string
    {
        return 'Stub tool that is never configured';
    }

    public function inputSchema(): array
    {
        return [];
    }

    public function execute(array $input): string
    {
        return 'ok';
    }

    public static function settingsFields(): array
    {
        return [];
    }

    public function configure(array $config): void {}

    public function isConfigured(): bool
    {
        return false;
    }
}

final class ThrowingConfigureToolStub implements ConfigurableToolInterface
{
    public function name(): string
    {
        return 'stub_throwing_tool';
    }

    public function description(): string
    {
        return 'Stub tool whose configure() throws';
    }

    public function inputSchema(): array
    {
        return [];
    }

    public function execute(array $input): string
    {
        return 'ok';
    }

    public static function settingsFields(): array
    {
        return [];
    }

    public function configure(array $config): void
    {
        throw new \RuntimeException('configure failed intentionally');
    }

    public function isConfigured(): bool
    {
        return false;
    }
}

final class FieldedToolStub implements ConfigurableToolInterface
{
    public function name(): string
    {
        return 'stub_fielded_tool';
    }

    public function description(): string
    {
        return 'Stub tool that contributes Settings-page fields';
    }

    public function inputSchema(): array
    {
        return [];
    }

    public function execute(array $input): string
    {
        return 'ok';
    }

    public static function settingsFields(): array
    {
        return [
            'stub_endpoint' => [
                'label' => 'Stub Endpoint',
                'type' => 'url',
                'placeholder' => 'https://example.com/hook',
                'description' => 'Stub endpoint URL.',
            ],
            'stub_limit' => [
                'label' => 'Stub Limit',
                'type' => 'number',
            ],
            'stub_note' => [
                'label' => 'Stub Note',
                'type' => 'text',
            ],
        ];
    }

    public function configure(array $config): void {}

    public function isConfigured(): bool
    {
        return true;
    }
}
