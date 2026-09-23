<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Tests\Unit\Controller\Adminhtml\Analytics;

use Magento\Backend\App\Action\Context;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Framework\View\Page\Title;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;
use PhpClaw\Magento\Controller\Adminhtml\Analytics\Index;
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
        $this->page = $this->createMock(Page::class);
        $this->pageFactory = $this->createMock(PageFactory::class);

        $this->pageFactory->method('create')->willReturn($this->page);

        $this->controller = new Index($this->context, $this->pageFactory);
    }

    public function test_execute_returns_page_result(): void
    {
        self::assertSame($this->page, $this->controller->execute());
    }

    public function test_execute_calls_page_factory_create_once(): void
    {
        $this->pageFactory->expects(self::once())->method('create');

        $this->controller->execute();
    }

    public function test_admin_resource_constant_is_correct(): void
    {
        self::assertSame('PhpClaw_Magento::phpclaw_analytics', Index::ADMIN_RESOURCE);
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
        $this->page->method('getConfig')->willReturn($configMock);

        $this->controller->execute();

        self::assertSame('PhpClaw: Analytics', $prepended);
    }
}
