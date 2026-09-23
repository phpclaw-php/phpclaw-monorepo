<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Providers;

use PhpClaw\Providers\OpenAIPresets;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OpenAIPresetsTest extends TestCase
{
    public function test_all_returns_the_expected_preset_slugs(): void
    {
        $this->assertSame(
            ['openai', 'groq', 'deepseek', 'mistral', 'ollama', 'custom'],
            array_keys(OpenAIPresets::all()),
        );
    }

    public function test_custom_preset_is_present(): void
    {
        $this->assertTrue(OpenAIPresets::has('custom'));
    }

    #[DataProvider('networkPresetProvider')]
    public function test_network_preset_rows_are_well_formed(string $slug): void
    {
        $preset = OpenAIPresets::find($slug);

        $this->assertNotNull($preset);
        $this->assertNotSame('', $preset['label']);
        $this->assertStringStartsWith('http', $preset['baseUrl']);
        $this->assertStringContainsString('/chat/completions', $preset['baseUrl']);
        $this->assertNotSame('', $preset['model']);
        $this->assertContains($preset['auth'], [OpenAIPresets::AUTH_BEARER, OpenAIPresets::AUTH_NONE]);
    }

    public function test_bearer_presets_declare_a_key_env(): void
    {
        foreach (OpenAIPresets::all() as $slug => $preset) {
            if ($slug === 'custom' || $preset['auth'] === OpenAIPresets::AUTH_NONE) {
                continue;
            }
            $this->assertNotSame('', $preset['keyEnv'], "Preset '{$slug}' must declare a keyEnv.");
        }
    }

    public function test_keyless_presets_have_no_key_env(): void
    {
        $ollama = OpenAIPresets::find('ollama');
        $this->assertNotNull($ollama);
        $this->assertSame(OpenAIPresets::AUTH_NONE, $ollama['auth']);
        $this->assertSame('', $ollama['keyEnv']);
    }

    public function test_custom_preset_defers_base_url_and_model_to_runtime(): void
    {
        $custom = OpenAIPresets::find('custom');

        $this->assertNotNull($custom);
        $this->assertSame('', $custom['baseUrl']);
        $this->assertSame('', $custom['model']);
    }

    public function test_has_returns_false_for_unknown_slug(): void
    {
        $this->assertFalse(OpenAIPresets::has('not-a-provider'));
    }

    public function test_find_returns_null_for_unknown_slug(): void
    {
        $this->assertNull(OpenAIPresets::find('not-a-provider'));
    }

    public static function networkPresetProvider(): array
    {
        return [
            'openai' => ['openai'],
            'groq' => ['groq'],
            'deepseek' => ['deepseek'],
            'mistral' => ['mistral'],
            'ollama' => ['ollama'],
        ];
    }
}
