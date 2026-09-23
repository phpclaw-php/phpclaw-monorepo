<?php

declare(strict_types=1);

/**
 * Minimal WP_Post stub for unit testing without a WordPress installation.
 */
if (! class_exists('WP_Post')) {
    class WP_Post
    {
        public int $ID = 0;

        public string $post_title = '';

        public string $post_mime_type = '';

        public string $post_date = '';

        public string $post_modified = '';

        public int $post_parent = 0;

        public int $post_author = 0;

        public string $post_excerpt = '';

        public string $post_content = '';

        public string $post_status = '';

        public string $post_type = '';

        public string $post_name = '';

        public int $comment_count = 0;

        public int $menu_order = 0;

        public static function fromData(array|object $data): self
        {
            $post = new self;
            $data = (array) $data;

            foreach ($data as $key => $value) {
                if (property_exists($post, $key)) {
                    $post->{$key} = $value;
                }
            }

            return $post;
        }
    }
}
