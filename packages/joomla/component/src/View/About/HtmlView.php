<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Component\Administrator\View\About;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;
use PhpClaw\Joomla\Component\Administrator\View\Concerns\RegistersAdminAssets;

/**
 * About view - marketing and feature overview page.
 */
final class HtmlView extends BaseHtmlView
{
    use RegistersAdminAssets;

    /**
     * Render the About view: add the toolbar and enqueue assets.
     *
     * @param  string  $tpl  Template file to use.
     * @return void
     */
    public function display($tpl = null): void
    {
        $this->addToolbar();
        $this->enqueueAssets();

        parent::display($tpl);
    }

    /**
     * Register the About page toolbar title and preferences button.
     *
     * @return void
     */
    private function addToolbar(): void
    {
        ToolbarHelper::title(Text::_('COM_PHPCLAW_ABOUT_TITLE'), 'info-circle');

        if (Factory::getApplication()->getIdentity()->authorise('core.admin', 'com_phpclaw')) {
            ToolbarHelper::preferences('com_phpclaw');
        }
    }

    /**
     * Register and load the admin stylesheet for the About view.
     *
     * @return void
     */
    private function enqueueAssets(): void
    {
        $this->enqueueAdminStyle();
    }
}
