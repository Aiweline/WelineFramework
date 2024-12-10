<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Console;

class Command extends CommandAbstract
{
    public function execute(array $args = [], array $data = [])
    {
        return '定位命令';
    }

    public function tip(): string
    {
        return '定位命令';
    }
}
