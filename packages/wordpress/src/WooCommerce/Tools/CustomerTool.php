<?php

declare(strict_types=1);

namespace PhpClaw\WooCommerce\Tools;

use PhpClaw\Exceptions\ToolException;
use PhpClaw\Tools\Contracts\ToolInterface;
use PhpClaw\Tools\Contracts\ToolRoutingInterface;
use PhpClaw\Tools\ToolRoutingMetadata;
use PhpClaw\WordPress\Tools\Concerns\HasToolExecutionContract;

/**
 * Customer tool - read-only customer order statistics.
 */
final class CustomerTool implements ToolInterface, ToolRoutingInterface
{
    use HasToolExecutionContract;

    private const REQUIRED_CAPABILITY = 'phpclaw_use_chat';

    private const PHPCLAW_CAPABILITY = 'woocommerce.customers.read';

    private const RISK_LEVEL = 'read';

    private const RETURNED_FIELDS = [
        'customer_id',
        'order_count',
        'total_spent',
    ];

    private const WITHHELD_FIELDS = [
        'billing_address',
        'shipping_address',
        'email',
        'phone',
        'payment_tokens',
    ];

    private const ALLOWED_KEYS = [
        'customer_id',
        'schema',
    ];

    public const EXAMPLES = [
        [
            'prompt' => 'what has customer 1 bought from us?',
            'arguments' => ['customer_id' => 1],
        ],
        [
            'prompt' => 'what can you tell me about a customer?',
            'arguments' => ['schema' => true],
        ],
    ];

    private $resolver;

    /**
     * Bind the customer resolver, defaulting to a WC_Customer lookup by id.
     *
     * @param  callable(int): mixed|null  $resolver  Receives customer ID, returns WC_Customer-like object.
     */
    public function __construct(?callable $resolver = null)
    {
        $this->resolver = $resolver ?? static fn (int $id): mixed => new \WC_Customer($id);
    }

    /**
     * Return the tool's machine-readable identifier.
     *
     * @return string
     */
    public function name(): string
    {
        return 'wc_get_customer';
    }

    /**
     * Return the phpClaw capability identifier this tool exercises.
     *
     * @return string
     */
    public function capability(): string
    {
        return self::PHPCLAW_CAPABILITY;
    }

    /**
     * Return the WordPress capability required to run this tool.
     *
     * @return string
     */
    public function requiredCapability(): string
    {
        return self::REQUIRED_CAPABILITY;
    }

    /**
     * Return the risk classification for this tool.
     *
     * @return string
     */
    public function risk(): string
    {
        return self::RISK_LEVEL;
    }

    /**
     * Report whether repeated identical calls produce the same result.
     *
     * @return bool
     */
    public function isIdempotent(): bool
    {
        return true;
    }

    /**
     * Return worked example prompts for this tool.
     *
     * @return array<int, array{prompt: string, arguments: array<string, mixed>}>
     */
    public static function examples(): array
    {
        return self::EXAMPLES;
    }

    /**
     * Return the natural-language description presented to the LLM.
     *
     * @return string
     */
    public function description(): string
    {
        return <<<'DESC'
Read WooCommerce customer order statistics. READ-ONLY.
Requires the "phpclaw_use_chat" capability.

MODES
  schema=true   Discover returned fields, limits and capabilities. No customer read.
  default       Look up one customer by customer_id.

RETURNS
  customer_id, order_count, total_spent. Nothing else.

NEVER USE FOR
  Reading customer email, phone, billing or shipping addresses, payment tokens or
  order line items; creating or updating customers. This tool cannot do any of it.

NOTES
  customer_id is the WordPress user ID. Guests have no customer record.
  An unknown id returns CUSTOMER_NOT_FOUND, not an empty result.
DESC;
    }

    /**
     * Return the JSON Schema describing the tool's accepted input parameters.
     *
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'customer_id' => [
                    'type' => 'integer',
                    'description' => 'WordPress user ID of the WooCommerce customer.',
                    'minimum' => 1,
                ],

                'schema' => [
                    'type' => 'boolean',
                    'description' => 'Return field and capability metadata without reading a customer.',
                    'default' => false,
                ],
            ],
            'required' => [],
        ];
    }

    /**
     * Plan the execution by enforcing authorization, validating input, and determining the operation that can safely run.
     *
     * @param  array<string, mixed>  $input  Runtime input.
     * @return array{input: array<string, mixed>, result: string|null}
     */
    protected function plan(array $input): array
    {
        if (! $this->runningInConsole() && ! $this->callerHasCapability(self::REQUIRED_CAPABILITY)) {
            return [
                'input' => $input,
                'result' => $this->error(
                    'FORBIDDEN',
                    sprintf(
                        'The current user lacks the "%s" capability required to read customers.',
                        self::REQUIRED_CAPABILITY,
                    ),
                ),
            ];
        }

        $validationError = $this->validate($input);

        if ($validationError !== null) {
            return [
                'input' => $input,
                'result' => $validationError,
            ];
        }

        return [
            'input' => $input,
            'result' => null,
        ];
    }

    /**
     * Execute the planned WooCommerce operation without handling model policy.
     *
     * @param  array<string, mixed>  $input  Validated runtime input.
     * @return array<string, mixed> Internal execution result.
     *
     * @throws ToolException On infrastructure failure.
     */
    protected function perform(array $input): array
    {
        if (($input['schema'] ?? false) === true) {
            return [
                'type' => 'schema',
                'payload' => $this->schemaData(),
            ];
        }

        return [
            'type' => 'lookup',
            'payload' => $this->customerData((int) $input['customer_id']),
        ];
    }

