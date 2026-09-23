<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Tests\Unit\Rest;

use PhpClaw\PrestaShop\Rest\ApiHandler;
use PHPUnit\Framework\TestCase;

final class ApiControllerTest extends TestCase
{
    public function test_api_controller_file_exists(): void
    {
        self::assertFileExists(__DIR__.'/../../../upload/modules/phpclaw/controllers/front/api.php');
    }

    public function test_message_length_ceiling_is_the_value_the_controller_enforces(): void
    {
        self::assertSame(50000, ApiHandler::MAX_MESSAGE_LENGTH);
    }

    public function test_the_public_accessor_reports_the_same_ceiling_the_controller_reads(): void
    {
        self::assertSame(ApiHandler::MAX_MESSAGE_LENGTH, ApiHandler::maxMessageLength());
    }
}
