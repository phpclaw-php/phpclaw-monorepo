<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Tests\Unit\Field;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Language\Text;
use PhpClaw\Joomla\Field\ProvidersField;
use PHPUnit\Framework\TestCase;

final class ProvidersFieldTest extends TestCase
{
    private array $inlineScripts = [];

    protected function setUp(): void
    {
        $scripts = &$this->inlineScripts;
        Factory::$application = new class($scripts)
        {
            public function __construct(private array &$scripts) {}

            public function getDocument(): object
            {
                $scripts = &$this->scripts;

                return new class($scripts)
                {
                    public function __construct(private array &$scripts) {}

                    public function getWebAssetManager(): object
                    {
                        $scripts = &$this->scripts;

                        return new class($scripts)
                        {
                            public function __construct(private array &$scripts) {}

                            public function addInlineScript(string $content): static
                            {
                                $this->scripts[] = $content;

                                return $this;
                            }
                        };
                    }
                };
            }
        };
    }

    protected function tearDown(): void
    {
        Factory::$application = null;
        Text::$strings = [];
    }

    public function test_both_dropdowns_show_the_core_provider_names_even_when_the_language_names_them_differently(): void
    {
        Text::$strings = ['PLG_SYSTEM_PHPCLAW_PROVIDER_GROQ' => 'Groq (Llama)', 'PLG_SYSTEM_PHPCLAW_PROVIDER_OLLAMA' => 'Ollama (local / self-hosted)'];

        $primary = array_column($this->options('<field name="provider" type="providers"/>', ''), 'text', 'value');
        $fallback = array_column($this->options($this->fallbackXml(), 'ollama'), 'text', 'value');
        $field = $this->field($this->fallbackXml(), 'ollama');
        (new \ReflectionMethod($field, 'getInput'))->invoke($field);

        self::assertSame('Groq', $primary['groq']);
        self::assertSame('Ollama (Local)', $primary['ollama']);
        self::assertSame('Groq', $fallback['groq']);
        self::assertSame('Ollama (Local)', $fallback['ollama']);
        self::assertStringContainsString('"groq":"Groq"', $this->inlineScripts[0]);
    }

    public function test_the_primary_dropdown_lists_every_provider_except_the_excluded_ones(): void
    {
        $values = array_column($this->options('<field name="provider" type="providers"/>', ''), 'value');

        self::assertContains('anthropic', $values);
        self::assertContains('ollama', $values);
        self::assertContains('custom', $values);
        self::assertSame('', $values[0]);
    }

    public function test_a_following_dropdown_offers_off_and_only_providers_with_the_primary_tool_format(): void
    {
        $options = $this->options($this->fallbackXml(), 'ollama');

        self::assertSame(['', 'gemini', 'openai', 'groq', 'deepseek', 'mistral', 'ollama'], array_column($options, 'value'));
        self::assertSame('PLG_SYSTEM_PHPCLAW_FALLBACK_OFF', $options[0]->text);
    }

    public function test_an_anthropic_primary_offers_only_anthropic(): void
    {
        self::assertSame(['', 'anthropic'], array_column($this->options($this->fallbackXml(), 'anthropic'), 'value'));
    }

    public function test_a_custom_primary_offers_the_openai_compatible_providers(): void
    {
        self::assertSame(['', 'gemini', 'openai', 'groq', 'deepseek', 'mistral', 'ollama'], array_column($this->options($this->fallbackXml(), 'custom'), 'value'));
    }

    public function test_an_empty_primary_follows_the_auto_detected_provider(): void
    {
        $env = getenv('PHPCLAW_PROVIDER');
        putenv('PHPCLAW_PROVIDER=openai');

        try {
            $values = array_column($this->options($this->fallbackXml(), ''), 'value');
        } finally {
            $env === false ? putenv('PHPCLAW_PROVIDER') : putenv("PHPCLAW_PROVIDER={$env}");
        }

        self::assertContains('groq', $values);
        self::assertNotContains('anthropic', $values);
    }

    public function test_a_following_dropdown_adds_a_script_that_rebuilds_it_from_the_primary(): void
    {
        $field = $this->field($this->fallbackXml(), 'ollama');
        $html = (new \ReflectionMethod($field, 'getInput'))->invoke($field);

        self::assertStringContainsString('id="jform_params_fallback_provider"', $html);
        self::assertCount(1, $this->inlineScripts);
        self::assertStringStartsWith("document.addEventListener('DOMContentLoaded'", trim($this->inlineScripts[0]));
        self::assertStringContainsString('"primary":"jform_params_provider"', $this->inlineScripts[0]);
        self::assertStringContainsString('"fallback":"jform_params_fallback_provider"', $this->inlineScripts[0]);
        self::assertStringContainsString('"deepseek":"openai"', $this->inlineScripts[0]);
        self::assertStringContainsString('"custom":"openai"', $this->inlineScripts[0]);
        self::assertStringContainsString('"ollama":"openai"', $this->inlineScripts[0]);
        self::assertStringNotContainsString('"custom":"Custom', $this->inlineScripts[0]);
    }

    public function test_the_primary_dropdown_adds_no_script(): void
    {
        $field = $this->field('<field name="provider" type="providers"/>', '');
        (new \ReflectionMethod($field, 'getInput'))->invoke($field);

        self::assertSame([], $this->inlineScripts);
    }

    private function fallbackXml(): string
    {
        return '<field name="fallback_provider" type="providers" exclude="custom" follow="provider"/>';
    }

    private function options(string $xml, string $primary): array
    {
        $field = $this->field($xml, $primary);

        return (new \ReflectionMethod($field, 'getOptions'))->invoke($field);
    }

    private function field(string $xml, string $primary): ProvidersField
    {
        $form = new Form;
        $form->values['params']['provider'] = $primary;
        $primaryField = new ProvidersField;
        $primaryField->setup(new \SimpleXMLElement('<field name="provider" type="providers"/>'), $primary, 'params');
        $form->fields['params']['provider'] = $primaryField;

        $field = new ProvidersField;
        $field->setForm($form);
        $field->setup(new \SimpleXMLElement($xml), '', 'params');

        return $field;
    }
}
