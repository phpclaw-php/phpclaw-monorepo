<?php

declare(strict_types=1);

namespace PhpClaw;

use PhpClaw\Builder\Concerns\CloudSetters;
use PhpClaw\Builder\Concerns\FlagSetters;
use PhpClaw\Builder\Concerns\LimitSetters;
use PhpClaw\Builder\Concerns\ProviderSetters;
use PhpClaw\Builder\Concerns\SkillSetters;
use PhpClaw\Builder\Concerns\ToolSetters;
use PhpClaw\Config\CloudSettings;
use PhpClaw\Config\LoopConfig;
use PhpClaw\Config\ProviderConfig;
use PhpClaw\Config\RuntimeConfig;
use PhpClaw\Config\SkillConfig;
use PhpClaw\Config\ToolConfig;
use PhpClaw\Tools\Contracts\ToolInterface;
use PhpClaw\Tools\RemoteToolActivator;

/**
 * Fluent builder for {@see Claw}: collect every configuration option via chainable setters, then build() to assemble the Claw instance.
 */
final class ClawBuilder
{
    use CloudSetters;
    use FlagSetters;
    use LimitSetters;
    use ProviderSetters;
    use SkillSetters;
    use ToolSetters;

    /**
     * Assemble the final ClawConfig and return a new Claw instance.
     *
     * @return Claw Fully wired engine; default guards, skills, and cloud boot have all run.
     */
    public function build(): Claw
    {
        $tools = $this->tools;
        $maxToolsPerTurn = $this->maxToolsPerTurn;
        foreach ($this->remoteToolProfileUrls as $profileUrl) {
            $profile = RemoteToolActivator::fetch($profileUrl);
            if ($profile === null) {
                continue;
            }
            $tools = RemoteToolActivator::filter($tools, $profile['tools']);
            if ($profile['max_tools_per_turn'] > 0 && $this->maxToolsPerTurn === 0) {
                $maxToolsPerTurn = $profile['max_tools_per_turn'];
            }
        }

        $composedSystemPrompt = $this->composeSystemPrompt($tools);

        $config = new ClawConfig(
            provider: new ProviderConfig(
                apiKey: $this->apiKey,
                provider: $this->provider,
                model: $this->model,
                systemPrompt: $composedSystemPrompt,
                maxTokens: $this->maxTokens,
                promptCache: $this->promptCache,
                thinkingBudget: $this->thinkingBudget,
                providerOverride: $this->providerOverride,
                providerTools: $this->providerTools,
            ),
            limits: new LoopConfig(
                maxIterations: $this->maxIterations,
                maxRetries: $this->maxRetries,
                maxHistoryLength: $this->maxHistoryLength,
                maxHistoryTokens: $this->maxHistoryTokens,
                maxToolsPerTurn: $maxToolsPerTurn,
                maxToolResultTokens: $this->maxToolResultTokens,
            ),
            tools: new ToolConfig(
                tools: $tools,
                shellAllowlist: $this->shellAllowlist,
                allowPhpWrite: $this->allowPhpWrite,
                remoteToolProfileUrls: $this->remoteToolProfileUrls,
                approvalGate: $this->approvalGate,
            ),
            skills: new SkillConfig(
                skills: $this->skills,
                skillMatchLimit: $this->skillMatchLimit,
                remoteSkillUrls: $this->remoteSkillUrls,
            ),
            cloud: new CloudSettings(
                cloudKey: $this->cloudKey,
                cloudDisable: $this->cloudDisable,
                cloudSigningSecret: $this->cloudSigningSecret,
            ),
            flags: new RuntimeConfig(
                storeMessages: $this->storeMessages,
                useDefaultGuards: $this->useDefaultGuards,
                sanitiseOutput: $this->sanitiseOutput,
                compactHistory: $this->compactHistory,
                memory: $this->memory,
            ),
        );

        return new Claw($config);
    }

    /**
     * Prepend the agentic doctrine when tools are registered.
     *
     * @param  ToolInterface[]  $tools  The (possibly remote-profile-filtered) tool list.
     * @return string Either the raw systemPrompt or Claw::AGENTIC_DOCTRINE (optionally followed by the user prompt).
     */
    private function composeSystemPrompt(array $tools): string
    {
        if (empty($tools)) {
            return $this->systemPrompt;
        }

        return $this->systemPrompt === ''
            ? Claw::AGENTIC_DOCTRINE
            : Claw::AGENTIC_DOCTRINE."\n\n".$this->systemPrompt;
    }
}
