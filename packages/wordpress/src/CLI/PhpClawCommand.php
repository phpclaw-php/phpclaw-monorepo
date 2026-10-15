<?php

declare(strict_types=1);

namespace PhpClaw\WordPress\CLI;

use PhpClaw\Agent\Conversation;
use PhpClaw\Agent\ConversationTurn;
use PhpClaw\Contracts\ClawInterface as PhpClawInterface;
use PhpClaw\WordPress\CLI\Concerns\RendersToolTrace;
use PhpClaw\WordPress\CLI\Concerns\ReportsAgentTurns;
use PhpClaw\WordPress\CLI\Concerns\ResolvesEngine;
use PhpClaw\WordPress\CLI\Concerns\TracksLastConversation;
use PhpClaw\WordPress\Rest\PhpClawRestController;
use PhpClaw\WordPress\Support\ToolHistorySplicer;

/**
 * WP-CLI command: wp phpclaw send <message> [--stream] [--provider=<p>] [--model=<m>] [--conv-id=<id>] [--continue] [--trace]
 */
final class PhpClawCommand
{
    use RendersToolTrace;
    use ReportsAgentTurns;
    use ResolvesEngine;
    use TracksLastConversation;

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
     * [--conv-id=<id>]
     * : Continue the stored conversation with this id.
     *
     * [--continue]
     * : Continue the last conversation.
     *
     * [--trace]
     * : Print one line per tool call.
     *
     * ## EXAMPLES
     *
     *     wp phpclaw send "How many published posts do I have?"
     *     wp phpclaw send "Write a 50-word intro for a recipe post" --stream
     *     wp phpclaw send "Check error logs" --provider=groq --model=llama-3.1-8b-instant
     *     wp phpclaw send "and the drafts?" --continue --trace
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

        $stream = isset($assocArgs['stream']);

        if (isset($assocArgs['trace'])) {
            $this->startToolTrace(static fn (string $line) => \WP_CLI::log($line));
        }

        try {
            $engine = $this->resolveEngine((string) ($assocArgs['provider'] ?? ''), (string) ($assocArgs['model'] ?? ''));
            $conversation = $this->openConversation(static fn (string $line) => \WP_CLI::log($line), $engine, (string) ($assocArgs['conv-id'] ?? ''), continue: isset($assocArgs['continue']));
            $turn = $this->sendTurn($engine, $conversation, $message, stream: $stream);
        } catch (\Throwable $e) {
            \WP_CLI::error($this->agentErrorMessage($e, $stream ? 'stream' : 'send'));

            return;
        } finally {
            $this->stopToolTrace();
        }

        $this->rememberConversation($turn->conversation->id);
        \WP_CLI::success($this->doneLine($turn));
    }

    /**
     * The one-shot success line: provider, model, total tokens, iterations and the conversation id.
     *
     * @param  ConversationTurn  $turn  The finished turn.
     * @return string
     */
    private function doneLine(ConversationTurn $turn): string
    {
        return sprintf(
            'Done. Provider: %s | Model: %s | Tokens: %d | Iterations: %d | Conversation: %s',
            $turn->response->provider,
            $turn->response->model,
            $turn->response->totalTokens() ?? 0,
            $turn->response->iterations,
            $turn->conversation->id,
        );
    }

    /**
     * Send one message and print the reply: all at once, or token by token when streaming.
     *
     * @param  PhpClawInterface  $engine  The resolved phpClaw engine.
     * @param  Conversation  $conversation  Conversation the turn belongs to.
     * @param  string  $message  The prompt to send.
     * @param  bool  $stream  Whether to write each token to STDOUT as it arrives.
     * @return ConversationTurn
     */
    private function sendTurn(PhpClawInterface $engine, Conversation $conversation, string $message, bool $stream): ConversationTurn
    {
        $toolCalls = [];
        ToolHistorySplicer::collect($toolCalls);
        $streamed = false;

        \WP_CLI::log('');

        $turn = $engine->streamInConversation(
            $conversation,
            $message,
            static function (string $chunk) use ($stream, &$streamed): void {
                if (! $stream || $chunk === '') {
                    return;
                }

                $streamed = true;
                fwrite(STDOUT, $chunk);
            },
            ToolHistorySplicer::beforePersist($toolCalls),
        );

        if (! $stream) {
            \WP_CLI::log($turn->response->text === '' ? PhpClawRestController::EMPTY_RESPONSE_TEXT : $turn->response->text);
        } elseif (! $streamed && $turn->response->text === '') {
            \WP_CLI::log(PhpClawRestController::EMPTY_RESPONSE_TEXT);
        }

        \WP_CLI::log('');

        return $turn;
    }
}
