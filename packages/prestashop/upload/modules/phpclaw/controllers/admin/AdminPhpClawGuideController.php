<?php

declare(strict_types=1);
use PhpClaw\PrestaShop\Admin\GuidePage;
use PhpClaw\PrestaShop\PsIdentityResolver;

if (! defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__.'/AdminPhpClawBaseController.php';

/**
 * phpClaw Guide admin controller: reference page for tools, providers, API, and CLI.
 */
final class AdminPhpClawGuideController extends AdminPhpClawBaseController
{
    /**
     * Set the page title after the parent bootstrap.
     */
    public function __construct()
    {
        parent::__construct();
        $this->meta_title = $this->trans('phpClaw: Guide', [], 'Modules.Phpclaw.Admin');
    }

    /**
     * Render the Guide page template with tool, provider, and CLI reference data.
     *
     * @return void
     */
    public function initContent(): void
    {
        parent::initContent();

        $plugin = $this->getPlugin();

        $this->context->smarty->assign([
            'tools' => GuidePage::guideToolRows($plugin),
            'core_tools' => GuidePage::getCoreUtilityTools(),
            'memory_drivers' => GuidePage::getMemoryDrivers(),
            'url_api_base' => $this->getApiBaseUrl(),
            'cli_commands' => $this->getCliCommands(),
            'providers' => GuidePage::guideProviders(),
            'guards' => GuidePage::getDiscoveredGuards(),
            'hooks' => GuidePage::getDiscoveredHooks(),
            'skills' => GuidePage::getDiscoveredSkills(),
            'remote_skill_urls' => GuidePage::getRemoteSkillUrls($plugin),
            'remote_skills' => GuidePage::getRemoteSkills($plugin),
            'url_settings' => $this->context->link->getAdminLink('AdminPhpClawSettings'),
            'can_manage_all' => PsIdentityResolver::manageAll(),
            'url_debug' => $this->context->link->getAdminLink('AdminPhpClawDebug'),
            'db_prefix' => _DB_PREFIX_,
        ]);

        $this->content .= $this->context->smarty->fetch('file:'._PS_MODULE_DIR_.'phpclaw/views/templates/admin/guide.tpl');
        $this->context->smarty->assign('content', $this->content);
    }

    /**
     * Base URL for the public REST front controller (without the action param).
     *
     * @return string
     */
    private function getApiBaseUrl(): string
    {
        return rtrim((string) $this->context->shop->getBaseURL(true), '/')
            .'/index.php?fc=module&module=phpclaw&controller=api';
    }

    /**
     * CLI command examples.
     *
     * @return array<int, array{command: string, description: string}>
     */
    private function getCliCommands(): array
    {
        return [
            ['command' => 'php modules/phpclaw/cli/phpclaw.php send "your prompt"',                    'description' => 'Send a prompt and print the response.'],
            ['command' => 'php modules/phpclaw/cli/phpclaw.php send "prompt" --provider=anthropic',    'description' => 'Override provider for one call.'],
            ['command' => 'php modules/phpclaw/cli/phpclaw.php send "prompt" --model=claude-opus-4-8', 'description' => 'Override model for one call.'],
            ['command' => 'php modules/phpclaw/cli/phpclaw.php send "prompt" --stream',                'description' => 'Stream the response token-by-token.'],
            ['command' => 'php modules/phpclaw/cli/phpclaw.php mcp-server',                            'description' => 'Start the phpClaw MCP server (stdio transport).'],
        ];
    }
}
