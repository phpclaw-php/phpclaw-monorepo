<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Model\Api;

use Magento\Framework\Exception\InputException;
use Magento\Framework\Phrase;
use Magento\Framework\Webapi\Exception as WebapiException;
use PhpClaw\Exceptions\GuardException;
use PhpClaw\Magento\Api\Data\SendResponseInterface;
use PhpClaw\Magento\Api\SendInterface;
use PhpClaw\Magento\Factory\PhpClawFactoryInterface;
use PhpClaw\Magento\Model\Api\Data\SendResponse;
use Psr\Log\LoggerInterface;

/**
 * REST API implementation for synchronous phpClaw agent execution.
 */
// non-final: Magento interceptor required
class Send implements SendInterface
{
    /**
     * Bind the agent factory and logger this REST endpoint runs through.
     *
     * @param  PhpClawFactoryInterface  $phpClawFactory  Factory that builds the configured agent.
     * @param  LoggerInterface  $logger  PSR-3 logger for unexpected errors.
     * @return void
     */
    public function __construct(
        private readonly PhpClawFactoryInterface $phpClawFactory,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Execute the phpClaw agent synchronously and return a structured response.
     *
     * @param  string  $message  User prompt for the agent.
     * @return SendResponseInterface Structured response with text, provider, model, tokens, iterations.
     *
     * @throws InputException When `$message` is empty after trimming.
     * @throws WebapiException HTTP 422 on guard block; HTTP 500 on agent/provider failure.
     */
    public function send(string $message): SendResponseInterface
    {
        $message = trim($message);

        if ($message === '') {
            throw new InputException(new Phrase('"%fieldName" is required. Enter and try again.', ['fieldName' => 'message']));
        }

        try {
            $agent = $this->phpClawFactory->create();
            $conversation = $agent->conversation();
            $turn = $agent->sendInConversation($conversation, $message);
            $response = $turn->response;
        } catch (GuardException $e) {
            throw new WebapiException(
                new Phrase('Request blocked by security guard.'),
                0,
                422,
            );
        } catch (\Throwable $e) {
            $this->logger->error('phpclaw rest send: '.$e->getMessage(), ['exception' => $e]);
            throw new WebapiException(
                new Phrase('An internal error occurred. Please try again.'),
                0,
                WebapiException::HTTP_INTERNAL_ERROR,
            );
        }

        return new SendResponse(
            text: $response->text,
            provider: $response->provider,
            model: $response->model,
            tokens: $response->totalTokens() ?? 0,
            iterations: $response->iterations,
        );
    }
}
