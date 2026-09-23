<?php

declare(strict_types=1);

namespace PhpClaw\Testing;

use PHPUnit\Framework\TestCase;

/**
 * Adapter-agnostic tool contract invariants, enforced by scanning the source. Each adapter
 * extends this with its own tool list; adapter-specific rules stay in the subclass.
 */
abstract class AbstractToolContractInvariants extends TestCase
{
    /**
     * Return every tool source file this adapter ships.
     *
     * @return array<int, string>
     */
    abstract protected function toolFiles(): array;

    /**
     * Return every tool that returns a collection of rows this adapter ships. The list is
     * the adapter's, so a new adapter cannot inherit a check that silently scans nothing.
     *
     * @return array<int, string>
     */
    abstract protected function collectionTools(): array;

    /**
     * Report why one collection tool has no stable secondary sort, or null if it has one. The
     * rule lives in core; only the evidence is the adapter's, as there is no shared shape.
     *
     * @param  string  $tool  Tool class name from collectionTools().
     * @return string|null The problem, or null when the tool sorts stably.
     */
    abstract protected function stableSecondarySortProblem(string $tool): ?string;

    /**
     * Return the source path of one tool by class name.
     *
     * @param  string  $name  Tool class name.
     * @return string Absolute path to the tool source file.
     */
    protected function toolPath(string $name): string
    {
        foreach ($this->toolFiles() as $file) {
            if (basename($file, '.php') === $name) {
                return $file;
            }
        }

        self::fail("no source file found for {$name}");
    }

    /**
     * Assert no tool source asks for an unbounded result set via a -1 row limit.
     *
     * @return void
     */
    public function test_no_tool_issues_an_unbounded_query(): void
    {
        $offenders = [];

        foreach ($this->toolFiles() as $file) {
            $source = (string) file_get_contents($file);

            if (preg_match("/'limit'\s*=>\s*-1/", $source) === 1) {
                $offenders[] = basename($file);
            }

            if (preg_match("/'posts_per_page'\s*=>\s*-1/", $source) === 1) {
                $offenders[] = basename($file);
            }

            if (preg_match("/'numberposts'\s*=>\s*-1/", $source) === 1) {
                $offenders[] = basename($file);
            }
        }

        self::assertNotEmpty($this->toolFiles(), 'the scan found no tool files, so it proves nothing');
        self::assertSame([], $offenders, 'unbounded query reintroduced in: '.implode(', ', $offenders));
    }

    /**
     * Return tool class names not yet on the execution contract. This list may only shrink.
     *
     * @return array<int, string>
     */
    abstract protected function pendingConversion(): array;

    /**
     * Assert every tool outside the pending list declares the full execution contract.
     *
     * @return void
     */
    public function test_every_converted_tool_declares_the_execution_contract(): void
    {
        $missing = [];
        $checked = 0;

        foreach ($this->toolFiles() as $file) {
            $source = (string) file_get_contents($file);
            $name = basename($file, '.php');

            if (in_array($name, $this->pendingConversion(), true)) {
                continue;
            }

            $checked++;

            foreach ([
                'use HasToolExecutionContract;',
                'protected function plan(',
                'protected function perform(',
                'protected function verify(',
                'protected function complete(',
                'public function requiredCapability(',
                "'additionalProperties' => false",
                'const ALLOWED_KEYS',
            ] as $needle) {
                if (! str_contains($source, $needle)) {
                    $missing[] = $name.' is missing '.$needle;
                }
            }
        }

        self::assertGreaterThan(0, $checked, 'the scan checked no converted tools, so it proves nothing');
        self::assertSame([], $missing, implode("\n", $missing));
    }

    /**
     * Assert the pending list names only existing tools that have not yet been converted.
     *
     * @return void
     */
    public function test_the_pending_conversion_list_only_names_real_tools(): void
    {
        $names = array_map(
            static fn (string $f): string => basename($f, '.php'),
            $this->toolFiles(),
        );

        $pendingList = $this->pendingConversion();

        self::assertSame(
            [],
            array_values(array_diff($pendingList, $names)),
            'the pending list names tools that do not exist; remove them from the list',
        );

        foreach ($pendingList as $pending) {
            self::assertStringNotContainsString(
                'HasToolExecutionContract',
                (string) file_get_contents($this->toolPath($pending)),
                "{$pending} already declares the contract; remove it from the pending list",
            );
        }
    }

