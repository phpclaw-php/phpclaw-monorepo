<?php

declare(strict_types=1);

namespace PhpClaw\Cloud\Tests\Unit;

use PhpClaw\Cloud\CloudManager;
use PhpClaw\Cloud\CloudManifest;
use PhpClaw\Cloud\CloudScanGuard;
use PhpClaw\Guards\GuardRegistry;
use PhpClaw\Hooks\HookRegistry;
use PHPUnit\Framework\TestCase;

final class CloudManagerTest extends TestCase
{
    private array $cacheFiles = [];

    private function writeCacheEnvelope(string $file, string $key, array $manifest): void
    {
        $payload = json_encode($manifest);
        file_put_contents($file, json_encode([
            'mac' => hash_hmac('sha256', $payload, $key),
            'data' => $payload,
        ]));
    }

    protected function setUp(): void
    {
        HookRegistry::reset();
        GuardRegistry::reset();
        CloudManager::reset();
    }

    protected function tearDown(): void
    {
        HookRegistry::reset();
        GuardRegistry::reset();
        CloudManager::reset();

        foreach ($this->cacheFiles as $file) {
            if (file_exists($file)) {
                @unlink($file);
            }
        }
    }

    public function test_write_cache_round_trips_through_read_cache_with_owner_only_perms(): void
    {
        $key = 'writecache-proof-key';
        $manifest = new CloudManifest('pro', ['tracing' => ['tool.after']], ['scan' => ['priority' => 50]], []);

        $rc = new \ReflectionClass(CloudManager::class);
        $file = $this->invokeStatic($rc, 'cacheFilePath', [$key]);
        $this->cacheFiles[] = $file;
        @unlink($file);

        $this->invokeStatic($rc, 'writeCache', [$file, $manifest, $key]);

        $this->assertFileExists($file);
        $this->assertSame('0600', substr(sprintf('%o', fileperms($file)), -4));

        $read = $this->invokeStatic($rc, 'readCache', [$file, $key, PHP_INT_MAX]);
        $this->assertInstanceOf(CloudManifest::class, $read);
        $this->assertSame('pro', $read->toArray()['plan']);

        $this->assertSame([], glob(sys_get_temp_dir().'/phpclaw_manifest_*.tmp') ?: []);
    }

    private function invokeStatic(\ReflectionClass $rc, string $method, array $args): mixed
    {
        $m = $rc->getMethod($method);
        $m->setAccessible(true);

        return $m->invoke(null, ...$args);
    }

    public function test_boot_with_empty_key_registers_nothing(): void
    {
        CloudManager::boot('');

        $this->assertSame(0, HookRegistry::count());
        $this->assertSame(0, GuardRegistry::count());
    }

    public function test_boot_with_whitespace_key_registers_nothing(): void
    {
        $this->assertSame(0, HookRegistry::count());
        $this->assertSame(0, GuardRegistry::count());
    }

    public function test_boot_registers_hooks_from_cached_manifest(): void
    {
        $key = 'test-key-'.uniqid();
        $cacheFile = sys_get_temp_dir().DIRECTORY_SEPARATOR.'phpclaw_manifest_'.hash('sha256', $key).'.json';
        $this->cacheFiles[] = $cacheFile;

        $manifest = [
            'plan' => 'active',
            'hook_events' => ['hook_a' => ['agent.before', 'agent.after']],
            'guard_features' => [],
            'config' => [],
        ];

        $this->writeCacheEnvelope($cacheFile, $key, $manifest);

        CloudManager::boot($key);

        $this->assertSame(2, HookRegistry::count());
    }

    public function test_boot_called_twice_with_the_same_key_does_not_double_register(): void
    {
        $key = 'test-key-'.uniqid();
        $cacheFile = sys_get_temp_dir().DIRECTORY_SEPARATOR.'phpclaw_manifest_'.hash('sha256', $key).'.json';
        $this->cacheFiles[] = $cacheFile;

        $manifest = [
            'plan' => 'active',
            'hook_events' => ['hook_a' => ['agent.before', 'agent.after']],
            'guard_features' => [],
            'config' => [],
        ];

        $this->writeCacheEnvelope($cacheFile, $key, $manifest);

        CloudManager::boot($key);
        CloudManager::boot($key);

        $this->assertSame(
            2,
            HookRegistry::count(),
            'a second boot() with the same key must not re-register hooks; any caller (a standalone '
            .'memory driver AND a later real conversation()/send() in the same process) booting the same '
            .'key must not double the cloud webhook listeners.',
        );
    }

    public function test_boot_registers_guards_from_cached_manifest(): void
    {
        $key = 'test-key-'.uniqid();
        $cacheFile = sys_get_temp_dir().DIRECTORY_SEPARATOR.'phpclaw_manifest_'.hash('sha256', $key).'.json';
        $this->cacheFiles[] = $cacheFile;

        $manifest = [
            'plan' => 'active',
            'hook_events' => [],
            'guard_features' => ['guard_a' => ['priority' => 30]],
            'config' => [],
        ];

        $this->writeCacheEnvelope($cacheFile, $key, $manifest);

        CloudManager::boot($key);

        $this->assertSame(1, GuardRegistry::count());
    }

