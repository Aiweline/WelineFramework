<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */
# 全局 DEBUG 模式跟随部署模式，避免生产环境误用沙盒数据库
defined('DEBUG') || define(
    'DEBUG',
    (string) \Weline\Framework\App\Env::getInstance()->getConfig('system.deploy', 'dev') === 'dev'
);
# 全局沙盒模式 注释可使用url控制运行环境，设置可以强行控制SANDBOX
defined('SANDBOX') || define('SANDBOX', 0);
