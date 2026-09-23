<?php

declare(strict_types=1);

/**
 * Native OpenCart 3 engine primitives, reimplemented so tests can drive the real OC3
 * admin controller without an OpenCart install.
 */
if (! class_exists('Registry', false)) {
    final class Registry
    {
        private array $data = [];

        public function get($key)
        {
            return $this->data[$key] ?? null;
        }

        public function set($key, $value): void
        {
            $this->data[$key] = $value;
        }

        public function has($key): bool
        {
            return isset($this->data[$key]);
        }
    }
}

if (! class_exists('Controller', false)) {
    abstract class Controller
    {
        protected $registry;

        public function __construct($registry)
        {
            $this->registry = $registry;
        }

        public function __get($key)
        {
            return $this->registry->get($key);
        }

        public function __set($key, $value): void
        {
            $this->registry->set($key, $value);
        }
    }
}

if (! class_exists('Request', false)) {
    class Request
    {
        public $get = [];

        public $post = [];

        public $request = [];

        public $cookie = [];

        public $files = [];

        public $server = [];

        public function __construct() {}
    }
}

if (! class_exists('Response', false)) {
    class Response
    {
        private array $headers = [];

        private $output;

        public function addHeader($header): void
        {
            $this->headers[] = $header;
        }

        public function getHeaders(): array
        {
            return $this->headers;
        }

        public function redirect($url, $status = 302): void {}

        public function setCompression($level): void {}

        public function getOutput()
        {
            return $this->output;
        }

        public function setOutput($output): void
        {
            $this->output = $output;
        }

        public function output(): void {}
    }
}
