<?php

declare(strict_types=1);

use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use PhpClaw\Joomla\Component\Administrator\View\Chat\HtmlView;

defined('_JEXEC') || exit;

/** @var HtmlView $this */
$convs = $this->conversations;
$engineError = $this->engineError;
$initConvId = $this->initConvId;
$initTitle = $this->initTitle;
$initMessages = $this->initMessages;
$provider = $this->provider;
$model = $this->model;
$csrfToken = $this->csrfToken;
$storeMessages = $this->storeMessages;
$hasCloudKey = $this->hasCloudKey;
$canConfigure = $this->canConfigure;

$provLabel = match (strtolower(trim($provider))) {
    'anthropic' => 'Anthropic',
    'openai' => 'OpenAI',
    'groq' => 'Groq',
    'gemini' => 'Google Gemini',
    'mistral' => 'Mistral AI',
    'ollama' => 'Ollama',
    'deepseek' => 'DeepSeek',
    '' => Text::_('COM_PHPCLAW_NOT_CONFIGURED'),
    default => ucwords(str_replace(['-', '_'], ' ', $provider)),
};
$modelLabel = $model ?: Text::_('COM_PHPCLAW_DEFAULT');

$apiUrl = Route::_('index.php?option=com_phpclaw&task=api.send&format=json', false);
$streamUrl = Route::_('index.php?option=com_phpclaw&task=api.stream', false);
$loadUrl = Route::_('index.php?option=com_phpclaw&task=api.loadConversation&format=json', false);

$settingsUrl = $canConfigure
    ? Route::_('index.php?option=com_plugins&view=plugins&filter[folder]=system&filter[search]=phpclaw', false)
    : '';

