<?php
// +----------------------------------------------------------------------
// | 数据库配置 —— 直连 beautsgo_api 同款 RDS，只读
// +----------------------------------------------------------------------
return [
    'default' => env('database.type', 'mysql'),

    'time_query_rule' => [],

    'auto_timestamp'  => false,
    'datetime_format' => 'Y-m-d H:i:s',
    'datetime_field'  => '',

    'connections' => [
        'mysql' => [
            'type'            => env('database.type', 'mysql'),
            'hostname'        => env('database.hostname', '127.0.0.1'),
            'database'        => env('database.database', ''),
            'username'        => env('database.username', ''),
            'password'        => env('database.password', ''),
            'hostport'        => env('database.hostport', '3306'),
            'charset'         => env('database.charset', 'utf8mb4'),
            'prefix'          => env('database.prefix', 'fcam_'),

            // 严格模式：访问不存在字段直接抛异常，避免 SSR 误读垃圾数据
            'fields_strict'   => true,
            // 不缓存字段元数据（SSR 不写表，缓存意义不大；改 schema 后无需清缓存）
            'fields_cache'    => false,

            // SQL 监听：开发期间打印
            'trigger_sql'     => env('app_debug', false),

            'params'          => [],
            'deploy'          => 0,
            'rw_separate'     => false,
            'master_num'      => 1,
            'slave_no'        => '',
            'break_reconnect' => false,
        ],
    ],
];
