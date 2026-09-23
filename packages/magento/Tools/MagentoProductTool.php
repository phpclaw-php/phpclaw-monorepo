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
 * Tool that fetches catalog product data via ResourceConnection.
 */
// non-final: Magento interceptor required
class MagentoProductTool extends AbstractMagentoResourceTool
{
    use HasToolExecutionContract;

    private const REQUIRED_CAPABILITY = 'PhpClaw_Magento::phpclaw_chat';

    private const DEFAULT_LIMIT = 20;

    private const MAX_LIMIT = 50;

    private const MIN_QUERY_LENGTH = 2;

    private const PRODUCT_ENTITY_TYPE_ID = 4;

    private const NOT_LOGGED_IN_CUSTOMER_GROUP_ID = 0;

    private const DEFAULT_STOCK_ID = 1;

    private const DEFAULT_STORE_ID = 0;

    /**
     * Bind the resource connection, identity resolver, and ACL service.
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
     * Return the ACL resource a caller must hold to fetch products.
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
            domains: ['commerce', 'catalog'],
            tags: ['product', 'products', 'item', 'items', 'sku', 'price', 'prices', 'catalog', 'catalogue', 'listing', 'attribute', 'attributes', 'configurable', 'simple', 'visibility'],
            intents: ['list products', 'find an item', 'show the catalog', 'what do we sell'],
            examples: ['list the products and their prices'],
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
        return 'magento_products';
    }

    /**
     * Returns the natural-language description shown to the LLM.
     *
     * @return string
     */
    public function description(): string
    {
        return 'FETCH Magento catalog products from the live database. Use for ANY question about products, pricing, or stock. Provide sku for a single product. Provide query to search by name or SKU. Omit both to list all products. NEVER guess product data, always invoke this tool.';
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
                    'description' => 'Exact product SKU. Returns full detail including price and stock.',
                ],
                'query' => [
                    'type' => 'string',
                    'description' => 'Search term matched against SKU and product name. Omit or leave empty to list all products.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum products to return (1-50, default 20).',
                    'default' => self::DEFAULT_LIMIT,
                    'minimum' => 1,
                    'maximum' => self::MAX_LIMIT,
                ],
            ],
        ];
    }

    /**
     * Guard the caller and validate the input before fetching products.
     *
     * @param  array<string, mixed>  $input  Runtime input.
     * @return array{input: array<string, mixed>, result: string|null}
     */
    protected function plan(array $input): array
    {
        $forbidden = $this->guardCapability('fetch Magento catalog products');

        if ($forbidden !== null) {
            return ['input' => $input, 'result' => $forbidden];
        }

        $unknown = $this->rejectUnknownArguments($input, ['sku', 'query', 'limit']);

        if ($unknown !== null) {
            return ['input' => $input, 'result' => $unknown];
        }

        $sku = trim((string) ($input['sku'] ?? ''));
        $query = trim((string) ($input['query'] ?? ''));

        if ($sku === '' && $query !== '' && mb_strlen($query) < self::MIN_QUERY_LENGTH) {
            return [
                'input' => $input,
                'result' => $this->error('INVALID_ARGUMENT', 'Search query must be at least 2 characters.'),
            ];
        }

        return ['input' => $input, 'result' => null];
    }

    /**
     * Fetch a single product by SKU or run a name/SKU search.
     *
     * @param  array<string, mixed>  $input  Validated runtime input.
     * @return array<string, mixed> Internal execution result.
     *
     * @throws ToolException When the SKU is provided but no matching product is found.
     */
    protected function perform(array $input): array
    {
        $sku = trim((string) ($input['sku'] ?? ''));
        $query = trim((string) ($input['query'] ?? ''));

        if ($sku !== '') {
            return $this->fetchBySku($sku);
        }

        return $this->search($query, $input);
    }

    /**
     * Confirm the execution result is structurally complete before packaging.
     *
     * @param  array<string, mixed>  $execution  Internal execution result.
     * @param  array<string, mixed>  $input  Validated input.
     * @return array{result: string|null}
     *
     * @throws ToolException When the execution result is structurally incomplete.
     */
    protected function verify(array $execution, array $input): array
    {
        $mode = (string) ($execution['mode'] ?? '');

        if ($mode === 'not_found') {
            return [
                'result' => $this->error(
                    'NOT_FOUND',
                    sprintf('No product exists with SKU "%s".', $execution['sku']),
                ),
            ];
        }

        if ($mode === 'sku_lookup' && ! is_array($execution['product'] ?? null)) {
            throw new ToolException('magento_products: incomplete SKU lookup result.');
        }

        if ($mode === 'search' && ! is_array($execution['products'] ?? null)) {
            throw new ToolException('magento_products: incomplete search result.');
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
        if ($execution['mode'] === 'sku_lookup') {
            return $this->success(
                ['product' => $execution['product']],
                ['sku' => $execution['sku'], 'mode' => 'sku_lookup'],
            );
        }

        $capped = $this->capRows($execution['products']);

        return $this->success(
            ['products' => $capped['rows']],
            [
                'total' => $capped['total'],
                'shown' => $capped['shown'],
                'truncated' => $capped['truncated'],
                'limit' => $execution['limit'],
                'query' => $execution['query'],
                'mode' => 'search',
            ],
        );
    }

    /**
     * Fetch full product detail (entity, name, price, stock) for the exact SKU.
     *
     * @param  string  $sku  Exact product SKU.
     * @return array<string, mixed> Execution result containing the product row and mode.
     *
     * @throws ToolException When no product matches the given SKU.
     */
    private function fetchBySku(string $sku): array
    {
        $tPrice = $this->resourceConnection->getTableName('catalog_product_index_price');

        $sql = $this->buildProductQuery(
            'e.entity_id, e.sku, e.type_id, e.created_at, e.updated_at,
             n.value AS name,
             p.final_price AS price, p.min_price,
             si.qty, si.is_in_stock, si.manage_stock, si.min_qty',
            'LEFT JOIN '.$tPrice.' p
               ON p.entity_id = e.entity_id AND p.customer_group_id = '.self::NOT_LOGGED_IN_CUSTOMER_GROUP_ID,
            'e.sku = ?',
            'LIMIT 1',
        );

        $rows = $this->resourceConnection->getConnection()->fetchAll($sql, [$sku]);

        if (empty($rows)) {
            return [
                'mode' => 'not_found',
                'sku' => $sku,
            ];
        }

        return [
            'mode' => 'sku_lookup',
            'sku' => $sku,
            'product' => $rows[0],
        ];
    }

    /**
     * Run a LIKE search against SKU and EAV product name; empty $query returns all products.
     *
     * @param  string  $query  Search term; empty string matches all products.
     * @param  array<string, mixed>  $input  Original input array used to extract the limit.
     * @return array<string, mixed> Execution result containing the product rows and mode.
     */
    private function search(string $query, array $input): array
    {
        $limit = isset($input['limit']) ? max(1, min((int) $input['limit'], self::MAX_LIMIT)) : self::DEFAULT_LIMIT;
        $pattern = '%'.$query.'%';

        $sql = $this->buildProductQuery(
            'e.entity_id, e.sku, e.type_id, e.created_at, n.value AS name, si.qty, si.is_in_stock',
            '',
            '(e.sku LIKE ? OR n.value LIKE ?)',
            'ORDER BY e.entity_id DESC LIMIT '.(int) $limit,
        );

        $rows = $this->resourceConnection->getConnection()->fetchAll($sql, [$pattern, $pattern]);

        return [
            'mode' => 'search',
            'products' => $rows,
            'query' => $query,
            'limit' => $limit,
        ];
    }

    /**
     * Build a parameterised SELECT over the product EAV and stock join skeleton.
     *
     * @param  string  $columns  Comma-separated column expressions.
     * @param  string  $extraJoins  Additional JOIN clauses (empty string = none).
     * @param  string  $where  WHERE clause body (without the WHERE keyword).
     * @param  string  $tail  ORDER BY / LIMIT suffix.
     * @return string Complete SQL SELECT string.
     */
    private function buildProductQuery(
        string $columns,
        string $extraJoins,
        string $where,
        string $tail,
    ): string {
        $tProductEntity = $this->resourceConnection->getTableName('catalog_product_entity');
        $tNameVarchar = $this->resourceConnection->getTableName('catalog_product_entity_varchar');
        $tStockItem = $this->resourceConnection->getTableName('cataloginventory_stock_item');
        $tEavAttribute = $this->resourceConnection->getTableName('eav_attribute');

        $nameSubquery = "(SELECT attribute_id FROM {$tEavAttribute}
                          WHERE attribute_code = 'name'
                            AND entity_type_id = ".self::PRODUCT_ENTITY_TYPE_ID.' LIMIT 1)';

        return "SELECT {$columns}
                FROM {$tProductEntity} e
                LEFT JOIN {$tNameVarchar} n
                  ON n.entity_id = e.entity_id AND n.store_id = ".self::DEFAULT_STORE_ID."
                 AND n.attribute_id = {$nameSubquery}
                LEFT JOIN {$tStockItem} si
                  ON si.product_id = e.entity_id AND si.stock_id = ".self::DEFAULT_STOCK_ID."
                {$extraJoins}
                WHERE {$where}
                {$tail}";
    }
}
