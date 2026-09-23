<?php

declare(strict_types=1);

namespace PhpClaw\Tools\Contracts;

use PhpClaw\Tools\ToolRoutingMetadata;

/**
 * Optional contract letting a tool declare whether it is eligible for the caller and advertise its
 * routing signals. Eligibility decides only what the model is shown, never what is allowed to run.
 */
interface ToolRoutingInterface
{
    /**
     * Report whether this tool should be offered to the model for the current caller.
     *
     * @return bool True when the tool may be exposed, false to hide it from the schema payload.
     */
    public function isEligibleForRouting(): bool;

    /**
     * Return the routing signals the router ranks this tool by.
     *
     * @return ToolRoutingMetadata
     */
    public function routingMetadata(): ToolRoutingMetadata;
}
