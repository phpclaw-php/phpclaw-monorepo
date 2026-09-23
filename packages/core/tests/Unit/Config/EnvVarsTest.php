<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Config;

use PhpClaw\Config\EnvVars;
use PHPUnit\Framework\TestCase;

final class EnvVarsTest extends TestCase
{
    public function test_all_returns_every_declared_constant(): void
    {
        $names = EnvVars::all();

        $this->assertContains(EnvVars::PHPCLAW_PROVIDER, $names);
        $this->assertContains(EnvVars::PHPCLAW_MODEL, $names);
        $this->assertContains(EnvVars::ANTHROPIC_API_KEY, $names);
        $this->assertContains(EnvVars::OPENAI_API_KEY, $names);
        $this->assertContains(EnvVars::GROQ_API_KEY, $names);
        $this->assertContains(EnvVars::GEMINI_API_KEY, $names);
        $this->assertContains(EnvVars::MISTRAL_API_KEY, $names);
        $this->assertContains(EnvVars::OLLAMA_HOST, $names);
        $this->assertContains(EnvVars::PHPCLAW_HTTP_TIMEOUT, $names);
        $this->assertContains(EnvVars::PHPCLAW_HTTP_CONNECT_TIMEOUT, $names);
    }

    public function test_all_returns_only_strings(): void
    {
        foreach (EnvVars::all() as $name) {
            $this->assertIsString($name);
            $this->assertNotEmpty($name);
        }
    }

    public function test_all_entries_are_unique(): void
    {
        $names = EnvVars::all();
        $this->assertCount(count($names), array_unique($names));
    }
}
