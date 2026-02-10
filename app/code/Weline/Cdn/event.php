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
    'Weline_Cdn::security::attack_detected' => [
        'name' => __('CDN攻击检测信号'),
        'description' => __('当 WLS Dispatcher 检测到攻击并发送信号时触发。
CDN 模块收到此事件后，将广播到各 CDN 服务商开启攻击防护模式。

事件数据结构：
- signal: array 攻击信号详情
  - type: string 攻击类型（rate_limit, path_scan, malicious_pattern, bad_user_agent, protected_path, slowloris）
  - domain: string 被攻击的域名
  - ip: string 攻击者IP
  - timestamp: int 检测时间戳
  - reason: string 攻击原因描述
- summary: array 攻击摘要
  - total: int 总攻击次数
  - by_type: array 按类型分组的攻击次数
  - recent_ips: array 最近的攻击IP列表
- domain: string 被攻击域名
- attack_type: string 攻击类型
- attacker_ip: string 攻击者IP
- timestamp: int 时间戳
- reason: string 原因

使用方法：
dispatch("Weline_Cdn::security::attack_detected", $eventData);

CDN 模块处理流程：
1. 接收攻击信号
2. 判断攻击严重程度
3. 向各 CDN 服务商 API 发送开启攻击防护模式请求
4. 记录攻击日志'),
        'doc' => 'CDN攻击检测信号.md',
    ],
    'Weline_Cdn::security::attack_recovered' => [
        'name' => __('CDN攻击恢复信号'),
        'description' => __('当攻击停止并超过恢复超时时间后触发。
CDN 模块收到此事件后，将广播到各 CDN 服务商关闭攻击防护模式。

事件数据结构：
- domains: array 受影响的域名列表
- started_at: int 攻击开始时间戳
- recovered_at: int 恢复时间戳
- duration: int 攻击持续时间（秒）

使用方法：
dispatch("Weline_Cdn::security::attack_recovered", $eventData);

CDN 模块处理流程：
1. 接收恢复信号
2. 向各 CDN 服务商 API 发送关闭攻击防护模式请求
3. 恢复正常访问策略'),
        'doc' => 'CDN攻击恢复信号.md',
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
    'Weline_Cdn::provider::list' => [
        'name' => __('CDN供应商列表'),
        'description' => __('获取CDN供应商列表时触发，允许其他模块补充或修改供应商列表数据。事件数据包含 providers 数组。'),
        'doc' => 'CDN供应商列表.md',
    ],
    'Weline_Cdn::account::list' => [
        'name' => __('CDN账户列表'),
        'description' => __('获取CDN账户列表时触发，允许其他模块补充或修改账户列表数据。事件数据包含 accounts 数组及 adapter 过滤条件。'),
        'doc' => 'CDN账户列表.md',
    ],
];

