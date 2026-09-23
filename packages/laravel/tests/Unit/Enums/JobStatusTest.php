<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Tests\Unit\Enums;

use PhpClaw\Laravel\Enums\JobStatus;
use PHPUnit\Framework\TestCase;

final class JobStatusTest extends TestCase
{
    public function test_it_declares_exactly_three_statuses(): void
    {
        self::assertSame(
            ['pending', 'done', 'failed'],
            array_column(JobStatus::cases(), 'value'),
        );
    }

    public function test_each_case_maps_to_its_wire_value(): void
    {
        self::assertSame(JobStatus::Pending, JobStatus::from('pending'));
        self::assertSame(JobStatus::Done, JobStatus::from('done'));
        self::assertSame(JobStatus::Failed, JobStatus::from('failed'));
    }

    public function test_an_unknown_value_has_no_case(): void
    {
        self::assertNull(JobStatus::tryFrom('running'));
        self::assertNull(JobStatus::tryFrom(''));
    }
}
