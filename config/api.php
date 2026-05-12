<?php
// +----------------------------------------------------------------------
// | 后端 API 接入配置
// +----------------------------------------------------------------------
return [
    // 后端 BeautsGO API 地址
    'base_url'        => env('api.base_url', 'https://api.beautsgo.com/api'),

    // 单请求总超时
    'timeout'         => (int) env('api.timeout', 5),

    // TCP 连接超时
    'connect_timeout' => (int) env('api.connect_timeout', 2),

    // 重试次数（暂未启用）
    'retries'         => 1,

    // 各业务接口的缓存 TTL（秒），未列出走 default
    'cache_ttl' => [
        'hospital_detail' => 600,
        'project_detail'  => 1800,
        'doctor_detail'   => 600,
        'home_feed'       => 120,
        'default'         => 60,
    ],
];
