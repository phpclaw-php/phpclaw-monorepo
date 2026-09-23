<?php

declare(strict_types=1);

namespace PhpClaw\Providers\Tools;

/**
 * Provider-agnostic web search config: each provider translates it into its own native tool options.
 */
final class WebSearch
{
    private int $maxUses = 0;

    private array $allowedDomains = [];

    private array $userLocation = [];

    /**
     * Limit how many searches the provider may run per request.
     *
     * @param  int  $maxUses  Maximum search count; 0 = provider default.
     * @return self Fluent instance for chaining.
     */
    public function max(int $maxUses): self
    {
        $this->maxUses = max(0, $maxUses);

        return $this;
    }

    /**
     * Restrict search results to the given domains.
     *
     * @param  string[]  $domains  Allowed domains, e.g. ['php.net']; empty = unrestricted.
     * @return self Fluent instance for chaining.
     */
    public function allow(array $domains): self
    {
        $this->allowedDomains = $domains;

        return $this;
    }

    /**
     * Refine search results with an approximate user location.
     *
     * @param  string  $city  City name; empty = omitted.
     * @param  string  $region  Region or state name; empty = omitted.
     * @param  string  $country  Two-letter country code; empty = omitted.
     * @return self Fluent instance for chaining.
     */
    public function location(string $city = '', string $region = '', string $country = ''): self
    {
        $this->userLocation = array_filter(
            ['city' => $city, 'region' => $region, 'country' => $country],
            static fn (string $value): bool => $value !== '',
        );

        return $this;
    }

    /**
     * Configured maximum search count.
     *
     * @return int Maximum searches per request; 0 = provider default.
     */
    public function maxUses(): int
    {
        return $this->maxUses;
    }

    /**
     * Configured allowed-domain list.
     *
     * @return string[] Allowed domains; empty = unrestricted.
     */
    public function allowedDomains(): array
    {
        return $this->allowedDomains;
    }

    /**
     * Configured approximate user location.
     *
     * @return array<string, string> Non-empty location fields (city/region/country); empty = none.
     */
    public function userLocation(): array
    {
        return $this->userLocation;
    }
}
