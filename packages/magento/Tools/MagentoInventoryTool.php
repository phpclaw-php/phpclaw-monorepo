<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Tools;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\AuthorizationInterface;
use PhpClaw\Exceptions\ToolException;
use PhpClaw\Magento\Model\IdentityResolver;
use PhpClaw\Magento\Tools\Concerns\HasToolExecutionContract;
use PhpClaw\Tools\ToolRoutingMetadata;

/**
 * Tool that queries inventory levels via ResourceConnection.
 */
// non-final: Magento interceptor required
class MagentoInventoryTool extends AbstractMagentoResourceTool
{
    use HasToolExecutionContract;

    private const REQUIRED_CAPABILITY = 'PhpClaw_Magento::phpclaw_chat';

    private const DEFAULT_THRESHOLD = 0;

    private const DEFAULT_LIMIT = 20;

    private const MAX_LIMIT = 100;

    private const DEFAULT_STOCK_ID = 1;

    private const MANAGED_STOCK = 1;

    /**
     * Bind the database connection, identity resolver and ACL service used to guard and query inventory.
     *
     * @param  ResourceConnection  $resourceConnection  Magento DB connection provider.
     * @param  IdentityResolver  $identityResolver  Resolver reporting the area and the acting admin identity.
     * @param  AuthorizationInterface  $acl  Magento authorization service.
     * @return void
     */
    public function __construct(
        ResourceConnection $resourceConnection,
        private readonly IdentityResolver $identityResolver,
        private readonly AuthorizationInterface $acl,
    ) {
        parent::__construct($resourceConnection);
    }

    /**
     * Return the ACL resource a caller must hold to query inventory.
     *
     * @return string
     */
    public function requiredCapability(): string
    {
        return self::REQUIRED_CAPABILITY;
    }

    /**
     * Return the routing signals the router ranks this tool by.
     *
     * @return ToolRoutingMetadata Domains, tags, intents and examples for this tool.
     */
    public function routingMetadata(): ToolRoutingMetadata
    {
        return new ToolRoutingMetadata(
            domains: ['commerce', 'inventory'],
            tags: ['inventory', 'stock', 'quantity', 'qty', 'salable', 'available', 'source', 'sources', 'reservation', 'low', 'levels', 'remaining', 'backorder'],
            intents: ['check stock', 'show inventory levels', 'what is running low'],
            examples: ['which products are low on stock'],
        );
    }

    /**
     * Return the identity resolver the contract trait reads the area from.
     *
     * @return IdentityResolver
     */
    protected function identity(): IdentityResolver
    {
        return $this->identityResolver;
    }

    /**
     * Return the ACL service the contract trait checks capabilities against.
     *
     * @return AuthorizationInterface
     */
    protected function authorization(): AuthorizationInterface
    {
        return $this->acl;
    }

    /**
     * Returns the tool identifier used in agent tool dispatch.
     *
     * @return string
     */
    public function name(): string
    {
        return 'magento_inventory';
    }

    /**
     * Returns the natural-language description shown to the LLM.
     *
     * @return string
     */
    public function description(): string
    {
        return 'CHECK Magento product stock. Provide sku for a single product\'s stock details. Omit sku and set threshold to list all products at or below that stock level (default 0 = out-of-stock). Invoke it, never guess inventory.';
    }

    /**
     * Returns the JSON Schema object describing accepted inputs.
     *
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'sku' => [
                    'type' => 'string',
                    'description' => 'Exact product SKU to check stock for.',
                ],
                'threshold' => [
                    'type' => 'integer',
                    'description' => 'Return products with qty <= this value (default 0 = out-of-stock only).',
                    'default' => self::DEFAULT_THRESHOLD,
                    'minimum' => 0,
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum products to return for a threshold scan (1-100, default 20)',
                    'default' => self::DEFAULT_LIMIT,
                    'minimum' => 1,
                    'maximum' => self::MAX_LIMIT,
                ],
            ],
        ];
    }

    /**
     * Guard the caller, reject unknown arguments and decide the operation that can safely run.
     *
     * @param  array<string, mixed>  $input  Runtime input.
     * @return array{input: array<string, mixed>, result: string|null}
     */
    protected function plan(array $input): array
    {
        $forbidden = $this->guardCapability('check Magento inventory');

        if ($forbidden !== null) {
            return ['input' => $input, 'result' => $forbidden];
        }

        $unknown = $this->rejectUnknownArguments($input, ['sku', 'threshold', 'limit']);

        if ($unknown !== null) {
            return ['input' => $input, 'result' => $unknown];
        }

        return ['input' => $input, 'result' => null];
    }

