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
 * Tool that fetches sales order data via ResourceConnection.
 */
// non-final: Magento interceptor required
class MagentoOrderTool extends AbstractMagentoResourceTool
{
    use HasToolExecutionContract;

    private const REQUIRED_CAPABILITY = 'PhpClaw_Magento::phpclaw_chat';

    private const VALID_STATUSES = [
        'pending', 'pending_payment', 'payment_review',
        'processing', 'holded', 'complete', 'closed', 'canceled',
    ];

    private const DEFAULT_LIMIT = 20;

    private const MAX_LIMIT = 100;

    /**
     * Bind the database connection provider, identity resolver and ACL service.
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
     * Return the ACL resource a caller must hold to fetch order data.
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
            domains: ['commerce', 'orders'],
            tags: ['order', 'orders', 'purchase', 'purchases', 'sale', 'sales', 'transaction', 'transactions', 'invoice', 'shipment', 'refund', 'credit', 'status', 'increment', 'grand', 'total', 'buyer'],
            intents: ['list orders', 'show purchases', 'find a sale', 'what did customers buy'],
            examples: ['show me yesterdays purchases'],
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
        return 'magento_orders';
    }

    /**
     * Returns the natural-language description shown to the LLM.
     *
     * @return string
     */
    public function description(): string
    {
        return 'FETCH Magento sales orders. Provide increment_id (e.g. "000000001") to get a single order with line items. Provide status/from/to to list orders. Invoke it, never guess order data.';
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
                'increment_id' => [
                    'type' => 'string',
                    'description' => 'Order increment ID (e.g. 000000001). Returns full detail + line items.',
                ],
                'status' => [
                    'type' => 'string',
                    'description' => 'Filter by status: pending, processing, complete, closed, canceled, holded',
                    'enum' => self::VALID_STATUSES,
                ],
                'from' => [
                    'type' => 'string',
                    'description' => 'Created-at lower bound, inclusive (YYYY-MM-DD)',
                ],
                'to' => [
                    'type' => 'string',
                    'description' => 'Created-at upper bound, inclusive (YYYY-MM-DD)',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum orders to return (1-100, default 20)',
                    'default' => self::DEFAULT_LIMIT,
                    'minimum' => 1,
                    'maximum' => self::MAX_LIMIT,
                ],
            ],
        ];
    }

    /**
     * Guard the caller, validate arguments and confirm the status value is known.
     *
     * @param  array<string, mixed>  $input  Runtime input.
     * @return array{input: array<string, mixed>, result: string|null}
     */
    protected function plan(array $input): array
    {
        $forbidden = $this->guardCapability('fetch Magento sales orders');

        if ($forbidden !== null) {
            return ['input' => $input, 'result' => $forbidden];
        }

        $unknown = $this->rejectUnknownArguments($input, ['increment_id', 'status', 'from', 'to', 'limit']);

        if ($unknown !== null) {
            return ['input' => $input, 'result' => $unknown];
        }

        $status = trim((string) ($input['status'] ?? ''));

        if ($status !== '' && ! in_array($status, self::VALID_STATUSES, true)) {
            return [
                'input' => $input,
                'result' => $this->error(
                    'INVALID_ARGUMENT',
                    sprintf('"status" must be one of: %s.', implode(', ', self::VALID_STATUSES)),
                ),
            ];
        }

        return ['input' => $input, 'result' => null];
    }

    /**
     * Run the order query: fetch a single order by increment_id or a filtered list.
     *
     * @param  array<string, mixed>  $input  Validated runtime input.
     * @return array<string, mixed> Internal execution result.
     */
    protected function perform(array $input): array
    {
        $incrementId = trim((string) ($input['increment_id'] ?? ''));

        if ($incrementId !== '') {
            return $this->performSingle($incrementId);
        }

        return $this->performList($input);
    }

    /**
     * Confirm the execution produced a complete result before it becomes the model response.
     *
     * @param  array<string, mixed>  $execution  Internal execution result.
     * @param  array<string, mixed>  $input  Validated input.
     * @return array{result: string|null}
     *
     * @throws ToolException When the single-mode result is structurally incomplete.
     */
    protected function verify(array $execution, array $input): array
    {
        if ($execution['mode'] === 'single' && ! $execution['found']) {
            return [
                'result' => $this->error(
                    'NOT_FOUND',
                    sprintf("magento_orders: order '%s' not found.", $execution['increment_id']),
                ),
            ];
        }

        if ($execution['mode'] === 'single' && ! is_array($execution['order']['items'] ?? null)) {
            throw new ToolException('magento_orders: returned an incomplete order result.');
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
        if ($execution['mode'] === 'single') {
            return $this->success(
                ['order' => $execution['order']],
                [
                    'mode' => 'single',
                    'increment_id' => $execution['increment_id'],
                ],
            );
        }

        $filters = $execution['filters'];

        $capped = $this->capRows($execution['orders']);

        return $this->success(
            ['orders' => $capped['rows']],
            [
                'mode' => 'list',
                'total' => $capped['total'],
                'shown' => $capped['shown'],
                'truncated' => $capped['truncated'],
                'filters' => [
                    'status' => $filters['status'] !== '' ? $filters['status'] : null,
                    'from' => $filters['from'] !== '' ? $filters['from'] : null,
                    'to' => $filters['to'] !== '' ? $filters['to'] : null,
                    'limit' => $filters['limit'],
                ],
            ],
        );
    }

    /**
     * Fetch a full order record plus non-child line items from sales_order_item.
     *
     * @param  string  $incrementId  Human-readable order increment ID (e.g. "000000001").
     * @return array<string, mixed> Internal execution result with mode, found flag and order data.
     */
    private function performSingle(string $incrementId): array
    {
        $conn = $this->resourceConnection->getConnection();
        $tOrder = $this->resourceConnection->getTableName('sales_order');
        $tOrderItem = $this->resourceConnection->getTableName('sales_order_item');

        $rows = $conn->fetchAll(
            "SELECT entity_id, increment_id, status, state,
                    grand_total, subtotal, tax_amount, shipping_amount, discount_amount,
                    base_currency_code, created_at, updated_at,
                    customer_email, customer_firstname, customer_lastname,
                    shipping_method, shipping_description, total_item_count
             FROM {$tOrder}
             WHERE increment_id = ?
             LIMIT 1",
            [$incrementId],
        );

        if (empty($rows)) {
            return ['mode' => 'single', 'found' => false, 'increment_id' => $incrementId, 'order' => null];
        }

        $order = $rows[0];
        $orderId = (int) $order['entity_id'];

        $order['items'] = $conn->fetchAll(
            "SELECT sku, name, qty_ordered, qty_invoiced, qty_shipped, qty_canceled,
                    price, tax_amount, discount_amount, row_total
             FROM {$tOrderItem}
             WHERE order_id = ? AND parent_item_id IS NULL
             LIMIT 50",
            [$orderId],
        );

        return ['mode' => 'single', 'found' => true, 'increment_id' => $incrementId, 'order' => $order];
    }

    /**
     * Build a dynamic WHERE clause from optional filters and return matching orders.
     *
     * @param  array<string, mixed>  $input  Validated runtime input.
     * @return array<string, mixed> Internal execution result with mode, orders and applied filters.
     */
    private function performList(array $input): array
    {
        $status = trim((string) ($input['status'] ?? ''));
        $from = trim((string) ($input['from'] ?? ''));
        $to = trim((string) ($input['to'] ?? ''));
        $limit = isset($input['limit']) ? max(1, min((int) $input['limit'], self::MAX_LIMIT)) : self::DEFAULT_LIMIT;

        $conditions = ['1=1'];
        $bindings = [];

        if ($status !== '') {
            $conditions[] = 'status = ?';
            $bindings[] = $status;
        }

        if ($from !== '') {
            $conditions[] = 'created_at >= ?';
            $bindings[] = $from.' 00:00:00';
        }

        if ($to !== '') {
            $conditions[] = 'created_at <= ?';
            $bindings[] = $to.' 23:59:59';
        }

        $tOrder = $this->resourceConnection->getTableName('sales_order');
        $sql = "SELECT entity_id, increment_id, status, state, grand_total,
                       base_currency_code, created_at, customer_email,
                       customer_firstname, customer_lastname, total_item_count
                FROM {$tOrder}
                WHERE ".implode(' AND ', $conditions).'
                ORDER BY created_at DESC
                LIMIT '.(int) $limit;

        $rows = $this->resourceConnection->getConnection()->fetchAll($sql, $bindings);

        return [
            'mode' => 'list',
            'orders' => $rows,
            'filters' => [
                'status' => $status,
                'from' => $from,
                'to' => $to,
                'limit' => $limit,
            ],
        ];
    }
}