    /**
     * Verify the raw execution result before it becomes the final model result.
     *
     * @param  array<string, mixed>  $execution  Internal execution result.
     * @param  array<string, mixed>  $input  Validated input.
     * @return array{result: string|null}
     *
     * @throws ToolException When an infrastructure result is incomplete.
     */
    protected function verify(array $execution, array $input): array
    {
        if ($execution['type'] === 'schema') {
            return ['result' => null];
        }

        foreach (self::RETURNED_FIELDS as $field) {
            if (! array_key_exists($field, $execution['payload'])) {
                throw new ToolException('CustomerTool returned an incomplete customer result.');
            }
        }

        foreach (self::WITHHELD_FIELDS as $field) {
            if (array_key_exists($field, $execution['payload'])) {
                throw new ToolException('CustomerTool attempted to return withheld customer data.');
            }
        }

        return ['result' => null];
    }

    /**
     * Complete a verified execution into the public response envelope.
     *
     * @param  array<string, mixed>  $execution  Verified execution result.
     * @param  array<string, mixed>  $input  Validated input.
     * @return string JSON-encoded response envelope.
     */
    protected function complete(array $execution, array $input): string
    {
        if ($execution['type'] === 'schema') {
            return $this->encodeResult([
                'success' => true,
                'data' => $execution['payload'],
                'meta' => [
                    'mode' => 'schema',
                    'database_query_performed' => false,
                ],
                'warnings' => [],
            ]);
        }

        return $this->encodeResult([
            'success' => true,
            'data' => [
                'customers' => [$execution['payload']],
            ],
            'meta' => [
                'mode' => 'lookup',
                'count' => 1,
                'fields_returned' => self::RETURNED_FIELDS,
            ],
            'warnings' => [],
        ]);
    }

    /**
     * Resolve one customer and collect the statistics this tool exposes.
     *
     * @param  int  $customerId  WordPress user ID of the customer.
     * @return array<string, mixed> Customer statistics.
     *
     * @throws ToolException When customer resolution fails.
     */
    private function customerData(int $customerId): array
    {
        try {
            $customer = ($this->resolver)($customerId);
        } catch (\Throwable $e) {
            $this->logExecutionError($e);

            throw new ToolException('WC_Customer resolution failed', previous: $e);
        }

        return [
            'customer_id' => $customerId,
            'order_count' => $customer->get_order_count(),
            'total_spent' => $customer->get_total_spent(),
        ];
    }

    /**
     * Build field and capability metadata without reading a customer.
     *
     * @return array<string, mixed> Schema metadata.
     */
    private function schemaData(): array
    {
        return [
            'examples' => self::EXAMPLES,
            'parameters' => self::ALLOWED_KEYS,
            'modes' => ['schema', 'lookup'],
            'fields_returned' => self::RETURNED_FIELDS,
            'fields_never_returned' => self::WITHHELD_FIELDS,
            'field_descriptions' => [
                'customer_id' => 'WordPress user ID of the customer.',
                'order_count' => 'Number of completed orders attributed to the customer.',
                'total_spent' => 'Lifetime spend, as a decimal string in store currency.',
            ],
            'woocommerce_capability' => self::REQUIRED_CAPABILITY,
            'phpclaw_capability' => self::PHPCLAW_CAPABILITY,
            'risk' => self::RISK_LEVEL,
            'idempotent' => true,
        ];
    }

    /**
     * Validate runtime input and return a structured error when it is unusable.
     *
     * @param  array<string, mixed>  $input  Runtime input.
     * @return string|null JSON-encoded error envelope, or null when valid.
     */
    private function validate(array $input): ?string
    {
        foreach (array_keys($input) as $key) {
            if (! in_array($key, self::ALLOWED_KEYS, true)) {
                return $this->error(
                    'UNKNOWN_ARGUMENT',
                    sprintf('Unknown argument "%s".', (string) $key),
                    ['accepted_arguments' => self::ALLOWED_KEYS],
                );
            }
        }

        if (array_key_exists('schema', $input) && ! is_bool($input['schema'])) {
            return $this->error('INVALID_ARGUMENT', '"schema" must be a boolean.');
        }

        if (($input['schema'] ?? false) === true) {
            return null;
        }

        if (! array_key_exists('customer_id', $input)) {
            return $this->error(
                'INVALID_ARGUMENT',
                'Provide "customer_id", or set "schema" to true.',
                ['accepted_arguments' => self::ALLOWED_KEYS],
            );
        }

        if (! is_int($input['customer_id']) || $input['customer_id'] < 1) {
            return $this->error(
                'INVALID_CUSTOMER_ID',
                '"customer_id" must be a positive integer.',
            );
        }

        if (! $this->customerExists($input['customer_id'])) {
            return $this->error(
                'CUSTOMER_NOT_FOUND',
                sprintf('No customer exists with id %d.', $input['customer_id']),
            );
        }

        return null;
    }

    /**
     * Report whether a WordPress user record backs the given customer id.
     *
     * @param  int  $customerId  WordPress user ID of the customer.
     * @return bool
     */
    private function customerExists(int $customerId): bool
    {
        if (! function_exists('get_userdata')) {
            return true;
        }

        return get_userdata($customerId) !== false;
    }

    /**
     * Whether this tool may be offered to the model. WordPress evaluates the caller's capability when the tool runs, so every tool stays eligible for routing.
     *
     * @return bool Always true; execution-time checks remain the authority.
     */
    public function isEligibleForRouting(): bool
    {
        return true;
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
            tags: ['customer', 'customers', 'buyer', 'buyers', 'shopper', 'shoppers', 'client', 'clients', 'billing', 'shipping', 'address', 'contact'],
            intents: ['find a customer', 'show buyer details', 'look up a shopper'],
            examples: ['look up the customer who placed order 265'],
        );
    }
}
