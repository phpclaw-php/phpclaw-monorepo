<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Controller\Adminhtml\Settings;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use PhpClaw\Exceptions\GuardException;
use PhpClaw\Exceptions\ProviderException;
use PhpClaw\Magento\Factory\PhpClawFactoryInterface;
use PhpClaw\Magento\Model\Config;
use Psr\Log\LoggerInterface;

/**
 * Admin AJAX endpoint for the Test Connection button on the Settings page.
 */
// non-final: Magento interceptor required
class TestConnection extends Action
{
    public const ADMIN_RESOURCE = 'PhpClaw_Magento::phpclaw_settings';

    /**
     * Bind the action context and JSON factory this endpoint responds through.
     *
     * @param  Context  $context  Magento admin action context.
     * @param  JsonFactory  $jsonFactory  Factory for creating JSON result instances.
     * @param  PhpClawFactoryInterface  $phpClawFactory  Factory that builds a configured phpClaw agent.
     * @param  Config  $config  PhpClaw config reader for provider/key validation.
     * @param  LoggerInterface  $logger  PSR-3 logger for provider and unexpected errors.
     * @return void
     */
    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly PhpClawFactoryInterface $phpClawFactory,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($context);
    }

    /**
     * Send a deterministic test prompt to the configured provider and return the result.
     *
     * @return Json { ok: bool, provider?: string, model?: string, text?: string, message?: string }
     */
    public function execute(): Json
    {
        $result = $this->jsonFactory->create();

        $provider = $this->config->getProvider();
        $apiKey = $this->config->getApiKey();

        if ($provider === '') {
            return $this->failure($result, 'No provider selected. Choose a provider and save settings first.');
        }

        if ($provider !== 'ollama' && $apiKey === '') {
            return $this->failure($result, 'API key is missing. Add your API key and save settings first.');
        }

        try {
            $response = $this->phpClawFactory->create()->send('Reply with exactly: OK');

            return $result->setData([
                'ok' => true,
                'provider' => $response->provider,
                'model' => $response->model,
                'text' => $response->text,
            ]);
        } catch (GuardException) {
            return $this->failure($result, 'Guard blocked the test prompt.');
        } catch (ProviderException $e) {
            $this->logger->error('phpclaw test connection: provider error', ['exception' => $e]);

            return $this->failure($result, 'Test connection failed. Check server logs for details.');
        } catch (\Throwable $e) {
            $this->logger->error('phpclaw test connection: unexpected error', ['exception' => $e]);

            return $this->failure($result, 'An internal error occurred. Please try again.');
        }
    }

    /**
     * Build a failed test-connection JSON response carrying $message.
     *
     * @param  Json  $result  JSON result instance to populate.
     * @param  string  $message  Human-readable failure reason.
     * @return Json The result with ok=false and the message set.
     */
    private function failure(Json $result, string $message): Json
    {
        return $result->setData([
            'ok' => false,
            'message' => $message,
        ]);
    }
}
