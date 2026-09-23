<?php

declare(strict_types=1);

namespace PhpClaw\WordPress\Admin;

/**
 * Shared footer rendered at the bottom of every phpClaw admin page.
 */
final class AdminFooter
{
    /**
     * Community card - shown at the bottom of every admin page.
     *
     * @return void
     */
    public static function renderCommunityCard(): void
    {
        ?>
        <div class="pc-about-card pc-footer-card">
            <h2><?= esc_html__('Built for the PHP community', 'phpclaw') ?></h2>
            <p><?= esc_html__('phpClaw is open source under the MIT license.', 'phpclaw') ?></p>
            <p><?= esc_html__('One engine for the entire PHP ecosystem.', 'phpclaw') ?></p>
            <div class="pc-about-cta-btns">
                <a href="https://github.com/phpclaw-php/phpclaw-monorepo" target="_blank" rel="noopener" class="pc-about-cta-btn pc-about-cta-btn--dark">
                    ⭐ <?= esc_html__('Star on GitHub', 'phpclaw') ?>
                </a>
                <a href="https://packagist.org/packages/phpclaw/phpclaw" target="_blank" rel="noopener" class="pc-about-cta-btn pc-about-cta-btn--orange">
                    📦 <?= esc_html__('View on Packagist', 'phpclaw') ?>
                </a>
            </div>
            <div class="pc-about-enterprise">
                <p class="pc-about-enterprise-title"><?= esc_html__('Need custom AI tools for your site?', 'phpclaw') ?></p>
                <a href="https://phpclaw.ai/enterprise" target="_blank" rel="noopener" class="pc-about-enterprise-link">
                    &rarr; phpclaw.ai/enterprise
                </a>
            </div>
        </div>
        <?php
    }
}
