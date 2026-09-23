<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Memory;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Hooks\LifecycleEvent;
use PhpClaw\Support\Ulid;

/**
 * Query-builder memory driver (phpclaw_memory table) with TTL expiry and JSON-encoded values.
 */
final class DatabaseMemory extends AbstractDatabaseMemory
{
    private const TABLE = 'phpclaw_memory';

    private const DRIVER = 'database';

    /**
     * Retrieve a stored value by key and namespace, respecting TTL expiry.
     *
     * @param  string  $key
     * @param  string  $namespace
     * @return mixed
     */
    public function get(string $key, string $namespace = 'default'): mixed
    {
        return $this->guard('DatabaseMemory::get', function () use ($key, $namespace): mixed {
            $row = DB::table(self::TABLE)
                ->where('namespace', $namespace)
                ->where('lookup_key', $key)
                ->first();

            if ($row === null) {
                $this->fireRead($key, $namespace, hit: false);

                return null;
            }

            if ($row->expires_at !== null && Carbon::parse($row->expires_at, 'UTC')->lte(Carbon::now('UTC'))) {
                DB::table(self::TABLE)
                    ->where('namespace', $namespace)
                    ->where('lookup_key', $key)
                    ->delete();

                $this->fireRead($key, $namespace, hit: false);

                return null;
            }

            $this->fireRead($key, $namespace, hit: true);

            return json_decode($row->value, associative: true);
        });
    }

    /**
     * Persist a value under the given key and namespace, with an optional TTL in seconds.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @param  string  $namespace
     * @param  ?int  $ttl
     * @return void
     */
    public function set(string $key, mixed $value, string $namespace = 'default', ?int $ttl = null): void
    {
        $this->guard('DatabaseMemory::set', function () use ($key, $value, $namespace, $ttl): void {
            $now = Carbon::now('UTC');
            $expiresAt = $ttl !== null && $ttl > 0
                ? $now->copy()->addSeconds($ttl)->toDateTimeString()
                : null;

            DB::table(self::TABLE)->upsert(
                values: [
                    [
                        'id' => Ulid::generate(),
                        'namespace' => $namespace,
                        'lookup_key' => $key,
                        'value' => json_encode($value),
                        'expires_at' => $expiresAt,
                        'created_at' => $now->toDateTimeString(),
                        'updated_at' => $now->toDateTimeString(),
                    ],
                ],
                uniqueBy: ['namespace', 'lookup_key'],
                update: ['value', 'expires_at', 'updated_at'],
            );

            HookRegistry::fire(LifecycleEvent::MemoryWrite->value, [
                'key' => $key,
                'namespace' => $namespace,
                'driver' => self::DRIVER,
                'ttl' => $ttl,
            ]);
        });
    }

    /**
     * Remove a single key from the given namespace.
     *
     * @param  string  $key
     * @param  string  $namespace
     * @return void
     */
    public function forget(string $key, string $namespace = 'default'): void
    {
        $this->guard('DatabaseMemory::forget', function () use ($key, $namespace): void {
            DB::table(self::TABLE)
                ->where('namespace', $namespace)
                ->where('lookup_key', $key)
                ->delete();

            HookRegistry::fire(LifecycleEvent::MemoryForget->value, [
                'key' => $key,
                'namespace' => $namespace,
                'driver' => self::DRIVER,
            ]);
        });
    }

    /**
     * Delete all keys in the given namespace.
     *
     * @param  string  $namespace
     * @return void
     */
    public function flush(string $namespace = 'default'): void
    {
        $this->guard('DatabaseMemory::flush', function () use ($namespace): void {
            DB::table(self::TABLE)
                ->where('namespace', $namespace)
                ->delete();
        });
    }

    /**
     * Return all non-expired key-value pairs in the given namespace.
     *
     * @param  string  $namespace
     * @return array<string, mixed>
     */
    public function all(string $namespace = 'default'): array
    {
        return $this->guard('DatabaseMemory::all', function () use ($namespace): array {
            $rows = DB::table(self::TABLE)
                ->where('namespace', $namespace)
                ->where($this->notExpired())
                ->get(['lookup_key', 'value']);

            $result = [];
            foreach ($rows as $row) {
                $result[$row->lookup_key] = json_decode($row->value, associative: true);
            }

            return $result;
        });
    }

    /**
     * Return true when a non-expired entry exists for the given key and namespace.
     *
     * @param  string  $key
     * @param  string  $namespace
     * @return bool
     */
    public function has(string $key, string $namespace = 'default'): bool
    {
        return $this->guard('DatabaseMemory::has', function () use ($key, $namespace): bool {
            return DB::table(self::TABLE)
                ->where('namespace', $namespace)
                ->where('lookup_key', $key)
                ->where($this->notExpired())
                ->exists();
        });
    }

    /**
     * Closure constraining a query to non-expired rows (expires_at null or in the future).
     *
     * @return \Closure
     */
    private function notExpired(): \Closure
    {
        return static function ($query): void {
            $query->whereNull('expires_at')
                ->orWhere('expires_at', '>', Carbon::now('UTC')->toDateTimeString());
        };
    }

    /**
     * Fire the memory.read lifecycle event with hit/miss metadata.
     *
     * @param  string  $key
     * @param  string  $namespace
     * @param  bool  $hit
     * @return void
     */
    private function fireRead(string $key, string $namespace, bool $hit): void
    {
        HookRegistry::fire(LifecycleEvent::MemoryRead->value, [
            'key' => $key,
            'namespace' => $namespace,
            'driver' => self::DRIVER,
            'hit' => $hit,
        ]);
    }
}
