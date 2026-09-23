<?php

declare(strict_types=1);

namespace PhpClaw\Cloud;

use PhpClaw\Exceptions\GuardException;
use PhpClaw\Guards\Contracts\GuardInterface;

/**
 * Cloud-powered prompt guard. Blocks detected threats before reaching the LLM.
 */
final class CloudScanGuard implements GuardInterface
{
    private const SIGNATURE_ALGO = 'sha256';

    private const SCAN_PATH = '/'.CloudHttp::API_VERSION.'/scan';

    /**
     * Configure the cloud-backed scan guard.
     *
     * @param  string  $key  Cloud API key authorising the scan endpoint.
     * @param  string  $feature  Scan feature flag forwarded to the cloud endpoint.
     * @param  string  $signingSecret  Shared secret the cloud receiver signs responses with. When
     *                                 non-empty, every scan response must carry a valid HMAC or the
     *                                 verdict is rejected (fail closed). Empty preserves the pre-signing
     *                                 behaviour until a signing secret is configured.
     * @param  bool  $failClosed  When true, a scan the cloud cannot answer (transport failure/timeout)
     *                            blocks the message; when false (default) the message is allowed through,
     *                            preserving availability.
     * @return void
     */
    public function __construct(
        private readonly string $key,
        private readonly string $feature = 'scan',
        private readonly string $signingSecret = '',
        private readonly bool $failClosed = false,
    ) {}

    /**
     * Inspect the message and block if a threat is detected.
     *
     * @param  string  $message  The user message to inspect.
     * @return void
     *
     * @throws GuardException If the cloud scan detects a threat, or the response fails signature verification while a signing secret is configured.
     */
    public function scan(string $message): void
    {
        $result = CloudHttp::post(
            CloudHttp::baseUrl().self::SCAN_PATH,
            $this->key,
            ['message' => $message, 'feature' => $this->feature],
        );

        if ($result === null) {
            if ($this->failClosed) {
                throw new GuardException('Cloud security scan is unavailable, request blocked (fail-closed mode).');
            }

            return;
        }

        if ($this->signingSecret !== '' && ! $this->signatureIsValid($result)) {
            throw new GuardException('Cloud security scan response could not be verified.');
        }

        if (($result['allowed'] ?? true) === true) {
            return;
        }

        throw new GuardException(
            (string) ($result['reason'] ?? '') !== ''
                ? (string) $result['reason']
                : 'Cloud security scan blocked this message.'
        );
    }

    /**
     * Verify the HMAC the cloud receiver signs over the verdict (the allowed/reason/request_id concatenation), keyed by the shared signing secret.
     *
     * @param  array<string, mixed>  $result  Decoded scan response.
     * @return bool True only when a signature is present and matches.
     */
    private function signatureIsValid(array $result): bool
    {
        $signature = $result['signature'] ?? null;

        if (! is_string($signature) || $signature === '') {
            return false;
        }

        $allowed = ($result['allowed'] ?? true) === true ? 'true' : 'false';
        $reason = (string) ($result['reason'] ?? '');
        $requestId = (string) ($result['request_id'] ?? '');
        $expected = hash_hmac(self::SIGNATURE_ALGO, $allowed.$reason.$requestId, $this->signingSecret);

        return hash_equals($expected, $signature);
    }
}
