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
 * Tool that explores the category tree and product assignments.
 */
// non-final: Magento interceptor required
class MagentoCategoryTool extends AbstractMagentoResourceTool
{
    use HasToolExecutionContract;

    private const REQUIRED_CAPABILITY = 'PhpClaw_Magento::phpclaw_chat';

    private const DEFAULT_ROOT_CATEGORY = 2;

    private const CATEGORY_ENTITY_TYPE = 3;

    private const PRODUCT_ENTITY_TYPE = 4;

    private const DEFAULT_STORE_ID = 0;

    private const DEFAULT_PRODUCT_LIMIT = 50;

    private const MAX_PRODUCT_LIMIT = 200;

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
     * Return the ACL resource a caller must hold to explore categories.
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
            tags: ['category', 'categories', 'department', 'departments', 'collection', 'collections', 'section', 'tree', 'parent', 'child', 'anchor'],
            intents: ['list categories', 'show departments', 'what collections exist'],
            examples: ['list the product categories'],
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
        return 'magento_categories';
    }

    /**
     * Returns the natural-language description shown to the LLM.
     *
     * @return string
     */
    public function description(): string
    {
        return 'EXPLORE the Magento category tree. Omit all params to list top-level categories. Provide parent_id to list children. Provide category_id + show_products=true to list products in that category. Invoke it, never guess category structure.';
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
                'category_id' => [
                    'type' => 'integer',
                    'description' => 'Category entity_id. Provide alone to get category details, or with show_products=true to list assigned products.',
                ],
                'parent_id' => [
                    'type' => 'integer',
                    'description' => 'List immediate children of this parent (default 2 = root).',
                ],
                'show_products' => [
                    'type' => 'boolean',
                    'description' => 'When true and category_id is set, return assigned products instead of category details.',
                    'default' => false,
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Max products to return when show_products=true (1-200, default 50)',
                    'default' => self::DEFAULT_PRODUCT_LIMIT,
                    'minimum' => 1,
                    'maximum' => self::MAX_PRODUCT_LIMIT,
                ],
            ],
        ];
    }

    /**
     * Guard the caller and reject unknown arguments before exploring the category tree.
     *
     * @param  array<string, mixed>  $input  Runtime input.
     * @return array{input: array<string, mixed>, result: string|null}
     */
    protected function plan(array $input): array
    {
        $forbidden = $this->guardCapability('explore the Magento category tree');

        if ($forbidden !== null) {
            return ['input' => $input, 'result' => $forbidden];
        }

        $unknown = $this->rejectUnknownArguments($input, ['category_id', 'parent_id', 'show_products', 'limit']);

        if ($unknown !== null) {
            return ['input' => $input, 'result' => $unknown];
        }

        return ['input' => $input, 'result' => null];
    }

    /**
     * Dispatch to the products path or the children path based on the validated input.
     *
     * @param  array<string, mixed>  $input  Validated runtime input.
     * @return array<string, mixed> Internal execution result.
     */
    protected function perform(array $input): array
    {
        $categoryId = isset($input['category_id']) ? (int) $input['category_id'] : 0;
        $parentId = isset($input['parent_id']) ? (int) $input['parent_id'] : 0;
        $showProducts = (bool) ($input['show_products'] ?? false);

        if ($categoryId > 0 && $showProducts) {
            return $this->fetchProducts($categoryId, $input);
        }

        $resolvedParentId = $parentId > 0 ? $parentId : ($categoryId > 0 ? $categoryId : self::DEFAULT_ROOT_CATEGORY);

        return $this->fetchChildren($resolvedParentId);
    }

    /**
     * Confirm the execution result contains the required rows array before packaging.
     *
     * @param  array<string, mixed>  $execution  Internal execution result.
     * @param  array<string, mixed>  $input  Validated input.
     * @return array{result: string|null}
     *
     * @throws ToolException When the execution result is missing the rows array.
     */
    protected function verify(array $execution, array $input): array
    {
        if (! is_array($execution['rows'] ?? null)) {
            throw new ToolException('magento_categories: incomplete result.');
        }

        return ['result' => null];
    }

    /**
     * Convert the verified execution into the public response envelope, capping rows to the byte budget.
     *
     * @param  array<string, mixed>  $execution  Verified execution result.
     * @param  array<string, mixed>  $input  Validated input.
     * @return string JSON-encoded response envelope.
     */
    protected function complete(array $execution, array $input): string
    {
        $capped = $this->capRows($execution['rows']);

        if ($execution['mode'] === 'products') {
            return $this->success(
                ['products' => $capped['rows']],
                [
                    'mode' => 'products',
                    'category_id' => $execution['category_id'],
                    'total' => $capped['total'],
                    'shown' => $capped['shown'],
                    'truncated' => $capped['truncated'],
                ],
            );
        }

        return $this->success(
            ['categories' => $capped['rows']],
            [
                'mode' => 'children',
                'parent_id' => $execution['parent_id'],
                'total' => $capped['total'],
                'shown' => $capped['shown'],
                'truncated' => $capped['truncated'],
            ],
        );
    }

    /**
     * Query immediate child categories of $parentId with name and is_active EAV, ordered by position.
     *
     * @param  int  $parentId  Parent category entity_id.
     * @return array<string, mixed> Internal execution result in children mode.
     */
    private function fetchChildren(int $parentId): array
    {
        $tCategory = $this->resourceConnection->getTableName('catalog_category_entity');
        $tCategoryVarchar = $this->resourceConnection->getTableName('catalog_category_entity_varchar');
        $tCategoryInt = $this->resourceConnection->getTableName('catalog_category_entity_int');
        $tEavAttribute = $this->resourceConnection->getTableName('eav_attribute');

        $rows = $this->resourceConnection->getConnection()->fetchAll(
            "SELECT c.entity_id, c.parent_id, c.level, c.position,
                    c.children_count, c.path,
                    n.value AS name,
                    COALESCE(ia.value, 1) AS is_active
             FROM {$tCategory} c
             LEFT JOIN {$tCategoryVarchar} n
               ON n.entity_id    = c.entity_id
              AND n.store_id      = ".self::DEFAULT_STORE_ID."
              AND n.attribute_id  = (
                      SELECT attribute_id
                      FROM   {$tEavAttribute}
                      WHERE  attribute_code = 'name'
                        AND  entity_type_id = ".self::CATEGORY_ENTITY_TYPE."
                      LIMIT  1
                  )
             LEFT JOIN {$tCategoryInt} ia
               ON ia.entity_id    = c.entity_id
              AND ia.store_id      = ".self::DEFAULT_STORE_ID."
              AND ia.attribute_id  = (
                      SELECT attribute_id
                      FROM   {$tEavAttribute}
                      WHERE  attribute_code = 'is_active'
                        AND  entity_type_id = ".self::CATEGORY_ENTITY_TYPE.'
                      LIMIT  1
                  )
             WHERE c.parent_id = ?
             ORDER BY c.position ASC
             LIMIT 100',
            [$parentId],
        );

        return [
            'mode' => 'children',
            'parent_id' => $parentId,
            'rows' => $rows,
        ];
    }

    /**
     * Query products assigned to $categoryId with name EAV, ordered by position.
     *
     * @param  int  $categoryId  Category entity_id whose products to list.
     * @param  array<string, mixed>  $input  Used to extract the configurable row limit.
     * @return array<string, mixed> Internal execution result in products mode.
     */
    private function fetchProducts(int $categoryId, array $input): array
    {
        $limit = isset($input['limit']) ? max(1, min((int) $input['limit'], self::MAX_PRODUCT_LIMIT)) : self::DEFAULT_PRODUCT_LIMIT;

        $tCategoryProduct = $this->resourceConnection->getTableName('catalog_category_product');
        $tProduct = $this->resourceConnection->getTableName('catalog_product_entity');
        $tProductVarchar = $this->resourceConnection->getTableName('catalog_product_entity_varchar');
        $tEavAttribute = $this->resourceConnection->getTableName('eav_attribute');

        $rows = $this->resourceConnection->getConnection()->fetchAll(
            "SELECT cp.product_id, e.sku, e.type_id, n.value AS name, cp.position
             FROM {$tCategoryProduct} cp
             JOIN {$tProduct} e ON e.entity_id = cp.product_id
             LEFT JOIN {$tProductVarchar} n
               ON n.entity_id    = e.entity_id
              AND n.store_id      = ".self::DEFAULT_STORE_ID."
              AND n.attribute_id  = (
                      SELECT attribute_id
                      FROM   {$tEavAttribute}
                      WHERE  attribute_code = 'name'
                        AND  entity_type_id = ".self::PRODUCT_ENTITY_TYPE.'
                      LIMIT  1
                  )
             WHERE cp.category_id = ?
             ORDER BY cp.position ASC
             LIMIT '.(int) $limit,
            [$categoryId],
        );

        return [
            'mode' => 'products',
            'category_id' => $categoryId,
            'limit' => $limit,
            'rows' => $rows,
        ];
    }
}