    /**
     * Query inventory for a single SKU or run a low-stock scan, returning an internal execution result.
     *
     * @param  array<string, mixed>  $input  Validated runtime input.
     * @return array<string, mixed> Internal execution result.
     *
     * @throws ToolException When the requested SKU has no stock record.
     */
    protected function perform(array $input): array
    {
        $sku = trim((string) ($input['sku'] ?? ''));

        if ($sku !== '') {
            return $this->lookupBySku($sku);
        }

        return $this->scanLowStock($input);
    }

    /**
     * Confirm the execution result is structurally complete before it becomes the model response.
     *
     * @param  array<string, mixed>  $execution  Internal execution result.
     * @param  array<string, mixed>  $input  Validated input.
     * @return array{result: string|null}
     *
     * @throws ToolException When the execution result is missing a required key.
     */
    protected function verify(array $execution, array $input): array
    {
        if ($execution['mode'] === 'not_found') {
            return [
                'result' => $this->error(
                    'NOT_FOUND',
                    sprintf('No stock record exists for SKU "%s".', $execution['sku']),
                ),
            ];
        }

        if ($execution['mode'] === 'sku' && ! is_array($execution['row'] ?? null)) {
            throw new ToolException('magento_inventory: incomplete SKU result.');
        }

        if ($execution['mode'] === 'list' && ! is_array($execution['rows'] ?? null)) {
            throw new ToolException('magento_inventory: incomplete list result.');
        }

        return ['result' => null];
    }

    /**
     * Convert the verified execution into the public response envelope.
     *
     * @param  array<string, mixed>  $execution  Verified execution result.
     * @param  array<string, mixed>  $input  Validated input.
     * @return string JSON-encoded response envelope.
     */
    protected function complete(array $execution, array $input): string
    {
        if ($execution['mode'] === 'sku') {
            return $this->success(
                $execution['row'],
                ['mode' => 'sku', 'sku' => $execution['sku']],
            );
        }

        $capped = $this->capRows($execution['rows']);

        return $this->success(
            ['items' => $capped['rows']],
            [
                'mode' => 'list',
                'total' => $capped['total'],
                'shown' => $capped['shown'],
                'truncated' => $capped['truncated'],
                'threshold' => $execution['threshold'],
                'limit' => $execution['limit'],
            ],
        );
    }

    /**
     * Fetch qty, is_in_stock, manage_stock, min_qty, notify_stock_qty, backorders and use_config_manage_stock for one SKU.
     *
     * @param  string  $sku  Exact product SKU.
     * @return array<string, mixed> Internal execution array in SKU mode.
     *
     * @throws ToolException When no stock record exists for the given SKU.
     */
    private function lookupBySku(string $sku): array
    {
        $tStockItem = $this->resourceConnection->getTableName('cataloginventory_stock_item');
        $tProduct = $this->resourceConnection->getTableName('catalog_product_entity');

        $rows = $this->resourceConnection->getConnection()->fetchAll(
            "SELECT e.sku,
                    si.qty, si.is_in_stock, si.manage_stock,
                    si.min_qty, si.notify_stock_qty, si.backorders, si.use_config_manage_stock
             FROM {$tStockItem} si
             JOIN {$tProduct} e ON e.entity_id = si.product_id
             WHERE e.sku    = ?
               AND si.stock_id = ".self::DEFAULT_STOCK_ID.'
             LIMIT 1',
            [$sku],
        );

        if (empty($rows)) {
            return [
                'mode' => 'not_found',
                'sku' => $sku,
            ];
        }

        return [
            'mode' => 'sku',
            'sku' => $sku,
            'row' => $rows[0],
        ];
    }

    /**
     * Return managed SKUs with qty at or below the threshold, ordered by qty ascending.
     *
     * @param  array<string, mixed>  $input  Runtime input containing optional threshold and limit.
     * @return array<string, mixed> Internal execution array in list mode.
     */
    private function scanLowStock(array $input): array
    {
        $threshold = isset($input['threshold']) ? max(0, (int) $input['threshold']) : self::DEFAULT_THRESHOLD;
        $limit = isset($input['limit']) ? max(1, min((int) $input['limit'], self::MAX_LIMIT)) : self::DEFAULT_LIMIT;

        $tStockItem = $this->resourceConnection->getTableName('cataloginventory_stock_item');
        $tProduct = $this->resourceConnection->getTableName('catalog_product_entity');

        $rows = $this->resourceConnection->getConnection()->fetchAll(
            "SELECT e.sku, si.qty, si.is_in_stock, si.manage_stock, si.min_qty
             FROM {$tStockItem} si
             JOIN {$tProduct} e ON e.entity_id = si.product_id
             WHERE si.stock_id     = ".self::DEFAULT_STOCK_ID.'
               AND si.manage_stock = '.self::MANAGED_STOCK.'
               AND si.qty         <= ?
             ORDER BY si.qty ASC
             LIMIT '.(int) $limit,
            [$threshold],
        );

        return [
            'mode' => 'list',
            'threshold' => $threshold,
            'limit' => $limit,
            'rows' => $rows,
        ];
    }
}
