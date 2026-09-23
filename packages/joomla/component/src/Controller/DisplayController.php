<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Component\Administrator\Controller;

use Joomla\CMS\Access\Exception\NotAllowed;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;

/**
 * Default display controller for the phpClaw admin component.
 */
final class DisplayController extends BaseController
{
    protected $default_view = 'chat';

    /**
     * Render the default component view, after requiring phpclaw.chat.use and rejecting
     * anyone without it with a 403.
     *
     * @param  bool  $cachable  If true, the view output will be cached.
     * @param  array  $urlparams  An array of safe URL parameters.
     * @return static
     */
    public function display($cachable = false, $urlparams = []): static
    {
        $identity = Factory::getApplication()->getIdentity();

        if ($identity === null || ! $identity->authorise('phpclaw.chat.use', 'com_phpclaw')) {
            throw new NotAllowed(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        return parent::display($cachable, $urlparams);
    }
}
