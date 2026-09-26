<?php

declare(strict_types=1);

namespace PhpClaw\Tools;

use PhpClaw\Tools\Contracts\AuthorizableToolInterface;
use PhpClaw\Tools\Contracts\ResettableInterface;
use PhpClaw\Tools\Contracts\ToolAuthorizerInterface;
use PhpClaw\Tools\Contracts\ToolInterface;
use PhpClaw\Tools\Contracts\ToolRoutingInterface;

/** Holds all registered tools and formats their schemas per provider for the agent loop. */
final class ToolRegistry
{
    private const OPENAI_COMPATIBLE_PROVIDERS = ['openai', 'groq', 'gemini', 'mistral', 'ollama'];

    private array $tools = [];

    private array $schemasCache = [];

    /**
     * Create a registry, optionally holding the authorizer every core tool registered here consults.
     *
     * @param  ToolAuthorizerInterface|null  $authorizer  Adapter-supplied authorizer, or null to allow every caller.
     * @return void
     */
    public function __construct(
        private readonly ?ToolAuthorizerInterface $authorizer = null,
    ) {}

    /**
     * Register one or more tools, applying deny-list enforcement at the point of entry so every consumer of this registry sees the same denied set.
     *
     * @param  ToolInterface[]  $tools  Tools to add to the registry.
     * @param  string[]  $deny  Tool names or group references to exclude from registration.
     * @param  array<string, string[]>  $groups  Group definitions the deny list may reference.
     * @return void
     */
    public function register(array $tools, array $deny = [], array $groups = []): void
    {
        $denyNames = $deny !== [] ? ToolProfileResolver::resolveNames($deny, $groups) : [];

        $this->warnOnDanglingGroupMembers($tools, $groups);

        foreach ($tools as $tool) {
            if ($denyNames !== [] && in_array($tool->name(), $denyNames, strict: true)) {
                continue;
            }
            if ($this->authorizer !== null && $tool instanceof AuthorizableToolInterface) {
                $tool->withAuthorizer($this->authorizer);
            }

            $this->tools[$tool->name()] = $tool;
        }
        $this->schemasCache = [];
    }

    /**
     * Log a warning for every group member name that matches no tool in the incoming batch.
     *
     * @param  ToolInterface[]  $tools  Tools being registered in this call.
     * @param  array<string, string[]>  $groups  Group definitions to validate.
     * @return void
     */
    private function warnOnDanglingGroupMembers(array $tools, array $groups): void
    {
        if ($groups === []) {
            return;
        }

        $incomingNames = array_map(static fn (ToolInterface $t): string => $t->name(), $tools);

        foreach ($groups as $group => $members) {
            foreach ($members as $member) {
                if (! in_array($member, $incomingNames, strict: true)) {
                    error_log("phpClaw ToolRegistry: group '{$group}' references unregistered tool '{$member}', check for a name() mismatch.");
                }
            }
        }
    }

    /**
     * Return true when a tool with the given name is registered.
     *
     * @param  string  $name  Tool name to look up.
     * @return bool
     */
    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    /**
     * Return the registered tool instance for the given name.
     *
     * @param  string  $name  Tool name (must exist).
     * @return ToolInterface
     */
    public function get(string $name): ToolInterface
    {
        return $this->tools[$name];
    }

    /**
     * Return all registered tools as a positional array.
     *
     * @return ToolInterface[]
     */
    public function all(): array
    {
        return array_values($this->tools);
    }

    /**
     * Reset per-run state on every registered tool that implements ResettableInterface.
     *
     * @return void
     */
    public function resetRunState(): void
    {
        foreach ($this->tools as $tool) {
            if ($tool instanceof ResettableInterface) {
                $tool->reset();
            }
        }
    }

    /**
     * Return the number of registered tools.
     *
     * @return int
     */
    public function count(): int
    {
        return count($this->tools);
    }

    /**
     * Return all registered tool names.
     *
     * @return string[]
     */
    public function names(): array
    {
        return array_keys($this->tools);
    }

