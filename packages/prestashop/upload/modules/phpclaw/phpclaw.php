<?php

declare(strict_types=1);
use PhpClaw\PrestaShop\Db\PsDbAdapter;
use PhpClaw\PrestaShop\Install\SqlStatements;
use PhpClaw\PrestaShop\Plugin;
use PhpClaw\PrestaShop\PsIdentityResolver;

if (! defined('_PS_VERSION_')) {
    exit;
}

/**
 * phpClaw AI Agent: PrestaShop module bootstrap class.
 */
class Phpclaw extends Module
{
    public const PHPCLAW_VERSION = '1.0.0';

    protected $tabs = [
        [
            'name' => 'phpClaw AI Agent',
            'class_name' => 'AdminPhpClaw',
            'parent_class_name' => '',
            'visible' => true,
        ],
        [
            'name' => 'Settings',
            'class_name' => 'AdminPhpClawSettings',
            'parent_class_name' => 'AdminPhpClaw',
            'visible' => true,
        ],
        [
            'name' => 'Chat',
            'class_name' => 'AdminPhpClawDebug',
            'parent_class_name' => 'AdminPhpClaw',
            'visible' => true,
        ],
        [
            'name' => 'Analytics',
            'class_name' => 'AdminPhpClawAnalytics',
            'parent_class_name' => 'AdminPhpClaw',
            'visible' => true,
        ],
        [
            'name' => 'Guide',
            'class_name' => 'AdminPhpClawGuide',
            'parent_class_name' => 'AdminPhpClaw',
            'visible' => true,
        ],
        [
            'name' => 'About',
            'class_name' => 'AdminPhpClawAbout',
            'parent_class_name' => 'AdminPhpClaw',
            'visible' => true,
        ],
    ];

