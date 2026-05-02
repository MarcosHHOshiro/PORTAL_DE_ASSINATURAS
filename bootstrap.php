<?php

declare(strict_types=1);

use App\Config\Env;
use App\Database\Migrations;

define('BASE_PATH', __DIR__);
define('STORAGE_PATH', BASE_PATH . DIRECTORY_SEPARATOR . 'storage');
define('FILES_PATH', BASE_PATH . DIRECTORY_SEPARATOR . 'files');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$vendorAutoload = BASE_PATH . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

if (is_file($vendorAutoload)) {
    require_once $vendorAutoload;
} else {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'App\\';

        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            return;
        }

        $relativeClass = substr($class, strlen($prefix));
        $file = BASE_PATH . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR, $relativeClass) . '.php';

        if (is_file($file)) {
            require_once $file;
        }
    });
}

Env::load(BASE_PATH . DIRECTORY_SEPARATOR . '.env');
Migrations::run();
