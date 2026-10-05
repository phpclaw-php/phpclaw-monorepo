<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Providers;

use PhpClaw\Agent\Message;
use PhpClaw\Providers\AnthropicProvider;
use PhpClaw\Support\JsonSchemaValidator;
use PhpClaw\Tests\Unit\Flow\Support\ScriptedHttpClient;
use PHPUnit\Framework\TestCase;

final class AnthropicStructuredOutputTest extends TestCase
{
    private function nestedSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'order_id' => ['type' => 'string', 'minLength' => 5],
                'shipping' => [
                    'type' => 'object',
                    'properties' => [
                        'city' => ['type' => 'string'],
                    ],
                    'required' => ['city'],
                ],
                'total' => ['type' => 'number', 'minimum' => 0, 'maximum' => 100000],
            ],
            'required' => ['order_id', 'shipping', 'total'],
        ];
    }

    private function anthropicTextResponse(array $data, int $inputTokens, int $outputTokens): array
    {
        return [
            'content' => [['type' => 'text', 'text' => (string) json_encode($data)]],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => $inputTokens, 'output_tokens' => $outputTokens],
        ];
    }

    public function test_a_model_in_the_structured_output_list_reports_native_support(): void
    {
        $provider = new AnthropicProvider(apiKey: 'test-key', model: 'claude-sonnet-5');

        self::assertTrue($provider->supportsResponseSchema());
    }

    public function test_a_model_not_in_the_structured_output_list_reports_no_native_support(): void
    {
        $provider = new AnthropicProvider(apiKey: 'test-key', model: 'claude-sonnet-latest');

        self::assertFalse($provider->supportsResponseSchema());
    }

    public function test_a_dated_id_not_on_the_list_reports_no_native_support(): void
    {
        $provider = new AnthropicProvider(apiKey: 'test-key', model: 'claude-sonnet-4-5-20260101');

        self::assertFalse($provider->supportsResponseSchema());
    }

    public function test_send_with_a_response_schema_sends_output_config_and_no_respond_with_schema_tool(): void
    {
        $http = new ScriptedHttpClient;
        $http->queuePostResponse($this->anthropicTextResponse(['order_id' => 'A-1042', 'total' => 87.5], 25, 8));

        $provider = (new AnthropicProvider(apiKey: 'test-key', http: $http, model: 'claude-sonnet-5'))
            ->withResponseSchema(['type' => 'object', 'properties' => ['order_id' => ['type' => 'string'], 'total' => ['type' => 'number']]]);

        $result = $provider->send([Message::user('hi')], []);

        self::assertSame('text', $result['type']);
        self::assertSame((string) json_encode(['order_id' => 'A-1042', 'total' => 87.5]), $result['text']);

        $body = $http->postBodies[0];
        self::assertArrayNotHasKey('tools', $body);
        self::assertSame('json_schema', $body['output_config']['format']['type']);
    }

    public function test_without_a_response_schema_the_request_body_has_no_output_config(): void
    {
        $http = new ScriptedHttpClient;
        $http->queuePostResponse($this->anthropicTextResponse(['order_id' => 'A-1042', 'total' => 87.5], 25, 8));

        $provider = new AnthropicProvider(apiKey: 'test-key', http: $http, model: 'claude-sonnet-5');
        $provider->send([Message::user('hi')], []);

        self::assertArrayNotHasKey('output_config', $http->postBodies[0]);
    }

    public function test_the_vendor_schema_adds_additional_properties_false_recursively_and_removes_unsupported_numeric_and_length_keywords(): void
    {
        $http = new ScriptedHttpClient;
        $http->queuePostResponse($this->anthropicTextResponse(
            ['order_id' => 'A-10420', 'shipping' => ['city' => 'NYC'], 'total' => 87.5],
            20,
            10,
        ));

        $provider = (new AnthropicProvider(apiKey: 'test-key', http: $http, model: 'claude-sonnet-5'))
            ->withResponseSchema($this->nestedSchema());

        $provider->send([Message::user('hi')], []);

        $sentSchema = $http->postBodies[0]['output_config']['format']['schema'];

        self::assertArrayHasKey('additionalProperties', $sentSchema);
        self::assertFalse($sentSchema['additionalProperties']);
        self::assertArrayHasKey('additionalProperties', $sentSchema['properties']['shipping']);
        self::assertFalse($sentSchema['properties']['shipping']['additionalProperties']);
        self::assertArrayNotHasKey('minLength', $sentSchema['properties']['order_id']);
        self::assertArrayNotHasKey('minimum', $sentSchema['properties']['total']);
        self::assertArrayNotHasKey('maximum', $sentSchema['properties']['total']);
    }

    public function test_an_items_schema_of_objects_gains_additional_properties_false_and_loses_min_length_on_each_item(): void
    {
        $http = new ScriptedHttpClient;
        $http->queuePostResponse($this->anthropicTextResponse([['sku' => 'ABC123']], 20, 10));

        $schema = [
            'type' => 'array',
            'items' => [
                'type' => 'object',
                'properties' => [
                    'sku' => ['type' => 'string', 'minLength' => 3],
                ],
                'required' => ['sku'],
            ],
        ];

        $provider = (new AnthropicProvider(apiKey: 'test-key', http: $http, model: 'claude-sonnet-5'))
            ->withResponseSchema($schema);

        $provider->send([Message::user('hi')], []);

        $sentSchema = $http->postBodies[0]['output_config']['format']['schema'];

        self::assertArrayHasKey('additionalProperties', $sentSchema['items']);
        self::assertFalse($sentSchema['items']['additionalProperties']);
        self::assertArrayNotHasKey('minLength', $sentSchema['items']['properties']['sku']);
    }

    public function test_an_explicit_additional_properties_on_the_caller_schema_is_left_untouched(): void
    {
        $http = new ScriptedHttpClient;
        $http->queuePostResponse($this->anthropicTextResponse(['order_id' => 'A-10420'], 20, 10));

        $schema = [
            'type' => 'object',
            'properties' => ['order_id' => ['type' => 'string']],
            'additionalProperties' => true,
        ];

        $provider = (new AnthropicProvider(apiKey: 'test-key', http: $http, model: 'claude-sonnet-5'))
            ->withResponseSchema($schema);

        $provider->send([Message::user('hi')], []);

        $sentSchema = $http->postBodies[0]['output_config']['format']['schema'];

        self::assertTrue($sentSchema['additionalProperties']);
    }

    public function test_local_validation_still_rejects_a_too_short_value_against_the_original_schema(): void
    {
        $validator = new JsonSchemaValidator;

        $errors = $validator->validate($this->nestedSchema(), [
            'order_id' => 'ab',
            'shipping' => ['city' => 'NYC'],
            'total' => 50,
        ]);

        self::assertNotEmpty($errors);
    }

    public function test_local_validation_accepts_a_value_that_satisfies_the_original_schema(): void
    {
        $validator = new JsonSchemaValidator;

        $errors = $validator->validate($this->nestedSchema(), [
            'order_id' => 'A-10420',
            'shipping' => ['city' => 'NYC'],
            'total' => 50,
        ]);

        self::assertSame([], $errors);
    }
}