    /**
     * Return all registered tools formatted for the given provider.
     *
     * @param  string  $providerName  Target provider name.
     * @param  bool  $lean  True to send only the first line of each description, for small-model profiles.
     * @return array<int, array<string, mixed>>
     */
    public function schemas(string $providerName, bool $lean = false): array
    {
        if (empty($this->tools)) {
            return [];
        }

        $cacheKey = $providerName.($lean ? ':lean' : '');

        if (isset($this->schemasCache[$cacheKey])) {
            return $this->schemasCache[$cacheKey];
        }

        $eligible = array_filter(
            $this->tools,
            static fn (ToolInterface $tool): bool => ! $tool instanceof ToolRoutingInterface || $tool->isEligibleForRouting(),
        );

        return $this->schemasCache[$cacheKey] = array_values(array_map(
            fn (ToolInterface $tool): array => $this->formatForProvider($tool, $providerName, $lean),
            $eligible,
        ));
    }

    /**
     * Collect routing metadata from every tool that declares it, keyed by lowercased tool name.
     *
     * @return array<string, ToolRoutingMetadata> Metadata per tool name; tools without the routing contract are absent.
     */
    public function routingMetadata(): array
    {
        $out = [];

        foreach ($this->tools as $tool) {
            $name = strtolower($tool->name());

            if (! $tool instanceof ToolRoutingInterface) {
                continue;
            }

            $out[$name] = $tool->routingMetadata();
        }

        return $out;
    }

    /**
     * Format a single tool's schema for the given provider's API. Unknown providers receive Anthropic-native shape as a safe default.
     *
     * @param  ToolInterface  $tool  Tool whose schema is being formatted.
     * @param  string  $providerName  Target provider name.
     * @param  bool  $lean  True to send only the first line of the description.
     * @return array<string, mixed>
     */
    private function formatForProvider(ToolInterface $tool, string $providerName, bool $lean = false): array
    {
        $schema = $this->normalizeSchema($tool->inputSchema());
        $description = $lean ? trim((string) strtok($tool->description(), "\n")) : $tool->description();

        if (in_array($providerName, self::OPENAI_COMPATIBLE_PROVIDERS, strict: true)) {
            return $this->openAiFunctionShape($tool, $schema, $description);
        }

        return $this->anthropicNativeShape($tool, $schema, $description);
    }

    /**
     * Anthropic-native tool shape: { name, description, input_schema }.
     *
     * @param  ToolInterface  $tool  Tool being serialised.
     * @param  array<string, mixed>  $schema  Normalised input schema.
     * @param  string  $description  Description to send, full or first line.
     * @return array<string, mixed>
     */
    private function anthropicNativeShape(ToolInterface $tool, array $schema, string $description): array
    {
        return [
            'name' => $tool->name(),
            'description' => $description,
            'input_schema' => $schema,
        ];
    }

    /**
     * OpenAI function-calling shape: { type: 'function', function: { name, description, parameters } }.
     *
     * @param  ToolInterface  $tool  Tool being serialised.
     * @param  array<string, mixed>  $schema  Normalised input schema.
     * @param  string  $description  Description to send, full or first line.
     * @return array<string, mixed>
     */
    private function openAiFunctionShape(ToolInterface $tool, array $schema, string $description): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $tool->name(),
                'description' => $description,
                'parameters' => $schema,
            ],
        ];
    }

    /**
     * JSON object vs JSON array fix: when `properties` is an empty PHP array `[]` json_encode renders it as `[]`, but providers reject that and require `{}`. Swap empty PHP arrays for stdClass so json_encode emits an object.
     *
     * @param  array<string, mixed>  $schema  Raw schema from the tool.
     * @return array<string, mixed>
     */
    private function normalizeSchema(array $schema): array
    {
        if (array_key_exists('properties', $schema) && $schema['properties'] === []) {
            $schema['properties'] = new \stdClass;
        }

        return $schema;
    }
}
