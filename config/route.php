<?php
// +----------------------------------------------------------------------
// | 路由配置
// +----------------------------------------------------------------------
return [
    'pathinfo_depr'        => '/',
    'url_html_suffix'      => false,
    'url_common_param'     => true,
    'url_param_type'       => 0,
    'url_lazy_route'       => false,
    'route_check_cache'    => false,
    'controller_layer'     => 'controller',
    'empty_controller'     => 'Error',
    'use_action_prefix'    => false,
    'action_suffix'        => '',
    'controller_suffix'    => false,
    'default_controller'   => 'Index',
    'default_action'       => 'index',
    'url_route_must'       => true,
    'route_complete_match' => true,
    'route_rule_merge'     => false,
    'cross_domain_rule'    => [],
];
