<?php

declare(strict_types=1);

use PhpClaw\Cloud\CloudManager;
use PhpClaw\PrestaShop\Admin\AdminNotice;
use PhpClaw\PrestaShop\Admin\AutoUpdater;
use PhpClaw\PrestaShop\Admin\SettingsPage;
use PhpClaw\PrestaShop\Plugin;
use PhpClaw\PrestaShop\PsIdentityResolver;
use PhpClaw\PrestaShop\Rest\ApiHandler;
use PhpClaw\PrestaShop\Rest\PsApiToken;

if (! defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__.'/AdminPhpClawBaseController.php';

/**
 * phpClaw Settings admin controller: settings page and AJAX endpoints.
 */
final class AdminPhpClawSettingsController extends AdminPhpClawBaseController
{
    private const UPDATE_FOUND_CACHE_SECONDS = 86400;

    private const UPDATE_NULL_CACHE_SECONDS = 3600;

    /**
     * Set the page title after the parent bootstrap.
     */
    public function __construct()
    {
        parent::__construct();
        $this->meta_title = $this->trans('phpClaw: Settings', [], 'Modules.Phpclaw.Admin');
    }

    /**
     * Render the Settings page template and handle form saves and notices.
     *
     * @return void
     */
    public function initContent(): void
    {
        parent::initContent();

        $plugin = $this->getPlugin();
        $saved = $plugin->saved();
        $config = $plugin->config();

        if (Tools::isSubmit('submitPhpClawSettings')) {
            $this->handleSettingsSave($plugin);
            $saved = $plugin->saved();
        }

        $current = SettingsPage::merge($saved, $config);

        if (AdminNotice::shouldShow($saved)) {
            $this->warnings[] = $this->trans(
                'phpClaw is not configured. Set your AI provider and API key below to start using AI agents in your store.',
                [],
                'Modules.Phpclaw.Admin',
            );
        }

        $update = $this->cachedUpdateCheck((string) ($config['update_server'] ?? ''));

        if (is_array($update) && ! empty($update['version'])) {
            $msg = $this->trans('A new version of phpClaw is available: %s.', [(string) $update['version']], 'Modules.Phpclaw.Admin');
            if (! empty($update['download_url'])) {
                $msg .= ' <a href="'.htmlspecialchars((string) $update['download_url'], ENT_QUOTES).'" target="_blank" rel="noopener">'
                     .$this->trans('Download', [], 'Modules.Phpclaw.Admin').'</a>';
            }
            $this->informations[] = $msg;
        }

        $this->context->smarty->assign([
            'phpclaw_settings' => $current,
            'phpclaw_providers' => SettingsPage::providers(),
            'phpclaw_cloud_available' => class_exists(CloudManager::class),
            'url_debug' => $this->context->link->getAdminLink('AdminPhpClawDebug'),
            'url_about' => $this->context->link->getAdminLink('AdminPhpClawAbout'),
            'url_test_connection' => $this->context->link->getAdminLink('AdminPhpClawSettings', true, [], ['ajax' => 1, 'action' => 'TestConnection']),
            'url_api' => $this->context->link->getModuleLink('phpclaw', 'api'),
            'phpclaw_can_manage_tokens' => PsIdentityResolver::manageAll(),
            'phpclaw_api_tokens' => $this->apiTokens(),
            'phpclaw_employees' => $this->chatEmployees(),
            'phpclaw_new_token' => (string) ($_GET['phpclaw_new_token'] ?? ''),
            'url_issue_token' => $this->context->link->getAdminLink('AdminPhpClawSettings', true, [], ['ajax' => 1, 'action' => 'IssueToken']),
            'url_revoke_token' => $this->context->link->getAdminLink('AdminPhpClawSettings', true, [], ['ajax' => 1, 'action' => 'RevokeToken']),
        ]);

        $this->content .= $this->context->smarty->fetch('file:'._PS_MODULE_DIR_.'phpclaw/views/templates/admin/settings.tpl');
        $this->context->smarty->assign('content', $this->content);
    }

    /**
     * POST ajax=1&action=TestConnection: verify provider credentials.
     *
     * @return void
     */
    public function ajaxProcessTestConnection(): void
    {
        if (! $this->canDo('view')) {
            $this->respondJson(['error' => 'Permission denied.']);
        }

        if (! $this->checkToken()) {
            $this->respondJson(['error' => 'Invalid security token.']);
        }

        try {
            $engine = $this->getPlugin()->engine();
            $response = $engine->send(ApiHandler::TEST_PROBE);

            $this->respondJson([
                'success' => true,
                'provider' => $response->provider,
                'model' => $response->model,
                'response' => $response->text,
            ]);
        } catch (Throwable) {
            $this->respondJson(['success' => false, 'error' => 'Connection test failed. Check your API key and provider settings.']);
        }
    }

    /**
     * POST ajax=1&action=IssueToken: issue a REST API token for one employee.
     *
     * @return void
     */
    public function ajaxProcessIssueToken(): void
    {
        if (! PsIdentityResolver::manageAll()) {
            $this->respondJson(['error' => 'Permission denied.']);
        }

        if (! $this->checkToken()) {
            $this->respondJson(['error' => 'Invalid security token.']);
        }

        $employeeId = (int) Tools::getValue('id_employee');
        $label = (string) Tools::getValue('label', '');

        if ($employeeId <= 0) {
            $this->respondJson(['success' => false, 'error' => 'Choose an employee for this token.']);
        }

        $store = $this->tokenStore();

        if ($store === null) {
            $this->respondJson(['success' => false, 'error' => 'Token storage is unavailable.']);
        }

        $this->respondJson([
            'success' => true,
            'token' => $store->issue($employeeId, $label),
        ]);
    }

    /**
     * POST ajax=1&action=RevokeToken: revoke one REST API token.
     *
     * @return void
     */
    public function ajaxProcessRevokeToken(): void
    {
        if (! PsIdentityResolver::manageAll()) {
            $this->respondJson(['error' => 'Permission denied.']);
        }

        if (! $this->checkToken()) {
            $this->respondJson(['error' => 'Invalid security token.']);
        }

        $tokenId = (int) Tools::getValue('id_api_token');
        $store = $this->tokenStore();

        if ($tokenId <= 0 || $store === null) {
            $this->respondJson(['success' => false, 'error' => 'Unknown token.']);
        }

        $store->revoke($tokenId);

        $this->respondJson(['success' => true]);
    }

    /**
     * Build the token store bound to PrestaShop's database.
     *
     * @return PsApiToken|null Null when PrestaShop's database is unavailable.
     */
    private function tokenStore(): ?PsApiToken
    {
        return PsApiToken::fromPrestaShop();
    }

    /**
     * List issued tokens with their owning employee's email resolved for display.
     *
     * @return list<array{id: int, id_employee: int, label: string, created_at: string, last_used_at: string, employee: string}>
     */
    private function apiTokens(): array
    {
        $store = $this->tokenStore();

        if ($store === null) {
            return [];
        }

        $rows = [];

        foreach ($store->all() as $row) {
            $employee = new Employee($row['id_employee']);
            $row['employee'] = Validate::isLoadedObject($employee)
                ? (string) $employee->email
                : 'employee #'.$row['id_employee'];
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * List active employees a token may be issued for.
     *
     * @return list<array{id: int, email: string}>
     */
    private function chatEmployees(): array
    {
        $out = [];

        foreach (Employee::getEmployees(true) as $row) {
            $id = (int) ($row['id_employee'] ?? 0);

            if ($id === 0) {
                continue;
            }

            $employee = new Employee($id);

            $label = Validate::isLoadedObject($employee) ? (string) $employee->email : '';

            if ($label === '') {
                $label = trim(($row['firstname'] ?? '').' '.($row['lastname'] ?? ''));
            }

            $out[] = [
                'id' => $id,
                'email' => $label !== '' ? $label : 'employee #'.$id,
            ];
        }

        return $out;
    }

    /**
     * GET ajax=1&action=CheckUpdate: check for a newer version.
     *
     * @return void
     */
    public function ajaxProcessCheckUpdate(): void
    {
        if (! $this->canDo('view')) {
            $this->respondJson(['error' => 'Permission denied.']);
        }

        if (! $this->checkToken()) {
            $this->respondJson(['error' => 'Invalid security token.']);
        }

        try {
            $config = $this->getPlugin()->config();
            $updater = new AutoUpdater(
                currentVersion: Phpclaw::PHPCLAW_VERSION,
                updateServerUrl: (string) ($config['update_server'] ?? ''),
            );
            $update = $updater->checkForUpdate();
            $this->respondJson($update ?? ['status' => 'up_to_date']);
        } catch (Throwable) {
            $this->respondJson(['error' => 'Update check failed.']);
        }
    }

    /**
     * Validate the submitted Settings form and persist it, or record errors.
     *
     * @param  Plugin  $plugin
     * @return void
     */
    private function handleSettingsSave(Plugin $plugin): void
    {
        if (! $this->checkToken()) {
            $this->errors[] = $this->trans('Invalid security token. Please reload the page and try again.', [], 'Admin.Notifications.Error');

            return;
        }

        if (! $this->canDo('edit')) {
            $this->errors[] = $this->trans('You do not have permission to modify these settings.', [], 'Admin.Notifications.Error');

            return;
        }

        $result = SettingsPage::validate($_POST);

        if ($result['errors'] !== []) {
            foreach ($result['errors'] as $field => $msg) {
                $this->errors[] = $msg;
            }

            return;
        }

        $plugin->saveSettings($result['data']);

        Tools::redirectAdmin($this->context->link->getAdminLink('AdminPhpClawSettings', true, [], ['conf' => 4]));
    }

    /**
     * The passive, on-page-load update check, cached via native Configuration.
     *
     * @param  string  $updateServerUrl
     * @return array{version: string, download_url: string, changelog_url: string, released_at: string}|null
     */
    private function cachedUpdateCheck(string $updateServerUrl): ?array
    {
        if (! class_exists(Configuration::class)) {
            try {
                return (new AutoUpdater(Phpclaw::PHPCLAW_VERSION, $updateServerUrl))->checkForUpdate();
            } catch (Throwable) {
                return null;
            }
        }

        $lastCheckedAt = (int) Configuration::get('PHPCLAW_UPDATE_CHECKED_AT');
        $cached = (string) Configuration::get('PHPCLAW_UPDATE_CACHE');
        $ttl = $cached !== '' ? self::UPDATE_FOUND_CACHE_SECONDS : self::UPDATE_NULL_CACHE_SECONDS;

        if ((time() - $lastCheckedAt) < $ttl) {
            $decoded = $cached !== '' ? json_decode($cached, true) : null;

            return is_array($decoded) ? $decoded : null;
        }

        try {
            $update = (new AutoUpdater(Phpclaw::PHPCLAW_VERSION, $updateServerUrl))->checkForUpdate();
        } catch (Throwable) {
            $update = null;
        }

        Configuration::updateValue('PHPCLAW_UPDATE_CHECKED_AT', (string) time());
        Configuration::updateValue('PHPCLAW_UPDATE_CACHE', $update !== null ? (string) json_encode($update) : '');

        return $update;
    }
}
