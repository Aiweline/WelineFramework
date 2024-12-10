<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Plugin\Api\Data;

interface PluginInterface
{
    public const dir = 'Plugin';

    /**
     * @DESC         |设置插件顺序
     *
     * 参数区：
     *
     * @return int
     */
    public function orderNumber(): int;

    /**
     * @DESC         |是否启用
     *
     * 参数区：
     *
     * @return bool
     */
    public function isEnable(): bool;
}
