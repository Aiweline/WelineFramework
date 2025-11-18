<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Database;

use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

interface ModelInterface
{
    /**
     * @DESC          # 设置 【开发模式下每次都会执行】
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/9/7 20:05
     * 参数区：
     * @Suppress("unused")
     */
    public function setup(ModelSetup $setup, Context $context): void;

    /**
     * @DESC          # 模块更新时执行
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/9/7 20:07
     * 参数区：
     * @Suppress("unused")
     */
    public function upgrade(ModelSetup $setup, Context $context): void;

    /**
     * @DESC          # 模块安装时执行
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/9/7 20:07
     * 参数区：
     * @Suppress("unused")
     */
    public function install(ModelSetup $setup, Context $context): void;

    /**
     * @DESC          # 模型列
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2022/3/21 20:49
     * 参数区：
     * @return array
     */
    public function columns(): array;
}
