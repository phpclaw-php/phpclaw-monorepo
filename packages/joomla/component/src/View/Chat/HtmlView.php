<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Component\Administrator\View\Chat;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Toolbar\ToolbarHelper;
use PhpClaw\Joomla\Component\Administrator\View\Concerns\RegistersAdminAssets;

/**
 * Chat view - chat-style conversation interface.
 */
final class HtmlView extends BaseHtmlView
{
    use RegistersAdminAssets;

    private const TOOLBAR_ICON = 'comments';

    private const JS_HANDLE = 'com_phpclaw.chat';

    private const JS_PATH = 'com_phpclaw/phpclaw-chat.js';

    private const PROVIDER_STATUS_NOT_CONFIGURED = 'not_configured';

    public array $conversations = [];

    public ?string $engineError = null;

    public string $initConvId = '';

    public string $initTitle = '';

    public array $initMessages = [];

    public string $provider = '';

    public string $model = '';

    public string $csrfToken = '';

    public bool $storeMessages = true;

    public bool $hasCloudKey = false;

    public bool $canConfigure = false;

    public array $stats = [
        'conversations' => 0,
        'messages' => 0,
        'provider_status' => self::PROVIDER_STATUS_NOT_CONFIGURED,
    ];

    /**
     * Render the chat view: hydrate model state, add toolbar, enqueue assets.
     *
     * @param  string|null  $tpl  Template file to use.
     * @return void
     */
    public function display($tpl = null): void
    {
        $this->hydrateFromModel();

        $this->addToolbar();
        $this->enqueueAssets();

        parent::display($tpl);
    }

    /**
     * Pull every public field this view exposes to the layout from the ChatModel.
     *
     * @return void
     */
    private function hydrateFromModel(): void
    {
        $model = $this->getModel();

        $this->conversations = $model->getConversations();
        $this->engineError = $model->getEngineError();
        $this->provider = $model->getProvider();
        $this->model = $model->getModelName();
        $this->csrfToken = Session::getFormToken();
        $this->storeMessages = $model->isStoreMessages();
        $this->hasCloudKey = $model->hasCloudKey();
        $this->stats = $model->getStats();
        $this->canConfigure = self::canConfigure();

        if ($this->engineError !== null || $this->conversations === []) {
            return;
        }

        $initData = $model->getInitialConversation();
        $this->initConvId = (string) $initData['id'];
        $this->initTitle = (string) $initData['title'];
        $this->initMessages = $initData['messages'];
    }

    /**
     * Render the page toolbar with the chat title and icon.
     *
     * @return void
     */
    private function addToolbar(): void
    {
        ToolbarHelper::title(Text::_('COM_PHPCLAW_CHAT_TITLE'), self::TOOLBAR_ICON);

        if (self::canConfigure()) {
            ToolbarHelper::preferences('com_phpclaw');
        }
    }

    /**
     * Whether the acting identity may reach the plugin settings page.
     *
     * @return bool
     */
    private static function canConfigure(): bool
    {
        return Factory::getApplication()->getIdentity()->authorise('core.admin', 'com_phpclaw') === true;
    }

    /**
     * Register and enqueue the chat page CSS + JS via the Joomla Web Asset Manager.
     *
     * @return void
     */
    private function enqueueAssets(): void
    {
        $this->enqueueAdminStyle();

        Factory::getApplication()
            ->getDocument()
            ->getWebAssetManager()
            ->registerAndUseScript(self::JS_HANDLE, self::JS_PATH, [], ['defer' => true]);
    }
}
