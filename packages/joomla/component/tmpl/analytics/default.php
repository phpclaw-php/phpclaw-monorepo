<?php

declare(strict_types=1);

use Joomla\CMS\Language\Text;
use PhpClaw\Joomla\Component\Administrator\View\Analytics\HtmlView;

defined('_JEXEC') || exit;

/** @var HtmlView $this */
$stats = $this->stats;
$engineError = $this->engineError;

$conversations = (int) ($stats['conversations'] ?? 0);
$messages = (int) ($stats['messages'] ?? 0);
$active24h = (int) ($stats['active_24h'] ?? 0);

$hasData = ($conversations > 0 || $messages > 0);
?>
<div class="pc-analytics-page">
    <div class="pc-page-header">
        <?= $this->escape(Text::_('COM_PHPCLAW_ANALYTICS_TITLE')) ?>
    </div>

    <?php if ($engineError) { ?>
        <div class="pc-notice-warn"><?= $this->escape($engineError) ?></div>
    <?php } ?>

    <?php if (! $hasData) { ?>
        <div class="pc-card">
            <p class="pc-analytics-no-data">
                <?= $this->escape(Text::_('COM_PHPCLAW_ANALYTICS_NO_DATA')) ?>
            </p>
        </div>
    <?php } else { ?>

    <div class="pc-analytics-cards">
        <div class="pc-analytics-stat-card">
            <div class="pc-analytics-stat-value"><?= $this->escape((string) $conversations) ?></div>
            <div class="pc-analytics-stat-label"><?= $this->escape(Text::_('COM_PHPCLAW_ANALYTICS_CONVERSATIONS')) ?></div>
        </div>
        <div class="pc-analytics-stat-card">
            <div class="pc-analytics-stat-value"><?= $this->escape((string) $messages) ?></div>
            <div class="pc-analytics-stat-label"><?= $this->escape(Text::_('COM_PHPCLAW_ANALYTICS_MESSAGES')) ?></div>
        </div>
        <div class="pc-analytics-stat-card">
            <div class="pc-analytics-stat-value"><?= $this->escape((string) $active24h) ?></div>
            <div class="pc-analytics-stat-label"><?= $this->escape(Text::_('COM_PHPCLAW_ANALYTICS_ACTIVE_24H')) ?></div>
        </div>
    </div>

    <?php } ?>

    <div class="pc-card pc-mt-24">
        <p><strong><?= $this->escape(Text::_('COM_PHPCLAW_NOTE')) ?>:</strong>
        <?= $this->escape(Text::_('COM_PHPCLAW_ANALYTICS_NOTE_DISCLAIMER')) ?></p>
    </div>

    <?php include __DIR__.'/../_community_card.php'; ?>
</div>