$jsStrings = [
    'calling' => Text::_('COM_PHPCLAW_CHAT_JS_CALLING'),
    'showRaw' => Text::_('COM_PHPCLAW_CHAT_JS_SHOW_RAW'),
    'noArticles' => Text::_('COM_PHPCLAW_CHAT_JS_NO_ARTICLES'),
    'noUsers' => Text::_('COM_PHPCLAW_CHAT_JS_NO_USERS'),
    'noCategories' => Text::_('COM_PHPCLAW_CHAT_JS_NO_CATEGORIES'),
    'noExtensions' => Text::_('COM_PHPCLAW_CHAT_JS_NO_EXTENSIONS'),
    'noResults' => Text::_('COM_PHPCLAW_CHAT_JS_NO_RESULTS'),
    'noRows' => Text::_('COM_PHPCLAW_CHAT_JS_NO_ROWS'),
    'toolFailed' => Text::_('COM_PHPCLAW_CHAT_JS_TOOL_FAILED'),
    'streamFailed' => Text::_('COM_PHPCLAW_CHAT_JS_STREAM_FAILED'),
    'requestFailed' => Text::_('COM_PHPCLAW_CHAT_JS_REQUEST_FAILED'),
    'moreAvailable' => Text::_('COM_PHPCLAW_CHAT_JS_MORE_AVAILABLE'),
    'rows' => Text::_('COM_PHPCLAW_CHAT_JS_ROWS'),
    'limit' => Text::_('COM_PHPCLAW_CHAT_JS_LIMIT'),
    'offset' => Text::_('COM_PHPCLAW_CHAT_JS_OFFSET'),
    'hits' => Text::_('COM_PHPCLAW_CHAT_JS_HITS'),
    'cat' => Text::_('COM_PHPCLAW_CHAT_JS_CAT'),
    'level' => Text::_('COM_PHPCLAW_CHAT_JS_LEVEL'),
    'parent' => Text::_('COM_PHPCLAW_CHAT_JS_PARENT'),
    'published' => Text::_('COM_PHPCLAW_CHAT_JS_STATE_PUBLISHED'),
    'unpublished' => Text::_('COM_PHPCLAW_CHAT_JS_STATE_UNPUBLISHED'),
    'trashed' => Text::_('COM_PHPCLAW_CHAT_JS_STATE_TRASHED'),
    'archived' => Text::_('COM_PHPCLAW_CHAT_JS_STATE_ARCHIVED'),
    'blocked' => Text::_('COM_PHPCLAW_CHAT_JS_BLOCKED'),
    'active' => Text::_('COM_PHPCLAW_CHAT_JS_ACTIVE'),
    'enabled' => Text::_('COM_PHPCLAW_CHAT_JS_ENABLED'),
    'disabled' => Text::_('COM_PHPCLAW_CHAT_JS_DISABLED'),
    'name' => Text::_('COM_PHPCLAW_CHAT_JS_NAME'),
    'email' => Text::_('COM_PHPCLAW_CHAT_JS_EMAIL'),
    'groups' => Text::_('COM_PHPCLAW_CHAT_JS_GROUPS'),
    'lastVisit' => Text::_('COM_PHPCLAW_CHAT_JS_LAST_VISIT'),
    'status' => Text::_('COM_PHPCLAW_CHAT_JS_STATUS'),
    'unknownError' => Text::_('COM_PHPCLAW_CHAT_JS_UNKNOWN_ERROR'),
    'sessionExpired' => Text::_('COM_PHPCLAW_CHAT_JS_SESSION_EXPIRED'),
    'unexpectedResponse' => Text::_('COM_PHPCLAW_CHAT_JS_UNEXPECTED_RESPONSE'),
    'untitled' => Text::_('COM_PHPCLAW_CHAT_JS_UNTITLED'),
    'noHistory' => Text::_('COM_PHPCLAW_CHAT_JS_NO_HISTORY'),
    'loadFailed' => Text::_('COM_PHPCLAW_CHAT_JS_LOAD_FAILED'),
    'loadError' => Text::_('COM_PHPCLAW_CHAT_JS_LOAD_ERROR'),
    'emptyPrompt' => Text::_('COM_PHPCLAW_CHAT_JS_EMPTY_PROMPT'),
    'notConfigured' => Text::_('COM_PHPCLAW_CHAT_JS_NOT_CONFIGURED'),
    'configureLink' => Text::_('COM_PHPCLAW_CHAT_JS_CONFIGURE_LINK'),
];
?>
<div class="pc-chat-page">
    <a href="#phpclaw-message" class="sr-only sr-only-focusable"><?= $this->escape(Text::_('COM_PHPCLAW_SKIP_TO_CHAT_INPUT')) ?></a>

    <div class="pc-chat-header">
        <h2 class="pc-chat-header__title"><?= $this->escape(Text::_('COM_PHPCLAW_CHAT_HEADING')) ?></h2>
        <div class="pc-chat-header__end">
            <?php if ($engineError) { ?>
                <span class="pc-chat-header__status--error">
                    <?= $this->escape(Text::_('COM_PHPCLAW_ENGINE_ERROR')) ?>.
                    <?php if ($canConfigure) { ?>
                        <a href="<?= $this->escape($settingsUrl) ?>"><?= $this->escape(Text::_('COM_PHPCLAW_CONFIGURE')) ?></a>
                    <?php } ?>
                </span>
            <?php } else { ?>
                <span class="pc-chat-header__status">
                    <span class="pc-status-online">&#9679;</span>
                    <?= $this->escape($provLabel) ?> &middot; <?= $this->escape($modelLabel) ?>
                </span>
            <?php } ?>
            <?php if ($canConfigure) { ?>
                <a href="<?= $this->escape($settingsUrl) ?>" class="btn btn-sm btn-outline-secondary"><?= $this->escape(Text::_('COM_PHPCLAW_SETTINGS')) ?></a>
            <?php } ?>
        </div>
    </div>


    <?php if ($engineError) { ?>
        <div class="pc-notice-warn"><?= $this->escape($engineError) ?></div>
    <?php } ?>

    <div id="phpclaw-chat-wrap"
         data-init-conv-id="<?= $this->escape($initConvId) ?>"
         data-api-url="<?= $this->escape($apiUrl) ?>"
         data-stream-url="<?= $this->escape($streamUrl) ?>"
         data-load-url="<?= $this->escape($loadUrl) ?>"
         data-csrf-token="<?= $this->escape($csrfToken) ?>"
         data-settings-url="<?= $this->escape($settingsUrl) ?>"
         data-i18n="<?= $this->escape(json_encode($jsStrings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>">

        <nav id="phpclaw-sidebar" aria-label="<?= $this->escape(Text::_('COM_PHPCLAW_CONVERSATIONS')) ?>">
            <div id="phpclaw-sidebar-top">
                <button id="phpclaw-new-chat" class="btn btn-primary btn-sm w-100" aria-label="<?= $this->escape(Text::_('COM_PHPCLAW_NEW_CHAT')) ?>">
                    + <?= $this->escape(Text::_('COM_PHPCLAW_NEW_CHAT')) ?>
                </button>
                <div role="search" aria-label="<?= $this->escape(Text::_('COM_PHPCLAW_SEARCH_CONVERSATIONS')) ?>">
                    <input id="phpclaw-conv-search" type="search"
                           class="form-control form-control-sm mt-2"
                           placeholder="<?= $this->escape(Text::_('COM_PHPCLAW_SEARCH_CONVERSATIONS')) ?>"
                           aria-label="<?= $this->escape(Text::_('COM_PHPCLAW_SEARCH_CONVERSATIONS')) ?>" />
                </div>
            </div>
            <div id="phpclaw-conv-list" role="list" aria-label="<?= $this->escape(Text::_('COM_PHPCLAW_CONVERSATION_LIST')) ?>">
                <?php if ($convs === []) { ?>
                    <p id="phpclaw-empty-sidebar" class="px-3 py-3 pc-text-meta small mb-0 pc-empty-sidebar">
                        <?= $this->escape(Text::_('COM_PHPCLAW_NO_CONVERSATIONS')) ?>
                    </p>
                <?php } else { ?>
                    <?php foreach ($convs as $cid => $conv) {
                        $title = (string) ($conv['title'] ?? '');
                        $display = $title !== '' ? $title : Text::_('COM_PHPCLAW_NEW_CONVERSATION');
                        $isFirst = ((string) $cid === $initConvId);
                        ?>
                    <div class="phpclaw-conv-item <?= $isFirst ? 'active' : '' ?>"
                         role="listitem"
                         tabindex="0"
                         aria-current="<?= $isFirst ? 'true' : 'false' ?>"
                         data-conv-id="<?= $this->escape((string) $cid) ?>"
                         data-title="<?= $this->escape(strtolower($display)) ?>">
                        <div class="phpclaw-conv-item-title"><?= $this->escape($display) ?></div>
                    </div>
                    <?php } ?>
                <?php } ?>
            </div>
        </nav>

        <div id="phpclaw-chat-area" role="main">
            <div id="phpclaw-chat-header">
                <?php if ($initConvId !== '') { ?>
                    <?= $this->escape($initTitle !== '' ? $initTitle : Text::_('COM_PHPCLAW_CONVERSATION')) ?>
                <?php } else { ?>
                    <?= $this->escape(Text::_('COM_PHPCLAW_NEW_CHAT')) ?>
                <?php } ?>
            </div>

            <div id="phpclaw-messages" role="log" aria-live="polite" aria-label="<?= $this->escape(Text::_('COM_PHPCLAW_CHAT_MESSAGES')) ?>">
                <?php if ($initConvId === '') { ?>
                    <div id="phpclaw-welcome">
                        <h2><?= $this->escape(Text::_('COM_PHPCLAW_WELCOME_TITLE')) ?></h2>
                        <p><?= $this->escape(Text::_('COM_PHPCLAW_WELCOME_DESC')) ?></p>
                    </div>
                <?php } elseif ($initMessages === []) { ?>
                    <div id="phpclaw-welcome">
                        <h2><?= $this->escape(Text::_('COM_PHPCLAW_CONVERSATION_STARTED')) ?></h2>
                        <p><?= $this->escape(Text::_('COM_PHPCLAW_NO_MESSAGES_YET')) ?></p>
                    </div>
                <?php } else { ?>
                    <?php foreach ($initMessages as $msg) { ?>
                        <div class="phpclaw-bubble-wrap <?= $this->escape($msg['role']) ?>">
                            <?php if ($msg['role'] === 'assistant') { ?>
                                <div class="phpclaw-avatar" aria-hidden="true">&#129302;</div>
                            <?php } ?>
                            <div class="phpclaw-bubble <?= $this->escape($msg['role']) ?>"><?= $this->escape($msg['content']) ?></div>
                        </div>
                    <?php } ?>
                <?php } ?>
            </div>

            <div id="phpclaw-input-area">
                <div id="phpclaw-input-row">
                    <label for="phpclaw-message" class="visually-hidden"><?= $this->escape(Text::_('COM_PHPCLAW_MESSAGE')) ?></label>
                    <textarea id="phpclaw-message" rows="2"
                        placeholder="<?= $this->escape(Text::_('COM_PHPCLAW_MESSAGE_PLACEHOLDER')) ?>"
                        aria-label="<?= $this->escape(Text::_('COM_PHPCLAW_TYPE_MESSAGE')) ?>"></textarea>
                    <button id="phpclaw-send-btn"
                            title="<?= $this->escape(Text::_('COM_PHPCLAW_SEND')) ?>"
                            aria-label="<?= $this->escape(Text::_('COM_PHPCLAW_SEND_MESSAGE')) ?>"
                            <?= $engineError ? 'disabled' : '' ?>>
                        <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <path d="M12 4l-1.41 1.41L16.17 11H4v2h12.17l-5.58 5.59L12 20l8-8z"/>
                        </svg>
                    </button>
                </div>
                <div id="phpclaw-input-meta">
                    <?= $this->escape($provLabel) ?> &middot; <?= $this->escape($modelLabel) ?> &middot;
                    <?php if (! $storeMessages) { ?>
                        <?= $this->escape(Text::_('COM_PHPCLAW_PRIVACY_OFF')) ?>
                    <?php } elseif ($hasCloudKey) { ?>
                        <?= $this->escape(Text::_('COM_PHPCLAW_PRIVACY_ON_CLOUD')) ?>
                    <?php } else { ?>
                        <?= $this->escape(Text::_('COM_PHPCLAW_PRIVACY_ON_LOCAL')) ?>
                    <?php } ?>
                </div>
            </div>
        </div>

    </div>

    <?php include __DIR__.'/../_community_card.php'; ?>
</div>