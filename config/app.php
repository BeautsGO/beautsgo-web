<?php
// +----------------------------------------------------------------------
// | 应用配置
// +----------------------------------------------------------------------
return [
    'app_name'         => 'BeautsGO',
    'app_host'         => env('APP_HOST', ''),
    'with_route'       => true,
    'auto_multi_app'   => false,
    'app_map'          => [],
    'domain_bind'      => [],
    'deny_app_list'    => [],
    'default_timezone' => env('app.default_timezone', 'Asia/Shanghai'),
    'exception_handle' => '\\app\\ExceptionHandle',
    'error_message'    => '页面错误，请稍后再试',
    'show_error_msg'   => env('APP_DEBUG', false),
];
