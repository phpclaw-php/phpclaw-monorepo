<?php

declare(strict_types=1);

namespace PhpClaw\Builder\Concerns;

use PhpClaw\Agent\CliApprovalGate;
use PhpClaw\Agent\Contracts\ApprovalGateInterface;
use PhpClaw\Tools\Contracts\ToolInterface;

/**
 * Tool registration, shell allowlist, and approval-gate setters for ClawBuilder.
 */
trait ToolSetters
{
    private array $tools = [];

    private array $shellAllowlist = [];

    private bool $allowPhpWrite = false;

    private array $remoteToolProfileUrls = [];

    private ?ApprovalGateInterface $approvalGate = null;

    /**
     * Replace the tools list.
     *
     * @param  ToolInterface[]  $tools  Full list of tools the agent may invoke; replaces any previously set tools.
     * @return static Builder instance for fluent chaining.
     */
    public function tools(array $tools): static
    {
        $this->tools = $tools;

        return $this;
    }

    /**
     * Append a single tool to the list.
     *
     * @param  ToolInterface  $tool  Tool to register; appended to the existing tools array.
     * @return static Builder instance for fluent chaining.
     */
    public function addTool(ToolInterface $tool): static
    {
        $this->tools[] = $tool;

        return $this;
    }

    /**
     * Set the ShellTool allowlist. Empty = use ClawConfig::DEFAULT_SHELL_ALLOWLIST.
     *
     * @param  string[]  $commands  Allowed shell command names; empty array falls back to the built-in default.
     * @return static Builder instance for fluent chaining.
     */
    public function shellAllowlist(array $commands): static
    {
        $this->shellAllowlist = $commands;

        return $this;
    }

    /**
     * Allow adapters to write .php/.phtml/.phar files via FileWriteTool. Default false.
     *
     * @param  bool  $flag  True to permit PHP file writes when an adapter constructs FileWriteTool from this config.
     * @return static Builder instance for fluent chaining.
     */
    public function allowPhpWrite(bool $flag = true): static
    {
        $this->allowPhpWrite = $flag;

        return $this;
    }

    /**
     * Register a remote tool-activation profile URL that names which local tools stay active.
     *
     * @param  string  $url  HTTPS URL of a tool profile JSON.
     * @return static Builder instance for fluent chaining.
     */
    public function withRemoteToolProfile(string $url): static
    {
        $this->remoteToolProfileUrls[] = $url;

        return $this;
    }

    /**
     * Install an approval gate checked before every gated tool executes.
     *
     * @param  ApprovalGateInterface  $gate  Gate implementation.
     * @return static Builder instance for fluent chaining.
     */
    public function approvalGate(ApprovalGateInterface $gate): static
    {
        $this->approvalGate = $gate;

        return $this;
    }

    /**
     * Install the built-in CLI Y/n approval gate.
     *
     * @return static Builder instance for fluent chaining.
     */
    public function withHumanApproval(): static
    {
        $this->approvalGate = new CliApprovalGate;

        return $this;
    }
}
