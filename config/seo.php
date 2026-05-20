<?php
// +----------------------------------------------------------------------
// | SEO 与 Schema.org 配置
// |   与 beauts_app/utils/jsonld.js 的常量对齐
// +----------------------------------------------------------------------
return [
    'site_url'         => env('seo.site_url', 'https://i.beautsgo.com'),
    'site_name'        => 'BeautsGO',
    'org_logo'         => 'https://beautsgoimg.59w.net/20250307145130/67ca97727a5f0.png',
    'org_description'  => 'BeautsGO - 中韩同价，价格透明的韩国医美预约平台',
    'brand_suffix'     => ' - BeautsGO 韩国医美',
    'default_keywords' => '韩国医美,韩国整形医院,医美项目,K-Beauty',
    'default_lang'     => env('seo.default_lang', 'zh-Hans'),

    // 与 jsonld.js getLangPrefix() 完全一致
    'lang_path_map' => [
        'zh-Hans' => 'cn',
        'zh-Hant' => 'zh',
        'ja'      => 'ja',
        'en'      => 'en',
        'th'      => 'th',
    ],
    // 与 jsonld.js getLanguageTag() 完全一致
    'lang_tag_map' => [
        'zh-Hans' => 'zh-CN',
        'zh-Hant' => 'zh-TW',
        'ja'      => 'ja',
        'en'      => 'en',
        'th'      => 'th',
    ],
    // URL段 -> 语言 key
    'lang_segment_map' => [
        'cn' => 'zh-Hans',
        'zh' => 'zh-Hant',
        'ja' => 'ja',
        'en' => 'en',
        'th' => 'th',
    ],
    // 与 jsonld.js getBreadcrumbI18n() 一致
    'breadcrumb_i18n' => [
        'zh-Hans' => ['home' => '首页',     'hospital' => '机构',     'doctor' => '医生',  'project' => '项目'],
        'zh-Hant' => ['home' => '首頁',     'hospital' => '機構',     'doctor' => '醫生',  'project' => '項目'],
        'ja'      => ['home' => 'ホーム',   'hospital' => 'クリニック', 'doctor' => '医師',  'project' => '施術'],
        'en'      => ['home' => 'Home',     'hospital' => 'Clinic',   'doctor' => 'Doctor','project' => 'Procedure'],
        'th'      => ['home' => 'หน้าแรก',  'hospital' => 'คลินิก',     'doctor' => 'หมอ',    'project' => 'บริการ'],
    ],

    // 静态资源版本号 —— CSS / JS 升级时改这里实现强缓存击穿
    'assets' => [
        'css_ver' => '20260520d',
        'js_ver'  => '20260520d',
    ],
];
