<?php

declare(strict_types=1);

namespace PhpClaw\Tools\Contracts;

/**
 * Implemented by every tool that can be handed an authorizer after construction, so the registry
 * binds one without asking whether the method exists.
 */
interface AuthorizableToolInterface
{
    /**
     * Bind the authorizer this tool consults before it runs.
     *
     * @param  ToolAuthorizerInterface|null  $authorizer  Authorizer to consult, or null to allow.
     * @return void
     */
    public function withAuthorizer(?ToolAuthorizerInterface $authorizer): void;
}
