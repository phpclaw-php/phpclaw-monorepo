<?php

declare(strict_types=1);

namespace PhpClaw\OpenCart\Factory;

use PhpClaw\Contracts\ClawInterface;
use PhpClaw\OpenCart\Contracts\OcDbInterface;

/**
 * Contract for PhpClaw engine factories in the OpenCart adapter.
 */
interface PhpClawFactoryInterface
{
    /**
     * Build and return a configured PhpClaw engine instance.
     *
     * @param  string|null  $tablePrefix  OpenCart table prefix; defaults to DB_PREFIX, or 'oc_' when undefined.
     * @param  object|null  $registry  OpenCart Registry (for `phpclaw/extra/*` event hooks).
     * @param  OcDbInterface|null  $db  OC native DB handle (enables the 'opencart' memory driver).
     * @return ClawInterface
     */
    public function create(
        ?string $tablePrefix = null,
        ?object $registry = null,
        ?OcDbInterface $db = null,
    ): ClawInterface;
}
