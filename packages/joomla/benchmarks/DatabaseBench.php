<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Benchmarks;

use PhpClaw\SqlGuard\SqlDialect;
use PhpClaw\SqlGuard\SqlGuardException;
use PhpClaw\SqlGuard\SqlReadOnlyGuard;

/**
 * @BeforeMethods({"setUp"})
 *
 * @Iterations(3)
 *
 * @Revs(50)
 */
final class DatabaseBench
{
    private SqlReadOnlyGuard $guard;

    private string $select = '';

    private string $joined = '';

    private string $rejected = '';

    /**
     * Build the guard DatabaseTool uses and the statements it is asked to validate.
     *
     * @return void
     */
    public function setUp(): void
    {
        $this->guard = new SqlReadOnlyGuard(SqlDialect::Generic);
        $this->select = 'SELECT id, title, state FROM jos_content WHERE state = 1 ORDER BY created DESC LIMIT 20';
        $this->joined = 'SELECT c.id, c.title, cat.title AS category FROM jos_content c '
            .'INNER JOIN jos_categories cat ON cat.id = c.catid WHERE c.state = 1 LIMIT 50';
        $this->rejected = 'UPDATE jos_content SET state = 0 WHERE id = 1';
    }

    /**
     * @Subject
     *
     * @return void
     */
    public function bench_guard_accepts_a_simple_select(): void
    {
        $this->guard->validate($this->select);
    }

    /**
     * @Subject
     *
     * @return void
     */
    public function bench_guard_accepts_a_joined_select(): void
    {
        $this->guard->validate($this->joined);
    }

    /**
     * @Subject
     *
     * @return void
     */
    public function bench_guard_rejects_a_write(): void
    {
        try {
            $this->guard->validate($this->rejected);
        } catch (SqlGuardException) {

        }
    }

    /**
     * @Subject
     *
     * @return void
     */
    public function bench_prefix_substitution(): void
    {
        str_replace('#__', 'jos_', 'SELECT id FROM #__content WHERE catid IN (SELECT id FROM #__categories)');
    }
}
