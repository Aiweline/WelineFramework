<?php
/**
 * GuoLaiRen_PageBuilder 模块事件规约
 *
 * 网站保存后等事件定义，供 event.xml 中观察者使用。
 */
return [
    'GuoLaiRen_PageBuilder::website_save_after' => [
        'name' => __('网站保存后'),
        'description' => __('新建或更新站点时触发，用于将站点归属到当前后台用户等后续处理。'),
        'doc' => 'website_save_after.md',
    ],
];
