<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Graph;

use PhpClaw\Graph\GraphState;
use PHPUnit\Framework\TestCase;

final class GraphStateTest extends TestCase
{
    public function test_get_returns_the_initial_value_and_the_default_for_a_missing_key(): void
    {
        $state = new GraphState(['topic' => 'refund policy']);

        $this->assertSame('refund policy', $state->get('topic'));
        $this->assertNull($state->get('draft'));
        $this->assertSame('none', $state->get('draft', 'none'));
    }

    public function test_get_returns_a_stored_null_instead_of_the_default(): void
    {
        $state = new GraphState(['reviewer_note' => null]);

        $this->assertNull($state->get('reviewer_note', 'fallback'));
    }

    public function test_set_stores_the_value_and_returns_the_same_state(): void
    {
        $state = new GraphState;

        $returned = $state->set('draft', 'v1');

        $this->assertSame($state, $returned);
        $this->assertSame(['draft' => 'v1'], $state->all());
    }
}
