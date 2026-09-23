<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Tests\Unit\Block\Adminhtml;

use Magento\Backend\Block\Template;
use PhpClaw\Magento\Block\Adminhtml\Chat;
use PhpClaw\Magento\Memory\ConversationMemory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ChatTest extends TestCase
{
    private Template\Context&MockObject $context;

    private ConversationMemory&MockObject $conversationMemory;

    private Chat $block;

    protected function setUp(): void
    {
        $this->context = $this->createMock(Template\Context::class);
        $this->conversationMemory = $this->createMock(ConversationMemory::class);

        $this->block = new Chat($this->context, $this->conversationMemory);
    }

    public function test_get_send_url_points_at_the_send_route(): void
    {
        self::assertSame('http://example.com/admin/phpclaw/chat/send', $this->block->getSendUrl());
    }

    public function test_get_send_url_contains_chat_send(): void
    {
        self::assertStringContainsString('phpclaw/chat/send', $this->block->getSendUrl());
    }

    public function test_get_stream_url_points_at_the_stream_route(): void
    {
        self::assertSame('http://example.com/admin/phpclaw/chat/stream', $this->block->getStreamUrl());
    }

    public function test_get_stream_url_contains_chat_stream(): void
    {
        self::assertStringContainsString('phpclaw/chat/stream', $this->block->getStreamUrl());
    }

    public function test_get_admin_urls_returns_four_url_templates(): void
    {
        $urls = $this->block->getAdminUrls();

        self::assertIsArray($urls);
        self::assertArrayHasKey('product_edit', $urls);
        self::assertArrayHasKey('customer_edit', $urls);
        self::assertArrayHasKey('category_edit', $urls);
        self::assertArrayHasKey('order_view', $urls);
        self::assertCount(4, $urls);
    }

    public function test_get_admin_urls_carry_an_id_placeholder_for_each_entity(): void
    {
        self::assertSame([
            'product_edit' => 'http://example.com/admin/catalog/product/edit/id/__ID__',
            'customer_edit' => 'http://example.com/admin/customer/index/edit/id/__ID__',
            'category_edit' => 'http://example.com/admin/catalog/category/edit/id/__ID__',
            'order_view' => 'http://example.com/admin/sales/order/view/order_id/__ID__',
        ], $this->block->getAdminUrls());
    }

    public function test_get_admin_urls_targets_native_magento_routes(): void
    {
        $urls = $this->block->getAdminUrls();

        self::assertStringContainsString('catalog/product/edit', $urls['product_edit']);
        self::assertStringContainsString('customer/index/edit', $urls['customer_edit']);
        self::assertStringContainsString('catalog/category/edit', $urls['category_edit']);
        self::assertStringContainsString('sales/order/view', $urls['order_view']);
    }

    public function test_get_conversations_passes_through_the_memory_listing(): void
    {
        $rows = [
            ['id' => 'conv-1', 'title' => 'First', 'created_at' => '2026-01-01 00:00:00'],
            ['id' => 'conv-2', 'title' => null, 'created_at' => '2026-01-02 00:00:00'],
        ];
        $this->conversationMemory->method('listConversations')->willReturn($rows);

        self::assertSame($rows, $this->block->getConversations());
    }

    public function test_get_conversations_delegates_to_memory(): void
    {
        $expected = [
            ['id' => 'abc', 'title' => 'Hello', 'created_at' => '2024-01-01 00:00:00', 'messages' => []],
        ];
        $this->conversationMemory->method('listConversations')->willReturn($expected);

        self::assertSame($expected, $this->block->getConversations());
    }

    public function test_get_conversations_returns_empty_array_on_throwable(): void
    {
        $this->conversationMemory->method('listConversations')
            ->willThrowException(new \RuntimeException('DB unavailable'));

        self::assertSame([], $this->block->getConversations());
    }

    public function test_get_conversations_passes_correct_namespace(): void
    {
        $this->conversationMemory->expects(self::once())
            ->method('listConversations')
            ->with('conversations')
            ->willReturn([]);

        $this->block->getConversations();
    }
}
