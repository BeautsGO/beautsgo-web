<?php
// 内置 PHP 服务器路由（用于 `php think run`）
// 静态资源直通，不经过 ThinkPHP 路由
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = __DIR__ . $path;
if ($path !== '/' && is_file($file)) {
    return false;  // 让内置 server 直接 serve 该文件
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/index.php';
require __DIR__ . '/index.php';
