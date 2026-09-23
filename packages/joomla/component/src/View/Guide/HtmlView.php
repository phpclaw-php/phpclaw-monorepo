<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Component\Administrator\View\Guide;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;
use PhpClaw\Joomla\Component\Administrator\View\Concerns\RegistersAdminAssets;

/**
 * Guide view - Tools, Memory, REST API, CLI documentation tabs.
 */
final class HtmlView extends BaseHtmlView
{
    use RegistersAdminAssets;

    public bool $canManageAll = false;

    /**
     * Render the Guide view: resolve the manage-all flag the layout reads, add the toolbar,
     * and enqueue assets.
     *
     * @param  string  $tpl  Template file to use.
     * @return void
     */
    public function display($tpl = null): void
    {
        $this->canManageAll = (bool) Factory::getApplication()->getIdentity()->authorise('phpclaw.chat.manageall', 'com_phpclaw');

        $this->addToolbar();
        $this->enqueueAssets();

        parent::display($tpl);
    }

    /**
     * Register the Guide page toolbar title and preferences button.
     *
     * @return void
     */
    private function addToolbar(): void
    {
        ToolbarHelper::title(Text::_('COM_PHPCLAW_GUIDE_TITLE'), 'book');

        if (Factory::getApplication()->getIdentity()->authorise('core.admin', 'com_phpclaw')) {
            ToolbarHelper::preferences('com_phpclaw');
        }
    }

    /**
     * Register and load the admin stylesheet for the Guide view.
     *
     * @return void
     */
    private function enqueueAssets(): void
    {
        $this->enqueueAdminStyle();
    }
}