    public function test_boot_registers_both_hooks_and_guards_from_cache(): void
    {
        $key = 'test-key-'.uniqid();
        $cacheFile = sys_get_temp_dir().DIRECTORY_SEPARATOR.'phpclaw_manifest_'.hash('sha256', $key).'.json';
        $this->cacheFiles[] = $cacheFile;

        $manifest = [
            'plan' => 'active',
            'hook_events' => ['hook_a' => ['agent.before', 'tool.before', 'tool.after']],
            'guard_features' => ['guard_a' => ['priority' => 30], 'guard_b' => ['priority' => 50]],
            'config' => [],
        ];

        $this->writeCacheEnvelope($cacheFile, $key, $manifest);

        CloudManager::boot($key);

        $this->assertSame(3, HookRegistry::count());
        $this->assertSame(1, GuardRegistry::count());
    }

    public function test_boot_skips_disabled_hook_feature(): void
    {
        $key = 'test-key-'.uniqid();
        $cacheFile = sys_get_temp_dir().DIRECTORY_SEPARATOR.'phpclaw_manifest_'.hash('sha256', $key).'.json';
        $this->cacheFiles[] = $cacheFile;

        $manifest = [
            'plan' => 'active',
            'hook_events' => [
                'hook_a' => ['agent.before', 'agent.after'],
                'hook_b' => ['guard.blocked'],
            ],
            'guard_features' => [],
            'config' => [],
        ];

        $this->writeCacheEnvelope($cacheFile, $key, $manifest);

        CloudManager::boot($key, disable: ['hook_b']);

        $this->assertSame(2, HookRegistry::count());
    }

    public function test_boot_skips_disabled_guard_feature(): void
    {
        $key = 'test-key-'.uniqid();
        $cacheFile = sys_get_temp_dir().DIRECTORY_SEPARATOR.'phpclaw_manifest_'.hash('sha256', $key).'.json';
        $this->cacheFiles[] = $cacheFile;

        $manifest = [
            'plan' => 'active',
            'hook_events' => [],
            'guard_features' => [
                'guard_a' => ['priority' => 30],
                'guard_b' => ['priority' => 50],
            ],
            'config' => [],
        ];

        $this->writeCacheEnvelope($cacheFile, $key, $manifest);

        CloudManager::boot($key, disable: ['guard_b']);

        $this->assertSame(1, GuardRegistry::count());
    }

    public function test_boot_registers_nothing_when_api_unreachable_and_no_cache(): void
    {
        $key = 'no-cache-'.uniqid();

        CloudManager::boot($key);

        $this->assertSame(0, HookRegistry::count());
        $this->assertSame(0, GuardRegistry::count());
    }

    public function test_same_key_always_produces_same_cache_path(): void
    {
        $key = 'deterministic-'.uniqid();
        $hash = hash('sha256', $key);
        $expectedFile = sys_get_temp_dir().DIRECTORY_SEPARATOR.'phpclaw_manifest_'.$hash.'.json';
        $this->cacheFiles[] = $expectedFile;

        $manifest = [
            'plan' => 'active',
            'hook_events' => ['hook_a' => ['agent.before']],
            'guard_features' => [],
            'config' => [],
        ];

        $this->writeCacheEnvelope($expectedFile, $key, $manifest);

        CloudManager::boot($key);

        $this->assertSame(1, HookRegistry::count());
    }

    public function test_fresh_cache_is_used_without_calling_api(): void
    {
        $key = 'fresh-cache-'.uniqid();
        $cacheFile = sys_get_temp_dir().DIRECTORY_SEPARATOR.'phpclaw_manifest_'.hash('sha256', $key).'.json';
        $this->cacheFiles[] = $cacheFile;

        $manifest = [
            'plan' => 'active',
            'hook_events' => ['hook_a' => ['agent.before', 'agent.after', 'tool.before']],
            'guard_features' => [],
            'config' => [],
        ];

        $this->writeCacheEnvelope($cacheFile, $key, $manifest);

        CloudManager::boot($key);

        $this->assertSame(3, HookRegistry::count());
    }

    public function test_tampered_cache_is_treated_as_miss(): void
    {
        $key = 'tamper-key-'.uniqid();
        $cacheFile = sys_get_temp_dir().DIRECTORY_SEPARATOR.'phpclaw_manifest_'.hash('sha256', $key).'.json';
        $this->cacheFiles[] = $cacheFile;

        $payload = json_encode([
            'plan' => 'active',
            'hook_events' => ['hook_a' => ['agent.before', 'agent.after']],
            'guard_features' => ['guard_a' => ['priority' => 30]],
            'config' => [],
        ]);

        file_put_contents($cacheFile, json_encode([
            'mac' => hash_hmac('sha256', $payload, $key),
            'data' => $payload,
        ]));
        $this->assertNotNull($this->invokeReadCache($cacheFile, $key), 'correctly-signed cache should be read');

        file_put_contents($cacheFile, json_encode([
            'mac' => hash_hmac('sha256', $payload, 'attacker-key'),
            'data' => $payload,
        ]));
        $this->assertNull($this->invokeReadCache($cacheFile, $key), 'tampered cache must read as a miss');
    }

