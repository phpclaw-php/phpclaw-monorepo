<?php

declare(strict_types=1);

namespace PhpClaw\Drupal\Tests\Kernel\Memory;

use Drupal\KernelTests\KernelTestBase;
use PhpClaw\Drupal\Memory\DrupalDbMemory;
use PHPUnit\Framework\Attributes\Group;

/**
 * @group phpclaw
 */
#[Group('phpclaw')]
final class DrupalDbMemoryKernelTest extends KernelTestBase
{
    /** @var string[] */
    protected static $modules = ['phpclaw', 'system'];

    private DrupalDbMemory $memory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installSchema('phpclaw', ['phpclaw_memory']);
        $this->memory = new DrupalDbMemory(\Drupal::database(), \Drupal::time());
    }

    public function test_it_stores_and_retrieves_a_value(): void
    {
        $this->memory->set('greeting', 'hello', 'default');

        $this->assertSame('hello', $this->memory->get('greeting', 'default'));
    }

    public function test_it_returns_null_for_missing_key(): void
    {
        $this->assertNull($this->memory->get('does-not-exist', 'default'));
    }

    public function test_it_updates_existing_key_on_set(): void
    {
        $this->memory->set('colour', 'blue', 'theme');
        $this->memory->set('colour', 'red', 'theme');

        $this->assertSame('red', $this->memory->get('colour', 'theme'));
    }

    public function test_it_stores_value_with_ttl(): void
    {
        $this->memory->set('tmp', 'value', 'default', ttl: 3600);

        $this->assertSame('value', $this->memory->get('tmp', 'default'));
    }

    public function test_it_returns_null_for_expired_entry(): void
    {
        $this->memory->set('exp', 'value', 'default', ttl: 1);

        \Drupal::database()->update('phpclaw_memory')
            ->fields(['expires_at' => date('Y-m-d H:i:s', time() - 3600)])
            ->condition('namespace', 'default')
            ->condition('key', 'exp')
            ->execute();

        $this->assertNull($this->memory->get('exp', 'default'));
    }

    public function test_it_deletes_a_single_key(): void
    {
        $this->memory->set('to-delete', 'bye', 'default');
        $this->memory->forget('to-delete', 'default');

        $this->assertNull($this->memory->get('to-delete', 'default'));
    }

    public function test_forget_does_not_affect_other_keys(): void
    {
        $this->memory->set('keep', 'yes', 'default');
        $this->memory->set('remove', 'no', 'default');

        $this->memory->forget('remove', 'default');

        $this->assertSame('yes', $this->memory->get('keep', 'default'));
    }

    public function test_flush_removes_all_keys_in_namespace(): void
    {
        $this->memory->set('a', '1', 'ns');
        $this->memory->set('b', '2', 'ns');
        $this->memory->set('c', '3', 'other');

        $this->memory->flush('ns');

        $this->assertNull($this->memory->get('a', 'ns'));
        $this->assertNull($this->memory->get('b', 'ns'));
        $this->assertSame('3', $this->memory->get('c', 'other'));
    }

    public function test_it_returns_all_active_entries_in_namespace(): void
    {
        $this->memory->set('x', 'X', 'letters');
        $this->memory->set('y', 'Y', 'letters');
        $this->memory->set('z', 'Z', 'other');

        $all = $this->memory->all('letters');

        $this->assertSame(['x' => 'X', 'y' => 'Y'], $all);
    }

    public function test_it_excludes_expired_entries_from_all(): void
    {
        $this->memory->set('live', 'yes', 'mix');
        $this->memory->set('dead', 'no', 'mix', ttl: 1);

        \Drupal::database()->update('phpclaw_memory')
            ->fields(['expires_at' => date('Y-m-d H:i:s', time() - 3600)])
            ->condition('namespace', 'mix')
            ->condition('key', 'dead')
            ->execute();

        $all = $this->memory->all('mix');

        $this->assertArrayHasKey('live', $all);
        $this->assertArrayNotHasKey('dead', $all);
    }

    public function test_it_returns_true_for_existing_key(): void
    {
        $this->memory->set('present', 'value', 'default');

        $this->assertTrue($this->memory->has('present', 'default'));
    }

    public function test_it_returns_false_for_missing_key(): void
    {
        $this->assertFalse($this->memory->has('absent', 'default'));
    }
}
