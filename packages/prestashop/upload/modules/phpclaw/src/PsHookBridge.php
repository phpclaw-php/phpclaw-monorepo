<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop;

/**
 * Fires phpClaw extension events via PrestaShop hooks and merges contributions into a bucket.
 */
final class PsHookBridge
{
    /**
     * Fire a phpClaw extension event via PrestaShop hooks and return the merged bucket.
     *
     * @param  string  $eventName  e.g. `phpclaw/extra/tools`
     * @param  array<mixed>  $bucket  Initial accumulator.
     * @return array<mixed>
     */
    public static function fireExtra(string $eventName, array $bucket): array
    {
        if (! class_exists('\Hook')) {
            return $bucket;
        }

        $hookName = 'action'.implode('', array_map('ucfirst', explode('/', $eventName)));

        try {
            $results = \Hook::exec($hookName, ['bucket' => $bucket], null, true);
        } catch (\Throwable) {
            return $bucket;
        }

        if (is_array($results)) {
            foreach ($results as $contribution) {
                if (is_array($contribution)) {
                    $bucket = [...$bucket, ...$contribution];
                }
            }
        }

        return $bucket;
    }
}
