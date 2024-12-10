<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Console\Console\Deploy\Mode;

use Weline\Framework\App\Env;
use Weline\Framework\Console\CommandAbstract;

class Show extends CommandAbstract
{
    public function execute(array $args = [], array $data = [])
    {
        $this->printer->success('当前部署模式：' . Env::getInstance()->getConfig('deploy'));
    }

    public function tip(): string
    {
        return '查看部署环境';
    }
}
