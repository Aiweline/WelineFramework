<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2022/12/23 21:22:00
 */

namespace Weline\Component;

interface ComponentInterface
{
    /**
     * @DESC          # 继承Block处理初始化函数
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2022/12/23 21:22
     * 参数区：
     * @return void
     */
    public function __init(): void;

    /**
     * @DESC          # 组件文档：描述如何使用Block调用组件 返回html文档
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2022/12/23 21:23
     * 参数区：
     * @return string
     */
    public function doc(): string;
}
