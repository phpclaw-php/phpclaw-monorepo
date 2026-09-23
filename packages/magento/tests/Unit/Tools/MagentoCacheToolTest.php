<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Tests\Unit\Tools;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\DataObject;
use PhpClaw\Magento\Model\IdentityResolver;
use PhpClaw\Magento\Tools\MagentoCacheTool;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class MagentoCacheToolTest extends TestCase
{
    private TypeListInterface&MockObject $cacheTypeList;

    protected function setUp(): void
    {
        $this->cacheTypeList = $this->createMock(TypeListInterface::class);
    }

    private function tool(bool $allowed = true, bool $console = false): MagentoCacheTool
    {
        $identity = $this->createMock(IdentityResolver::class);
        $identity->method('runningInConsole')->willReturn($console);

        $acl = $this->createMock(AuthorizationInterface::class);
        $acl->method('isAllowed')->willReturn($allowed);

        return new MagentoCacheTool($this->cacheTypeList, $identity, $acl);
    }

    private function envelope(string $result): array
    {
        $decoded = json_decode($result, true);

        self::assertIsArray($decoded, 'the tool must return a JSON envelope');

        return $decoded;
    }

    private function forbidMutation(): void
    {
        $this->cacheTypeList->expects(self::never())->method('cleanType');
        $this->cacheTypeList->expects(self::never())->method('invalidate');
    }

    private function threeTypes(): void
    {
        $this->cacheTypeList->method('getTypes')->willReturn([
            new DataObject(['id' => 'config',    'cache_type' => 'Configuration', 'description' => 'System config.', 'status' => 1]),
            new DataObject(['id' => 'full_page', 'cache_type' => 'Page Cache',    'description' => 'Full page.',     'status' => 0]),
            new DataObject(['id' => 'layout',    'cache_type' => 'Layout',        'description' => 'Layout XML.',    'status' => 1]),
        ]);
        $this->cacheTypeList->method('getInvalidated')->willReturn([
            new DataObject(['id' => 'layout']),
        ]);
    }

    public function test_name(): void
    {
        self::assertSame('magento_cache', $this->tool()->name());
    }

    public function test_required_capability_is_the_chat_resource(): void
    {
        self::assertSame('PhpClaw_Magento::phpclaw_chat', $this->tool()->requiredCapability());
    }

    public function test_description_states_read_only(): void
    {
        self::assertStringContainsStringIgnoringCase('read-only', $this->tool()->description());
    }

    public function test_input_schema_takes_no_parameters(): void
    {
        $schema = $this->tool()->inputSchema();

        self::assertSame([], $schema['required']);
        self::assertSame('{"type":"object","properties":{},"required":[]}', json_encode($schema));
    }

    public function test_no_path_of_this_tool_ever_mutates_the_cache(): void
    {
        $this->forbidMutation();
        $this->threeTypes();

        $this->envelope($this->tool()->execute([]));
        $this->envelope($this->tool(allowed: false)->execute([]));
        $this->envelope($this->tool(allowed: false, console: true)->execute([]));
        $this->envelope($this->tool()->execute(['nope' => 1]));
    }

    public function test_a_caller_without_the_chat_resource_is_forbidden(): void
    {
        $this->forbidMutation();
        $this->threeTypes();

        $envelope = $this->envelope($this->tool(allowed: false)->execute([]));

        self::assertFalse($envelope['success']);
        self::assertSame('FORBIDDEN', $envelope['error']['code']);
        self::assertStringContainsString('PhpClaw_Magento::phpclaw_chat', $envelope['error']['message']);
    }

    public function test_the_console_reaches_the_tool_without_the_chat_resource(): void
    {
        $this->forbidMutation();
        $this->threeTypes();

        $envelope = $this->envelope($this->tool(allowed: false, console: true)->execute([]));

        self::assertTrue($envelope['success']);
        self::assertCount(3, $envelope['data']['cache_types']);
    }

    public function test_an_unknown_argument_is_rejected(): void
    {
        $this->forbidMutation();
        $this->threeTypes();

        $envelope = $this->envelope($this->tool()->execute(['nope' => 1]));

        self::assertFalse($envelope['success']);
        self::assertSame('UNKNOWN_ARGUMENT', $envelope['error']['code']);
    }

    public function test_reports_each_type_status(): void
    {
        $this->forbidMutation();
        $this->threeTypes();

        $envelope = $this->envelope($this->tool()->execute([]));

        self::assertSame(3, $envelope['meta']['total']);
        self::assertSame(2, $envelope['meta']['enabled']);
        self::assertSame(1, $envelope['meta']['invalidated']);

        $byId = [];
        foreach ($envelope['data']['cache_types'] as $row) {
            $byId[$row['id']] = $row['status'];
        }

        self::assertSame('enabled', $byId['config']);
        self::assertSame('disabled', $byId['full_page']);
        self::assertSame('invalidated', $byId['layout']);
    }

    public function test_empty_cache_list_returns_zero_counts(): void
    {
        $this->forbidMutation();
        $this->cacheTypeList->method('getTypes')->willReturn([]);
        $this->cacheTypeList->method('getInvalidated')->willReturn([]);

        $envelope = $this->envelope($this->tool()->execute([]));

        self::assertSame(0, $envelope['meta']['total']);
        self::assertSame(0, $envelope['meta']['enabled']);
        self::assertSame(0, $envelope['meta']['invalidated']);
        self::assertSame([], $envelope['data']['cache_types']);
    }

    public function test_row_carries_label_and_description(): void
    {
        $this->forbidMutation();
        $this->cacheTypeList->method('getTypes')->willReturn([
            new DataObject(['id' => 'config', 'cache_type' => 'Configuration', 'description' => 'System config.', 'status' => 1]),
        ]);
        $this->cacheTypeList->method('getInvalidated')->willReturn([]);

        $row = $this->envelope($this->tool()->execute([]))['data']['cache_types'][0];

        self::assertSame('config', $row['id']);
        self::assertSame('Configuration', $row['label']);
        self::assertSame('System config.', $row['description']);
    }

    public function test_a_list_over_the_byte_budget_is_capped_and_reported_in_meta(): void
    {
        $this->forbidMutation();

        $types = [];
        for ($i = 0; $i < 400; $i++) {
            $types[] = new DataObject([
                'id' => 'type_'.str_pad((string) $i, 30, '0', STR_PAD_LEFT),
                'cache_type' => str_repeat('Label', 8),
                'description' => str_repeat('Description text ', 4),
                'status' => 1,
            ]);
        }
        $this->cacheTypeList->method('getTypes')->willReturn($types);
        $this->cacheTypeList->method('getInvalidated')->willReturn([]);

        $envelope = $this->envelope($this->tool()->execute([]));

        self::assertTrue($envelope['meta']['truncated']);
        self::assertSame(400, $envelope['meta']['total']);
        self::assertLessThan(400, $envelope['meta']['shown']);
        self::assertCount($envelope['meta']['shown'], $envelope['data']['cache_types']);
    }
}
