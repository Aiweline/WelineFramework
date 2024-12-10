<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Console\Console\Dev;

use Weline\Framework\Manager\ObjectManager;

class Debug extends \Weline\Framework\Console\CommandAbstract
{
    public function execute(array $args = [], array $data = [])
    {
        if (!isset($args[1])) {
            if (DEV) {
                $this->printer->error('请输入要测试的类！ex:bin/m dev:debug [class] (请用初始化函数测试类，此处不接受运行类方法。)');
            }
            exit();
        }
        $class = ObjectManager::getInstance($args[1]);
    }

    public function tip(): string
    {
        return '开发测试：用于运行测试对象！';
    }
}
