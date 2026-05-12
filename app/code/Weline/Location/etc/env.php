<?php

/*
 * Location module configuration
 * 
 * 配置各个定位服务提供者的启用状态和参数
 * 
 * 各通道说明：
 * 1. ip-api.com - 完全免费，无需API Key，45请求/分钟
 *    官网: http://ip-api.com
 *    文档: http://ip-api.com/docs/api:json
 * 
 * 2. geojs.io - 完全免费，无需API Key，无配额限制
 *    官网: https://www.geojs.io
 *    文档: https://www.geojs.io/docs/v1/endpoints/geo/
 * 
 * 3. ipwhois.app - 免费，无需API Key，有配额限制
 *    官网: https://ipwhois.app
 *    文档: https://ipwhois.io/documentation
 * 
 * 4. ipinfo.io - 需要API Key，免费版50,000请求/月
 *    官网: https://ipinfo.io
 *    文档: https://ipinfo.io/developers
 *    注册: https://ipinfo.io/signup
 * 
 * 5. ipapi.co - 需要API Key，免费版1,000请求/天
 *    官网: https://ipapi.co
 *    文档: https://ipapi.co/documentation/
 *    注册: https://ipapi.co/signup/
 */

return [
    'router' => 'location',
    'location' => [
        // 提供者配置
        'providers' => [
            // 本地自建通道（默认禁用，功能暂未实现）
            'local' => [
                'enabled' => false,  // 暂时禁用，触发fallback
                'priority' => 0
            ],
            // ip-api.com - 完全免费，无需API Key
            // 官网: http://ip-api.com | 文档: http://ip-api.com/docs/api:json
            'ip-api.com' => [
                'enabled' => true,
                'priority' => 1,
                'timeout' => 5
            ],
            // geojs.io - 完全免费，无需API Key，无配额限制
            // 官网: https://www.geojs.io | 文档: https://www.geojs.io/docs/v1/endpoints/geo/
            'geojs.io' => [
                'enabled' => true,
                'priority' => 2,
                'timeout' => 5
            ],
            // ipwhois.app - 免费，无需API Key
            // 官网: https://ipwhois.app | 文档: https://ipwhois.io/documentation
            'ipwhois.app' => [
                'enabled' => true,
                'priority' => 3,
                'timeout' => 5
            ],
            // ipinfo.io - 需要API Key，免费版50,000请求/月
            // 官网: https://ipinfo.io | 文档: https://ipinfo.io/developers | 注册: https://ipinfo.io/signup
            'ipinfo.io' => [
                'enabled' => false,  // 默认禁用，需要配置API Key后启用
                'priority' => 4,
                'api_key' => '',     // 在此处配置API Key
                'timeout' => 5
            ],
            // ipapi.co - 需要API Key，免费版1,000请求/天
            // 官网: https://ipapi.co | 文档: https://ipapi.co/documentation/ | 注册: https://ipapi.co/signup/
            'ipapi.co' => [
                'enabled' => false,  // 默认禁用，需要配置API Key后启用
                'priority' => 5,
                'api_key' => '',     // 在此处配置API Key
                'timeout' => 5
            ]
        ],
        // 全局配置
        'timeout' => 5,  // 默认超时时间（秒）
        'retry' => 1     // 每个通道重试次数
    ]
];