    /**
     * Assert every collection tool sorts stably, and that the adapter named at least one.
     *
     * @return void
     */
    public function test_every_collection_tool_uses_a_stable_secondary_sort(): void
    {
        $missing = [];
        $checked = 0;

        foreach ($this->collectionTools() as $tool) {
            $checked++;
            $problem = $this->stableSecondarySortProblem($tool);

            if ($problem !== null) {
                $missing[] = $tool.' ('.$problem.')';
            }
        }

        self::assertGreaterThan(
            0,
            $checked,
            'the adapter named no collection tools, so this invariant scanned nothing. An empty '
            .'list here is not a pass, it is an absent check',
        );
        self::assertSame(
            [],
            $missing,
            'a collection tool without a secondary id sort returns non-deterministic pages on tied '
            .'sort values. Missing in: '.implode(', ', $missing),
        );
    }

    /**
     * Every capability check must stand down on the console path. A tool that inlines a check
     * must inline both halves, or the console and MCP surfaces get FORBIDDEN at user 0.
     *
     * @return void
     */
    public function test_no_inlined_capability_check_blocks_the_console_path(): void
    {
        $offenders = [];
        $checked = 0;

        foreach ($this->toolFiles() as $file) {
            $source = (string) file_get_contents($file);
            $name = basename($file, '.php');

            if (! str_contains($source, 'callerHasCapability')) {
                continue;
            }

            $checked++;

            if (! str_contains($source, 'runningInConsole')) {
                $offenders[] = $name.' calls callerHasCapability() without the console branch, so '
                    .'wp phpclaw send and the MCP server would get FORBIDDEN from it at user 0';
            }
        }

        self::assertGreaterThan(
            0,
            $checked,
            'no tool inlines a capability check, so this invariant matched nothing. If the shared '
            .'guard in core is now the only path, delete this test rather than letting it pass empty',
        );
        self::assertSame([], $offenders, implode("\n", $offenders));
    }

    /**
     * Assert every contract tool ships an EXAMPLES const and overrides examples() to return it.
     *
     * @return void
     */
    public function test_every_tool_exposes_at_least_one_worked_example(): void
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

            if (! str_contains($source, 'public const EXAMPLES')) {
                $missing[] = $name.' declares no EXAMPLES const, so the docs table would render it blank';

                continue;
            }

            if (! str_contains($source, 'public static function examples()')) {
                $missing[] = $name.' declares EXAMPLES but does not override examples(), so it reads back empty';
            }

            $hasSchemaMode = str_contains($source, "'schema',")
                || str_contains($source, "'schema' => [");

            if ($hasSchemaMode && ! str_contains($source, "'examples' => self::EXAMPLES")) {
                $missing[] = $name.' has a schema mode but does not surface its examples through it';
            }

