<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Tools;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\AuthorizationInterface;
use PhpClaw\Exceptions\ToolException;
use PhpClaw\Magento\Model\IdentityResolver;
use PhpClaw\Magento\Service\JsonToolResult;
use PhpClaw\Magento\Tools\Concerns\HasToolExecutionContract;
use PhpClaw\Tools\Contracts\ToolInterface;
use PhpClaw\Tools\Contracts\ToolRoutingInterface;
use PhpClaw\Tools\ToolRoutingMetadata;

/**
 * Read-only tool that reports each cache type's status via TypeListInterface.
 */
// non-final: Magento interceptor required
class MagentoCacheTool implements ToolInterface, ToolRoutingInterface
{
    use HasToolExecutionContract;
    use JsonToolResult;

    private const REQUIRED_CAPABILITY = 'PhpClaw_Magento::phpclaw_chat';

    private const STATUS_ENABLED = 'enabled';

    private const STATUS_DISABLED = 'disabled';

    private const STATUS_INVALIDATED = 'invalidated';

    /**
     * Bind the cache type registry this tool reports status from.
     *
     * @param  TypeListInterface  $cacheTypeList  Magento cache type registry (read-only status source).
     * @param  IdentityResolver  $identityResolver  Resolver reporting the area and the acting admin identity.
     * @param  AuthorizationInterface  $acl  Magento authorization service.
     * @return void
     */
    public function __construct(
        private readonly TypeListInterface $cacheTypeList,
        private readonly IdentityResolver $identityResolver,
        private readonly AuthorizationInterface $acl,
    ) {}

    /**
     * Return the ACL resource a caller must hold to read cache status.
     *
     * @return string
     */
    public function requiredCapability(): string
    {
        return self::REQUIRED_CAPABILITY;
    }

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
     * Return the routing signals the router ranks this tool by.
     *
     * @return ToolRoutingMetadata Domains, tags, intents and examples for this tool.
     */
    public function routingMetadata(): ToolRoutingMetadata
    {
        return new ToolRoutingMetadata(
            domains: ['performance', 'system'],
            tags: ['cache', 'caches', 'type', 'types', 'flush', 'clean', 'invalidate', 'refresh', 'enabled', 'disabled', 'status'],
            intents: ['show cache status', 'list cache types', 'flush the cache'],
            examples: ['list the cache types and their status'],
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
        return 'magento_cache';
    }

    /**
     * Returns the natural-language description shown to the LLM.
     *
     * @return string
     */
    public function description(): string
    {
        return 'Show Magento cache type status: lists each cache type with its label, description, '
             .'and current state (enabled, disabled, or invalidated). '
             .'Read-only diagnostics; does not flush or clear caches.';
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
            'properties' => new \stdClass,
            'required' => [],
        ];
    }

    /**
     * Guard the caller and reject any argument, since this tool takes none.
     *
     * @param  array<string, mixed>  $input  Runtime input.
     * @return array{input: array<string, mixed>, result: string|null}
     */
    protected function plan(array $input): array
    {
        $forbidden = $this->guardCapability('read Magento cache status');

        if ($forbidden !== null) {
            return ['input' => $input, 'result' => $forbidden];
        }

        $unknown = $this->rejectUnknownArguments($input, []);

        if ($unknown !== null) {
            return ['input' => $input, 'result' => $unknown];
        }

        return ['input' => $input, 'result' => null];
    }

    /**
     * Read every cache type and its current status without mutating any cache.
     *
     * @param  array<string, mixed>  $input  Validated runtime input.
     * @return array<string, mixed> Internal execution result.
     */
    protected function perform(array $input): array
    {
        $invalidated = [];

        foreach ($this->cacheTypeList->getInvalidated() as $type) {
            $invalidated[(string) $type->getData('id')] = true;
        }

        $types = [];
        $enabled = 0;

        foreach ($this->cacheTypeList->getTypes() as $type) {
            $id = (string) $type->getData('id');
            $isEnabled = (bool) $type->getData('status');

            if ($isEnabled) {
                $enabled++;
            }

            $types[] = [
                'id' => $id,
                'label' => (string) $type->getData('cache_type'),
                'description' => (string) $type->getData('description'),
                'status' => isset($invalidated[$id])
                    ? self::STATUS_INVALIDATED
                    : ($isEnabled ? self::STATUS_ENABLED : self::STATUS_DISABLED),
            ];
        }

        return [
            'types' => $types,
            'enabled' => $enabled,
            'invalidated' => count($invalidated),
        ];
    }

    /**
     * Confirm the execution produced a cache type list.
     *
     * @param  array<string, mixed>  $execution  Internal execution result.
     * @param  array<string, mixed>  $input  Validated input.
     * @return array{result: string|null}
     *
     * @throws ToolException When the execution result is structurally incomplete.
     */
    protected function verify(array $execution, array $input): array
    {
        if (! is_array($execution['types'] ?? null)) {
            throw new ToolException('magento_cache: incomplete cache type result.');
        }

        return ['result' => null];
    }

    /**
     * Convert the verified cache status read into the public response envelope.
     *
     * @param  array<string, mixed>  $execution  Verified execution result.
     * @param  array<string, mixed>  $input  Validated input.
     * @return string JSON-encoded response envelope.
     */
    protected function complete(array $execution, array $input): string
    {
        $capped = $this->capRows($execution['types']);

        return $this->success(
            ['cache_types' => $capped['rows']],
            [
                'total' => $capped['total'],
                'shown' => $capped['shown'],
                'truncated' => $capped['truncated'],
                'enabled' => $execution['enabled'],
                'invalidated' => $execution['invalidated'],
            ],
        );
    }
}
