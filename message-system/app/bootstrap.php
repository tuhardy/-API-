<?php
declare(strict_types=1);

$config = require dirname(__DIR__) . '/config/config.php';

date_default_timezone_set($config['timezone']);

spl_autoload_register(static function (string $class): void {
    $path = __DIR__ . '/' . $class . '.php';
    if (is_file($path)) {
        require $path;
    }
});

require __DIR__ . '/helpers.php';