            if (! $hasSchemaMode && str_contains($source, "'examples' => self::EXAMPLES")) {
                $missing[] = $name.' surfaces examples into a schema payload it does not have';
            }
        }

        self::assertGreaterThan(0, $checked, 'no tool declares the contract, so this proves nothing');
        self::assertSame([], $missing, implode("\n", $missing));

        $withSchema = 0;

        foreach ($this->toolFiles() as $file) {
            $source = (string) file_get_contents($file);

            if (str_contains($source, "'examples' => self::EXAMPLES")) {
                $withSchema++;
            }
        }

        self::assertGreaterThan(
            1,
            $withSchema,
            'the schema-surfacing half of this test matched nothing, so it could not have failed',
        );
    }

    /**
     * Assert every worked example uses only argument names the tool declares in ALLOWED_KEYS.
     *
     * @return void
     */
    public function test_every_example_names_arguments_the_tool_accepts(): void
    {
        $offenders = [];
        $checked = 0;

        foreach ($this->toolFiles() as $file) {
            $source = (string) file_get_contents($file);
            $name = basename($file, '.php');

            if (! preg_match('/public const EXAMPLES = \[(.*?)\n    \];/s', $source, $block)) {
                continue;
            }

            if (! preg_match('/const ALLOWED_KEYS = \[(.*?)\];/s', $source, $allowed)) {
                continue;
            }

            preg_match_all("/'([a-z_]+)'\s*=>/", $allowed[1], $keyMatches);
            $accepted = $keyMatches[1];

            if ($accepted === []) {
                preg_match_all("/'([a-z_]+)'/", $allowed[1], $keyMatches);
                $accepted = $keyMatches[1];
            }

            preg_match_all("/'arguments' => \[(.*?)\],?\n        \]/s", $block[1], $argBlocks);

            foreach ($argBlocks[1] as $args) {
                preg_match_all("/'([a-z_]+)'\s*=>/", $args, $used);

                foreach ($used[1] as $key) {
                    $checked++;

                    if (! in_array($key, $accepted, true)) {
                        $offenders[] = $name.' has an example passing "'.$key.'", which is not in ALLOWED_KEYS';
                    }
                }
            }
        }

        self::assertGreaterThan(0, $checked, 'no example arguments were checked, so this proves nothing');
        self::assertSame([], $offenders, implode("\n", $offenders));
    }

    /**
     * Return field names holding text not written by a site administrator.
     *
     * @return array<int, string>
     */
    abstract protected function freeTextFields(): array;

    /**
     * Assert a tool that can return a free-text column marks its output as untrusted content.
     *
     * @return void
     */
    public function test_any_tool_returning_free_text_declares_untrusted_content(): void
    {
        $offenders = [];
        $checked = 0;

        foreach ($this->toolFiles() as $file) {
            $source = (string) file_get_contents($file);
            $name = basename($file, '.php');

            if (! preg_match('/const AVAILABLE_COLUMNS = \[(.*?)\];/s', $source, $columns)) {
                continue;
            }

            preg_match_all("/'([a-z_0-9]+)'/", $columns[1], $matches);
            $free = array_values(array_intersect($matches[1], $this->freeTextFields()));

            if ($free === []) {
                continue;
            }

            $checked++;

            if (! str_contains($source, 'UNTRUSTED_CONTENT')) {
                $offenders[] = $name.' returns free text ('.implode(', ', $free)
                    .') but never emits UNTRUSTED_CONTENT';

                continue;
            }

            if (! preg_match('/const UNTRUSTED_COLUMNS = \[(.*?)\];/s', $source, $declared)) {
                $offenders[] = $name.' mentions UNTRUSTED_CONTENT but declares no UNTRUSTED_COLUMNS list';

                continue;
            }

            if (trim($declared[1]) === '') {
                $offenders[] = $name.' declares an empty UNTRUSTED_COLUMNS list, so it warns about '
                    .'no field. Either name the fields or remove the const';
            }
        }

        self::assertGreaterThan(
            1,
            $checked,
            'the scan matched at most one tool, so it could not have caught a regression',
        );
        self::assertSame([], $offenders, implode("\n", $offenders));
    }

    /**
     * Assert every UNTRUSTED_COLUMNS entry is a column the tool actually exposes.
     *
     * @return void
     */
    public function test_every_untrusted_column_is_a_field_the_tool_can_return(): void
    {
        $offenders = [];
        $checked = 0;

        foreach ($this->toolFiles() as $file) {
            $source = (string) file_get_contents($file);
            $name = basename($file, '.php');

            if (! preg_match('/const UNTRUSTED_COLUMNS = \[(.*?)\];/s', $source, $untrusted)) {
                continue;
            }

            if (! preg_match('/const AVAILABLE_COLUMNS = \[(.*?)\];/s', $source, $available)) {
                continue;
            }

            preg_match_all("/'([a-z_0-9]+)'/", $untrusted[1], $u);
            preg_match_all("/'([a-z_0-9]+)'/", $available[1], $a);

            foreach ($u[1] as $field) {
                $checked++;

                if (! in_array($field, $a[1], true)) {
                    $offenders[] = $name.' marks "'.$field.'" untrusted but never returns it, '
                        .'so the warning can never fire for it';
                }
            }
        }

        self::assertGreaterThan(0, $checked, 'no untrusted columns were checked, so this proves nothing');
        self::assertSame([], $offenders, implode("\n", $offenders));
    }

    /**
     * Assert no tool guards with method_exists(), which is false for a mock and skips the path.
     *
     * @return void
     */
    public function test_no_tool_guards_with_method_exists(): void
    {
        $offenders = [];

        foreach ($this->toolFiles() as $file) {
            if (preg_match('/method_exists\(\$/', (string) file_get_contents($file)) === 1) {
                $offenders[] = basename($file);
            }
        }

        self::assertSame(
            [],
            $offenders,
            'method_exists() returns false for a Mockery mock, silently skipping the guarded path. '
            .'Use is_callable([$obj, $method]) instead. Offenders: '.implode(', ', $offenders),
        );
    }
}
