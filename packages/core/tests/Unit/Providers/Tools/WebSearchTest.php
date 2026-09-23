<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Providers\Tools;

use PhpClaw\Providers\Tools\WebSearch;
use PHPUnit\Framework\TestCase;

final class WebSearchTest extends TestCase
{
    public function test_defaults_are_empty(): void
    {
        $search = new WebSearch;

        $this->assertSame(0, $search->maxUses());
        $this->assertSame([], $search->allowedDomains());
        $this->assertSame([], $search->userLocation());
    }

    public function test_max_sets_max_uses(): void
    {
        $search = (new WebSearch)->max(5);

        $this->assertSame(5, $search->maxUses());
    }

    public function test_max_clamps_negative_to_zero(): void
    {
        $search = (new WebSearch)->max(-3);

        $this->assertSame(0, $search->maxUses());
    }

    public function test_allow_sets_domains(): void
    {
        $search = (new WebSearch)->allow(['php.net', 'laravel.com']);

        $this->assertSame(['php.net', 'laravel.com'], $search->allowedDomains());
    }

    public function test_location_keeps_only_non_empty_fields(): void
    {
        $search = (new WebSearch)->location(city: 'Ahmedabad', country: 'IN');

        $this->assertSame(['city' => 'Ahmedabad', 'country' => 'IN'], $search->userLocation());
    }

    public function test_location_with_all_empty_fields_stays_empty(): void
    {
        $search = (new WebSearch)->location();

        $this->assertSame([], $search->userLocation());
    }

    public function test_fluent_chain_returns_same_instance(): void
    {
        $search = new WebSearch;

        $this->assertSame($search, $search->max(3)->allow(['php.net'])->location(city: 'Pune'));
        $this->assertSame(3, $search->maxUses());
        $this->assertSame(['php.net'], $search->allowedDomains());
        $this->assertSame(['city' => 'Pune'], $search->userLocation());
    }
}
