<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Cache;

interface CacheFactoryInterface
{
    /**
     * @DESC          # 缓存创建函数
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2022/4/14 23:17
     * 参数区：
     *
     * @param string      $driver
     * @param string|null $tip
     *
     * @return CacheInterface
     */
    public function create(string $driver = '', string $tip = null): CacheInterface;
}
