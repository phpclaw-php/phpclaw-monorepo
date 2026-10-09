<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit;

use PhpClaw\Claw;
use PhpClaw\Cloud\CloudManager;
use PhpClaw\Providers\Contracts\ProviderInterface;
use PHPUnit\Framework\TestCase;

final class ClawCloudBootTest extends TestCase
{
    protected function setUp(): void
    {
        if (! class_exists(CloudManager::class)) {
            require_once __DIR__.'/Fixtures/CloudManager.php';
        }

        CloudManager::$boots = [];
    }

    private function provider(): ProviderInterface
    {
        $mock = $this->createMock(ProviderInterface::class);
        $mock->method('name')->willReturn('anthropic');
        $mock->method('model')->willReturn('claude-haiku-4-5-20251001');
        $mock->method('send')->willReturn(['type' => 'text', 'text' => 'ok', 'input_tokens' => 1, 'output_tokens' => 1]);

        return $mock;
    }

    private function claw(string $cloudKey, bool $storeMessages): Claw
    {
        return Claw::builder()
            ->providerOverride($this->provider())
            ->cloudKey($cloudKey)
            ->cloudDisable(['scan'])
            ->cloudSigningSecret('signing-secret')
            ->storeMessages($storeMessages)
            ->build();
    }

    public function test_a_cloud_key_with_store_messages_on_boots_cloud_once(): void
    {
        $claw = $this->claw('key-store-on', storeMessages: true);

        $claw->send('first');
        $claw->send('second');

        $this->assertSame([['key-store-on', ['scan'], 'signing-secret', false]], CloudManager::$boots);
    }

    public function test_store_messages_off_never_boots_cloud_even_with_a_cloud_key(): void
    {
        $this->claw('key-store-off', storeMessages: false)->send('private text');

        $this->assertSame([], CloudManager::$boots);
    }

    public function test_no_cloud_key_never_boots_cloud(): void
    {
        $this->claw('', storeMessages: true)->send('hello');

        $this->assertSame([], CloudManager::$boots);
    }
}
