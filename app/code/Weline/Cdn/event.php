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
        // 长结构/用法说明只放 doc，禁止把多行 API 文档塞进 __() 以免进词典被 AI 扇出
        'description' => __('WLS 检测到攻击时触发，广播各 CDN 开启防护；字段与流程见事件文档。'),
        'doc' => 'CDN攻击检测信号.md',
    ],
    'Weline_Cdn::security::attack_recovered' => [
        'name' => __('CDN攻击恢复信号'),
        'description' => __('攻击恢复超时后触发，广播各 CDN 关闭防护；字段与流程见事件文档。'),
        'doc' => 'CDN攻击恢复信号.md',
    ],
    'Weline_Cdn::push_rules' => [
        'name' => __('CDN规则推送'),
        'description' => __('在推送CDN规则到服务商时触发，允许其他模块监听并参与规则推送。事件数据包含规则列表、域名等信息。'),
        'doc' => 'CDN规则推送.md',
    ],
    'Weline_Cdn::request' => [
        'name' => __('CDN统一请求事件'),
        'description' => __('CDN 统一操作请求（purge/push/capability）；action 与响应字段见事件文档。'),
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
