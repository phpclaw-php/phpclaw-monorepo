<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Tools;

use Magento\Framework\App\ResourceConnection;
use PhpClaw\Magento\Service\JsonToolResult;
use PhpClaw\Tools\Contracts\ToolInterface;
use PhpClaw\Tools\Contracts\ToolRoutingInterface;
use PhpClaw\Tools\ToolRoutingMetadata;

/**
 * Base for Magento-native tools that read commerce data through ResourceConnection.
 */
// non-final: Magento interceptor required
abstract class AbstractMagentoResourceTool implements ToolInterface, ToolRoutingInterface
{
    use JsonToolResult;

    /**
     * Bind the Magento database connection provider shared by every resource tool.
     *
     * @param  ResourceConnection  $resourceConnection  Magento DB connection provider.
     * @return void
     */
    public function __construct(
        protected readonly ResourceConnection $resourceConnection,
    ) {}

    /**
     * Whether this tool may be offered to the model. Magento evaluates ACL when the tool runs, so every tool stays eligible for routing.
     *
     * @return bool Always true; execution-time checks remain the authority.
     */
    public function isEligibleForRouting(): bool
    {
        return true;
    }

    /**
     * Return the routing signals the router ranks this tool by; subclasses override with their own domains, tags and intents.
     *
     * @return ToolRoutingMetadata Empty by default.
     */
    public function routingMetadata(): ToolRoutingMetadata
    {
        return ToolRoutingMetadata::empty();
    }
}
