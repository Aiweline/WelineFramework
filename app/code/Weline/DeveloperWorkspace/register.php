<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

use Weline\Framework\Register\Register;

// 注意：修改下面注册中的版本号（第4个参数）会触发模块升级流程
// 示例：'1.0.0' -> '1.0.1'，然后运行 `php bin/w setup:upgrade --model` 来执行模型 upgrade()
Register::register(
    Register::MODULE,
    'Weline_DeveloperWorkspace',
    __DIR__,
    '1.3.0',
    '系统开发空间'
);