<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Tests\Unit\Tools\Concerns;

use Magento\Framework\AuthorizationInterface;
use PhpClaw\Magento\Model\IdentityResolver;
use PhpClaw\Magento\Tools\Concerns\HasToolExecutionContract;
use PHPUnit\Framework\TestCase;

final class HasToolExecutionContractTest extends TestCase
{
    private function tool(bool $console, bool $allowed, ?\Throwable $aclThrows = null): object
    {
        $identity = $this->createMock(IdentityResolver::class);
        $identity->method('runningInConsole')->willReturn($console);

        $authorization = $this->createMock(AuthorizationInterface::class);

        if ($aclThrows !== null) {
            $authorization->method('isAllowed')->willThrowException($aclThrows);
        } else {
            $authorization->method('isAllowed')->willReturnCallback(
                static fn (string $resource): bool => $allowed && $resource === 'PhpClaw_Magento::phpclaw_chat',
            );
        }

        return new class($identity, $authorization)
        {
            use HasToolExecutionContract;

            public function __construct(
                private readonly IdentityResolver $identityResolver,
                private readonly AuthorizationInterface $acl,
            ) {}

            public function requiredCapability(): string
            {
                return 'PhpClaw_Magento::phpclaw_chat';
            }

            public function callConsole(): bool
            {
                return $this->runningInConsole();
            }

            public function callCapability(string $capability): bool
            {
                return $this->callerHasCapability($capability);
            }

            public function callGuard(string $subject): ?string
            {
                return $this->guardCapability($subject);
            }

            protected function identity(): IdentityResolver
            {
                return $this->identityResolver;
            }

            protected function authorization(): AuthorizationInterface
            {
                return $this->acl;
            }

            protected function plan(array $input): array
            {
                return ['result' => null, 'input' => $input];
            }

            protected function perform(array $input): array
            {
                return [];
            }

            protected function verify(array $execution, array $input): array
            {
                return ['result' => null];
            }

            protected function complete(array $execution, array $input): string
            {
                return '{}';
            }
        };
    }

    public function test_running_in_console_follows_the_identity_resolver(): void
    {
        self::assertTrue($this->tool(true, false)->callConsole());
        self::assertFalse($this->tool(false, false)->callConsole());
    }

    public function test_caller_has_capability_asks_the_acl_for_the_named_resource(): void
    {
        self::assertTrue($this->tool(false, true)->callCapability('PhpClaw_Magento::phpclaw_chat'));
        self::assertFalse($this->tool(false, true)->callCapability('PhpClaw_Magento::phpclaw_settings'));
        self::assertFalse($this->tool(false, false)->callCapability('PhpClaw_Magento::phpclaw_chat'));
    }

    public function test_caller_has_capability_denies_when_the_acl_throws(): void
    {
        $tool = $this->tool(false, true, new \RuntimeException('acl exploded'));

        self::assertFalse($tool->callCapability('PhpClaw_Magento::phpclaw_chat'));
    }

    public function test_guard_allows_the_console_without_consulting_the_acl(): void
    {
        self::assertNull($this->tool(true, false)->callGuard('read products'));
    }

    public function test_guard_allows_an_http_caller_holding_the_resource(): void
    {
        self::assertNull($this->tool(false, true)->callGuard('read products'));
    }

    public function test_guard_returns_a_forbidden_envelope_naming_the_resource(): void
    {
        $envelope = $this->tool(false, false)->callGuard('read products');

        self::assertIsString($envelope);

        $decoded = json_decode($envelope, true);

        self::assertIsArray($decoded);
        self::assertFalse($decoded['success']);
        self::assertSame('FORBIDDEN', $decoded['error']['code']);
        self::assertStringContainsString('PhpClaw_Magento::phpclaw_chat', $decoded['error']['message']);
        self::assertStringContainsString('read products', $decoded['error']['message']);
    }
}
