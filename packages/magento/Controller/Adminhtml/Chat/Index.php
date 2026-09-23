<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Controller\Adminhtml\Chat;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;

/**
 * Admin controller for the phpClaw Chat page.
 */
// non-final: Magento interceptor required
class Index extends Action
{
    public const ADMIN_RESOURCE = 'PhpClaw_Magento::phpclaw_chat';

    /**
     * Bind the action context and page factory this controller renders the chat page through.
     *
     * @param  Context  $context  Magento backend action context.
     * @param  PageFactory  $resultPageFactory  Factory for full-page result objects.
     * @return void
     */
    public function __construct(
        Context $context,
        private readonly PageFactory $resultPageFactory,
    ) {
        parent::__construct($context);
    }

    /**
     * Render the PhpClaw chat admin page.
     *
     * @return Page Configured result page with the chat title set.
     */
    public function execute(): Page
    {
        $resultPage = $this->resultPageFactory->create();
        $resultPage->getConfig()->getTitle()->prepend(__('PhpClaw: Chat'));

        return $resultPage;
    }
}
