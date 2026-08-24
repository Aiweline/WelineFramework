<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\UnitTest;

trait Boot
{
    /**
     * @DESC         |获取测试实例
     *
     * 参数区：
     * @param string $class
     * @return mixed
     */
    public function getInstance(string $class)
    {
        return \Weline\Framework\Manager\ObjectManager::getInstance($class);
    }
}
