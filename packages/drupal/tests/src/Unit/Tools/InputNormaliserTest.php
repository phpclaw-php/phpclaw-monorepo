<?php

declare(strict_types=1);

namespace PhpClaw\Drupal\Tests\Unit\Tools;

use PhpClaw\Drupal\Tools\InputNormaliser;
use PHPUnit\Framework\TestCase;

final class InputNormaliserTest extends TestCase
{
    public function test_scalars_pass_through_unchanged(): void
    {
        self::assertSame(
            ['a' => 'x', 'b' => 5, 'c' => true],
            InputNormaliser::flattenArrayValues(['a' => 'x', 'b' => 5, 'c' => true]),
        );
    }

    public function test_single_element_array_is_unwrapped_to_first_value(): void
    {
        self::assertSame(
            ['k' => 'only'],
            InputNormaliser::flattenArrayValues(['k' => ['only']]),
        );
    }

    public function test_multi_element_array_unwraps_to_first_element(): void
    {
        self::assertSame(
            ['k' => 'first'],
            InputNormaliser::flattenArrayValues(['k' => ['first', 'second']]),
        );
    }

    public function test_empty_array_becomes_empty_string(): void
    {
        self::assertSame(
            ['k' => ''],
            InputNormaliser::flattenArrayValues(['k' => []]),
        );
    }

    public function test_empty_input_returns_empty(): void
    {
        self::assertSame([], InputNormaliser::flattenArrayValues([]));
    }
}
