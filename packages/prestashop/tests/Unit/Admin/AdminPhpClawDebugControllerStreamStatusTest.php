<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Tests\Unit\Admin;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AdminPhpClawDebugControllerStreamStatusTest extends TestCase
{
    #[DataProvider('refusals')]
    public function test_a_stream_refusal_sets_its_http_status_and_emits_an_sse_error(string $message, int $status): void
    {
        ['status' => $actual, 'body' => $body] = $this->emitSseError($message, $status);

        self::assertSame(
            $status,
            $actual,
            "A stream refusal must carry status {$status}, otherwise the caller reads the refusal as a normal 200 reply.",
        );
        self::assertStringContainsString('event: error', $body);
        self::assertStringContainsString($message, $body);
    }

    /**
     * The four refusals the stream endpoint can return, with the status each must carry.
     *
     * @return array<string, array{0: string, 1: int}>
     */
    public static function refusals(): array
    {
        return [
            'method not allowed' => ['Method not allowed. Use POST.', 405],
            'permission denied' => ['Permission denied.', 403],
            'invalid token' => ['Invalid security token.', 403],
            'message required' => ['Message is required.', 400],
        ];
    }

    public function test_the_error_frame_is_valid_sse_that_a_browser_can_parse(): void
    {
        ['body' => $body] = $this->emitSseError('Permission denied.', 403);

        // exec() drops trailing blank lines, so assert the frame's shape rather than its exact tail.
        self::assertMatchesRegularExpression('/^event: error\ndata: \{.*\}/s', trim($body));

        preg_match('/data: (\{.*\})/', $body, $m);

        self::assertSame(['message' => 'Permission denied.'], json_decode($m[1], true));
    }

    public function test_a_refusal_without_a_status_leaves_the_response_code_untouched(): void
    {
        ['status' => $status, 'body' => $body] = $this->emitSseError('Agent error.', 0);

        self::assertNotSame(403, $status, 'Passing 0 must not force a refusal status.');
        self::assertNotSame(405, $status);
        self::assertNotSame(400, $status);
        self::assertStringContainsString('Agent error.', $body);
    }

    /**
     * Run the controller's real emitSseError() in a subprocess and capture what it produced.
     *
     * sendSseHeaders() destroys every output buffer, so ob_* cannot capture this in-process.
     *
     * @param  string  $message  Refusal message.
     * @param  int  $status  HTTP status to set, 0 to leave it alone.
     * @return array{status: int, body: string} The resulting status and emitted body.
     */
    private function emitSseError(string $message, int $status): array
    {
        $bootstrap = dirname(__DIR__, 2).'/bootstrap.php';
        $controller = dirname(__DIR__, 3).'/upload/modules/phpclaw/controllers/admin/AdminPhpClawDebugController.php';
        $marker = '@@STATUS@@';

        $script = sprintf(
            'require %s; require %s;'
            .'$c = (new ReflectionClass("AdminPhpClawDebugController"))->newInstanceWithoutConstructor();'
            .'$m = new ReflectionMethod("AdminPhpClawDebugController", "emitSseError"); $m->setAccessible(true);'
            .'$m->invoke($c, %s, %d);'
            .'file_put_contents("php://stdout", %s . http_response_code());',
            var_export($bootstrap, true),
            var_export($controller, true),
            var_export($message, true),
            $status,
            var_export($marker, true),
        );

        $out = [];
        exec(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($script).' 2>/dev/null', $out);
        $raw = implode("\n", $out);

        $at = strrpos($raw, $marker);
        self::assertNotFalse($at, 'The subprocess did not report a status, so the method never ran.');

        return [
            'status' => (int) substr($raw, $at + strlen($marker)),
            'body' => str_replace("\r\n", "\n", substr($raw, 0, $at))."\n\n",
        ];
    }
}
