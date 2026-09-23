<?php

declare(strict_types=1);

namespace PhpClaw\WordPress\CLI;

use PhpClaw\Contracts\ClawInterface as PhpClawInterface;
use PhpClaw\Exceptions\GuardException;
use PhpClaw\WordPress\Engine\EngineFactory;
use PhpClaw\WordPress\Plugin;
use PhpClaw\WordPress\Rest\PhpClawRestController;
use PhpClaw\WordPress\Support\ToolHistorySplicer;

/**
 * WP-CLI command: wp phpclaw send <message> [--stream] [--provider=<p>] [--model=<m>]
 */
final class PhpClawCommand
{
    /**
     * Run a phpClaw AI agent prompt.
     *
     * ## OPTIONS
     *
     * <message>
     * : The prompt to send to the AI agent.
     *
     * [--stream]
     * : Stream the response token-by-token.
     *
     * [--provider=<provider>]
     * : Provider override: anthropic, openai, groq, gemini, mistral, deepseek, ollama.
     *
     * [--model=<model>]
     * : Model override, e.g. gpt-4o, claude-haiku-4-5-20251001.
     *
     * ## EXAMPLES
     *
     *     wp phpclaw send "How many published posts do I have?"
     *     wp phpclaw send "Write a 50-word intro for a recipe post" --stream
     *     wp phpclaw send "Check error logs" --provider=groq --model=llama-3.1-8b-instant
     *
     * @when after_wp_load
     *
     * @param  string[]  $args  Positional arguments.
     * @param  string[]  $assocArgs  Named arguments.
     * @return void
     */
    public function __invoke(array $args, array $assocArgs): void
    {
        $message = trim($args[0] ?? '');

        if ($message === '') {
            \WP_CLI::error('Message cannot be empty.');

            return;
        }

        $engine = $this->resolveEngine($assocArgs);
        $doStream = isset($assocArgs['stream']);

        if ($doStream) {
            $this->runStream($engine, $message);
        } else {
            $this->runSync($engine, $message);
        }
    }

    /**
     * Resolve the PhpClaw engine, applying any provider/model overrides from CLI args.
     *
     * @param  string[]  $assocArgs  Named CLI arguments.
     * @return PhpClawInterface
     */
    private function resolveEngine(array $assocArgs): PhpClawInterface
    {
        if (empty($assocArgs['provider']) && empty($assocArgs['model'])) {
            return Plugin::getInstance()->engine();
        }

        $config = Plugin::getInstance()->config();
        $saved = (array) get_option('phpclaw_settings', []);

        if (! empty($assocArgs['provider'])) {
            $saved['provider'] = (string) $assocArgs['provider'];
        }
        if (! empty($assocArgs['model'])) {
            $saved['model'] = (string) $assocArgs['model'];
        }

        return EngineFactory::build(
            config: $config,
            saved: $saved,
            skills: [],
            extraToolClasses: Plugin::extraToolClasses(),
        );
    }

    /**
     * Run the prompt synchronously and print the full response to WP-CLI output.
     *
     * @param  PhpClawInterface  $engine  The resolved phpClaw engine.
     * @param  string  $message  The prompt to send.
     * @return void
     */
    private function runSync(PhpClawInterface $engine, string $message): void
    {
        try {
            $conversation = $engine->conversation();

            $toolCalls = [];
            ToolHistorySplicer::collect($toolCalls);

            $turn = $engine->streamInConversation(
                $conversation,
                $message,
                static function (string $token): void {
                    unset($token);
                },
                ToolHistorySplicer::beforePersist($toolCalls),
            );
            $response = $turn->response;

            \WP_CLI::log('');
            \WP_CLI::log($response->text !== '' ? $response->text : PhpClawRestController::EMPTY_RESPONSE_TEXT);
            \WP_CLI::log('');
            \WP_CLI::success(sprintf(
                'Done. Provider: %s | Model: %s | Tokens: %d | Iterations: %d | Conversation: %s',
                $response->provider,
                $response->model,
                $response->totalTokens() ?? 0,
                $response->iterations,
                $turn->conversation->id,
            ));
        } catch (GuardException $e) {
            error_log('phpClaw: guard blocked CLI send: '.$e->getMessage());
            \WP_CLI::error('Your message was blocked by a security guard. Rephrase your prompt and try again.');
        } catch (\Throwable $e) {
            error_log('phpClaw: CLI send error: '.$e->getMessage());
            \WP_CLI::error('An error occurred. Check your API key and provider settings.');
        }
    }

    /**
     * Run the prompt in streaming mode, writing each token chunk to STDOUT as it arrives.
     *
     * @param  PhpClawInterface  $engine  The resolved phpClaw engine.
     * @param  string  $message  The prompt to send.
     * @return void
     */
    private function runStream(PhpClawInterface $engine, string $message): void
    {
        try {
            \WP_CLI::log('');

            $conversation = $engine->conversation();

            $toolCalls = [];
            ToolHistorySplicer::collect($toolCalls);

            $streamed = false;

            $turn = $engine->streamInConversation(
                $conversation,
                $message,
                static function (string $chunk) use (&$streamed): void {
                    if ($chunk !== '') {
                        $streamed = true;
                    }

                    fwrite(STDOUT, $chunk);
                },
                ToolHistorySplicer::beforePersist($toolCalls),
            );

            if (! $streamed && $turn->response->text === '') {
                \WP_CLI::log(PhpClawRestController::EMPTY_RESPONSE_TEXT);
            }

            \WP_CLI::log('');
            \WP_CLI::log('');
            \WP_CLI::success(sprintf(
                'Streaming complete. Conversation: %s',
                $turn->conversation->id,
            ));
        } catch (GuardException $e) {
            error_log('phpClaw: guard blocked CLI stream: '.$e->getMessage());
            \WP_CLI::error('Your message was blocked by a security guard. Rephrase your prompt and try again.');
        } catch (\Throwable $e) {
            error_log('phpClaw: CLI stream error: '.$e->getMessage());
            \WP_CLI::error('An error occurred. Check your API key and provider settings.');
        }
    }
}
