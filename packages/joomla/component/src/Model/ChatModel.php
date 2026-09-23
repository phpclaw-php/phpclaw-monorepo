<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Component\Administrator\Model;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use PhpClaw\Claw;
use PhpClaw\Joomla\Component\Administrator\Database\PhpClawTables;
use PhpClaw\Joomla\Component\Administrator\Engine\EngineFactory;
use PhpClaw\Joomla\Component\Administrator\Model\Concerns\ReadsPhpClawParams;

/**
 * Chat model - loads conversations and initial messages for the chat view.
 */
final class ChatModel extends BaseDatabaseModel
{
    use ReadsPhpClawParams;

    private const CONVERSATIONS_NAMESPACE = 'conversations';

    private const PROVIDER_STATUS_CONNECTED = 'connected';

    private const PROVIDER_STATUS_NOT_CONFIGURED = 'not_configured';

    private const DISPLAYABLE_ROLES = ['user', 'assistant'];

    private ?Claw $engine = null;

    private ?string $engineError = null;

    /**
     * List the acting user's stored conversations for the chat sidebar, or none at all when
     * store_messages is disabled.
     *
     * @return array<string, mixed>
     */
    public function getConversations(): array
    {
        if (! $this->isStoreMessages()) {
            return [];
        }

        try {
            return $this->getEngine()->memory()->all(self::CONVERSATIONS_NAMESPACE);
        } catch (\Throwable $e) {
            error_log('phpClaw ChatModel: '.$e->getMessage());
            $this->engineError = Text::_('COM_PHPCLAW_ERROR_CONVERSATIONS_LOAD');

            return [];
        }
    }

    /**
     * Build the seed payload for the chat view.
     *
     * @return array{id: string, title: string, messages: list<array{role: string, content: string}>}
     */
    public function getInitialConversation(): array
    {
        $convs = $this->getConversations();

        if ($convs === []) {
            return ['id' => '', 'title' => '', 'messages' => []];
        }

        $initId = (string) array_key_first($convs);

        return [
            'id' => $initId,
            'title' => (string) ($convs[$initId]['title'] ?? ''),
            'messages' => $this->loadDisplayableMessages($initId),
        ];
    }

    /**
     * Resolved provider name (e.g. ollama, anthropic).
     *
     * @return string
     */
    public function getProvider(): string
    {
        return (string) EngineFactory::getPluginParams()->get('provider', '');
    }

    /**
     * Resolved model identifier.
     *
     * @return string
     */
    public function getModelName(): string
    {
        return (string) EngineFactory::getPluginParams()->get('model', '');
    }

    /**
     * Returns the latest engine-build error message, or null when the engine is healthy.
     *
     * @return ?string
     */
    public function getEngineError(): ?string
    {
        if ($this->engine === null && $this->engineError === null) {
            try {
                $this->getEngine();
            } catch (\Throwable $e) {
                error_log('phpClaw ChatModel engine error: '.$e->getMessage());
                $this->engineError = Text::_('COM_PHPCLAW_ENGINE_NOT_CONFIGURED_FULL');
            }
        }

        return $this->engineError;
    }

    /**
     * Quick conversation/message counts + provider status for the chat dashboard widget.
     *
     * @return array{conversations: int, messages: int, provider_status: string}
     */
    public function getStats(): array
    {
        [$conversations, $messages] = $this->countConversationsAndMessages();

        return [
            'conversations' => $conversations,
            'messages' => $messages,
            'provider_status' => $this->getProvider() !== ''
                ? self::PROVIDER_STATUS_CONNECTED
                : self::PROVIDER_STATUS_NOT_CONFIGURED,
        ];
    }

    /**
     * Load the user/assistant messages for a conversation, swallowing engine errors.
     *
     * @param  string  $conversationId
     * @return list<array{role: string, content: string}>
     */
    private function loadDisplayableMessages(string $conversationId): array
    {
        try {
            $stored = $this->getEngine()->memory()->get($conversationId, self::CONVERSATIONS_NAMESPACE);
        } catch (\Throwable) {
            return [];
        }

        if (! is_array($stored)) {
            return [];
        }

        $history = (array) ($stored['messages'] ?? $stored['history'] ?? []);
        $out = [];

        foreach ($history as $msg) {
            $role = $msg['role'] ?? '';

            if (in_array($role, self::DISPLAYABLE_ROLES, strict: true)) {
                $out[] = ['role' => $role, 'content' => (string) ($msg['content'] ?? '')];
            }
        }

        return $out;
    }

    /**
     * Build or return the cached phpClaw engine instance.
     *
     * @return Claw
     *
     * @throws \Throwable Engine build / params resolution failure.
     */
    private function getEngine(): Claw
    {
        if ($this->engine === null) {
            $this->engine = (new EngineFactory)->build(EngineFactory::getPluginParams());
        }

        return $this->engine;
    }

    /**
     * Count conversations and messages from the dedicated Joomla tables, scoped to the acting
     * user unless they hold phpclaw.chat.manageall.
     *
     * @return array{0: int, 1: int}
     */
    private function countConversationsAndMessages(): array
    {
        try {
            $db = $this->getDatabase();
            $convTable = $db->quoteName(PhpClawTables::CONVERSATIONS_TABLE);
            $msgTable = $db->quoteName(PhpClawTables::MESSAGES_TABLE);

            $ownerClause = '';

            if (! $this->canManageAll()) {
                $ownerClause = ' WHERE '.$convTable.'.'.$db->quoteName('user_id')
                    .' = '.$this->resolveActingUserId();
            }

            $conversations = (int) $db->setQuery(
                'SELECT COUNT(*) FROM '.$convTable.$ownerClause,
            )->loadResult();

            $messages = (int) $db->setQuery(
                'SELECT COUNT(*) FROM '.$msgTable
                .' INNER JOIN '.$convTable.' ON '.$convTable.'.'.$db->quoteName('id')
                .' = '.$msgTable.'.'.$db->quoteName('conversation_id')
                .$ownerClause,
            )->loadResult();

            return [$conversations, $messages];
        } catch (\Throwable) {
            return [0, 0];
        }
    }

    /**
     * Return true if the acting user holds phpclaw.chat.manageall on com_phpclaw.
     *
     * @return bool
     */
    private function canManageAll(): bool
    {
        try {
            return (bool) Factory::getApplication()
                ->getIdentity()
                ->authorise('phpclaw.chat.manageall', 'com_phpclaw');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Resolve the acting user's id, 0 if unavailable.
     *
     * @return int
     */
    private function resolveActingUserId(): int
    {
        try {
            return (int) Factory::getApplication()->getIdentity()->id;
        } catch (\Throwable) {
            return 0;
        }
    }
}
