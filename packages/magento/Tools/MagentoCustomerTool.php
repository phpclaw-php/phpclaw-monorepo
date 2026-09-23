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
 * Tool that looks up a customer and their order history via ResourceConnection.
 */
// non-final: Magento interceptor required
class MagentoCustomerTool extends AbstractMagentoResourceTool
{
    use HasToolExecutionContract;

    private const REQUIRED_CAPABILITY = 'PhpClaw_Magento::phpclaw_chat';

    private const RECENT_ORDERS_CAP = 10;

    /**
     * Bind the Magento database connection, identity resolver, and ACL service.
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
     * Return the ACL resource a caller must hold to look up customer data.
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
            domains: ['commerce', 'customers'],
            tags: ['customer', 'customers', 'buyer', 'buyers', 'shopper', 'shoppers', 'client', 'clients', 'account', 'accounts', 'email', 'group', 'groups', 'address', 'addresses'],
            intents: ['list customers', 'find a buyer', 'look up a shopper'],
            examples: ['list the customers and their emails'],
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
        return 'magento_customer';
    }

    /**
     * Returns the natural-language description shown to the LLM.
     *
     * @return string
     */
    public function description(): string
    {
        return 'LOOKUP a Magento customer by email or customer_id, OR count all registered customers. Set count=true to get the total customer count. Returns profile, lifetime value, and recent order history for a specific customer. Invoke it, never guess customer data.';
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
                'count' => [
                    'type' => 'boolean',
                    'description' => 'When true, return total customer count instead of fetching a specific customer.',
                ],
                'email' => [
                    'type' => 'string',
                    'description' => 'Customer email address.',
                ],
                'customer_id' => [
                    'type' => 'integer',
                    'description' => 'Magento customer entity_id.',
                ],
            ],
        ];
    }

    /**
     * Guard the caller and validate that a lookup target or count flag is present.
     *
     * @param  array<string, mixed>  $input  Runtime input.
     * @return array{input: array<string, mixed>, result: string|null}
     */
    protected function plan(array $input): array
    {
        $forbidden = $this->guardCapability('look up customer data');

        if ($forbidden !== null) {
            return ['input' => $input, 'result' => $forbidden];
        }

        $unknown = $this->rejectUnknownArguments($input, ['count', 'email', 'customer_id']);

        if ($unknown !== null) {
            return ['input' => $input, 'result' => $unknown];
        }

        if (empty($input['count'])) {
            $email = trim((string) ($input['email'] ?? ''));
            $customerId = isset($input['customer_id']) ? (int) $input['customer_id'] : 0;

            if ($email === '' && $customerId === 0) {
                return [
                    'input' => $input,
                    'result' => $this->error(
                        'MISSING_ARGUMENT',
                        "magento_customer: provide 'email', 'customer_id', or set count=true.",
                    ),
                ];
            }
        }

        return ['input' => $input, 'result' => null];
    }

    /**
     * Run the customer count query or the profile and order history lookup.
     *
     * @param  array<string, mixed>  $input  Validated runtime input.
     * @return array<string, mixed> Internal execution result.
     */
    protected function perform(array $input): array
    {
        $conn = $this->resourceConnection->getConnection();
        $tCustomer = $this->resourceConnection->getTableName('customer_entity');
        $tOrder = $this->resourceConnection->getTableName('sales_order');

        if (! empty($input['count'])) {
            $total = (int) $conn->fetchOne("SELECT COUNT(*) FROM {$tCustomer}");

            return ['mode' => 'count', 'count' => $total];
        }

        $email = trim((string) ($input['email'] ?? ''));
        $customerId = isset($input['customer_id']) ? (int) $input['customer_id'] : 0;

        [$whereColumn, $binding] = $email !== ''
            ? ['c.email', $email]
            : ['c.entity_id', $customerId];

        $rows = $conn->fetchAll(
            "SELECT c.entity_id, c.email, c.firstname, c.lastname,
                    c.created_at, c.updated_at, c.is_active, c.group_id,
                    COUNT(o.entity_id)      AS order_count,
                    COALESCE(SUM(o.grand_total), 0) AS lifetime_value
             FROM {$tCustomer} c
             LEFT JOIN {$tOrder} o ON o.customer_id = c.entity_id
             WHERE {$whereColumn} = ?
             GROUP BY c.entity_id
             LIMIT 1",
            [$binding],
        );

        if (empty($rows)) {
            $label = $email !== '' ? "email '{$email}'" : "ID {$customerId}";

            return ['mode' => 'not_found', 'label' => $label];
        }

        $customer = $rows[0];
        $entityId = (int) $customer['entity_id'];

        $customer['recent_orders'] = $conn->fetchAll(
            "SELECT increment_id, status, grand_total, base_currency_code, created_at
             FROM {$tOrder}
             WHERE customer_id = ?
             ORDER BY created_at DESC
             LIMIT ".self::RECENT_ORDERS_CAP,
            [$entityId],
        );

        return ['mode' => 'profile', 'customer' => $customer];
    }

    /**
     * Confirm the execution produced a complete result before the model sees it.
     *
     * @param  array<string, mixed>  $execution  Internal execution result.
     * @param  array<string, mixed>  $input  Validated input.
     * @return array{result: string|null}
     *
     * @throws ToolException When the execution result is structurally incomplete.
     */
    protected function verify(array $execution, array $input): array
    {
        if ($execution['mode'] === 'not_found') {
            return [
                'result' => $this->error(
                    'NOT_FOUND',
                    "magento_customer: customer with {$execution['label']} not found.",
                ),
            ];
        }

        if ($execution['mode'] === 'count' && ! isset($execution['count'])) {
            throw new ToolException('magento_customer: count result is incomplete.');
        }

        if ($execution['mode'] === 'profile' && ! isset($execution['customer'])) {
            throw new ToolException('magento_customer: profile result is incomplete.');
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
        if ($execution['mode'] === 'count') {
            return $this->success(
                ['count' => $execution['count']],
                ['mode' => 'count'],
            );
        }

        $customer = $execution['customer'];

        return $this->success(
            $customer,
            [
                'mode' => 'profile',
                'entity_id' => (int) $customer['entity_id'],
                'recent_orders_cap' => self::RECENT_ORDERS_CAP,
                'recent_orders_returned' => count($customer['recent_orders']),
            ],
        );
    }
}
