<?php

declare(strict_types=1);

namespace PhpClaw\Tools\Security;

/**
 * Single source of truth for the sensitive-path blocklists shared by FileReadTool and FileEditTool.
 */
final class BlockedPaths
{
    public const EXTENSIONS = [
        'env', 'key', 'pem', 'crt', 'p12', 'pfx', 'cer', 'der',
        'jks', 'keystore', 'truststore',
        'sqlite', 'sqlite3', 'db', 'mdb',
        'kdbx', 'kwallet',
    ];

    public const FILENAMES = [
        'wp-config.php', 'wp-config-sample.php',
        'config.php', 'configuration.php', 'database.php',
        'settings.php', 'local_settings.php', 'settings.local.php',
        'local.php', 'app.php',
        'env.php', 'config.local.php', 'config.prod.php',
        'settings.inc.php',
        'parameters.php', 'parameters.yml', 'parameters.yaml',
        '.htpasswd', '.htaccess', '.htdigest',
        'nginx.conf', 'httpd.conf', 'php.ini',
        '.npmrc', '.pypirc', '.netrc', '.envrc',
        '.my.cnf', '.pgpass',
        'credentials.json', 'service-account.json',
        'id_rsa', 'id_ed25519', 'id_ecdsa', 'id_dsa',
        'known_hosts', 'authorized_keys',
        'docker-compose.yml', 'docker-compose.yaml',
        '.dockerenv',
    ];

    public const SECURITY_DIRS = [
        '.git', '.ssh', '.gnupg',
        '.aws', '.azure', '.gcloud', '.kube',
        '.docker', '.config',
        'wp-admin', 'wp-includes',
    ];

    public const NOISE_DIRS = ['vendor', 'node_modules'];

    /**
     * Not instantiable: a constants-only holder.
     *
     * @return void
     */
    private function __construct() {}
}
