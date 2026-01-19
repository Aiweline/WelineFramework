<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Cache;

use Weline\Framework\Manager\FactoryObjectInterface;

/**
 * CacheInterface 工厂类
 * 用于 ObjectManager 依赖注入
 */
class CacheInterfaceFactory implements FactoryObjectInterface
{
    /**
     * 创建 CacheInterface 实例
     * 
     * @return CacheInterface
     */
    public function create(): CacheInterface
    {
        $cacheFactory = new CacheFactory('cache_system', '', false);
        return $cacheFactory->create();
    }
}
