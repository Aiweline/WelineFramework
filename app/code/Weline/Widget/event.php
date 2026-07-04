<?php

/**
 * Widget 模块事件定义
 */
return [
    'Weline_Widget::widget_install_after' => [
        'name' => __('普通部件首次入库后'),
        'description' => __('普通文件 Widget 第一次写入 DB 注册账本且声明默认注入后触发，载荷包含精确 Widget identity。'),
    ],
];
