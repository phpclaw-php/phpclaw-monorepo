<?php

declare(strict_types=1);

/**
 * Minimal WP_User stub for unit testing without a WordPress installation.
 *
 * WpUserTool::mapUsers() discards anything that is not a WP_User instance, so a
 * plain stdClass fixture produces zero rows and makes assertions pass vacuously
 * or fail for the wrong reason. Tests that feed WP_User_Query must use this.
 */
if (! class_exists('WP_User')) {
    class WP_User
    {
        public int $ID = 0;

        public string $user_login = '';

        public string $display_name = '';

        public string $user_nicename = '';

        public string $user_email = '';

        public string $user_url = '';

        public string $user_registered = '';

        public int $user_status = 0;

        public array $roles = [];
    }
}
