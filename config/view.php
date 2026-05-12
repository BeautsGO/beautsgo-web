<?php
// +----------------------------------------------------------------------
// | 模板配置
// +----------------------------------------------------------------------
return [
    'type'         => 'Think',
    'view_path'    => app()->getRootPath() . 'view' . DIRECTORY_SEPARATOR,
    'view_suffix'  => 'html',
    'view_depr'    => DIRECTORY_SEPARATOR,
    'tpl_begin'    => '{',
    'tpl_end'      => '}',
    'taglib_begin' => '{',
    'taglib_end'   => '}',
    // 开发环境关掉模板缓存方便调试，生产建议改 true
    'tpl_cache'    => env('APP_DEBUG', false) ? false : true,
    'cache_path'   => '',
    'tpl_replace_string' => [
        '__STATIC__' => '/static',
        '__CSS__'    => '/static/css',
        '__JS__'     => '/static/js',
        '__IMG__'    => '/static/img',
    ],
];
