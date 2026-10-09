<?php

declare(strict_types=1);

namespace PhpClaw\Graph;

/**
 * Key/value state passed from node to node in one Graph run; each node attempt gets its own copy to change.
 */
final class GraphState
{
    /**
     * Start the state with the given values.
     *
     * @param  array<string, mixed>  $data  Initial values keyed by name.
     */
    public function __construct(
        private array $data = [],
    ) {}

    /**
     * Return the value stored under the key, or the default when the key was never set.
     *
     * @param  string  $key  Value name.
     * @param  mixed  $default  Returned when the key is absent; a stored null is returned as null.
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->data) ? $this->data[$key] : $default;
    }

    /**
     * Store the value under the key and return this same state, so a node can end with `return $state->set(...)`.
     *
     * @param  string  $key  Value name.
     * @param  mixed  $value  Value to store.
     * @return self
     */
    public function set(string $key, mixed $value): self
    {
        $this->data[$key] = $value;

        return $this;
    }

    /**
     * Return every stored value keyed by name.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->data;
    }
}
