<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Tests\Feature\Durable;

use Illuminate\Foundation\Auth\User as Authenticatable;

final class DurableUser extends Authenticatable
{
    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];
}
