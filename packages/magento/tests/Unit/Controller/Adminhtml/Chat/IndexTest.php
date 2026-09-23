<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Tests\Unit\Controller\Adminhtml\Chat;

use Magento\Backend\App\Action\Context;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Framework\View\Page\Title;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;
use PhpClaw\Magento\Controller\Adminhtml\Chat\Index;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class IndexTest extends TestCase
{
    private Context&MockObject $context;

    private PageFactory&MockObject $pageFactory;

    private Page&MockObject $page;

    private Index $controller;

    protected function setUp(): void
    {
        $this->context = $this->createMock(Context::class);
        $this->pageFactory = $this->createMock(PageFactory::class);
        $this->page = $this->createMock(Page::class);

        $title = $this->createMock(Title::class);
        $config = $this->createMock(PageConfig::class);

        $config->method('getTitle')->willReturn($title);
        $this->page->method('getConfig')->willReturn($config);
        $this->pageFactory->method('create')->willReturn($this->page);

        $this->controller = new Index($this->context, $this->pageFactory);
    }

    public function test_execute_returns_page_result(): void
    {
        self::assertSame($this->page, $this->controller->execute());
    }

    public function test_admin_resource_constant_matches_acl_xml(): void
    {
        self::assertSame('PhpClaw_Magento::phpclaw_chat', Index::ADMIN_RESOURCE);
    }

    public function test_execute_sets_page_title(): void
    {
        $prepended = null;

        $titleMock = $this->createMock(Title::class);
        $titleMock->expects(self::once())->method('prepend')
            ->willReturnCallback(function (mixed $title) use (&$prepended): Title {
                $prepended = (string) $title;

                return $this->createMock(Title::class);
            });

        $configMock = $this->createMock(PageConfig::class);
        $configMock->method('getTitle')->willReturn($titleMock);

        $page = $this->createMock(Page::class);
        $page->method('getConfig')->willReturn($configMock);

        $pageFactory = $this->createMock(PageFactory::class);
        $pageFactory->method('create')->willReturn($page);

        (new Index($this->context, $pageFactory))->execute();

        self::assertSame('PhpClaw: Chat', $prepended);
    }
}
