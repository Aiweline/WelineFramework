<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */
return [
    // 路由：使用 system 避免与 area 名 backend 冲突导致 backend/backend 重复
    'router'       => 'system',
    'dependencies' => [
        'Weline_SystemConfig'
    ]
];
