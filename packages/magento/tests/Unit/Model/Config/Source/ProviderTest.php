<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Tests\Unit\Model\Config\Source;

use PhpClaw\Magento\Model\Config\Source\Provider;
use PhpClaw\Providers\ProviderCatalogue;
use PHPUnit\Framework\TestCase;

final class ProviderTest extends TestCase
{
    public function test_to_option_array_returns_at_least_eight_core_options(): void
    {
        $options = (new Provider)->toOptionArray();

        self::assertGreaterThanOrEqual(8, count($options));
    }

    public function test_to_option_array_never_contains_an_empty_value(): void
    {
        $values = array_column((new Provider)->toOptionArray(), 'value');

        self::assertNotContains('', $values);
    }

    public function test_to_option_array_is_sourced_from_the_provider_catalogue(): void
    {
        $expected = [];
        foreach (ProviderCatalogue::all() as $key => $info) {
            $expected[(string) $key] = (string) ($info['label'] ?? $key);
        }

        self::assertSame(
            $expected,
            array_column((new Provider)->toOptionArray(), 'label', 'value'),
        );
    }

    public function test_every_option_has_value_and_label_keys(): void
    {
        foreach ((new Provider)->toOptionArray() as $option) {
            self::assertArrayHasKey('value', $option);
            self::assertArrayHasKey('label', $option);
        }
    }

    public function test_fallback_list_returned_when_catalogue_absent(): void
    {
        $provider = new class extends Provider
        {
            protected function hasCatalogue(): bool
            {
                return false;
            }
        };

        self::assertSame([
            'anthropic' => 'Anthropic (Claude)',
            'openai' => 'OpenAI (GPT)',
            'groq' => 'Groq',
            'gemini' => 'Google Gemini',
            'mistral' => 'Mistral',
            'deepseek' => 'DeepSeek',
            'ollama' => 'Ollama (local)',
            'custom' => 'Custom (OpenAI-compatible)',
        ], array_column($provider->toOptionArray(), 'label', 'value'));
    }

    public function test_fallback_list_has_eight_entries(): void
    {
        $provider = new class extends Provider
        {
            protected function hasCatalogue(): bool
            {
                return false;
            }
        };

        self::assertCount(8, $provider->toOptionArray());
    }

    public function test_fallback_first_entry_is_anthropic(): void
    {
        $provider = new class extends Provider
        {
            protected function hasCatalogue(): bool
            {
                return false;
            }
        };

        $first = $provider->toOptionArray()[0];
        self::assertSame('anthropic', $first['value']);
        self::assertSame('Anthropic (Claude)', $first['label']);
    }
}
