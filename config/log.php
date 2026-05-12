<?php
// +----------------------------------------------------------------------
// | 日志配置
// +----------------------------------------------------------------------
return [
    'default' => env('log.channel', 'file'),

    'channels' => [
        'file' => [
            'type'        => 'File',
            'path'        => '',          // 空 = runtime/log/
            'apart_level' => [],
            'max_files'   => 30,          // 保留 30 天
            'json'        => false,
            'processor'   => null,
            'close'       => false,
            'format'      => '[%s][%s] %s',
            'time_format' => 'c',
            'single'      => false,
            // 不记录的日志级别：开发期都记录，生产可加 ['debug', 'info']
            'level'       => [],
        ],
    ],
];
