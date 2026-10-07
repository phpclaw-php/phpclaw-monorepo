<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Tests\Unit\Durable;

final class SwitchableUser
{
    public string $id = '';

    public bool $manageAll = false;
}
