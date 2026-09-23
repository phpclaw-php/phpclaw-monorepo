<?php

declare(strict_types=1);

namespace PhpClaw\Tools\Contracts;

/**
 * Optional interface for tools that expose admin-configurable settings to the adapter's settings page.
 */
interface ConfigurableToolInterface extends ToolInterface
{
    /**
     * Declare the settings fields this tool requires.
     *
     * @return array<string, array{label: string, type: string, placeholder?: string, description?: string, required?: bool}>
     */
    public static function settingsFields(): array;

    /**
     * Receive the saved settings values for this tool's declared fields.
     *
     * @param  array<string, mixed>  $config  Full saved settings array, tool reads only its own keys.
     * @return void
     */
    public function configure(array $config): void;

    /**
     * Return true when all required fields have been configured.
     *
     * @return bool
     */
    public function isConfigured(): bool;
}
