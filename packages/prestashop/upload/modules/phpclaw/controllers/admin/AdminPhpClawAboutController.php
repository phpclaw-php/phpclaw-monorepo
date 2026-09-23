<?php

declare(strict_types=1);

use PhpClaw\PrestaShop\Admin\AboutPage;

if (! defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__.'/AdminPhpClawBaseController.php';

/**
 * phpClaw About admin controller: product/marketing page.
 */
final class AdminPhpClawAboutController extends AdminPhpClawBaseController
{
    /**
     * Set the page title after the parent bootstrap.
     */
    public function __construct()
    {
        parent::__construct();
        $this->meta_title = $this->trans('phpClaw: About', [], 'Modules.Phpclaw.Admin');
    }

    /**
     * Render the About page template.
     *
     * @return void
     */
    public function initContent(): void
    {
        parent::initContent();

        $this->context->smarty->assign([
            'about' => AboutPage::data(),
            'url_settings' => $this->context->link->getAdminLink('AdminPhpClawSettings'),
        ]);

        $this->content .= $this->context->smarty->fetch('file:'._PS_MODULE_DIR_.'phpclaw/views/templates/admin/about.tpl');
        $this->context->smarty->assign('content', $this->content);
    }
}