    /**
     * Initialise module metadata required by PrestaShop before parent::__construct().
     */
    public function __construct()
    {
        $this->name = 'phpclaw';
        $this->tab = 'administration';
        $this->version = self::PHPCLAW_VERSION;
        $this->author = 'Akash Patel';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => _PS_VERSION_];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->trans('phpClaw AI Agent', [], 'Modules.Phpclaw.Admin');
        $this->description = $this->trans(
            'Universal AI agent engine for PrestaShop. Ask questions in plain English, get real answers from your '
            .'store data. View on the phpClaw Chat tab grants read access across the shop: see the Guide for what '
            .'each tool reaches.',
            [],
            'Modules.Phpclaw.Admin',
        );
    }

    /**
     * Skip the PS-container-dependent translation update. We ship no translations.
     *
     * @return bool
     */
    public function updateModuleTranslations(): bool
    {
        return true;
    }

    /**
     * Install the module: run SQL, set defaults, register tabs and hooks.
     *
     * @return bool
     */
    public function install(): bool
    {
        return parent::install()
            && $this->setDefaultConfig()
            && $this->installTabs()
            && $this->installSql()
            && $this->registerHook('actionAdminControllerInitBefore')
            && $this->registerHook('displayBackOfficeHeader');
    }

    /**
     * Uninstall the module: remove tabs, configuration keys, and SQL tables.
     *
     * @return bool
     */
    public function uninstall(): bool
    {
        return $this->uninstallTabs()
            && $this->deleteConfig()
            && $this->uninstallSql()
            && parent::uninstall();
    }

    /**
     * Redirect to the main settings controller when "Configure" is clicked.
     *
     * @return string
     */
    public function getContent(): string
    {
        Tools::redirectAdmin(
            $this->context->link->getAdminLink('AdminPhpClawSettings'),
        );

        return '';
    }

    /**
     * Fires before every admin controller init; loads vendor autoloader and boots the Plugin singleton.
     *
     * @param  array<string, mixed>  $params
     * @return void
     */
    public function hookActionAdminControllerInitBefore(array $params): void
    {
        $controller = Tools::getValue('controller');

        if (! str_starts_with((string) $controller, 'AdminPhpClaw')) {
            return;
        }

        $vendorAutoload = __DIR__.'/vendor/autoload.php';

        if (file_exists($vendorAutoload)) {
            require_once $vendorAutoload;
        }

        if (! class_exists(Plugin::class, false)) {
            return;
        }

        try {
            $db = new PsDbAdapter(Db::getInstance());
            $prefix = _DB_PREFIX_;
            Plugin::getInstance(
                $db,
                $prefix,
                PsIdentityResolver::actingEmployeeId(),
                PsIdentityResolver::manageAll(),
            );
        } catch (Throwable $e) {
            PrestaShopLogger::addLog(
                'phpClaw: boot failed: '.$e->getMessage(),
                3,
                null,
                'Module',
                (int) $this->id,
            );
        }

        static::$_INSTANCE[$this->name] = $this;
    }

    /**
     * Hook: inject admin CSS/JS for phpClaw pages.
     *
     * @return string
     */
    public function hookDisplayBackOfficeHeader(): string
    {
        $controller = Tools::getValue('controller');

        if (! str_starts_with((string) $controller, 'AdminPhpClaw')) {
            return '';
        }

        $cssFile = _PS_MODULE_DIR_.$this->name.'/views/css/phpclaw-admin.css';
        $cssVer = is_file($cssFile) ? (string) filemtime($cssFile) : self::PHPCLAW_VERSION;
        $this->context->controller->addCSS($this->getPathUri().'views/css/phpclaw-admin.css?v='.$cssVer);

        return '';
    }

    /**
     * Run every statement in sql/install.sql against the store database.
     *
     * @return bool
     */
    private function installSql(): bool
    {
        $sql = (string) file_get_contents(__DIR__.'/sql/install.sql');
        $sql = str_replace('PREFIX_', _DB_PREFIX_, $sql);

        foreach (SqlStatements::split($sql) as $statement) {
            if (! Db::getInstance()->execute($statement)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Run every statement in sql/uninstall.sql against the store database.
     *
     * @return bool
     */
    private function uninstallSql(): bool
    {
        $sql = (string) file_get_contents(__DIR__.'/sql/uninstall.sql');
        $sql = str_replace('PREFIX_', _DB_PREFIX_, $sql);

        foreach (SqlStatements::split($sql) as $statement) {
            Db::getInstance()->execute($statement);
        }

        return true;
    }

    /**
     * Seed the default ps_configuration values on install.
     *
     * @return bool
     */
    private function setDefaultConfig(): bool
    {
        Configuration::updateValue('PHPCLAW_MAX_ITERATIONS', '20');
        Configuration::updateValue('PHPCLAW_STORE_MESSAGES', '1');

        return true;
    }

    /**
     * Delete every PHPCLAW_-prefixed ps_configuration key on uninstall, including ps_setting memory rows and keys left by older versions.
     *
     * @return bool
     */
    private function deleteConfig(): bool
    {
        $db = Db::getInstance();
        $table = _DB_PREFIX_.'configuration';
        $like = "'PHPCLAW\\_%'";

        $ok = $db->execute(
            'DELETE cl FROM `'._DB_PREFIX_.'configuration_lang` cl'
            .' INNER JOIN `'.$table.'` c ON c.`id_configuration` = cl.`id_configuration`'
            .' WHERE c.`name` LIKE '.$like
        );

        $ok = $db->execute('DELETE FROM `'.$table.'` WHERE `name` LIKE '.$like) && $ok;

        Configuration::clearConfigurationCacheForTesting();
        Configuration::loadConfiguration();

        return $ok;
    }

    /**
     * Register every admin tab declared in $this->tabs.
     *
     * @return bool
     */
    private function installTabs(): bool
    {
        foreach ($this->tabs as $tabData) {
            $tab = new Tab;

            foreach (Language::getLanguages(false) as $lang) {
                $tab->name[$lang['id_lang']] = $tabData['name'];
            }

            $tab->class_name = $tabData['class_name'];
            $tab->module = $this->name;
            $tab->active = 1;

            if (! empty($tabData['parent_class_name'])) {
                $tab->id_parent = (int) Tab::getIdFromClassName($tabData['parent_class_name']);
            } else {
                $tab->id_parent = 0;
            }

            if ($tab->add() === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Remove every phpClaw admin tab from the table, including the ones PrestaShop auto-registers per controllers/admin file beyond $this->tabs.
     *
     * @return bool
     */
    private function uninstallTabs(): bool
    {
        $rows = Db::getInstance()->executeS(
            'SELECT `id_tab` FROM `'._DB_PREFIX_.'tab`'
            ." WHERE `module` = 'phpclaw' OR `class_name` LIKE 'AdminPhpClaw%'"
        );

        foreach (is_array($rows) ? $rows : [] as $row) {
            $tab = new Tab((int) $row['id_tab']);

            if (Validate::isLoadedObject($tab)) {
                $tab->delete();
            }
        }

        return true;
    }
}
