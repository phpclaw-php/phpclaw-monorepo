<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Tests\Unit\Support;

trait PinsProviderEnv
{
    private function withEnvProvider(string $provider, callable $callback): mixed
    {
        $saved = [getenv('PHPCLAW_PROVIDER'), $_ENV['PHPCLAW_PROVIDER'] ?? null];
        putenv('PHPCLAW_PROVIDER='.$provider);
        $_ENV['PHPCLAW_PROVIDER'] = $provider;

        try {
            return $callback();
        } finally {
            $saved[0] === false ? putenv('PHPCLAW_PROVIDER') : putenv('PHPCLAW_PROVIDER='.$saved[0]);
            if ($saved[1] === null) {
                unset($_ENV['PHPCLAW_PROVIDER']);
            } else {
                $_ENV['PHPCLAW_PROVIDER'] = $saved[1];
            }
        }
    }
}
