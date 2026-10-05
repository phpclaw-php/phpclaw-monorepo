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
use Magento\Framework\Message\ManagerInterface;
use PhpClaw\Magento\Controller\Adminhtml\Settings\Save;
use PhpClaw\Magento\Model\Config;
use PhpClaw\Magento\Tests\Unit\Support\PinsProviderEnv;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class SaveTest extends TestCase
{
    use PinsProviderEnv;

    private Context&MockObject $context;

    private WriterInterface&MockObject $configWriter;

    private EncryptorInterface&MockObject $encryptor;

    private RedirectFactory&MockObject $redirectFactory;

    private TypeListInterface&MockObject $cacheTypeList;

    private RequestInterface&MockObject $request;

    private Redirect&MockObject $redirect;

    private Save $controller;

    private array $savedConfig = [];

    private Config&MockObject $config;

    private array $errors = [];

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
            $this->config = $this->createMock(Config::class),
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
            ['phpclaw/general/store_messages', 'phpclaw/general/response_cache'],
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

    private function post(array $params): void
    {
        $this->request->method('has')->willReturnCallback(fn (string $k): bool => array_key_exists($k, $params));
        $this->request->method('getParam')->willReturnCallback(fn (string $k, mixed $default = null): mixed => $params[$k] ?? $default);
        $this->captureSaves();
        $this->captureErrors();
    }

    private function captureErrors(): void
    {
        $this->errors = [];
        $recorder = new class($this->errors) implements ManagerInterface
        {
            public function __construct(private array &$errors) {}

            public function addSuccessMessage(string $message, string $group = ''): static
            {
                return $this;
            }

            public function addErrorMessage(string $message, string $group = ''): static
            {
                $this->errors[] = $message;

                return $this;
            }

            public function addWarningMessage(string $message, string $group = ''): static
            {
                return $this;
            }

            public function addNoticeMessage(string $message, string $group = ''): static
            {
                return $this;
            }
        };
        (new \ReflectionProperty($this->controller, 'messageManager'))->setValue($this->controller, $recorder);
    }

    public function test_execute_saves_the_seven_agent_settings(): void
    {
        $this->encryptor->method('encrypt')->with('sk-fallback')->willReturn('ENC-FB');
        $this->post([
            'provider' => 'ollama',
            'fallback_provider' => 'groq',
            'fallback_model' => ' llama-3.1-8b-instant ',
            'fallback_api_key' => 'sk-fallback',
            'rate_limit_rpm' => '30',
            'response_cache' => '1',
            'response_cache_ttl' => '600',
            'max_token_budget' => '50000',
        ]);

        $this->controller->execute();

        self::assertSame([], $this->errors);
        self::assertSame('groq', $this->savedConfig['phpclaw/general/fallback_provider']);
        self::assertSame('llama-3.1-8b-instant', $this->savedConfig['phpclaw/general/fallback_model']);
        self::assertSame('ENC-FB', $this->savedConfig['phpclaw/general/fallback_api_key']);
        self::assertSame('30', $this->savedConfig['phpclaw/general/rate_limit_rpm']);
        self::assertSame('1', $this->savedConfig['phpclaw/general/response_cache']);
        self::assertSame('600', $this->savedConfig['phpclaw/general/response_cache_ttl']);
        self::assertSame('50000', $this->savedConfig['phpclaw/general/max_token_budget']);
    }

    public function test_execute_saves_response_cache_off_when_the_box_is_unticked(): void
    {
        $this->post(['provider' => 'ollama']);

        $this->controller->execute();

        self::assertSame('0', $this->savedConfig['phpclaw/general/response_cache']);
    }

    public function test_execute_clamps_out_of_range_numbers(): void
    {
        $this->post(['provider' => 'ollama', 'rate_limit_rpm' => '9999', 'response_cache_ttl' => '5', 'max_token_budget' => '-1']);

        $this->controller->execute();

        self::assertSame('600', $this->savedConfig['phpclaw/general/rate_limit_rpm']);
        self::assertSame('60', $this->savedConfig['phpclaw/general/response_cache_ttl']);
        self::assertSame('0', $this->savedConfig['phpclaw/general/max_token_budget']);
    }

    public function test_execute_saves_an_empty_ttl_as_the_default(): void
    {
        $this->post(['provider' => 'ollama', 'response_cache_ttl' => '']);

        $this->controller->execute();

        self::assertSame('3600', $this->savedConfig['phpclaw/general/response_cache_ttl']);
    }

    public function test_execute_keeps_the_saved_fallback_key_when_the_field_is_blank(): void
    {
        $this->encryptor->expects(self::never())->method('encrypt');
        $this->post(['provider' => 'ollama', 'fallback_provider' => 'groq', 'fallback_api_key' => '']);

        $this->controller->execute();

        self::assertArrayNotHasKey('phpclaw/general/fallback_api_key', $this->savedConfig);
        self::assertSame('groq', $this->savedConfig['phpclaw/general/fallback_provider']);
    }

    public function test_execute_rejects_a_fallback_with_another_tool_format_and_saves_nothing(): void
    {
        $this->post(['provider' => 'ollama', 'fallback_provider' => 'anthropic', 'rate_limit_rpm' => '5']);

        $this->controller->execute();

        self::assertSame(['Fallback provider must use the same tool format as the primary provider, and cannot be Custom.'], $this->errors);
        self::assertSame([], $this->savedConfig);
    }

    public function test_execute_rejects_a_custom_fallback(): void
    {
        $this->post(['provider' => 'openai', 'fallback_provider' => 'custom']);

        $this->controller->execute();

        self::assertCount(1, $this->errors);
        self::assertSame([], $this->savedConfig);
    }

    public function test_execute_checks_the_fallback_against_the_saved_provider_when_none_is_posted(): void
    {
        $this->config->method('getProvider')->willReturn('anthropic');
        $this->post(['fallback_provider' => 'anthropic']);

        $this->controller->execute();

        self::assertSame([], $this->errors);
        self::assertSame('anthropic', $this->savedConfig['phpclaw/general/fallback_provider']);
    }

    public function test_execute_checks_the_fallback_against_the_auto_provider_when_the_main_is_empty(): void
    {
        $this->post(['provider' => '', 'fallback_provider' => 'anthropic']);

        $this->withEnvProvider('anthropic', fn () => $this->controller->execute());

        self::assertSame([], $this->errors);
        self::assertSame('anthropic', $this->savedConfig['phpclaw/general/fallback_provider']);
    }

    public function test_execute_accepts_an_off_fallback_under_any_provider(): void
    {
        $this->post(['provider' => 'anthropic', 'fallback_provider' => '']);

        $this->controller->execute();

        self::assertSame([], $this->errors);
        self::assertSame('', $this->savedConfig['phpclaw/general/fallback_provider']);
    }
}
