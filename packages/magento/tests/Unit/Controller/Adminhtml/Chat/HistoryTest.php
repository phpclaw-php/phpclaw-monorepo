<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Tests\Unit\Controller\Adminhtml\Chat;

use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use PhpClaw\Magento\Controller\Adminhtml\Chat\History;
use PhpClaw\Magento\Memory\ConversationMemory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class HistoryTest extends TestCase
{
    private Context&MockObject $context;

    private JsonFactory&MockObject $jsonFactory;

    private Json&MockObject $jsonResult;

    private ConversationMemory&MockObject $conversationMemory;

    private History $controller;

    private ?array $capturedData = null;

    protected function setUp(): void
    {
        $this->context = $this->createMock(Context::class);
        $this->jsonFactory = $this->createMock(JsonFactory::class);
        $this->jsonResult = $this->createMock(Json::class);
        $this->conversationMemory = $this->createMock(ConversationMemory::class);

        $this->jsonFactory->method('create')->willReturn($this->jsonResult);
        $this->jsonResult->method('setData')
            ->willReturnCallback(function (array $data): Json {
                $this->capturedData = $data;

                return $this->jsonResult;
            });

        $this->controller = new History($this->context, $this->jsonFactory, $this->conversationMemory);
    }

    public function test_execute_returns_json_result(): void
    {
        $this->conversationMemory->method('listConversations')->willReturn([]);

        self::assertSame($this->jsonResult, $this->controller->execute());
    }

    public function test_execute_lists_conversations_under_conversations_namespace(): void
    {
        $rows = [['id' => 'A', 'title' => 'Test', 'created_at' => '2026-05-25']];
        $this->conversationMemory->expects(self::once())
            ->method('listConversations')
            ->with('conversations')
            ->willReturn($rows);

        $this->controller->execute();

        self::assertSame(['conversations' => $rows], $this->capturedData);
    }

    public function test_execute_returns_empty_conversations_when_none_exist(): void
    {
        $this->conversationMemory->method('listConversations')->willReturn([]);

        $this->controller->execute();

        self::assertSame(['conversations' => []], $this->capturedData);
    }

    public function test_admin_resource_constant_matches_acl_xml(): void
    {
        self::assertSame('PhpClaw_Magento::phpclaw_chat', History::ADMIN_RESOURCE);
    }
}
