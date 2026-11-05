<?php
declare(strict_types=1);

$storagePath = getenv('TEST_CONSOLE_STORAGE') ?: dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage';

return [
    'app_name' => '网关数据测试台',
    'timezone' => getenv('TEST_CONSOLE_TIMEZONE') ?: 'Asia/Shanghai',
    'storage_path' => $storagePath,
    'database_path' => $storagePath . DIRECTORY_SEPARATOR . 'app.sqlite',
    'admin_username' => getenv('TEST_CONSOLE_ADMIN_USER') ?: 'admin',
    // 空密码仅用于本机测试。公网部署必须设置 TEST_CONSOLE_ADMIN_PASSWORD。
    'admin_password' => getenv('TEST_CONSOLE_ADMIN_PASSWORD') ?: '',
    // 可选：为四个设备回调统一设置令牌，不影响后台登录。
    'callback_token' => getenv('TEST_CONSOLE_CALLBACK_TOKEN') ?: '',
    'max_body_bytes' => 20 * 1024 * 1024,
    'per_page' => 20,
    'raw_preview_bytes' => 512,
    'raw_display_limit' => 2 * 1024 * 1024,
];
