<?php

declare(strict_types=1);

namespace PhpClaw\Support;

use PhpClaw\Exceptions\UnsupportedSchemaException;

/**
 * Validates decoded JSON data against a restricted subset of JSON Schema, with no external dependency.
 */
final class JsonSchemaValidator
{
    private const SUPPORTED_KEYWORDS = [
        'type', 'properties', 'required', 'items', 'enum',
        'additionalProperties', 'minimum', 'maximum', 'minLength', 'maxLength',
        'description', 'title',
    ];

    /**
     * Recursively confirm every keyword in the schema tree is one this validator enforces.
     *
     * @param  array<string, mixed>  $schema  JSON Schema fragment to check.
     * @return void
     *
     * @throws UnsupportedSchemaException When any keyword outside the supported set is present.
     */
    public function assertSupported(array $schema): void
    {
        $this->assertSupportedNode($schema, '$');
    }

    /**
     * Validate decoded data against a schema, after confirming every keyword is supported.
     *
     * @param  array<string, mixed>  $schema  JSON Schema to validate against.
     * @param  mixed  $data  Decoded value to check.
     * @return list<string> Human-readable errors, each naming a JSON path; empty when valid.
     *
     * @throws UnsupportedSchemaException When any keyword outside the supported set is present.
     */
    public function validate(array $schema, mixed $data): array
    {
        $this->assertSupported($schema);

        $errors = [];
        $this->validateNode($schema, $data, '$', $errors);

        return $errors;
    }

    /**
     * Walk one schema node and every nested `properties`/`items` schema, rejecting unsupported keywords.
     *
     * @param  array<string, mixed>  $schema  Schema fragment at this node.
     * @param  string  $path  JSON path of this node, for the exception message.
     * @return void
     *
     * @throws UnsupportedSchemaException When any keyword outside the supported set is present.
     */
    private function assertSupportedNode(array $schema, string $path): void
    {
        foreach (array_keys($schema) as $keyword) {
            if (! is_string($keyword) || ! in_array($keyword, self::SUPPORTED_KEYWORDS, true)) {
                throw new UnsupportedSchemaException("Unsupported JSON Schema keyword '{$keyword}' at {$path}.");
            }
        }

        if (isset($schema['properties']) && is_array($schema['properties'])) {
            foreach ($schema['properties'] as $name => $child) {
                if (is_array($child)) {
                    $this->assertSupportedNode($child, "{$path}.{$name}");
                }
            }
        }

        if (isset($schema['items']) && is_array($schema['items'])) {
            $this->assertSupportedNode($schema['items'], "{$path}[]");
        }
    }

    /**
     * Validate one node's `type`, `enum`, and type-specific constraints, then recurse into children.
     *
     * @param  array<string, mixed>  $schema  Schema fragment at this node.
     * @param  mixed  $data  Decoded value at this node.
     * @param  string  $path  JSON path of this node.
     * @param  list<string>  $errors  Accumulator, appended in place.
     * @return void
     */
    private function validateNode(array $schema, mixed $data, string $path, array &$errors): void
    {
        $type = $schema['type'] ?? null;

        if ($type !== null && ! $this->matchesType($type, $data)) {
            $errors[] = "{$path}: expected type {$this->describeType($type)}, got ".get_debug_type($data).'.';

            return;
        }

        if (isset($schema['enum']) && is_array($schema['enum']) && ! $this->inEnum($data, $schema['enum'])) {
            $errors[] = "{$path}: value is not one of the allowed enum values.";
        }

        if (is_string($data)) {
            $this->validateStringConstraints($schema, $data, $path, $errors);
        } elseif (is_int($data) || is_float($data)) {
            $this->validateNumberConstraints($schema, $data, $path, $errors);
        } elseif (is_array($data) && $this->isObjectNode($schema, $data)) {
            $this->validateObject($schema, $data, $path, $errors);
        } elseif (is_array($data)) {
            $this->validateArray($schema, $data, $path, $errors);
        }
    }

    /**
     * Decide whether an array-valued node is an object (for `required`/`additionalProperties`) or a
     * list, preferring the declared `type` and falling back to the PHP array shape when undeclared.
     *
     * @param  array<string, mixed>  $schema  Schema fragment at this node.
     * @param  array<array-key, mixed>  $data  Decoded array at this node.
     * @return bool
     */
    private function isObjectNode(array $schema, array $data): bool
    {
        $type = $schema['type'] ?? null;

        return match (true) {
            $type === 'object' => true,
            $type === 'array' => false,
            default => ! array_is_list($data),
        };
    }

    /**
     * Check `required` properties are present and recurse into every declared child property.
     *
     * @param  array<string, mixed>  $schema  Schema fragment at this node.
     * @param  array<string, mixed>  $data  Decoded object at this node.
     * @param  string  $path  JSON path of this node.
     * @param  list<string>  $errors  Accumulator, appended in place.
     * @return void
     */
    private function validateObject(array $schema, array $data, string $path, array &$errors): void
    {
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];

