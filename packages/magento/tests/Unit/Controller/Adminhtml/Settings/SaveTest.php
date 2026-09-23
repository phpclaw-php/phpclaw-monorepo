<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Tests\Unit\Controller\Adminhtml\Settings;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Encryption\EncryptorInterface;
use PhpClaw\Magento\Controller\Adminhtml\Settings\Save;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class SaveTest extends TestCase
{
    private Context&MockObject $context;

    private WriterInterface&MockObject $configWriter;

    private EncryptorInterface&MockObject $encryptor;

    private RedirectFactory&MockObject $redirectFactory;

    private TypeListInterface&MockObject $cacheTypeList;

    private RequestInterface&MockObject $request;

    private Redirect&MockObject $redirect;

    private Save $controller;

    private array $savedConfig = [];

    protected function setUp(): void
    {
        $this->context = $this->createMock(Context::class);
        $this->configWriter = $this->createMock(WriterInterface::class);
        $this->encryptor = $this->createMock(EncryptorInterface::class);
        $this->redirectFactory = $this->createMock(RedirectFactory::class);
        $this->cacheTypeList = $this->createMock(TypeListInterface::class);
        $this->request = $this->createMock(RequestInterface::class);
        $this->redirect = $this->createMock(Redirect::class);

        $this->context->method('getRequest')->willReturn($this->request);
        $this->redirect->method('setPath')->willReturn($this->redirect);
        $this->redirectFactory->method('create')->willReturn($this->redirect);

        $this->controller = new Save(
            $this->context,
            $this->configWriter,
            $this->encryptor,
            $this->redirectFactory,
            $this->cacheTypeList,
        );
    }

    public function test_execute_returns_redirect(): void
    {
        $this->request->method('has')->willReturn(false);
        $this->request->method('getParam')->willReturn(null);

        self::assertSame($this->redirect, $this->controller->execute());
    }

    public function test_execute_cleans_config_cache(): void
    {
        $this->request->method('has')->willReturn(false);
        $this->request->method('getParam')->willReturn(null);

        $this->cacheTypeList->expects(self::once())
            ->method('cleanType')
            ->with('config');

        $this->controller->execute();
    }

    public function test_execute_saves_plain_field_when_present(): void
    {
        $this->request->method('has')
            ->willReturnCallback(fn (string $k) => $k === 'provider');
        $this->request->method('getParam')
            ->willReturnCallback(fn (string $k) => match ($k) {
                'provider' => 'openai',
                'store_messages' => null,
                default => null,
            });

        $this->captureSaves();

        $this->controller->execute();

        self::assertSame('openai', $this->savedConfig['phpclaw/general/provider'] ?? null);
    }

    public function test_execute_skips_missing_non_checkbox_fields(): void
    {
        $this->request->method('has')->willReturn(false);
        $this->request->method('getParam')->willReturn(null);

        $this->captureSaves();

        $this->controller->execute();

        self::assertSame(
            ['phpclaw/general/store_messages'],
            array_keys($this->savedConfig),
        );
    }

    public function test_execute_encrypts_api_key(): void
    {
        $this->request->method('has')
            ->willReturnCallback(fn (string $k) => $k === 'api_key');
        $this->request->method('getParam')
            ->willReturnCallback(fn (string $k) => match ($k) {
                'api_key' => 'sk-plaintext',
                'store_messages' => null,
                default => null,
            });

        $this->encryptor->expects(self::once())
            ->method('encrypt')
            ->with('sk-plaintext')
            ->willReturn('ENCRYPTED');

        $this->captureSaves();

        $this->controller->execute();

        self::assertSame('ENCRYPTED', $this->savedConfig['phpclaw/general/api_key'] ?? null);
    }

    public function test_execute_skips_api_key_when_placeholder_sent(): void
    {
        $this->request->method('has')
            ->willReturnCallback(fn (string $k) => $k === 'api_key');
        $this->request->method('getParam')
            ->willReturnCallback(fn (string $k) => match ($k) {
                'api_key' => '__phpclaw_secret_set__',
                'store_messages' => null,
                default => null,
            });

        $this->encryptor->expects(self::never())->method('encrypt');

        $this->captureSaves();

        $this->controller->execute();

        self::assertArrayNotHasKey('phpclaw/general/api_key', $this->savedConfig);
    }

    private function captureSaves(): void
    {
        $this->savedConfig = [];
        $this->configWriter->method('save')
            ->willReturnCallback(function (string $path, string $value): void {
                $this->savedConfig[$path] = $value;
            });
    }

    public function test_execute_skips_api_key_when_empty(): void
    {
        $this->request->method('has')
            ->willReturnCallback(fn (string $k) => $k === 'api_key');
        $this->request->method('getParam')
            ->willReturnCallback(fn (string $k) => match ($k) {
                'api_key' => '',
                'store_messages' => null,
                default => null,
            });

        $this->encryptor->expects(self::never())->method('encrypt');

        $this->controller->execute();
    }

    public function test_execute_stores_store_messages_one_when_truthy(): void
    {
        $this->request->method('has')->willReturn(false);
        $this->request->method('getParam')
            ->willReturnCallback(fn (string $k) => $k === 'store_messages' ? '1' : null);

        $this->captureSaves();

        $this->controller->execute();

        self::assertSame('1', $this->savedConfig['phpclaw/general/store_messages'] ?? null);
    }

    public function test_execute_stores_store_messages_zero_when_falsy(): void
    {
        $this->request->method('has')->willReturn(false);
        $this->request->method('getParam')
            ->willReturnCallback(fn (string $k) => $k === 'store_messages' ? null : null);

        $this->captureSaves();

        $this->controller->execute();

        self::assertSame('0', $this->savedConfig['phpclaw/general/store_messages'] ?? null);
    }

    public function test_execute_saves_remote_skill_urls_when_present(): void
    {
        $this->request->method('has')
            ->willReturnCallback(fn (string $k) => $k === 'remote_skill_urls');
        $this->request->method('getParam')
            ->willReturnCallback(fn (string $k) => match ($k) {
                'remote_skill_urls' => 'https://example.com/a.md,https://example.com/b.json',
                'store_messages' => null,
                default => null,
            });

        $this->captureSaves();

        $this->controller->execute();

        self::assertSame(
            'https://example.com/a.md,https://example.com/b.json',
            $this->savedConfig['phpclaw/general/remote_skill_urls'] ?? null,
        );
    }

    public function test_execute_filters_invalid_urls_out_of_remote_skill_urls(): void
    {
        $this->request->method('has')
            ->willReturnCallback(fn (string $k) => $k === 'remote_skill_urls');
        $this->request->method('getParam')
            ->willReturnCallback(fn (string $k) => match ($k) {
                'remote_skill_urls' => 'https://example.com/a.md, http://example.com/b.md, https://example.com/c, https://example.com/d.json',
                'store_messages' => null,
                default => null,
            });

        $this->captureSaves();

        $this->controller->execute();

        self::assertSame(
            'https://example.com/a.md,https://example.com/d.json',
            $this->savedConfig['phpclaw/general/remote_skill_urls'] ?? null,
        );
    }

    public function test_execute_encrypts_cloud_signing_secret(): void
    {
        $this->request->method('has')
            ->willReturnCallback(fn (string $k) => $k === 'cloud_signing_secret');
        $this->request->method('getParam')
            ->willReturnCallback(fn (string $k) => match ($k) {
                'cloud_signing_secret' => 'my-hmac-secret',
                'store_messages' => null,
                default => null,
            });

        $this->encryptor->expects(self::once())
            ->method('encrypt')
            ->with('my-hmac-secret')
            ->willReturn('ENCRYPTED_SECRET');

        $this->captureSaves();

        $this->controller->execute();

        self::assertSame('ENCRYPTED_SECRET', $this->savedConfig['phpclaw/general/cloud_signing_secret'] ?? null);
    }

    public function test_execute_skips_cloud_signing_secret_when_blank(): void
    {
        $this->request->method('has')
            ->willReturnCallback(fn (string $k) => $k === 'cloud_signing_secret');
        $this->request->method('getParam')
            ->willReturnCallback(fn (string $k) => match ($k) {
                'cloud_signing_secret' => '',
                'store_messages' => null,
                default => null,
            });

        $this->encryptor->expects(self::never())->method('encrypt');

        $this->captureSaves();

        $this->controller->execute();

        self::assertArrayNotHasKey('phpclaw/general/cloud_signing_secret', $this->savedConfig);
    }

    public function test_execute_skips_cloud_signing_secret_when_placeholder_sent(): void
    {
        $this->request->method('has')
            ->willReturnCallback(fn (string $k) => $k === 'cloud_signing_secret');
        $this->request->method('getParam')
            ->willReturnCallback(fn (string $k) => match ($k) {
                'cloud_signing_secret' => '__phpclaw_secret_set__',
                'store_messages' => null,
                default => null,
            });

        $this->encryptor->expects(self::never())->method('encrypt');

        $this->captureSaves();

        $this->controller->execute();

        self::assertArrayNotHasKey('phpclaw/general/cloud_signing_secret', $this->savedConfig);
    }

    public function test_admin_resource_constant_is_correct(): void
    {
        self::assertSame('PhpClaw_Magento::phpclaw_settings', Save::ADMIN_RESOURCE);
    }

    public function test_redirect_is_set_to_settings_index_path(): void
    {
        $this->request->method('has')->willReturn(false);
        $this->request->method('getParam')->willReturn(null);

        $this->redirect->expects(self::once())
            ->method('setPath')
            ->with('phpclaw/settings/index')
            ->willReturn($this->redirect);

        $this->controller->execute();
    }
}
