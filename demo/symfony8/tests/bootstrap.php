<?php

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

$_SERVER['APP_ENV']      = $_ENV['APP_ENV'] = 'test';
$_SERVER['KERNEL_CLASS'] = $_ENV['KERNEL_CLASS'] = 'App\\Kernel';

if (file_exists(dirname(__DIR__) . '/config/bootstrap.php')) {
    require dirname(__DIR__) . '/config/bootstrap.php';
} elseif (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__) . '/.env');
}

if ($_SERVER['APP_DEBUG'] ?? false) {
    umask(0000);
}
