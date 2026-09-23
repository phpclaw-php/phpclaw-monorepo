<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Controller\Adminhtml\Settings;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;

/**
 * Admin controller for the phpClaw Settings page.
 */
// non-final: Magento interceptor required
class Index extends Action
{
    public const ADMIN_RESOURCE = 'PhpClaw_Magento::phpclaw_settings';

    /**
     * Bind the action context and page factory this controller renders the settings page through.
     *
     * @param  Context  $context  Magento admin action context.
     * @param  PageFactory  $resultPageFactory  Factory for creating result page instances.
     * @return void
     */
    public function __construct(
        Context $context,
        private readonly PageFactory $resultPageFactory,
    ) {
        parent::__construct($context);
    }

    /**
     * Render the PhpClaw settings admin page.
     *
     * @return Page Configured result page with the settings title set.
     */
    public function execute(): Page
    {
        $resultPage = $this->resultPageFactory->create();
        $resultPage->getConfig()->getTitle()->prepend(__('PhpClaw: Settings'));

        return $resultPage;
    }
}
