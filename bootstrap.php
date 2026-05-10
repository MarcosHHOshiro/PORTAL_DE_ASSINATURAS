<?php

declare(strict_types=1);

use App\Config\Env;
use App\Database\Migrations;

// Caminhos compartilhados usados por configuracoes, banco, uploads e downloads.
define('BASE_PATH', __DIR__);
define('STORAGE_PATH', BASE_PATH . DIRECTORY_SEPARATOR . 'storage');
define('FILES_PATH', BASE_PATH . DIRECTORY_SEPARATOR . 'files');

// A autenticacao local depende da sessao PHP para manter o usuario logado.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$vendorAutoload = BASE_PATH . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

// Usa o autoload do Composer quando disponivel; caso contrario, registra
// um fallback simples para carregar as classes do namespace App em src/.
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

// Carrega variaveis sensiveis fora do codigo e garante que o banco exista.
Env::load(BASE_PATH . DIRECTORY_SEPARATOR . '.env');
Migrations::run();
