<?php
return [
    // ========== 布局事件 ==========
    'Weline_Layout::layout_switch_before' => [
        'name' => __('布局切换前'),
        'description' => __('布局切换前触发，可用于验证或准备工作。'),
        'doc' => '布局切换前.md',
    ],
    'Weline_Layout::layout_switch_after' => [
        'name' => __('布局切换后'),
        'description' => __('布局切换后触发，可用于清除缓存、更新索引等。'),
        'doc' => '布局切换后.md',
    ],
    'Weline_Layout::layout_schedule_trigger' => [
        'name' => __('布局计划触发'),
        'description' => __('布局定时计划触发时触发。'),
        'doc' => '布局计划触发.md',
    ],
];
