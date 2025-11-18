<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Installer\Helper;

class Data
{
    protected InstallData $installData;

    public function __construct()
    {
        $this->installData = new InstallData();
    }

    public function getCheckEnv()
    {
        return $this->installData->getData('env');
    }

    public function getCommands()
    {
        return $this->installData->getData('commands');
    }
}
