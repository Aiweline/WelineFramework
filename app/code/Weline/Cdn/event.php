<?php
return [
    'Weline_Cdn::send_warmup' => [
        'name' => __('CDN预热URL投递'),
        'description' => __('在提交CDN预热URL时触发，允许其他模块监听并处理预热URL。事件数据包含模块名、提供者、URL列表等信息。'),
        'doc' => 'CDN预热URL投递.md',
    ],
    'Weline_Cdn::clear' => [
        'name' => __('CDN缓存清理'),
        'description' => __('在清理CDN缓存时触发，允许其他模块监听并处理缓存清理操作。事件数据包含域名、清理模式等信息。'),
        'doc' => 'CDN缓存清理.md',
    ],
    'Weline_Cdn::request' => [
        'name' => __('CDN统一请求事件'),
        'description' => __('统一的CDN操作请求接口，支持多种操作类型。
使用方法：
dispatch("Weline_Cdn::request", [
    "action" => "purge_all|purge_urls|push_rule|check_capability",
    "website_id" => 1,        // 可选，网站ID，用于获取关联的CDN配置
    "domain" => "example.com", // 可选，直接指定域名
    "data" => [],             // 操作相关数据
]);

action 类型说明：
- purge_all: 清理全站缓存
- purge_urls: 清理指定URL缓存，data.urls = [url1, url2]
- push_rule: 推送CDN规则，data.rules = [...规则配置...]
- check_capability: 检测CDN能力，返回支持的操作类型

响应数据结构：
- success: bool 操作是否成功
- message: string 消息
- data: array 额外数据
- supports_api_purge: bool 是否支持API清理（仅 check_capability 返回）'),
        'doc' => 'CDN统一请求事件.md',
    ],
];

