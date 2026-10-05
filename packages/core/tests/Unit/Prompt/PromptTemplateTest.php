<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Prompt;

use PhpClaw\Exceptions\PromptTemplateException;
use PhpClaw\Prompt\PromptTemplate;
use PHPUnit\Framework\TestCase;
use Stringable;

final class PromptTemplateTest extends TestCase
{
    public function test_format_substitutes_a_single_placeholder(): void
    {
        $email = 'Hi team, please ship order #4471 to 12 Elm Street by Friday.';

        $template = PromptTemplate::from('Extract the order details from: {body}');

        $this->assertSame(
            'Extract the order details from: Hi team, please ship order #4471 to 12 Elm Street by Friday.',
            $template->format(['body' => $email]),
        );
    }

    public function test_format_substitutes_adjacent_placeholders(): void
    {
        $template = PromptTemplate::from('{greeting}{name}');

        $this->assertSame('HelloAva', $template->format(['greeting' => 'Hello', 'name' => 'Ava']));
    }

    public function test_format_substitutes_repeated_placeholder_with_same_value(): void
    {
        $template = PromptTemplate::from('Order {id} confirmed. Reference: {id}.');

        $this->assertSame(
            'Order 4471 confirmed. Reference: 4471.',
            $template->format(['id' => 4471]),
        );
    }

    public function test_format_substitutes_placeholder_at_start_and_end(): void
    {
        $template = PromptTemplate::from('{first} middle {last}');

        $this->assertSame('Ava middle Stone', $template->format(['first' => 'Ava', 'last' => 'Stone']));
    }

    public function test_format_returns_template_unchanged_when_no_placeholders(): void
    {
        $template = PromptTemplate::from('Summarize the ticket.');

        $this->assertSame('Summarize the ticket.', $template->format([]));
    }

    public function test_format_returns_empty_string_for_empty_template(): void
    {
        $template = PromptTemplate::from('');

        $this->assertSame('', $template->format([]));
    }

    public function test_format_accepts_int_value(): void
    {
        $template = PromptTemplate::from('Total: {total}');

        $this->assertSame('Total: 42', $template->format(['total' => 42]));
    }

    public function test_format_accepts_float_value(): void
    {
        $template = PromptTemplate::from('Total: {total}');

        $this->assertSame('Total: 42.5', $template->format(['total' => 42.5]));
    }

    public function test_format_accepts_true_boolean_value(): void
    {
        $template = PromptTemplate::from('Urgent: {urgent}');

        $this->assertSame('Urgent: 1', $template->format(['urgent' => true]));
    }

    public function test_format_accepts_false_boolean_value(): void
    {
        $template = PromptTemplate::from('Urgent: {urgent}');

        $this->assertSame('Urgent: ', $template->format(['urgent' => false]));
    }

    public function test_format_accepts_zero_value(): void
    {
        $template = PromptTemplate::from('Retries left: {count}');

        $this->assertSame('Retries left: 0', $template->format(['count' => 0]));
    }

    public function test_format_accepts_empty_string_value(): void
    {
        $template = PromptTemplate::from('Note: {note}');

        $this->assertSame('Note: ', $template->format(['note' => '']));
    }

    public function test_format_accepts_stringable_value(): void
    {
        $customer = new class implements Stringable
        {
            public function __toString(): string
            {
                return 'Ava Stone';
            }
        };

        $template = PromptTemplate::from('Customer: {customer}');

        $this->assertSame('Customer: Ava Stone', $template->format(['customer' => $customer]));
    }

    public function test_format_ignores_extra_variables(): void
    {
        $template = PromptTemplate::from('Hello {name}');

        $this->assertSame('Hello Ava', $template->format(['name' => 'Ava', 'unused' => 'value']));
    }

    public function test_format_throws_when_a_placeholder_has_no_value(): void
    {
        $template = PromptTemplate::from('Extract the order details from: {body}');

        $this->expectException(PromptTemplateException::class);

        $template->format([]);
    }

    public function test_format_throws_when_value_is_null(): void
    {
        $template = PromptTemplate::from('Note: {note}');

        $this->expectException(PromptTemplateException::class);

        $template->format(['note' => null]);
    }

    public function test_format_throws_when_value_is_an_array(): void
    {
        $template = PromptTemplate::from('Items: {items}');

        $this->expectException(PromptTemplateException::class);

        $template->format(['items' => ['pen', 'paper']]);
    }

    public function test_format_throws_when_value_is_a_non_stringable_object(): void
    {
        $template = PromptTemplate::from('Customer: {customer}');

        $this->expectException(PromptTemplateException::class);

        $template->format(['customer' => new \stdClass]);
    }

    public function test_format_treats_double_braces_as_literal_single_brace(): void
    {
        $template = PromptTemplate::from('Use {{literal}} braces around {name}.');

        $this->assertSame('Use {literal} braces around Ava.', $template->format(['name' => 'Ava']));
    }

    public function test_format_keeps_invalid_placeholder_braces_literal(): void
    {
        $template = PromptTemplate::from('JSON example: {"order_id": 1} for {name}.');

        $this->assertSame('JSON example: {"order_id": 1} for Ava.', $template->format(['name' => 'Ava']));
    }

    public function test_variables_lists_distinct_names_in_order_of_first_appearance(): void
    {
        $template = PromptTemplate::from('{last}, {first} {last}');

        $this->assertSame(['last', 'first'], $template->variables());
    }

    public function test_variables_returns_empty_list_when_no_placeholders(): void
    {
        $template = PromptTemplate::from('No placeholders here.');

        $this->assertSame([], $template->variables());
    }

    public function test_partial_binds_given_variables_and_leaves_the_rest(): void
    {
        $template = PromptTemplate::from('Dear {name}, your order {id} has shipped.');

        $partial = $template->partial(['name' => 'Ava']);

        $this->assertSame(['id'], $partial->variables());
        $this->assertSame('Dear Ava, your order 4471 has shipped.', $partial->format(['id' => 4471]));
    }

    public function test_partial_does_not_mutate_the_original_template(): void
    {
        $template = PromptTemplate::from('Dear {name}, your order {id} has shipped.');

        $template->partial(['name' => 'Ava']);

        $this->assertSame(['name', 'id'], $template->variables());
    }

    public function test_partial_ignores_extra_variables(): void
    {
        $template = PromptTemplate::from('Dear {name}.');

        $partial = $template->partial(['name' => 'Ava', 'unused' => 'value']);

        $this->assertSame('Dear Ava.', $partial->format([]));
    }

    public function test_partial_throws_when_bound_value_is_not_scalar_or_stringable(): void
    {
        $template = PromptTemplate::from('Items: {items}');

        $this->expectException(PromptTemplateException::class);

        $template->partial(['items' => ['pen', 'paper']]);
    }
}
