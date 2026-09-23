<?php

declare(strict_types=1);

use Joomla\CMS\Language\Text;

defined('_JEXEC') || exit;

/**
 * Shared community card + enterprise CTA - included on every phpClaw admin page.
 */
?>
<div class="pc-about-card pc-about-card--spaced">
    <h2><?= $this->escape(Text::_('COM_PHPCLAW_COMMUNITY_TITLE')) ?></h2>
    <p><?= $this->escape(Text::_('COM_PHPCLAW_COMMUNITY_DESC1')) ?></p>
    <p><?= $this->escape(Text::_('COM_PHPCLAW_COMMUNITY_DESC2')) ?></p>
    <div class="pc-about-cta-btns">
        <a href="https://github.com/phpclaw-php/phpclaw-monorepo" target="_blank" rel="noopener" class="pc-about-cta-btn pc-about-cta-btn--dark">
            <?= $this->escape(Text::_('COM_PHPCLAW_COMMUNITY_GITHUB')) ?>
        </a>
        <a href="https://packagist.org/packages/phpclaw/phpclaw" target="_blank" rel="noopener" class="pc-about-cta-btn pc-about-cta-btn--orange">
            <?= $this->escape(Text::_('COM_PHPCLAW_COMMUNITY_PACKAGIST')) ?>
        </a>
    </div>
    <div class="pc-about-enterprise">
        <p class="pc-enterprise-title"><?= $this->escape(Text::_('COM_PHPCLAW_ENTERPRISE_TITLE')) ?></p>
        <a href="https://phpclaw.ai/enterprise" target="_blank" rel="noopener" class="pc-enterprise-link">
            &rarr; <?= $this->escape(Text::_('COM_PHPCLAW_ENTERPRISE_LINK')) ?>
        </a>
    </div>
</div>
