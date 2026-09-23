<?php

declare(strict_types=1);

namespace PhpClaw\WordPress\Tests\Unit\Tools;

use PhpClaw\Testing\AbstractToolContractInvariants;

final class ToolContractInvariantsTest extends AbstractToolContractInvariants
{
    protected function toolFiles(): array
    {
        $roots = [
            __DIR__.'/../../../src/Tools',
            __DIR__.'/../../../src/WooCommerce/Tools',
        ];

        $files = [];

        foreach ($roots as $root) {
            foreach ((array) glob($root.'/*.php') as $file) {
                if (str_ends_with((string) $file, 'OutputByteCap.php')) {
                    continue;
                }

                $files[] = (string) $file;
            }
        }

        return $files;
    }

    protected function pendingConversion(): array
    {
        return [
        ];
    }

    protected function collectionTools(): array
    {
        return [
            'WpUserTool', 'WpQueryTool', 'WpCommentTool', 'WpMediaTool',
            'OrderTool', 'ProductTool', 'StockTool', 'CouponTool', 'CategoryTool',
        ];
    }

    protected function stableSecondarySortProblem(string $tool): ?string
    {
        $source = (string) preg_replace('/\s+/', ' ', (string) file_get_contents($this->toolPath($tool)));

        if (in_array($tool, $this->sortsInPhp(), true)) {
            return preg_match('/usort\(/', $source) === 1 ? null : 'expected a PHP-side stable sort';
        }

        return preg_match("/'orderby' =>[^;]{0,200}'(ID|comment_ID)' =>/", $source) === 1
            ? null
            : 'expected an orderby array with ID or comment_ID as the secondary key';
    }

    private function sortsInPhp(): array
    {
        return ['CategoryTool'];
    }

    protected function freeTextFields(): array
    {
        return [
            'preview', 'content', 'excerpt', 'description',
            'author_url', 'customer_note', 'billing_phone', 'billing_city',
        ];
    }

    public function test_no_order_tool_touches_legacy_post_storage(): void
    {
        $orderTools = ['OrderTool', 'ReportTool', 'CouponTool', 'StockTool'];
        $offenders = [];

        foreach ($this->toolFiles() as $file) {
            $name = basename($file, '.php');

            if (! in_array($name, $orderTools, true)) {
                continue;
            }

            foreach (explode("\n", (string) file_get_contents($file)) as $number => $line) {
                if (str_starts_with(ltrim($line), '*') || str_starts_with(ltrim($line), '/*')) {
                    continue;
                }

                if (preg_match('/\$wpdb->(posts|postmeta)\b|[\'"]wp_posts[\'"]|[\'"]postmeta[\'"]/', $line) === 1) {
                    $offenders[] = $name.':'.($number + 1);
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            'order data must come from wc_get_orders() and the WC data stores. On an HPOS site '
            .'wp_posts holds no orders, so these reads return silently empty. Offenders: '
            .implode(', ', $offenders),
        );
    }

    public function test_current_user_can_appears_only_in_the_shim(): void
    {
        $callers = [];
        $shim = __DIR__.'/../../../src/Tools/Concerns/HasToolExecutionContract.php';

        foreach ([...$this->toolFiles(), $shim] as $file) {
            if (str_contains((string) file_get_contents($file), 'current_user_can')) {
                $callers[] = basename($file, '.php');
            }
        }

        self::assertSame(
            ['HasToolExecutionContract'],
            $callers,
            'current_user_can() must appear only in the WordPress shim. Found in: '
            .implode(', ', $callers),
        );
    }
}
