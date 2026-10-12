<?php

declare(strict_types=1);

if (! class_exists('WP_CLI')) {
    final class WP_CLI
    {
        public static array $log = [];

        public static ?string $error = null;

        public static ?string $success = null;

        public static function log(string $msg): void
        {
            self::$log[] = $msg;
        }

        public static function error(string $msg, bool $exit = true): void
        {
            self::$error = $msg;
        }

        public static function success(string $msg): void
        {
            self::$success = $msg;
        }

        public static function add_command(string $name, mixed $callable, array $args = []): bool
        {
            $GLOBALS['phpclaw_test_cli_commands'][] = ['name' => $name, 'args' => $args];

            return true;
        }

        public static function add_hook(string $when, mixed $callback): void {}
    }
}
