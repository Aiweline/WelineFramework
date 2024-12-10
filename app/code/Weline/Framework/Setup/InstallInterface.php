<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Setup;

interface InstallInterface
{
    /**
     * @DESC          # 安装函数：仅初次安装会执行
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2022/1/18 20:28
     * 参数区：
     *
     * @param Data\Setup   $setup
     * @param Data\Context $context
     */
    public function setup(Data\Setup $setup, Data\Context $context): void;
}
