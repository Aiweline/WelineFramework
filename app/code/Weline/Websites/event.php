<?php

declare(strict_types=1);

/*
 * 网站模块事件规约
 */

return [
    'Weline_Websites::website_save_after' => [
        'name' => __('网站保存后'),
        'description' => __('网站保存后触发，可用于更新缓存、通知等相关操作。'),
        'doc' => 'website_save_after.md',
    ],
];

