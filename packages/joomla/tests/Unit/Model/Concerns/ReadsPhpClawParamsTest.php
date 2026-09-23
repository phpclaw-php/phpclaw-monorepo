<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Tests\Unit\Model\Concerns;

use Joomla\CMS\Plugin\PluginHelper;
use PhpClaw\Joomla\Component\Administrator\Model\ChatModel;
use PhpClaw\Joomla\Component\Administrator\Model\Concerns\ReadsPhpClawParams;
use PhpClaw\Joomla\Component\Administrator\Model\GuideModel;
use PHPUnit\Framework\TestCase;

final class ReadsPhpClawParamsTest extends TestCase
{
    protected function setUp(): void
    {
        PluginHelper::$plugin = false;
    }

    protected function tearDown(): void
    {
        PluginHelper::$plugin = false;
    }

    public function test_store_messages_defaults_to_true_when_the_plugin_is_not_installed(): void
    {
        self::assertTrue($this->subject()->isStoreMessages());
    }

    public function test_store_messages_defaults_to_true_when_the_plugin_saved_no_value(): void
    {
        $this->installPluginParams([]);

        self::assertTrue($this->subject()->isStoreMessages());
    }

    public function test_store_messages_is_true_when_the_saved_value_is_on(): void
    {
        $this->installPluginParams(['store_messages' => '1']);

        self::assertTrue($this->subject()->isStoreMessages());
    }

    public function test_store_messages_is_false_when_the_saved_value_is_off(): void
    {
        $this->installPluginParams(['store_messages' => '0']);

        self::assertFalse($this->subject()->isStoreMessages());
    }

    public function test_store_messages_falls_back_to_the_default_when_the_saved_value_is_blank(): void
    {
        $this->installPluginParams(['store_messages' => '']);

        self::assertTrue($this->subject()->isStoreMessages());
    }

    public function test_has_cloud_key_is_false_when_the_plugin_is_not_installed(): void
    {
        self::assertFalse($this->subject()->hasCloudKey());
    }

    public function test_has_cloud_key_is_false_when_no_key_was_saved(): void
    {
        $this->installPluginParams(['cloud_key' => '']);

        self::assertFalse($this->subject()->hasCloudKey());
    }

    public function test_has_cloud_key_is_true_when_a_key_was_saved(): void
    {
        $this->installPluginParams(['cloud_key' => 'pc_live_0123456789']);

        self::assertTrue($this->subject()->hasCloudKey());
    }

    public function test_has_cloud_key_reads_cloud_key_and_not_the_provider_api_key(): void
    {
        $this->installPluginParams(['api_key' => 'sk-provider-key', 'cloud_key' => '']);

        self::assertFalse($this->subject()->hasCloudKey());
    }

    public function test_the_shipped_models_read_params_through_the_trait_and_never_reimplement_it(): void
    {
        foreach ([ChatModel::class, GuideModel::class] as $model) {
            $reflection = new \ReflectionClass($model);

            self::assertContains(
                ReadsPhpClawParams::class,
                $reflection->getTraitNames(),
                $model.' must read plugin params through the shared trait.',
            );

            foreach (['isStoreMessages', 'hasCloudKey'] as $method) {
                self::assertSame(
                    ReadsPhpClawParams::class,
                    $this->declaringTrait($reflection, $method),
                    $model.'::'.$method.'() must come from the trait; a local copy silently shadows the shared read.',
                );
            }
        }
    }

    private function declaringTrait(\ReflectionClass $class, string $method): string
    {
        foreach ($class->getTraits() as $trait) {
            if ($trait->hasMethod($method)
                && $trait->getMethod($method)->getFileName() === $class->getMethod($method)->getFileName()
                && $trait->getMethod($method)->getStartLine() === $class->getMethod($method)->getStartLine()
            ) {
                return $trait->getName();
            }
        }

        return $class->getName();
    }

    private function installPluginParams(array $params): void
    {
        PluginHelper::$plugin = (object) ['params' => json_encode($params)];
    }

    private function subject(): object
    {
        return new class
        {
            use ReadsPhpClawParams;
        };
    }
}
