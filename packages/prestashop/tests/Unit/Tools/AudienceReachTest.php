<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Tests\Unit\Tools;

use PhpClaw\Exceptions\ToolException;
use PhpClaw\PrestaShop\Engine\EngineFactory;
use PhpClaw\Tools\Contracts\ToolInterface;
use PHPUnit\Framework\TestCase;

final class AudienceReachTest extends TestCase
{
    private const DEBUG_TAB_ID = 7;

    private const SETTINGS_TAB_ID = 3;

    protected function setUp(): void
    {
        parent::setUp();

        \Context::reset();
        \Tab::reset();
        \Profile::reset();

        \Tab::$idsByClass = [
            'AdminPhpClawDebug' => self::DEBUG_TAB_ID,
            'AdminPhpClawSettings' => self::SETTINGS_TAB_ID,
        ];
    }

    protected function tearDown(): void
    {
        \Context::reset();
        \Tab::reset();
        \Profile::reset();

        parent::tearDown();
    }

    public function test_the_admin_and_the_backend_employee_reach_the_identical_tool_set(): void
    {
        \Profile::grant(10, self::DEBUG_TAB_ID, 'view');
        \Profile::grant(10, self::SETTINGS_TAB_ID, 'view');
        \Profile::grant(20, self::DEBUG_TAB_ID, 'view');

        $admin = $this->reachableAs(10);
        $backend = $this->reachableAs(20);

        self::assertNotSame([], $admin, 'The admin employee reached no gated tool at all.');
        self::assertSame(
            $admin,
            $backend,
            'Flattening means the backend employee reaches the same set as the admin employee.',
        );
        self::assertSame(
            $this->gatedToolNames(),
            $admin,
            'Every gated tool this adapter ships must be reachable at the chat tier.',
        );
    }

    public function test_a_super_admin_reaches_the_same_set(): void
    {
        \Profile::grant(20, self::DEBUG_TAB_ID, 'view');

        $backend = $this->reachableAs(20);

        self::assertSame($backend, $this->reachableAs(1, superAdmin: true));
    }

    private function reachableAs(?int $profileId, bool $superAdmin = false): array
    {
        if ($profileId === null) {
            \Context::getContext()->employee = null;
        } else {
            $employee = new \Employee;
            $employee->id = 100 + $profileId;
            $employee->id_profile = $profileId;
            $employee->superAdmin = $superAdmin;

            \Context::getContext()->employee = $employee;
        }

        $reached = [];

        foreach ($this->gatedTools() as $tool) {
            if (! $this->refused($tool)) {
                $reached[] = $tool->name();
            }
        }

        sort($reached);

        return $reached;
    }

    private function refused(ToolInterface $tool): bool
    {
        try {
            $result = $tool->execute([]);
        } catch (ToolException) {
            return false;
        }

        $decoded = json_decode($result, true);

        if (! is_array($decoded)) {
            return false;
        }

        return ($decoded['error']['code'] ?? null) === 'FORBIDDEN';
    }

    private function gatedTools(): array
    {
        $factory = new EngineFactory([], [], null, 'ps_');

        return array_values(array_filter(
            $factory->buildTools(applyProfile: false, isCli: false),
            static fn (ToolInterface $tool): bool => method_exists($tool, 'requiredCapability'),
        ));
    }

    private function gatedToolNames(): array
    {
        $names = array_map(static fn (ToolInterface $tool): string => $tool->name(), $this->gatedTools());
        sort($names);

        return $names;
    }
}
