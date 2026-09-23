<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Providers;

use PhpClaw\AutoDiscovery\ComposerExtras;
use PhpClaw\Providers\OpenAIProvider;
use PhpClaw\Providers\ProviderCatalogue;
use PHPUnit\Framework\TestCase;

final class ProviderCatalogueTest extends TestCase
{
    protected function tearDown(): void
    {
        ProviderCatalogue::reset();
    }

    public function test_all_returns_built_in_providers(): void
    {
        $all = ProviderCatalogue::all();

        $this->assertArrayHasKey('deepseek', $all);
        $this->assertSame('DeepSeek', $all['deepseek']['label']);
        $this->assertSame(OpenAIProvider::class, $all['deepseek']['class']);
    }

    public function test_all_includes_every_openai_compatible_preset(): void
    {
        $all = ProviderCatalogue::all();

        foreach (['openai', 'groq', 'deepseek', 'mistral', 'ollama', 'custom'] as $slug) {
            $this->assertArrayHasKey($slug, $all, "Preset '{$slug}' missing from catalogue.");
            $this->assertSame(OpenAIProvider::class, $all[$slug]['class']);
        }
    }

    public function test_openai_appears_exactly_once(): void
    {
        $openaiKeys = array_filter(ProviderCatalogue::keys(), fn (string $k): bool => $k === 'openai');

        $this->assertCount(1, $openaiKeys);
    }

    public function test_native_anthropic_and_gemini_remain_present(): void
    {
        $all = ProviderCatalogue::all();

        $this->assertArrayHasKey('anthropic', $all);
        $this->assertArrayHasKey('gemini', $all);
    }

    public function test_find_returns_provider_entry_by_key(): void
    {
        $entry = ProviderCatalogue::find('deepseek');

        $this->assertNotNull($entry);
        $this->assertSame('DeepSeek', $entry['label']);
        $this->assertSame(OpenAIProvider::class, $entry['class']);
    }

    public function test_find_returns_null_for_unknown_key(): void
    {
        $this->assertNull(ProviderCatalogue::find('nonexistent-provider'));
    }

    public function test_keys_returns_all_registered_provider_keys(): void
    {
        $keys = ProviderCatalogue::keys();

        $this->assertContains('deepseek', $keys);
    }

    public function test_register_adds_custom_provider(): void
    {
        ProviderCatalogue::register('test-custom', 'Test Custom', OpenAIProvider::class);

        $entry = ProviderCatalogue::find('test-custom');

        $this->assertNotNull($entry);
        $this->assertSame('Test Custom', $entry['label']);
    }

    public function test_reset_clears_custom_registrations_only(): void
    {
        ProviderCatalogue::register('test-x', 'X', OpenAIProvider::class);
        ProviderCatalogue::reset();

        $this->assertNull(ProviderCatalogue::find('test-x'));
        $this->assertNotNull(ProviderCatalogue::find('deepseek'));
    }

    public function test_boot_registers_discovered_provider(): void
    {
        ComposerExtras::withTestPayload([
            'alice/phpclaw-grok-provider' => [
                'providers' => [
                    'grok-test' => [
                        'label' => 'Grok (test)',
                        'class' => OpenAIProvider::class,
                    ],
                ],
            ],
        ]);

        ProviderCatalogue::boot();

        $entry = ProviderCatalogue::find('grok-test');
        $this->assertNotNull($entry);
        $this->assertSame('Grok (test)', $entry['label']);
        $this->assertSame(OpenAIProvider::class, $entry['class']);

        ComposerExtras::reset();
    }

    public function test_boot_skips_entry_with_missing_label(): void
    {
        ComposerExtras::withTestPayload([
            'broken/pkg' => [
                'providers' => [
                    'no-label' => ['class' => OpenAIProvider::class],
                ],
            ],
        ]);

        ProviderCatalogue::boot();

        $this->assertNull(ProviderCatalogue::find('no-label'));
        ComposerExtras::reset();
    }

    public function test_boot_skips_entry_with_nonexistent_class(): void
    {
        ComposerExtras::withTestPayload([
            'broken/pkg' => [
                'providers' => [
                    'ghost' => ['label' => 'Ghost', 'class' => 'Does\\Not\\Exist\\Provider'],
                ],
            ],
        ]);

        ProviderCatalogue::boot();

        $this->assertNull(ProviderCatalogue::find('ghost'));
        ComposerExtras::reset();
    }

    public function test_boot_skips_entry_whose_class_does_not_implement_provider_interface(): void
    {
        ComposerExtras::withTestPayload([
            'broken/pkg' => [
                'providers' => [
                    'bad-class' => ['label' => 'Bad', 'class' => \stdClass::class],
                ],
            ],
        ]);

        ProviderCatalogue::boot();

        $this->assertNull(ProviderCatalogue::find('bad-class'));
        ComposerExtras::reset();
    }

    public function test_boot_is_idempotent(): void
    {
        ComposerExtras::withTestPayload([
            'alice/pkg' => [
                'providers' => [
                    'idem' => ['label' => 'Idempotent', 'class' => OpenAIProvider::class],
                ],
            ],
        ]);

        ProviderCatalogue::boot();
        ProviderCatalogue::boot();
        ProviderCatalogue::boot();

        $countWithIdem = count(array_filter(
            array_keys(ProviderCatalogue::all()),
            fn (string $k): bool => $k === 'idem',
        ));

        $this->assertSame(1, $countWithIdem);
        ComposerExtras::reset();
    }

    public function test_boot_mixed_valid_and_invalid_entries(): void
    {
        ComposerExtras::withTestPayload([
            'mixed/pkg' => [
                'providers' => [
                    'valid' => ['label' => 'Valid', 'class' => OpenAIProvider::class],
                    'bad-cls' => ['label' => 'Bad', 'class' => 'Nope\\NotReal'],
                    'no-lbl' => ['class' => OpenAIProvider::class],
                    'wrong' => ['label' => 'Wrong', 'class' => \stdClass::class],
                ],
            ],
        ]);

        ProviderCatalogue::boot();

        $this->assertNotNull(ProviderCatalogue::find('valid'));
        $this->assertNull(ProviderCatalogue::find('bad-cls'));
        $this->assertNull(ProviderCatalogue::find('no-lbl'));
        $this->assertNull(ProviderCatalogue::find('wrong'));

        ComposerExtras::reset();
    }

    public function test_boot_with_empty_payload_does_not_fatal(): void
    {
        ComposerExtras::withTestPayload([]);

        ProviderCatalogue::boot();

        $this->assertNotNull(ProviderCatalogue::find('deepseek'));

        ComposerExtras::reset();
    }
}
