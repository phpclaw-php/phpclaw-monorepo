<?php

declare(strict_types=1);

use Joomla\CMS\Language\Text;
use PhpClaw\Joomla\Component\Administrator\View\About\HtmlView;

defined('_JEXEC') || exit;

/** @var HtmlView $this */
?>
<div class="pc-about-wrap">

    <div class="pc-about-hero">
        <div class="pc-about-hero-brand">
            <div class="pc-about-hero-logo">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#fff" width="38" height="38">
                    <path d="M12 2a2 2 0 0 1 2 2 2 2 0 0 1-2 2 2 2 0 0 1-2-2 2 2 0 0 1 2-2m0 5c2.67 0 8 1.34 8 4v2H4v-2c0-2.66 5.33-4 8-4z"/>
                    <rect x="3" y="14" width="18" height="8" rx="2"/>
                    <circle cx="8.5" cy="18" r="1.5" fill="#7c3aed"/>
                    <circle cx="15.5" cy="18" r="1.5" fill="#7c3aed"/>
                    <rect x="10.5" y="16.5" width="3" height="1" rx=".5" fill="#7c3aed"/>
                </svg>
            </div>
            <div>
                <h1 class="pc-about-hero-title">phpClaw</h1>
                <p class="pc-about-hero-subtitle"><?= $this->escape(Text::_('COM_PHPCLAW_ABOUT_TAGLINE')) ?></p>
            </div>
        </div>
        <nav class="pc-about-hero-nav" aria-label="<?= $this->escape(Text::_('COM_PHPCLAW_LINKS')) ?>">
            <a href="https://phpclaw.ai" target="_blank" rel="noopener" class="pc-about-hero-btn pc-about-hero-btn--primary">
                <?= $this->escape(Text::_('COM_PHPCLAW_WEBSITE')) ?>
            </a>
            <a href="https://phpclaw.ai/docs" target="_blank" rel="noopener" class="pc-about-hero-btn pc-about-hero-btn--ghost">
                <?= $this->escape(Text::_('COM_PHPCLAW_DOCUMENTATION')) ?>
            </a>
            <a href="https://github.com/phpclaw-php/phpclaw-monorepo" target="_blank" rel="noopener" class="pc-about-hero-btn pc-about-hero-btn--ghost">
                <?= $this->escape(Text::_('COM_PHPCLAW_GITHUB')) ?>
            </a>
        </nav>
    </div>

    <div class="pc-about-body">

        <div class="pc-about-card">
            <h2><?= $this->escape(Text::_('COM_PHPCLAW_WHAT_IS')) ?></h2>
            <p><?= $this->escape(Text::_('COM_PHPCLAW_WHAT_IS_P1')) ?></p>
            <p><?= $this->escape(Text::_('COM_PHPCLAW_WHAT_IS_P2')) ?></p>
            <p><?= $this->escape(Text::_('COM_PHPCLAW_WHAT_IS_P3')) ?></p>
        </div>

        <h2 class="pc-about-features-heading"><?= $this->escape(Text::_('COM_PHPCLAW_FEATURES_HEADING')) ?></h2>
        <div class="phpclaw-features-grid">
            <?php
            $features = [
                ['icon' => '&#129302;', 'title' => Text::_('COM_PHPCLAW_FEAT_PROVIDERS_TITLE'),  'text' => Text::_('COM_PHPCLAW_FEAT_PROVIDERS_DESC')],
                ['icon' => '&#128736;', 'title' => Text::_('COM_PHPCLAW_FEAT_TOOLS_TITLE'),      'text' => Text::_('COM_PHPCLAW_FEAT_TOOLS_DESC')],
                ['icon' => '&#128172;', 'title' => Text::_('COM_PHPCLAW_FEAT_CHAT_TITLE'),       'text' => Text::_('COM_PHPCLAW_FEAT_CHAT_DESC')],
                ['icon' => '&#9889;',   'title' => Text::_('COM_PHPCLAW_FEAT_STREAMING_TITLE'),  'text' => Text::_('COM_PHPCLAW_FEAT_STREAMING_DESC')],
                ['icon' => '&#128268;', 'title' => Text::_('COM_PHPCLAW_FEAT_CLI_TITLE'),        'text' => Text::_('COM_PHPCLAW_FEAT_CLI_DESC')],
                ['icon' => '&#128274;', 'title' => Text::_('COM_PHPCLAW_FEAT_SECURITY_TITLE'),   'text' => Text::_('COM_PHPCLAW_FEAT_SECURITY_DESC')],
                ['icon' => '&#127760;', 'title' => Text::_('COM_PHPCLAW_FEAT_ECOSYSTEM_TITLE'),  'text' => Text::_('COM_PHPCLAW_FEAT_ECOSYSTEM_DESC')],
            ];
foreach ($features as $f) { ?>
            <div class="pc-feature-card">
                <div class="pc-feature-card-icon"><?= $f['icon'] ?></div>
                <strong class="pc-feature-card-title"><?= $this->escape($f['title']) ?></strong>
                <p class="pc-feature-card-text"><?= $this->escape($f['text']) ?></p>
            </div>
            <?php } ?>
        </div>

        <div class="pc-about-card">
            <h2><?= $this->escape(Text::_('COM_PHPCLAW_OSS_TITLE')) ?></h2>
            <p class="pc-about-pkg-intro"><?= $this->escape(Text::_('COM_PHPCLAW_OSS_INTRO')) ?></p>
            <table class="pc-about-pkg-table">
                <thead>
                    <tr>
                        <th><?= $this->escape(Text::_('COM_PHPCLAW_PACKAGE')) ?></th>
                        <th><?= $this->escape(Text::_('COM_PHPCLAW_WHAT_IT_ADDS')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
        $packages = [
            ['phpclaw/phpclaw',        'PhpClaw\\Claw',                  Text::_('COM_PHPCLAW_PKG_CORE')],
            ['phpclaw/phpclaw-joomla', 'PhpClaw\\Joomla\\Extension\\PhpClawPlugin', Text::_('COM_PHPCLAW_PKG_JOOMLA')],
            ['phpclaw/phpclaw-cloud',  'PhpClaw\\Cloud\\CloudManager',   Text::_('COM_PHPCLAW_PKG_CLOUD')],
            ['phpclaw/phpclaw-mcp',    'PhpClaw\\Mcp\\PhpClawMcpServer', Text::_('COM_PHPCLAW_PKG_MCP')],
        ];
foreach ($packages as [$pkg, $sentinel, $desc]) {
    $installed = class_exists($sentinel);
    ?>
                    <tr>
                        <td><code><?= $this->escape($pkg) ?></code></td>
                        <td>
                            <?php if ($installed) { ?>
                                <span class="badge bg-success" aria-label="<?= $this->escape(Text::_('COM_PHPCLAW_PKG_INSTALLED')) ?>">&#10003;</span>
                            <?php } else { ?>
                                <span class="badge bg-secondary" aria-label="<?= $this->escape(Text::_('COM_PHPCLAW_PKG_NOT_INSTALLED')) ?>">&#8212;</span>
                            <?php } ?>
                            <?= $this->escape($desc) ?>
                        </td>
                    </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>

        <div class="pc-about-cloud-note">
            <?= Text::sprintf(
                'COM_PHPCLAW_ABOUT_CLOUD_NOTE',
                '<a href="https://phpclaw.ai" target="_blank" rel="noopener">phpclaw.ai</a>'
            ) ?>
        </div>

        <?php include __DIR__.'/../_community_card.php'; ?>

    </div>
</div>
