<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Controller\Adminhtml\Chat;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use PhpClaw\Magento\Exception\ConversationAccessDeniedException;
use PhpClaw\Magento\Memory\ConversationMemory;

/**
 * Admin AJAX endpoint that returns the stored conversation list for the chat sidebar.
 */
// non-final: Magento interceptor required
class History extends Action
{
    public const ADMIN_RESOURCE = 'PhpClaw_Magento::phpclaw_chat';

    /**
     * Bind the action context and JSON factory this endpoint responds through.
     *
     * @param  Context  $context  Magento backend action context.
     * @param  JsonFactory  $jsonFactory  Factory for JSON result objects.
     * @param  ConversationMemory  $conversationMemory  Memory reader for stored conversations.
     * @return void
     */
    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly ConversationMemory $conversationMemory,
    ) {
        parent::__construct($context);
    }

    /**
     * Return the list of stored conversations for the chat sidebar.
     *
     * @return Json { conversations: array<array{ id: string, title: string, created_at: string, messages: array }> }
     */
    public function execute(): Json
    {
        $result = $this->jsonFactory->create();

        try {
            $rows = $this->conversationMemory->listConversations('conversations');
        } catch (ConversationAccessDeniedException) {
            return $result->setData(['error' => 'You do not have permission to access this conversation.'])->setHttpResponseCode(403);
        }

        return $result->setData(['conversations' => $rows]);
    }

    /**
     * Skip URL key validation for the AJAX sidebar fetch.
     *
     * @return bool
     */
    public function _processUrlKeys(): bool
    {
        return true;
    }
}
