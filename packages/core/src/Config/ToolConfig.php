<?php

declare(strict_types=1);

namespace PhpClaw\Config;

use PhpClaw\Agent\Contracts\ApprovalGateInterface;
use PhpClaw\Tools\Contracts\ToolInterface;

/**
 * Tool registration, shell allowlist, and approval gating for ClawConfig.
 */
final class ToolConfig
{
    public const DEFAULT_SHELL_ALLOWLIST = [
        'ls', 'pwd', 'df', 'cat', 'head', 'tail', 'grep', 'wc',
        'date', 'uptime', 'hostname', 'whoami',
    ];

    public readonly array $tools;

    public readonly array $shellAllowlist;

    public readonly bool $allowPhpWrite;

    public readonly array $remoteToolProfileUrls;

    public readonly ?ApprovalGateInterface $approvalGate;

    /**
     * Group and validate the tool-facing configuration.
     *
     * @param  ToolInterface[]  $tools  Tools available to the agent.
     * @param  string[]  $shellAllowlist  Allowed shell command names; empty array uses DEFAULT_SHELL_ALLOWLIST.
     * @param  bool  $allowPhpWrite  True to permit PHP file writes when an adapter constructs FileWriteTool from this config.
     * @param  string[]  $remoteToolProfileUrls  Remote tool-activation profile URLs applied at build.
     * @param  ApprovalGateInterface|null  $approvalGate  Approval gate checked before gated tools run; null disables gating.
     * @return void
     */
    public function __construct(
        array $tools = [],
        array $shellAllowlist = [],
        bool $allowPhpWrite = false,
        array $remoteToolProfileUrls = [],
        ?ApprovalGateInterface $approvalGate = null,
    ) {
        $this->tools = $tools;
        $this->shellAllowlist = $shellAllowlist !== [] ? $shellAllowlist : self::DEFAULT_SHELL_ALLOWLIST;
        $this->allowPhpWrite = $allowPhpWrite;
        $this->remoteToolProfileUrls = $remoteToolProfileUrls;
        $this->approvalGate = $approvalGate;
    }
}
