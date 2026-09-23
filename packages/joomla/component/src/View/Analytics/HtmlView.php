<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Component\Administrator\View\Analytics;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;
use PhpClaw\Joomla\Component\Administrator\View\Concerns\RegistersAdminAssets;

/**
 * Analytics view - usage statistics.
 */
final class HtmlView extends BaseHtmlView
{
    use RegistersAdminAssets;

    public array $stats = [];

    public ?string $engineError = null;

    /**
     * Render the Analytics view: hydrate stats and engine error, add the toolbar, and enqueue assets.
     *
     * @param  string  $tpl  Template file to use.
     * @return void
     */
    public function display($tpl = null): void
    {
        $model = $this->getModel();

        $this->stats = $model->getStats();
        $this->engineError = $model->getEngineError();

        $this->addToolbar();
        $this->enqueueAssets();

        parent::display($tpl);
    }

    /**
     * Register the Analytics page toolbar title and preferences button.
     *
     * @return void
     */
    private function addToolbar(): void
    {
        ToolbarHelper::title(Text::_('COM_PHPCLAW_ANALYTICS_TITLE'), 'chart');

        if (Factory::getApplication()->getIdentity()->authorise('core.admin', 'com_phpclaw')) {
            ToolbarHelper::preferences('com_phpclaw');
        }
    }

    /**
     * Register and load the admin stylesheet for the Analytics view.
     *
     * @return void
     */
    private function enqueueAssets(): void
    {
        $this->enqueueAdminStyle();
    }
}
