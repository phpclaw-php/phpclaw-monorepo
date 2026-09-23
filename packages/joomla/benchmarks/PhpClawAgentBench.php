<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Benchmarks;

use Joomla\Registry\Registry;
use PhpClaw\Joomla\Component\Administrator\Engine\PhpClawConfig;

/**
 * @BeforeMethods({"setUp"})
 *
 * @Iterations(3)
 *
 * @Revs(50)
 */
final class PhpClawAgentBench
{
    private Registry $full;

    private Registry $empty;

    /**
     * Build the saved-params shapes PhpClawConfig is asked to parse on every engine boot.
     *
     * @return void
     */
    public function setUp(): void
    {
        $this->full = new Registry([
            'provider' => 'anthropic',
            'model' => 'claude-haiku-4-5-20251001',
            'api_key' => 'sk-ant-benchmark-key',
            'base_url' => '',
            'store_messages' => '1',
            'system_prompt' => 'You are a helpful assistant for this Joomla site.',
            'max_iterations' => '20',
            'shell_allowlist' => 'ls,pwd,cat,head,tail,grep,wc',
            'remote_skill_urls' => "https://example.test/skills.json\nhttps://example.test/SKILL.md",
            'cloud_key' => '',
            'cloud_disable' => '',
            'tool_deny' => 'group:content,shell_exec',
        ]);
        $this->empty = new Registry([]);
    }

    /**
     * @Subject
     *
     * @return void
     */
    public function bench_config_from_populated_params(): void
    {
        PhpClawConfig::fromRegistry($this->full);
    }

    /**
     * @Subject
     *
     * @return void
     */
    public function bench_config_from_empty_params(): void
    {
        PhpClawConfig::fromRegistry($this->empty);
    }

    /**
     * @Subject
     *
     * @return void
     */
    public function bench_provider_url_allowlist_check(): void
    {
        PhpClawConfig::isAllowedProviderUrl('https://api.openai.com/v1/chat/completions');
        PhpClawConfig::isAllowedProviderUrl('http://169.254.169.254/latest/meta-data/');
    }

    /**
     * @Subject
     *
     * @return void
     */
    public function bench_remote_skill_url_filtering(): void
    {
        PhpClawConfig::filterRemoteSkillUrls(
            "https://example.test/a.json\nhttp://example.test/b.md\nnot-a-url\nhttps://example.test/c.md"
        );
    }
}