        foreach ((array) ($schema['required'] ?? []) as $requiredKey) {
            if (! array_key_exists((string) $requiredKey, $data)) {
                $errors[] = "{$path}.{$requiredKey}: required property is missing.";
            }
        }

        foreach ($properties as $key => $propertySchema) {
            if (array_key_exists($key, $data) && is_array($propertySchema)) {
                $this->validateNode($propertySchema, $data[$key], "{$path}.{$key}", $errors);
            }
        }

        if (($schema['additionalProperties'] ?? null) === false) {
            $allowed = array_keys($properties);
            foreach (array_keys($data) as $key) {
                if (! in_array($key, $allowed, true)) {
                    $errors[] = "{$path}.{$key}: additional property is not allowed.";
                }
            }
        }
    }

    /**
     * Recurse into every element against the `items` schema, when one is declared.
     *
     * @param  array<string, mixed>  $schema  Schema fragment at this node.
     * @param  list<mixed>  $data  Decoded array at this node.
     * @param  string  $path  JSON path of this node.
     * @param  list<string>  $errors  Accumulator, appended in place.
     * @return void
     */
    private function validateArray(array $schema, array $data, string $path, array &$errors): void
    {
        $items = $schema['items'] ?? null;

        if (! is_array($items)) {
            return;
        }

        foreach ($data as $index => $element) {
            $this->validateNode($items, $element, "{$path}[{$index}]", $errors);
        }
    }

    /**
     * Check `minLength`/`maxLength` using multibyte-aware length.
     *
     * @param  array<string, mixed>  $schema  Schema fragment at this node.
     * @param  string  $data  Decoded string at this node.
     * @param  string  $path  JSON path of this node.
     * @param  list<string>  $errors  Accumulator, appended in place.
     * @return void
     */
    private function validateStringConstraints(array $schema, string $data, string $path, array &$errors): void
    {
        $length = mb_strlen($data);

        if (isset($schema['minLength']) && $length < (int) $schema['minLength']) {
            $errors[] = "{$path}: string is shorter than the minimum length of {$schema['minLength']}.";
        }

        if (isset($schema['maxLength']) && $length > (int) $schema['maxLength']) {
            $errors[] = "{$path}: string is longer than the maximum length of {$schema['maxLength']}.";
        }
    }

    /**
     * Check `minimum`/`maximum`, where a value equal to the boundary passes.
     *
     * @param  array<string, mixed>  $schema  Schema fragment at this node.
     * @param  int|float  $data  Decoded number at this node.
     * @param  string  $path  JSON path of this node.
     * @param  list<string>  $errors  Accumulator, appended in place.
     * @return void
     */
    private function validateNumberConstraints(array $schema, int|float $data, string $path, array &$errors): void
    {
        if (isset($schema['minimum']) && $data < $schema['minimum']) {
            $errors[] = "{$path}: value is less than the minimum of {$schema['minimum']}.";
        }

        if (isset($schema['maximum']) && $data > $schema['maximum']) {
            $errors[] = "{$path}: value is greater than the maximum of {$schema['maximum']}.";
        }
    }

    /**
     * Whether a value is present in an enum list, using strict comparison.
     *
     * @param  mixed  $data  Decoded value to look up.
     * @param  list<mixed>  $enum  Allowed values.
     * @return bool
     */
    private function inEnum(mixed $data, array $enum): bool
    {
        foreach ($enum as $candidate) {
            if ($candidate === $data) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a value matches a `type` keyword, a single type name or a trivial list of type names.
     *
     * @param  string|list<string>  $type  Declared type or list of types.
     * @param  mixed  $data  Decoded value to check.
     * @return bool
     */
    private function matchesType(string|array $type, mixed $data): bool
    {
        if (is_array($type)) {
            foreach ($type as $single) {
                if (is_string($single) && $this->matchesSingleType($single, $data)) {
                    return true;
                }
            }

            return false;
        }

        return $this->matchesSingleType($type, $data);
    }

    /**
     * Whether a value matches one JSON Schema primitive type name.
     *
     * @param  string  $type  One of: object, array, string, number, integer, boolean, null.
     * @param  mixed  $data  Decoded value to check.
     * @return bool
     */
    private function matchesSingleType(string $type, mixed $data): bool
    {
        return match ($type) {
            'object' => is_array($data) && ($data === [] || ! array_is_list($data)),
            'array' => is_array($data) && array_is_list($data),
            'string' => is_string($data),
            'number' => is_int($data) || is_float($data),
            'integer' => is_int($data) || (is_float($data) && fmod($data, 1.0) === 0.0),
            'boolean' => is_bool($data),
            'null' => $data === null,
            default => false,
        };
    }

    /**
     * Render a `type` value for an error message.
     *
     * @param  string|list<string>  $type  Declared type or list of types.
     * @return string
     */
    private function describeType(string|array $type): string
    {
        return is_array($type) ? implode('|', $type) : $type;
    }
}
