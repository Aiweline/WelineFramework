<?php
return [
    'Weline_Visitor::taglib_pixel' => [
        'name' => __('访客像素标签'),
        'description' => __('在渲染访客像素标签时触发，允许其他模块控制像素启停和名称。出于安全原因，不执行 pixel_code 自定义脚本。'),
        'doc' => '访客像素标签.md',
    ],
    'Weline_Visitor::event_chain_collect' => [
        'name' => __('事件链收集'),
        'description' => __('组装前台 eventChains 时触发；业务模块向 chains 追加漏斗定义（id/steps/complete_event）。亦支持 Extends EventChainProvider。'),
        'doc' => '事件链注册.md',
        'version' => '1.0.0',
        'type' => 'integration',
        'data_contract' => [
            'website_id' => ['type' => 'int', 'required' => true, 'description' => __('网站 ID')],
            'chains' => ['type' => 'array', 'required' => true, 'description' => __('可追加的事件链列表')],
        ],
    ],
];

