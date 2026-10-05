<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Support;

use PhpClaw\Exceptions\UnsupportedSchemaException;
use PhpClaw\Support\JsonSchemaValidator;
use PHPUnit\Framework\TestCase;

final class JsonSchemaValidatorTest extends TestCase
{
    private JsonSchemaValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new JsonSchemaValidator;
    }

    public function test_valid_data_against_a_simple_object_schema_returns_no_errors(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'order_id' => ['type' => 'string'],
                'total' => ['type' => 'number'],
            ],
            'required' => ['order_id', 'total'],
        ];

        $errors = $this->validator->validate($schema, ['order_id' => 'A-1', 'total' => 42.5]);

        self::assertSame([], $errors);
    }

    public function test_type_mismatch_at_root_reports_the_root_path(): void
    {
        $errors = $this->validator->validate(['type' => 'string'], 42);

        self::assertCount(1, $errors);
        self::assertStringStartsWith('$:', $errors[0]);
    }

    public function test_required_property_missing_reports_its_path(): void
    {
        $schema = ['type' => 'object', 'required' => ['order_id']];

        $errors = $this->validator->validate($schema, []);

        self::assertSame(['$.order_id: required property is missing.'], $errors);
    }

    public function test_required_property_present_passes(): void
    {
        $schema = ['type' => 'object', 'required' => ['order_id']];

        $errors = $this->validator->validate($schema, ['order_id' => 'A-1']);

        self::assertSame([], $errors);
    }

    public function test_nested_object_property_error_reports_the_dotted_path(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'customer' => [
                    'type' => 'object',
                    'properties' => ['email' => ['type' => 'string']],
                    'required' => ['email'],
                ],
            ],
        ];

        $errors = $this->validator->validate($schema, ['customer' => []]);

        self::assertSame(['$.customer.email: required property is missing.'], $errors);
    }

    public function test_array_items_error_reports_the_indexed_path(): void
    {
        $schema = [
            'type' => 'array',
            'items' => ['type' => 'string'],
        ];

        $errors = $this->validator->validate($schema, ['ok', 42]);

        self::assertCount(1, $errors);
        self::assertStringStartsWith('$[1]:', $errors[0]);
    }

    public function test_array_without_an_items_schema_skips_element_validation(): void
    {
        $errors = $this->validator->validate(['type' => 'array'], [1, 'two', true]);

        self::assertSame([], $errors);
    }

    public function test_enum_value_in_the_list_passes(): void
    {
        $errors = $this->validator->validate(['enum' => ['a', 'b']], 'a');

        self::assertSame([], $errors);
    }

    public function test_enum_value_not_in_the_list_fails(): void
    {
        $errors = $this->validator->validate(['enum' => ['a', 'b']], 'c');

        self::assertSame(['$: value is not one of the allowed enum values.'], $errors);
    }

    public function test_additional_properties_false_rejects_an_undeclared_key(): void
    {
        $schema = ['type' => 'object', 'properties' => ['a' => ['type' => 'string']], 'additionalProperties' => false];

        $errors = $this->validator->validate($schema, ['a' => 'x', 'b' => 'y']);

        self::assertSame(['$.b: additional property is not allowed.'], $errors);
    }

    public function test_additional_properties_absent_allows_an_undeclared_key(): void
    {
        $schema = ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]];

        $errors = $this->validator->validate($schema, ['a' => 'x', 'b' => 'y']);

        self::assertSame([], $errors);
    }

    public function test_additional_properties_true_allows_an_undeclared_key(): void
    {
        $schema = ['type' => 'object', 'properties' => ['a' => ['type' => 'string']], 'additionalProperties' => true];

        $errors = $this->validator->validate($schema, ['a' => 'x', 'b' => 'y']);

        self::assertSame([], $errors);
    }

    public function test_integer_type_accepts_a_native_int(): void
    {
        $errors = $this->validator->validate(['type' => 'integer'], 4);

        self::assertSame([], $errors);
    }

    public function test_integer_type_accepts_a_float_with_no_fraction(): void
    {
        $errors = $this->validator->validate(['type' => 'integer'], 4.0);

        self::assertSame([], $errors);
    }

    public function test_integer_type_rejects_a_float_with_a_fraction(): void
    {
        $errors = $this->validator->validate(['type' => 'integer'], 4.5);

        self::assertCount(1, $errors);
    }

    public function test_number_type_accepts_both_int_and_float(): void
    {
        self::assertSame([], $this->validator->validate(['type' => 'number'], 4));
        self::assertSame([], $this->validator->validate(['type' => 'number'], 4.5));
    }

    public function test_minimum_boundary_value_equal_to_the_limit_passes(): void
    {
        $errors = $this->validator->validate(['type' => 'number', 'minimum' => 10], 10);

        self::assertSame([], $errors);
    }

    public function test_minimum_value_below_the_limit_fails(): void
    {
        $errors = $this->validator->validate(['type' => 'number', 'minimum' => 10], 9);

        self::assertCount(1, $errors);
    }

    public function test_maximum_boundary_value_equal_to_the_limit_passes(): void
    {
        $errors = $this->validator->validate(['type' => 'number', 'maximum' => 10], 10);

        self::assertSame([], $errors);
    }

    public function test_maximum_value_above_the_limit_fails(): void
    {
        $errors = $this->validator->validate(['type' => 'number', 'maximum' => 10], 11);

        self::assertCount(1, $errors);
    }

    public function test_min_length_counts_multibyte_characters_not_bytes(): void
    {
        $errors = $this->validator->validate(['type' => 'string', 'minLength' => 3], 'café');

        self::assertSame([], $errors);
    }

    public function test_min_length_multibyte_violation_fails(): void
    {
        $errors = $this->validator->validate(['type' => 'string', 'minLength' => 5], 'café');

        self::assertCount(1, $errors);
    }

    public function test_max_length_counts_multibyte_characters_not_bytes(): void
    {
        $errors = $this->validator->validate(['type' => 'string', 'maxLength' => 4], 'café');

        self::assertSame([], $errors);
    }

    public function test_max_length_multibyte_violation_fails(): void
    {
        $errors = $this->validator->validate(['type' => 'string', 'maxLength' => 3], 'café');

        self::assertCount(1, $errors);
    }

    public function test_description_and_title_keywords_are_allowed(): void
    {
        $schema = ['type' => 'string', 'description' => 'A note.', 'title' => 'Note'];

        $errors = $this->validator->validate($schema, 'hello');

        self::assertSame([], $errors);
    }

    public function test_unsupported_keyword_at_root_throws_before_returning_errors(): void
    {
        $this->expectException(UnsupportedSchemaException::class);
        $this->expectExceptionMessageMatches("/'pattern'.*\\\$\\./");

        $this->validator->validate(['type' => 'string', 'pattern' => '^A'], 'A1');
    }

    public function test_unsupported_keyword_nested_deep_throws_with_the_nested_path(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'customer' => [
                    'type' => 'object',
                    'properties' => [
                        'email' => ['type' => 'string', 'pattern' => '^.+@.+$'],
                    ],
                ],
            ],
        ];

        $this->expectException(UnsupportedSchemaException::class);
        $this->expectExceptionMessageMatches('/\$\.customer\.email/');

        $this->validator->assertSupported($schema);
    }

    public function test_unsupported_keyword_inside_items_throws(): void
    {
        $schema = ['type' => 'array', 'items' => ['type' => 'string', 'const' => 'x']];

        $this->expectException(UnsupportedSchemaException::class);

        $this->validator->assertSupported($schema);
    }

    public function test_trivial_type_list_matches_any_listed_type(): void
    {
        $errors = $this->validator->validate(['type' => ['string', 'null']], null);

        self::assertSame([], $errors);
    }

    public function test_boolean_type_accepts_true_and_false(): void
    {
        self::assertSame([], $this->validator->validate(['type' => 'boolean'], true));
        self::assertSame([], $this->validator->validate(['type' => 'boolean'], false));
    }

    public function test_boolean_type_rejects_an_integer_and_a_string(): void
    {
        $errorsForInt = $this->validator->validate(['type' => 'boolean'], 1);
        $errorsForString = $this->validator->validate(['type' => 'boolean'], 'true');

        self::assertCount(1, $errorsForInt);
        self::assertCount(1, $errorsForString);
    }

    public function test_type_list_with_no_matching_type_reports_the_path(): void
    {
        $errors = $this->validator->validate(['type' => ['string', 'null']], 5);

        self::assertCount(1, $errors);
        self::assertStringStartsWith('$:', $errors[0]);
        self::assertStringContainsString('string|null', $errors[0]);
    }
}
