<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Tests\Integration;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class ShippedConfigEnvBindingTest extends TestCase
{
    private const BOUND = [
        'PHPCLAW_PROVIDER' => ['provider', 'groq', ''],
        'PHPCLAW_MODEL' => ['model', 'probe-model', ''],
        'PHPCLAW_BASE_URL' => ['base_url', 'https://probe.example/v1', ''],
        'PHPCLAW_API_ENABLED' => ['api.enabled', 'false', true],
        'PHPCLAW_STORE_MESSAGES' => ['store_messages', 'false', true],
        'PHPCLAW_MAX_ITERATIONS' => ['max_iterations', '7', 20],
        'PHPCLAW_MAX_TOKENS' => ['max_tokens', '1234', 0],
        'PHPCLAW_MEMORY_DRIVER' => ['memory_driver', 'doctrine_conversation', 'doctrine'],
        'PHPCLAW_SYSTEM_PROMPT' => ['system_prompt', 'probe prompt', ''],
        'PHPCLAW_REMOTE_SKILL_URLS' => ['remote_skill_urls', 'https://a.example/s.md', ''],
        'PHPCLAW_CLOUD_KEY' => ['cloud_key', 'probe-key', ''],
        'PHPCLAW_CLOUD_SIGNING_SECRET' => ['cloud_signing_secret', 'probe-secret', ''],
    ];

    private const EXPECTED_WHEN_SET = [
        'provider' => 'groq',
        'model' => 'probe-model',
        'base_url' => 'https://probe.example/v1',
        'api.enabled' => false,
        'store_messages' => false,
        'max_iterations' => 7,
        'max_tokens' => 1234,
        'memory_driver' => 'doctrine_conversation',
        'system_prompt' => 'probe prompt',
        'remote_skill_urls' => 'https://a.example/s.md',
        'cloud_key' => 'probe-key',
        'cloud_signing_secret' => 'probe-secret',
    ];

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_shipped_config_keeps_every_default_when_no_environment_variable_is_set(): void
    {
        foreach (array_keys(self::BOUND) as $variable) {
            self::assertFalse(
                getenv($variable),
                "This control is only meaningful while {$variable} is absent.",
            );
        }

        $parameters = $this->bootAndRead('unset');

        foreach (self::BOUND as [$parameter, $_value, $default]) {
            self::assertSame($default, $parameters[$parameter], "phpclaw.{$parameter} lost its default");
        }
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_shipped_config_binds_every_environment_variable_the_guide_documents(): void
    {
        foreach (self::BOUND as $variable => [$_parameter, $value, $_default]) {
            putenv($variable.'='.$value);
            $_ENV[$variable] = $value;
        }

        $parameters = $this->bootAndRead('set');

        self::assertSame(self::EXPECTED_WHEN_SET, $parameters);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_tool_deny_stays_a_yaml_list_because_symfony_cannot_bind_it(): void
    {
        putenv('PHPCLAW_TOOL_DENY=shell_exec');
        $_ENV['PHPCLAW_TOOL_DENY'] = 'shell_exec';

        $kernel = new ShippedConfigKernel('tooldeny', false);
        $kernel->boot();
        $toolDeny = $kernel->getContainer()->getParameter('phpclaw.tool_deny');
        $kernel->shutdown();

        self::assertSame([], $toolDeny);
    }

    private function bootAndRead(string $environment): array
    {
        $kernel = new ShippedConfigKernel($environment, false);
        $kernel->boot();
        $container = $kernel->getContainer();

        $read = [];
        foreach (self::BOUND as [$parameter, $_value, $_default]) {
            $read[$parameter] = $container->getParameter('phpclaw.'.$parameter);
        }

        $kernel->shutdown();

        return $read;
    }
}
