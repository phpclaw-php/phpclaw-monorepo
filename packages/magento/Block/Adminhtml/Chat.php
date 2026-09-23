<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Block\Adminhtml;

use Magento\Backend\Block\Template;
use PhpClaw\Magento\Memory\ConversationMemory;

/**
 * Block for the phpClaw admin chat UI.
 */
// non-final: Magento interceptor required
class Chat extends Template
{
    /**
     * Bind the block context and conversation memory this block preloads the sidebar from.
     *
     * @param  Template\Context  $context  Magento block context.
     * @param  ConversationMemory  $conversationMemory  Conversation list provider.
     * @param  array<string, mixed>  $data  Optional block data passed from layout XML.
     * @return void
     */
    public function __construct(
        Template\Context $context,
        private readonly ConversationMemory $conversationMemory,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Admin URL for the AJAX send endpoint.
     *
     * @return string
     */
    public function getSendUrl(): string
    {
        return $this->getUrl('phpclaw/chat/send');
    }

    /**
     * Admin URL for the SSE streaming endpoint.
     *
     * @return string
     */
    public function getStreamUrl(): string
    {
        return $this->getUrl('phpclaw/chat/stream');
    }

    /**
     * Admin URL for fetching stored conversations with messages.
     *
     * @return string
     */
    public function getHistoryUrl(): string
    {
        return $this->getUrl('phpclaw/chat/history');
    }

    /**
     * Return URL templates for native admin edit pages used by typed tool cards.
     *
     * @return array<string, string>
     */
    public function getAdminUrls(): array
    {
        return [
            'product_edit' => $this->getUrl('catalog/product/edit', ['id' => '__ID__']),
            'customer_edit' => $this->getUrl('customer/index/edit', ['id' => '__ID__']),
            'category_edit' => $this->getUrl('catalog/category/edit', ['id' => '__ID__']),
            'order_view' => $this->getUrl('sales/order/view', ['order_id' => '__ID__']),
        ];
    }

    /**
     * Conversations pre-loaded for the sidebar (avoids a second AJAX round-trip).
     *
     * @return list<array{id: string, title: string|null, created_at: string}>
     */
    public function getConversations(): array
    {
        try {
            return $this->conversationMemory->listConversations('conversations');
        } catch (\Throwable) {
            return [];
        }
    }
}
