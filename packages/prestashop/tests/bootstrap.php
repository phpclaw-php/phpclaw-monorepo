<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap: neutral stubs for the PrestaShop globals, so class_exists() guards in src/
 * resolve true and read like a fresh install with no saved settings.
 */

require_once __DIR__.'/../vendor/autoload.php';

if (! defined('_PS_VERSION_')) {
    define('_PS_VERSION_', '8.1.0');
}

if (! defined('_DB_PREFIX_')) {
    define('_DB_PREFIX_', 'ps_');
}

if (! defined('_MODULE_DIR_')) {
    define('_MODULE_DIR_', '/var/www/html/modules/');
}

if (! class_exists('Configuration', false)) {
    class Configuration
    {
        private static array $store = [];

        public static function get(string $key): string|false
        {
            return self::$store[$key] ?? false;
        }

        public static function updateValue(string $key, string $value): bool
        {
            self::$store[$key] = $value;

            return true;
        }

        public static function deleteByName(string $key): bool
        {
            unset(self::$store[$key]);

            return true;
        }

        public static function reset(): void
        {
            self::$store = [];
        }
    }
}

if (! class_exists('Tools', false)) {
    class Tools
    {
        public static function getValue(string $key, mixed $default = false): mixed
        {
            return $_REQUEST[$key] ?? $default;
        }
    }
}

if (! class_exists('Hook', false)) {
    class Hook
    {
        public static array $resultsByHook = [];

        public static array $throwOnHook = [];

        public static function exec(string $name, array $params = [], mixed $idModule = null, bool $arrayReturn = false): array|string
        {
            if (self::$throwOnHook[$name] ?? false) {
                throw new RuntimeException("Hook::exec() stub configured to throw for '{$name}'.");
            }

            if ($arrayReturn) {
                return self::$resultsByHook[$name] ?? [];
            }

            return '';
        }

        public static function setResult(string $hookName, array $value): void
        {
            self::$resultsByHook[$hookName] = $value;
        }

        public static function throwOn(string $hookName): void
        {
            self::$throwOnHook[$hookName] = true;
        }

        public static function reset(): void
        {
            self::$resultsByHook = [];
            self::$throwOnHook = [];
        }
    }
}

if (! class_exists('Db', false)) {
    class Db
    {
        private static ?self $instance = null;

        public static function getInstance(): self
        {
            return self::$instance ??= new self;
        }

        public static array $rows = [];

        public static array $statements = [];

        public function executeS(string $sql): array
        {
            self::$statements[] = $sql;

            foreach (self::$rows as $needle => $rows) {
                if (str_contains($sql, (string) $needle)) {
                    return $rows;
                }
            }

            return [];
        }

        public function execute(string $sql): bool
        {
            self::$statements[] = $sql;

            return true;
        }

        public function escape(string $value): string
        {
            return addslashes($value);
        }

        public static function reset(): void
        {
            self::$instance = null;
            self::$rows = [];
            self::$statements = [];
        }
    }
}

if (! class_exists('Module', false)) {
    class Module
    {
        public static array $instances = [];

        public static function getInstanceByName(string $name): object|false
        {
            return self::$instances[$name] ?? false;
        }

        public static function reset(): void
        {
            self::$instances = [];
        }
    }
}

if (! class_exists('ModuleAdminController', false)) {
    class ModuleAdminController
    {
        public mixed $module = null;

        public bool $bootstrap = false;

        public string $meta_title = '';

        public string $content = '';

        public array $warnings = [];

        public array $informations = [];

        public array $errors = [];

        public ?Context $context = null;

        public array $accessLevels = [];

        public bool $tokenValid = true;

        public function checkToken(): bool
        {
            return $this->tokenValid;
        }

        public function __construct()
        {
            $this->context = Context::getContext();
        }

        public function initContent(): void {}

        public function trans(string $id, array $params = [], string $domain = ''): string
        {
            return vsprintf(str_replace('%s', '%s', $id), $params) ?: $id;
        }

        public function access(string $level): int
        {
            return (int) ($this->accessLevels[$level] ?? 0);
        }
    }
}
if (! class_exists('Employee', false)) {
    class Employee
    {
        public static array $records = [];

        public int $id = 0;

        public int $id_profile = 0;

        public bool $superAdmin = false;

        public bool $active = true;

        public string $email = '';

        public function __construct(int $id = 0)
        {
            if ($id > 0 && isset(self::$records[$id])) {
                $r = self::$records[$id];
                $this->id = $id;
                $this->id_profile = (int) ($r['id_profile'] ?? 0);
                $this->superAdmin = (bool) ($r['superAdmin'] ?? false);
                $this->active = (bool) ($r['active'] ?? true);
                $this->email = (string) ($r['email'] ?? '');
            }
        }

        public function isSuperAdmin(): bool
        {
            return $this->superAdmin;
        }

        public static function getEmployees(bool $activeOnly = true): array
        {
            $out = [];
            foreach (self::$records as $id => $r) {
                if ($activeOnly && ! ($r['active'] ?? true)) {
                    continue;
                }
                $out[] = ['id_employee' => $id, 'email' => (string) ($r['email'] ?? '')];
            }

            return $out;
        }

        public static function seed(int $id, array $attrs = []): void
        {
            self::$records[$id] = $attrs;
        }

        public static function reset(): void
        {
            self::$records = [];
        }
    }
}

if (! class_exists('Validate', false)) {
    class Validate
    {
        public static function isLoadedObject(mixed $object): bool
        {
            return is_object($object) && (int) ($object->id ?? 0) > 0;
        }
    }
}

if (! class_exists('Tab', false)) {
    class Tab
    {
        public static array $idsByClass = [];

        public static function getIdFromClassName(string $className): int|false
        {
            return self::$idsByClass[$className] ?? false;
        }

        public static function reset(): void
        {
            self::$idsByClass = [];
        }
    }
}

if (! class_exists('Profile', false)) {
    class Profile
    {
        public static array $access = [];

        public static function getProfileAccess(int $idProfile, int $idTab): array
        {
            return self::$access[$idProfile][$idTab]
                ?? ['view' => 0, 'add' => 0, 'edit' => 0, 'delete' => 0];
        }

        public static function grant(int $idProfile, int $idTab, string $level = 'view'): void
        {
            self::$access[$idProfile][$idTab][$level] = 1;
        }

        public static function reset(): void
        {
            self::$access = [];
        }
    }
}

if (! class_exists('Context', false)) {
    class Context
    {
        public ?Employee $employee = null;

        private static ?Context $instance = null;

        public static function getContext(): Context
        {
            return self::$instance ??= new self;
        }

        public static function reset(): void
        {
            self::$instance = null;
        }
    }
}

if (! class_exists('PrestaShopLogger', false)) {
    class PrestaShopLogger
    {
        public static array $logs = [];

        public static function addLog(
            string $message,
            int $severity = 1,
            mixed $errorCode = null,
            ?string $objectType = null,
            ?int $objectId = null,
        ): bool {
            self::$logs[] = [
                'message' => $message,
                'severity' => $severity,
                'error_code' => $errorCode,
                'object_type' => $objectType,
                'object_id' => $objectId,
            ];

            return true;
        }

        public static function reset(): void
        {
            self::$logs = [];
        }
    }
}
