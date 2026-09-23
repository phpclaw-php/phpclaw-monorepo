<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Controller\Adminhtml\About;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;

/**
 * Admin controller for the phpClaw About page.
 */
// non-final: Magento interceptor required
class Index extends Action
{
    public const ADMIN_RESOURCE = 'PhpClaw_Magento::phpclaw_about';

    /**
     * Bind the action context and page factory this controller renders the about page through.
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
     * Render the PhpClaw about admin page.
     *
     * @return Page Configured result page with the about title set.
     */
    public function execute(): Page
    {
        $resultPage = $this->resultPageFactory->create();
        $resultPage->getConfig()->getTitle()->prepend(__('PhpClaw: About'));

        return $resultPage;
    }
}
