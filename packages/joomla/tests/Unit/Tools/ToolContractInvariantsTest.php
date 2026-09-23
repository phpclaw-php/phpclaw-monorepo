<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Tests\Unit\Tools;

use PhpClaw\Joomla\Tests\Support\MockDatabase;
use PhpClaw\Joomla\Tests\Support\StubsJoomlaAccess;
use PhpClaw\Testing\AbstractToolContractInvariants;

final class ToolContractInvariantsTest extends AbstractToolContractInvariants
{
    use StubsJoomlaAccess;

    protected function setUp(): void
    {
        parent::setUp();
        $this->grantJoomlaAccess();
    }

    protected function toolFiles(): array
    {
        $files = [];

        foreach ((array) glob(__DIR__.'/../../../component/src/Tools/*.php') as $file) {
            if (str_starts_with(basename((string) $file), 'Abstract')) {
                continue;
            }

            $files[] = (string) $file;
        }

        return $files;
    }

    protected function pendingConversion(): array
    {
        return [
        ];
    }

    protected function collectionTools(): array
    {
        return array_keys($this->collectionSortEvidence());
    }

    protected function stableSecondarySortProblem(string $tool): ?string
    {
        [$orderBy, $tieBreak] = $this->collectionSortEvidence()[$tool];

        $db = MockDatabase::raw($this);
        MockDatabase::enqueueAssocList($db, [['total' => '2']]);
        MockDatabase::enqueueAssocList($db, [['id' => 1], ['id' => 2]]);

        $fqcn = 'PhpClaw\\Joomla\\Component\\Administrator\\Tools\\'.$tool;
        (new $fqcn($db))->execute(['order_by' => $orderBy, 'order_dir' => 'asc']);

        $sql = (string) preg_replace('/\s+/', ' ', MockDatabase::lastQuery($db));

        if (preg_match('/ORDER BY (.+?)(?: LIMIT | OFFSET |$)/', $sql, $match) !== 1) {
            return 'issued no ORDER BY at all';
        }

        $terms = array_map('trim', explode(',', $match[1]));

        if (count($terms) < 2) {
            return 'orders by a single term ('.$match[1].')';
        }

        return end($terms) === $tieBreak
            ? null
            : 'ends its ORDER BY with "'.end($terms).'", expected "'.$tieBreak.'"';
    }

    private function collectionSortEvidence(): array
    {
        return [
            'JoomlaArticleTool' => ['hits', 'c.id ASC'],
            'JoomlaCategoryTool' => ['level', 'cat.id ASC'],
            'JoomlaUserTool' => ['name', 'u.id ASC'],
            'JoomlaExtensionTool' => ['type', 'e.extension_id ASC'],
        ];
    }

    protected function freeTextFields(): array
    {
        return [
            'title', 'introtext', 'created_by_alias',
            'name', 'username', 'description', 'author',
        ];
    }

    public function test_authorise_appears_only_in_the_shim(): void
    {
        $callers = [];
        $shim = __DIR__.'/../../../component/src/Tools/Concerns/HasToolExecutionContract.php';

        foreach ([...$this->toolFiles(), $shim] as $file) {
            if (str_contains((string) file_get_contents($file), '->authorise(')) {
                $callers[] = basename($file, '.php');
            }
        }

        self::assertSame(
            ['HasToolExecutionContract'],
            $callers,
            'authorise() must appear only in the Joomla shim. Found in: '.implode(', ', $callers),
        );
    }

    public function test_every_tool_names_the_asset_its_action_is_checked_against(): void
    {
        $missing = [];
        $checked = 0;

        foreach ($this->toolFiles() as $file) {
            $source = (string) file_get_contents($file);
            $name = basename($file, '.php');

            if (! str_contains($source, 'HasToolExecutionContract')) {
                continue;
            }

            $checked++;

            if (! str_contains($source, 'REQUIRED_ACTION')) {
                $missing[] = $name.' declares no REQUIRED_ACTION';
            }

            if (! str_contains($source, 'protected function requiredAsset(): string')) {
                $missing[] = $name.' does not answer requiredAsset(), so its action has no asset';
            }
        }

        self::assertGreaterThan(0, $checked, 'no tool declares the contract, so this proves nothing');
        self::assertSame([], $missing, implode("\n", $missing));
    }

    public function test_no_description_carries_a_prose_examples_block(): void
    {
        $offenders = [];
        $checked = 0;

        foreach ($this->toolFiles() as $file) {
            $source = (string) file_get_contents($file);
            $checked++;

            if (preg_match('/^EXAMPLES:$/m', $source) === 1) {
                $offenders[] = basename($file, '.php');
            }
        }

        self::assertGreaterThan(0, $checked, 'no tool file was read, so this proves nothing');
        self::assertSame(
            [],
            $offenders,
            'examples belong in the EXAMPLES const, not in description(). Offenders: '
            .implode(', ', $offenders),
        );
    }

    public function test_no_inlined_capability_check_blocks_the_console_path(): void
    {
        $refusedOnConsole = [];
        $allowedWithoutAccess = [];

        foreach ($this->guardedTools() as $class => $input) {
            $fqcn = 'PhpClaw\\Joomla\\Component\\Administrator\\Tools\\'.$class;

            $this->consoleJoomlaAccess();

            if ($this->isForbidden((new $fqcn(MockDatabase::raw($this)))->execute($input))) {
                $refusedOnConsole[] = $class;
            }

            $this->denyJoomlaAccess();

            if (! $this->isForbidden((new $fqcn(MockDatabase::raw($this)))->execute($input))) {
                $allowedWithoutAccess[] = $class;
            }
        }

        $this->grantJoomlaAccess();

        self::assertCount(
            4,
            $this->guardedTools(),
            'the guarded tool list shrank, so this proves less than it claims',
        );
        self::assertSame(
            [],
            $refusedOnConsole,
            'the console exemption is broken. The console and the MCP server would get FORBIDDEN '
            .'from: '.implode(', ', $refusedOnConsole),
        );
        self::assertSame(
            [],
            $allowedWithoutAccess,
            'the ACL guard let an unauthorised caller through, so the console half of this test '
            .'proves nothing. Offenders: '.implode(', ', $allowedWithoutAccess),
        );
    }

    private function guardedTools(): array
    {
        return [
            'JoomlaArticleTool' => ['schema' => true],
            'JoomlaCategoryTool' => ['schema' => true],
            'JoomlaUserTool' => ['schema' => true],
            'JoomlaExtensionTool' => ['schema' => true],
        ];
    }

    private function isForbidden(string $json): bool
    {
        $result = json_decode($json, true);

        return ($result['error']['code'] ?? null) === 'FORBIDDEN';
    }
}
