<?php
/**
 * GuoLaiRen_PageBuilder 模块事件规约
 *
 * 仅保留通知型/多模块协作型事件。
 * 数据查询操作已迁移到 WebsitesQueryProvider（统一查询器），不再使用事件。
 */
return [
    'GuoLaiRen_PageBuilder::website_save_after' => [
        'name' => __('网站保存后'),
        'description' => __('新建或更新站点时触发，用于将站点归属到当前后台用户等后续处理。'),
        'doc' => 'website_save_after.md',
    ],
];