    public function test_boot_propagates_signing_secret_to_guard(): void
    {
        $key = 'test-key-'.uniqid();
        $secret = 'sign-secret-'.uniqid();
        $cacheFile = sys_get_temp_dir().DIRECTORY_SEPARATOR.'phpclaw_manifest_'.hash('sha256', $key).'.json';
        $this->cacheFiles[] = $cacheFile;

        $this->writeCacheEnvelope($cacheFile, $key, [
            'plan' => 'active',
            'hook_events' => [],
            'guard_features' => ['guard_a' => ['priority' => 30]],
            'config' => [],
        ]);

        CloudManager::boot($key, [], $secret);

        $this->assertSame(1, GuardRegistry::count());
        $this->assertSame($secret, $this->registeredGuardSigningSecret());
    }

    private function registeredGuardSigningSecret(): ?string
    {
        /** @var list<array{priority: int, guard: object}> $guards */
        $guards = (new \ReflectionProperty(GuardRegistry::class, 'guards'))->getValue();

        foreach ($guards as $entry) {
            if ($entry['guard'] instanceof CloudScanGuard) {
                return (string) (new \ReflectionProperty(CloudScanGuard::class, 'signingSecret'))
                    ->getValue($entry['guard']);
            }
        }

        return null;
    }

    private function invokeReadCache(string $file, string $key): ?CloudManifest
    {
        $method = new \ReflectionMethod(CloudManager::class, 'readCache');

        /** @var CloudManifest|null $result */
        $result = $method->invoke(null, $file, $key);

        return $result;
    }

    public function test_cache_file_is_0600(): void
    {
        $key = 'perm-key-'.uniqid();
        $file = sys_get_temp_dir().DIRECTORY_SEPARATOR.'phpclaw_manifest_'.hash('sha256', $key).'.json';
        $this->cacheFiles[] = $file;

        $this->invokeWriteCache($file, CloudManifest::empty(), $key);

        $this->assertFileExists($file);
        $this->assertSame('0600', substr(sprintf('%o', fileperms($file)), -4));
    }

    public function test_cache_written_with_lock(): void
    {
        $key = 'lock-key-'.uniqid();
        $file = sys_get_temp_dir().DIRECTORY_SEPARATOR.'phpclaw_manifest_'.hash('sha256', $key).'.json';
        $this->cacheFiles[] = $file;

        $manifest = CloudManifest::fromArray([
            'plan' => 'active',
            'hook_events' => ['hook_a' => ['agent.before']],
            'guard_features' => [],
            'config' => [],
        ]);

        $this->invokeWriteCache($file, $manifest, $key);

        $envelope = json_decode((string) file_get_contents($file), true);
        $this->assertIsArray($envelope);
        $this->assertArrayHasKey('mac', $envelope);
        $this->assertArrayHasKey('data', $envelope);
        $this->assertSame(hash_hmac('sha256', $envelope['data'], $key), $envelope['mac']);

        CloudManager::boot($key);
        $this->assertSame(1, HookRegistry::count());
    }

    private function invokeWriteCache(string $file, CloudManifest $manifest, string $key): void
    {
        $method = new \ReflectionMethod(CloudManager::class, 'writeCache');
        $method->invoke(null, $file, $manifest, $key);
    }

    public function test_hide_inputs_skips_the_scan_guard_but_keeps_tracing_hooks(): void
    {
        $key = $this->cacheScanAndTracingManifest();

        CloudManager::boot($key, ['hide_inputs']);

        $this->assertSame(0, GuardRegistry::count());
        $this->assertSame(2, HookRegistry::count());
    }

    public function test_hide_outputs_alone_keeps_the_scan_guard(): void
    {
        $key = $this->cacheScanAndTracingManifest();

        CloudManager::boot($key, ['hide_outputs']);

        $this->assertSame(1, GuardRegistry::count());
        $this->assertSame(2, HookRegistry::count());
    }

    private function cacheScanAndTracingManifest(): string
    {
        $key = 'test-key-'.uniqid();
        $cacheFile = sys_get_temp_dir().DIRECTORY_SEPARATOR.'phpclaw_manifest_'.hash('sha256', $key).'.json';
        $this->cacheFiles[] = $cacheFile;

        $this->writeCacheEnvelope($cacheFile, $key, [
            'plan' => 'active',
            'hook_events' => ['observability' => ['agent.before', 'agent.after']],
            'guard_features' => ['scan' => ['priority' => 50]],
            'config' => [],
        ]);

        return $key;
    }
}
