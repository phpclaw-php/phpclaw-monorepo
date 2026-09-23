<?php

declare(strict_types=1);

/**
 * Native OpenCart 4 engine primitives, reimplemented so tests can drive the real OC4
 * admin controller without an OpenCart install.
 */

namespace Opencart\System\Engine {
    if (! class_exists(Registry::class, false)) {
        final class Registry
        {
            private array $data = [];

            public function get(string $key): ?object
            {
                return $this->data[$key] ?? null;
            }

            public function set(string $key, object $value): void
            {
                $this->data[$key] = $value;
            }

            public function has(string $key): bool
            {
                return isset($this->data[$key]);
            }

            public function __get(string $key): ?object
            {
                return $this->get($key);
            }

            public function __set(string $key, object $value): void
            {
                $this->set($key, $value);
            }
        }
    }

    if (! class_exists(Controller::class, false)) {
        abstract class Controller
        {
            protected $registry;

            public function __construct(Registry $registry)
            {
                $this->registry = $registry;
            }

            public function __get(string $key): object
            {
                if ($this->registry->has($key)) {
                    return $this->registry->get($key);
                }

                throw new \Exception('Error: Could not call registry key '.$key.'!');
            }

            public function __set(string $key, object $value): void
            {
                $this->registry->set($key, $value);
            }
        }
    }
}

namespace Opencart\System\Library {
    if (! class_exists(Request::class, false)) {
        class Request
        {
            public array $get = [];

            public array $post = [];

            public array $cookie = [];

            public array $files = [];

            public array $server = [];

            public function __construct() {}
        }
    }

    if (! class_exists(Response::class, false)) {
        class Response
        {
            private array $headers = [];

            private string $output = '';

            public function addHeader(string $header): void
            {
                $this->headers[] = $header;
            }

            public function getHeaders(): array
            {
                return $this->headers;
            }

            public function redirect(string $url, int $status = 302): void {}

            public function setCompression(int $level): void {}

            public function setOutput(string $output): void
            {
                $this->output = $output;
            }

            public function getOutput(): string
            {
                return $this->output;
            }

            public function output(): void {}
        }
    }
}
