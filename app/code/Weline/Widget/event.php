<?php

/**
 * Widget 模块事件定义
 */
return [
    'Weline_Widget::registry_refresh_after' => [
        'name' => __('部件注册表刷新后'),
        'description' => __('部件注册表刷新完成后触发，允许其他模块基于最新部件元数据执行同步或默认注入等后置处理。'),
    ],
];
