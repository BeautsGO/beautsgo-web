<?php
// +----------------------------------------------------------------------
// | 缓存配置
// +----------------------------------------------------------------------
return [
    'default' => env('cache.driver', 'file'),

    'stores'  => [
        'file' => [
            'type'       => 'File',
            'path'       => '',
            'prefix'     => 'bgo:',
            'expire'     => 0,
            'tag_prefix' => 'tag:',
            'serialize'  => [],
        ],
        'redis' => [
            'type'       => 'redis',
            'host'       => env('redis.host', '127.0.0.1'),
            'port'       => env('redis.port', 6379),
            'password'   => env('redis.password', ''),
            'select'     => env('redis.select', 0),
            'timeout'    => 0,
            'expire'     => 0,
            'persistent' => false,
            'prefix'     => 'bgo:',
        ],
    ],
];
