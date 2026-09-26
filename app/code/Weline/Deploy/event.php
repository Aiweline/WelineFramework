<?php

declare(strict_types=1);

/**
 * Deploy 模块事件目录（补充 Framework 全局 catalog）。
 */
return [
    'Weline_Deploy::release_after' => [
        'name' => __('发布完成后'),
        'description' => __('deploy:release / Orchestrator 发布流程完成后触发。'),
    ],
    'Weline_Deploy::core_update_after' => [
        'name' => __('核心更新完成后'),
        'description' => __('core:update 将框架核心同步到项目并打印统计后触发。观察者可清主题布局固化物等派生磁盘；Deploy 不硬绑 Theme。'),
        'doc' => 'event/核心更新完成后.md',
    ],
];
