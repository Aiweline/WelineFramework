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
];

