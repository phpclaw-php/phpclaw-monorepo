<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Tests\Feature\Durable;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use PhpClaw\Laravel\LaravelIdentityResolver;
use PhpClaw\Laravel\PhpClawServiceProvider;

final class ActingAsTest extends TestCase
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [PhpClawServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('auth.providers.users.model', DurableUser::class);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('users', static function (Blueprint $table): void {
            $table->increments('id');
        });
        DurableUser::query()->create(['id' => 7]);
        DurableUser::query()->create(['id' => 8]);
    }

    public function test_work_runs_as_the_given_user_and_the_previous_user_comes_back(): void
    {
        Auth::setUser(DurableUser::query()->find(8));

        $inside = LaravelIdentityResolver::actingAs('7', static fn (): string => LaravelIdentityResolver::actingUserId());

        self::assertSame('7', $inside);
        self::assertSame('8', LaravelIdentityResolver::actingUserId());
    }

    public function test_with_nobody_logged_in_no_user_is_left_behind(): void
    {
        $inside = LaravelIdentityResolver::actingAs('7', static fn (): string => LaravelIdentityResolver::actingUserId());

        self::assertSame('7', $inside);
        self::assertSame('', LaravelIdentityResolver::actingUserId());
    }

    public function test_an_empty_user_id_runs_the_work_unchanged(): void
    {
        Auth::setUser(DurableUser::query()->find(8));

        self::assertSame('8', LaravelIdentityResolver::actingAs('', static fn (): string => LaravelIdentityResolver::actingUserId()));
    }
}
