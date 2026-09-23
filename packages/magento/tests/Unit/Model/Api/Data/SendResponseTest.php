<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Tests\Unit\Model\Api\Data;

use PhpClaw\Magento\Api\Data\SendResponseInterface;
use PhpClaw\Magento\Model\Api\Data\SendResponse;
use PHPUnit\Framework\TestCase;

final class SendResponseTest extends TestCase
{
    public function test_it_implements_the_run_response_contract(): void
    {
        self::assertInstanceOf(
            SendResponseInterface::class,
            new SendResponse('t', 'anthropic', 'claude-haiku-4-5-20251001', 1, 1),
        );
    }

    public function test_every_constructor_argument_is_returned_by_its_getter(): void
    {
        $response = new SendResponse(
            'Here are your orders.',
            'openai',
            'gpt-4o-mini',
            40,
            3,
        );

        self::assertSame('Here are your orders.', $response->getText());
        self::assertSame('openai', $response->getProvider());
        self::assertSame('gpt-4o-mini', $response->getModel());
        self::assertSame(40, $response->getTokens());
        self::assertSame(3, $response->getIterations());
    }
}
