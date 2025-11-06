<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'Weline_Sticker',
    __DIR__,
    '1.0.0',
    'Sticker 模块：提供一种非侵入式修改其他模块文件的能力，通过编译机制将源文件和 Sticker 规则合并。',
    [
        'Weline_Framework',
        'Weline_Admin'
    ]
);

